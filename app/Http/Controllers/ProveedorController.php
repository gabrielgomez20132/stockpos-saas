<?php

namespace App\Http\Controllers;

use App\Models\Control;
use App\Models\Deuda;
use App\Models\User;
use App\Services\Caja;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Proveedores (user_type 3) y su cuenta corriente: lo que se les debe por
 * compras (D) menos lo que se les pago (P).
 */
class ProveedorController extends Controller
{
    private function listado(bool $activos)
    {
        $saldos = Deuda::saldos('proveedor');

        return Inertia::render('Proveedores/Index', [
            'proveedores' => User::tipo(User::TIPO_PROVEEDOR)->where('activo', $activos)->orderBy('nombre')
                ->get(['id', 'nombre', 'telefono', 'contacto', 'cuit'])
                ->map(fn ($p) => $p->toArray() + ['saldo' => $saldos[$p->id] ?? 0]),
            'activos' => $activos,
        ]);
    }

    public function index()
    {
        return $this->listado(true);
    }

    public function papelera()
    {
        return $this->listado(false);
    }

    private function reglas(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:50'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'cuit' => ['nullable', 'string', 'max:20'],
            'contacto' => ['nullable', 'string', 'max:80'],
            'direccion' => ['nullable', 'string', 'max:150'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function create()
    {
        return Inertia::render('Proveedores/Form', ['proveedor' => null]);
    }

    public function store(Request $request)
    {
        User::create($request->validate($this->reglas()) + ['id_uType' => User::TIPO_PROVEEDOR, 'activo' => 1]);

        return redirect()->route('proveedores.index')->with('ok', 'Proveedor creado.');
    }

    public function edit(User $proveedor)
    {
        return Inertia::render('Proveedores/Form', ['proveedor' => $proveedor->only(['id', 'nombre', 'telefono', 'cuit', 'contacto', 'direccion', 'observaciones'])]);
    }

    public function update(Request $request, User $proveedor)
    {
        $proveedor->update($request->validate($this->reglas()));

        return redirect()->route('proveedores.index')->with('ok', 'Proveedor actualizado.');
    }

    public function destroy(User $proveedor)
    {
        $proveedor->update(['activo' => 0]);

        return $this->ok($proveedor->nombre.' pasó a la papelera.');
    }

    public function restaurar(User $proveedor)
    {
        $proveedor->update(['activo' => 1]);

        return $this->ok($proveedor->nombre.' recuperado.');
    }

    public function cuenta(User $proveedor, Caja $caja)
    {
        $movs = Deuda::deProveedores()->where('id_cliente', $proveedor->id)
            ->leftJoin('users as e', 'e.id', '=', 'deudas.id_encargado')
            ->orderByDesc('deudas.created_at')->orderByDesc('deudas.id')
            ->select('deudas.id', 'deudas.tipo', 'deudas.monto', 'deudas.detalle', 'deudas.created_at', 'e.nombre as encargado')
            ->paginate(30);

        $q = Deuda::deProveedores()->where('id_cliente', $proveedor->id);
        $compras = (float) (clone $q)->where('tipo', Deuda::DEBE)->sum('monto');
        $pagos = (float) (clone $q)->where('tipo', Deuda::PAGO)->sum('monto');

        return Inertia::render('Proveedores/Cuenta', [
            'proveedor' => $proveedor->only(['id', 'nombre', 'telefono', 'contacto', 'cuit', 'direccion', 'observaciones']),
            'movimientos' => $movs,
            'compras' => round($compras, 2),
            'pagos' => round($pagos, 2),
            'saldo' => round($compras - $pagos, 2),
            'cajaAbierta' => $caja->abierta(),
        ]);
    }

    public function compra(Request $request, User $proveedor)
    {
        $d = $request->validate([
            'monto' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'detalle' => ['nullable', 'string', 'max:150'],
        ], ['monto.gt' => 'El importe tiene que ser mayor a cero.']);

        Deuda::create([
            'id_cliente' => $proveedor->id, 'tipo_cuenta' => 'proveedor', 'id_encargado' => $request->user()->id,
            'monto' => round((float) $d['monto'], 2), 'tipo' => Deuda::DEBE, 'detalle' => $d['detalle'] ?? null, 'deHoy' => 1,
        ]);

        return $this->ok('Compra registrada.');
    }

    /** Pago al proveedor. Si sale del cajon, ademas queda como gasto de proveedores del turno. */
    public function pago(Request $request, User $proveedor, Caja $caja)
    {
        $d = $request->validate([
            'monto' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'medio' => ['required', 'in:caja,otro'],
            'detalle' => ['nullable', 'string', 'max:150'],
        ], ['monto.gt' => 'El importe tiene que ser mayor a cero.']);

        if ($d['medio'] === 'caja' && ! $caja->abierta()) {
            return back()->with('error', 'La caja está cerrada: no se puede pagar con efectivo del cajón. Abrí la caja o elegí "Otro medio".');
        }

        $monto = round((float) $d['monto'], 2);
        DB::transaction(function () use ($request, $proveedor, $d, $monto) {
            Deuda::create([
                'id_cliente' => $proveedor->id, 'tipo_cuenta' => 'proveedor', 'id_encargado' => $request->user()->id,
                'monto' => $monto, 'tipo' => Deuda::PAGO, 'deHoy' => 1,
                'detalle' => $d['detalle'] ?? ($d['medio'] === 'caja' ? 'Pago en efectivo' : 'Pago por otro medio'),
            ]);
            if ($d['medio'] === 'caja') {
                Control::create([
                    'admin' => mb_substr($request->user()->nombre, 0, 50), 'monto' => $monto,
                    'id_desc' => Control::GASTO_PROVEEDORES, 'id_proveedor' => $proveedor->id,
                    'detalle' => mb_substr('Pago a cuenta'.(! empty($d['detalle']) ? ' · '.$d['detalle'] : ''), 0, 100),
                    'caja_abierta' => 1,
                ]);
            }
        });

        return $this->ok('Pago registrado'.($d['medio'] === 'caja' ? ' (descontado de la caja).' : '.'));
    }
}
