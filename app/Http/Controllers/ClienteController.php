<?php

namespace App\Http\Controllers;

use App\Models\Deuda;
use App\Models\User;
use App\Services\Caja;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ClienteController extends Controller
{
    private function listado(Request $request, bool $activos)
    {
        $q = trim((string) $request->query('q', ''));
        $saldos = Deuda::saldos('cliente');

        $clientes = User::tipo(User::TIPO_CLIENTE)->where('id', '!=', User::ID_CLIENTE_GENERAL)->where('activo', $activos)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('nombre', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('dni', 'like', addcslashes($q, '%_\\').'%')))
            ->orderBy('nombre')->get(['id', 'nombre', 'dni', 'telefono', 'direccion'])
            ->map(fn ($c) => $c->toArray() + ['saldo' => $saldos[$c->id] ?? 0]);

        if ($request->boolean('deudores')) {
            $clientes = $clientes->filter(fn ($c) => $c['saldo'] > 0)->values();
        }

        return Inertia::render('Clientes/Index', [
            'clientes' => $clientes,
            'activos' => $activos,
            'filtros' => ['q' => $q, 'deudores' => $request->boolean('deudores')],
            'totalDeuda' => round(collect($saldos)->filter(fn ($s) => $s > 0)->sum(), 2),
            'cajaAbierta' => app(Caja::class)->abierta(),
        ]);
    }

    public function index(Request $request)
    {
        return $this->listado($request, true);
    }

    public function papelera(Request $request)
    {
        return $this->listado($request, false);
    }

    private function reglas(?User $c = null): array
    {
        return [
            'nombre' => ['required', 'string', 'max:50'],
            'dni' => ['nullable', 'integer', 'min:1', 'max:4294967295', Rule::unique('users', 'dni')->ignore($c?->id)],
            'telefono' => ['nullable', 'string', 'max:40'],
            'direccion' => ['nullable', 'string', 'max:150'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function create()
    {
        return Inertia::render('Clientes/Form', ['cliente' => null]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate($this->reglas());
        User::create($datos + ['id_uType' => User::TIPO_CLIENTE, 'activo' => 1]);

        return redirect()->route('clientes.index')->with('ok', 'Cliente creado.');
    }

    /** Alta rapida desde la pantalla de venta. */
    public function rapido(Request $request)
    {
        $datos = $request->validate(['nombre' => ['required', 'string', 'max:50']]);
        $c = User::create(['nombre' => $datos['nombre'], 'id_uType' => User::TIPO_CLIENTE, 'activo' => 1]);

        return response()->json(['id' => $c->id, 'nombre' => $c->nombre]);
    }

    public function edit(User $cliente)
    {
        return Inertia::render('Clientes/Form', ['cliente' => $cliente->only(['id', 'nombre', 'dni', 'telefono', 'direccion', 'observaciones'])]);
    }

    public function update(Request $request, User $cliente)
    {
        $cliente->update($request->validate($this->reglas($cliente)));

        return redirect()->route('clientes.index')->with('ok', 'Cliente actualizado.');
    }

    public function destroy(User $cliente)
    {
        $cliente->update(['activo' => 0]);

        return $this->ok($cliente->nombre.' pasó a la papelera.');
    }

    public function restaurar(User $cliente)
    {
        $cliente->update(['activo' => 1]);

        return $this->ok($cliente->nombre.' recuperado.');
    }

    /** Cobro de deuda (cuenta corriente). Entra efectivo a la caja del turno. */
    public function pago(Request $request, User $cliente, Caja $caja)
    {
        $saldo = Deuda::saldo('cliente', $cliente->id);
        $datos = $request->validate([
            'monto' => ['required', 'numeric', 'gt:0', 'max:'.max(0.01, $saldo)],
        ], [
            'monto.max' => 'El cliente debe $'.number_format($saldo, 2, ',', '.').': no podés cobrarle más que eso.',
            'monto.gt' => 'El monto tiene que ser mayor a cero.',
        ]);

        if (! $caja->abierta()) {
            return back()->with('error', 'La caja está cerrada: abrila para registrar cobros en efectivo.');
        }

        Deuda::create([
            'id_cliente' => $cliente->id, 'tipo_cuenta' => 'cliente', 'id_encargado' => $request->user()->id,
            'monto' => round((float) $datos['monto'], 2), 'tipo' => Deuda::PAGO, 'detalle' => 'Pago en efectivo', 'deHoy' => 1,
        ]);

        return $this->ok('Pago de '.$cliente->nombre.' registrado.');
    }

    public function historial(Request $request, User $cliente)
    {
        $rango = $this->rango($request) ?? [now()->subDays(60)->startOfDay(), now()->endOfDay()];

        $ventas = DB::table('orders')->leftJoin('users as e', 'e.id', '=', 'orders.id_encargado')
            ->leftJoin('formas_pago as f', 'f.id', '=', 'orders.id_forma_pago')
            ->where('orders.id_cliente', $cliente->id)->where('orders.completada', 1)
            ->whereBetween('orders.created_at', $rango)
            ->orderByDesc('orders.created_at')
            ->get(['orders.id', 'e.nombre as encargado', 'f.nombre as forma_pago', 'orders.monto', 'orders.descuento', 'orders.fiado', 'orders.created_at']);

        $movs = DB::table('deudas')->leftJoin('users as e', 'e.id', '=', 'deudas.id_encargado')
            ->where('deudas.tipo_cuenta', 'cliente')->where('deudas.id_cliente', $cliente->id)
            ->whereBetween('deudas.created_at', $rango)
            ->orderByDesc('deudas.created_at')
            ->get(['deudas.id', 'e.nombre as encargado', 'deudas.tipo', 'deudas.monto', 'deudas.detalle', 'deudas.created_at']);

        return Inertia::render('Clientes/Historial', [
            'cliente' => $cliente->only(['id', 'nombre']),
            'saldo' => Deuda::saldo('cliente', $cliente->id),
            'ventas' => $ventas,
            'movimientos' => $movs,
            'filtro' => ['desde' => $rango[0]->toDateString(), 'hasta' => $rango[1]->toDateString()],
        ]);
    }
}
