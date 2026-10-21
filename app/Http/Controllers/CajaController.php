<?php

namespace App\Http\Controllers;

use App\Models\CierreCaja;
use App\Models\Control;
use App\Models\User;
use App\Services\Caja;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CajaController extends Controller
{
    public function __construct(private Caja $caja)
    {
    }

    private function lista(int $tipo, ?array $rango)
    {
        $q = Control::where('id_desc', $tipo)->orderByDesc('id');
        $rango ? $q->whereBetween('created_at', $rango) : $q->where('caja_abierta', 1);

        return $q->limit(500)->get(['id', 'id_desc', 'id_proveedor', 'admin', 'detalle', 'monto', 'caja_abierta', 'created_at']);
    }

    /** Caja inicial: abre el turno. */
    public function inicial()
    {
        return Inertia::render('Caja/Inicial', [
            'movimientos' => $this->lista(Control::INICIAL, null),
        ]);
    }

    public function retiros(Request $request)
    {
        $rango = $this->rango($request);

        return Inertia::render('Caja/Retiros', [
            'movimientos' => $this->lista(Control::RETIRO, $rango),
            'filtro' => $rango ? ['desde' => $rango[0]->toDateString(), 'hasta' => $rango[1]->toDateString()] : null,
        ]);
    }

    public function gastos(Request $request, string $tipo)
    {
        $rango = $this->rango($request);
        $idDesc = Control::GASTOS[$tipo];

        $movs = $this->lista($idDesc, $rango);
        $proveedores = User::tipo(User::TIPO_PROVEEDOR)->orderBy('nombre')->get(['id', 'nombre', 'activo']);

        return Inertia::render('Caja/Gastos', [
            'tipo' => $tipo,
            'movimientos' => $movs,
            'proveedores' => $proveedores,
            'filtro' => $rango ? ['desde' => $rango[0]->toDateString(), 'hasta' => $rango[1]->toDateString()] : null,
        ]);
    }

    /**
     * Carga un movimiento de caja (caja inicial, retiro o gasto). El nombre de
     * quien lo carga sale de la sesion, no del formulario.
     */
    public function guardar(Request $request)
    {
        $tipos = [Control::INICIAL, Control::RETIRO, Control::GASTO_VARIOS, Control::GASTO_SERVICIOS, Control::GASTO_PROVEEDORES];
        $datos = $request->validate([
            'id_desc' => ['required', 'integer', Rule::in($tipos)],
            'monto' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'detalle' => ['nullable', 'string', 'max:100'],
            'id_proveedor' => ['nullable', 'integer', Rule::exists('users', 'id')->where('id_uType', User::TIPO_PROVEEDOR)],
        ], [
            'monto.gt' => 'El monto tiene que ser mayor a cero.',
            'monto.numeric' => 'El monto tiene que ser un número.',
        ]);

        $idDesc = (int) $datos['id_desc'];
        $esGasto = in_array($idDesc, Control::GASTOS, true);
        if ($esGasto && ! $request->user()->hasPermission('control')) {
            abort(403);
        }
        if ($idDesc !== Control::INICIAL && ! $this->caja->abierta()) {
            return back()->with('error', 'La caja está cerrada. Primero cargá la caja inicial.');
        }
        if ($idDesc === Control::GASTO_PROVEEDORES && empty($datos['id_proveedor']) && trim((string) ($datos['detalle'] ?? '')) === '') {
            return back()->withErrors(['id_proveedor' => 'Elegí el proveedor o escribí un detalle.']);
        }

        Control::create([
            'id_desc' => $idDesc,
            'admin' => mb_substr($request->user()->nombre, 0, 50),
            'monto' => round((float) $datos['monto'], 2),
            'detalle' => mb_substr((string) ($datos['detalle'] ?? ''), 0, 100),
            'id_proveedor' => $idDesc === Control::GASTO_PROVEEDORES ? ($datos['id_proveedor'] ?? null) : null,
            'caja_abierta' => 1,
        ]);

        return $this->ok($idDesc === Control::INICIAL ? 'Caja abierta: ya podés vender.' : 'Movimiento registrado.');
    }

    /** Solo se borran movimientos del turno abierto: los de turnos cerrados ya estan en el cierre. */
    public function borrar(Request $request, Control $control)
    {
        if (! $control->caja_abierta) {
            return back()->with('error', 'Ese movimiento pertenece a un turno ya cerrado.');
        }
        if (in_array($control->id_desc, Control::GASTOS, true) && ! $request->user()->hasPermission('control')) {
            abort(403);
        }
        $control->delete();

        return $this->ok('Movimiento eliminado.');
    }

    public function movimientos(Request $request)
    {
        $rango = $this->rango($request);

        return Inertia::render('Caja/Movimientos', [
            'datos' => $rango ? $this->caja->datosRango($rango[0], $rango[1]) : $this->caja->datosTurno(),
            'filtro' => $rango ? ['desde' => $rango[0]->toDateString(), 'hasta' => $rango[1]->toDateString()] : null,
        ]);
    }

    public function cerrar(Request $request)
    {
        try {
            $cierre = $this->caja->cerrar($request->user());
        } catch (\DomainException $e) {
            return redirect()->route('caja.inicial')->with('error', $e->getMessage());
        }

        return redirect()->route('caja.cierre', $cierre)->with('ok', 'Caja cerrada. Este es el resumen del turno.');
    }

    /** Historial de cierres, con filtro por fechas (de cierre) y por persona. */
    public function cierres(Request $request)
    {
        $rango = $this->rango($request, 3700);
        $quien = trim((string) $request->query('quien', ''));
        $like = '%'.addcslashes($quien, '%_\\').'%';

        $q = CierreCaja::query()
            ->when($rango, fn ($w) => $w->whereBetween('cierre', $rango))
            ->when($quien !== '', fn ($w) => $w->where(fn ($x) => $x->where('cerrado_por', 'like', $like)->orWhere('abierto_por', 'like', $like)));

        $totales = (clone $q)->selectRaw('COUNT(*) as cierres, COALESCE(SUM(cant_ventas),0) as ventas, COALESCE(SUM(total_efectivo),0) as efectivo, COALESCE(SUM(total_turno),0) as total')->first();

        return Inertia::render('Caja/Cierres', [
            'cierres' => $q->orderByDesc('cierre')
                ->select(['id', 'apertura', 'abierto_por', 'cierre', 'cerrado_por', 'cant_ventas', 'total_efectivo', 'total_turno'])
                ->paginate(25)->withQueryString(),
            'filtro' => $rango ? ['desde' => $rango[0]->toDateString(), 'hasta' => $rango[1]->toDateString()] : null,
            'quien' => $quien,
            'totales' => [
                'cierres' => (int) $totales->cierres, 'ventas' => (int) $totales->ventas,
                'efectivo' => round((float) $totales->efectivo, 2), 'total' => round((float) $totales->total, 2),
            ],
        ]);
    }

    public function verCierre(CierreCaja $cierre)
    {
        $anterior = CierreCaja::where('cierre', '<', $cierre->cierre)->orderByDesc('cierre')->value('id');
        $siguiente = CierreCaja::where('cierre', '>', $cierre->cierre)->orderBy('cierre')->value('id');

        return Inertia::render('Caja/Resumen', [
            'cierre' => ['id' => $cierre->id] + ($cierre->resumen ?? []) + [
                'apertura' => $cierre->apertura?->toDateTimeString(),
                'abierto_por' => $cierre->abierto_por ?? ($cierre->resumen['abierto_por'] ?? null),
                'cierre' => $cierre->cierre?->toDateTimeString(),
                'cerrado_por' => $cierre->cerrado_por,
            ],
            'anterior' => $anterior,
            'siguiente' => $siguiente,
            'verGanancia' => request()->user()->hasPermission('control'),
        ]);
    }
}
