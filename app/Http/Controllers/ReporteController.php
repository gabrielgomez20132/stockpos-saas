<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Sucursal;
use App\Support\SucursalActual;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Reportes de ventas. En control-soft el navegador recibia TODAS las ordenes
 * con sus productos y sumaba en JavaScript; ahora el servidor devuelve los
 * numeros ya agregados (mas rapido y no expone datos de mas).
 */
class ReporteController extends Controller
{
    public function index()
    {
        return Inertia::render('Reportes/Index', ['maxDias' => config('stock.max_dias_reporte')]);
    }

    public function datos(Request $request)
    {
        $max = (int) config('stock.max_dias_reporte');
        $v = $request->validate([
            'periodo' => ['required', 'in:semana,mes,personalizado'],
            'inicio' => ['required_if:periodo,personalizado', 'nullable', 'date_format:Y-m-d'],
            'fin' => ['required_if:periodo,personalizado', 'nullable', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'sucursal' => ['nullable', 'string', 'max:10'],
        ]);

        // sucursal del reporte: un encargado asignado ve solo la suya; el resto puede elegir una o "todas"
        $multi = SucursalActual::habilitado();
        $puedeTodas = $multi && ! $request->user()->id_sucursal;
        $sid = SucursalActual::id();
        if ($puedeTodas && ($v['sucursal'] ?? null) === 'todas') {
            $sid = null;
        } elseif ($puedeTodas && ctype_digit((string) ($v['sucursal'] ?? '')) && Sucursal::whereKey((int) $v['sucursal'])->exists()) {
            $sid = (int) $v['sucursal'];
        }
        $filtro = fn ($q) => $sid === null ? $q : $q->where('orders.id_sucursal', $sid);

        [$desde, $hasta] = match ($v['periodo']) {
            'semana' => [now()->subWeek()->startOfDay(), now()->endOfDay()],
            'mes' => [now()->subMonth()->startOfDay(), now()->endOfDay()],
            default => [Carbon::parse($v['inicio'])->startOfDay(), Carbon::parse($v['fin'])->endOfDay()],
        };
        if ($desde->diffInDays($hasta) > $max + 1) {
            return response()->json(['message' => 'El rango no puede superar '.$max.' días.'], 422);
        }

        $base = fn () => $filtro(DB::table('orders_products')
            ->join('orders', 'orders.id', '=', 'orders_products.id_order'))
            ->where('orders.completada', 1)->where('orders.id_type', Order::TIPO_PRODUCTOS)
            ->whereBetween('orders.created_at', [$desde, $hasta]);

        $porDia = $base()
            ->selectRaw('DATE(orders.created_at) as fecha,
                SUM(orders_products.cantidad * orders_products.monto) as ingreso,
                SUM(orders_products.cantidad * orders_products.costo_x_uni) as costo')
            ->groupBy(DB::raw('DATE(orders.created_at)'))->orderBy('fecha')->get()
            ->map(fn ($r) => ['fecha' => $r->fecha, 'ingreso' => round((float) $r->ingreso, 2), 'costo' => round((float) $r->costo, 2),
                'ganancia' => round((float) $r->ingreso - (float) $r->costo, 2)]);

        $top = $base()->leftJoin('products', 'products.id', '=', 'orders_products.id_producto')
            ->leftJoin('product_categories as c', 'c.id', '=', 'products.id_categoria')
            ->selectRaw('orders_products.id_producto, products.nombre, c.unidad, SUM(orders_products.cantidad) as cantidad,
                SUM(orders_products.cantidad * orders_products.monto) as ingreso,
                SUM((orders_products.monto - orders_products.costo_x_uni) * orders_products.cantidad) as ganancia')
            ->groupBy('orders_products.id_producto', 'products.nombre', 'c.unidad')
            ->orderByDesc('cantidad')->limit(15)->get()
            ->map(fn ($r) => ['nombre' => $r->nombre ?? 'Producto eliminado', 'unidad' => $r->unidad, 'cantidad' => round((float) $r->cantidad, 3),
                'ingreso' => round((float) $r->ingreso, 2), 'ganancia' => round((float) $r->ganancia, 2)]);

        $ordenes = $filtro(DB::table('orders'))->where('completada', 1)->where('id_type', Order::TIPO_PRODUCTOS)->whereBetween('created_at', [$desde, $hasta]);
        // desglose por medio de pago: TODOS los medios (aunque esten en 0), con lo cobrado y en cuantas ventas se uso
        $cols = \App\Support\FormasPago::COLUMNAS + ['fiado' => 'fiado'];
        $sel = [];
        foreach ($cols as $m => $col) {
            $sel[] = "COALESCE(SUM($col), 0) as t_$m, COALESCE(SUM(CASE WHEN $col > 0 THEN 1 ELSE 0 END), 0) as n_$m";
        }
        $suma = (clone $ordenes)->selectRaw(implode(', ', $sel))->first();
        $cobrado = round(array_sum(array_map(fn ($m) => (float) $suma->{'t_'.$m}, array_keys($cols))), 2);
        $pagos = [];
        foreach (array_keys($cols) as $m) {
            $t = round((float) $suma->{'t_'.$m}, 2);
            $pagos[] = ['medio' => $m, 'nombre' => \App\Support\FormasPago::ETIQUETAS[$m], 'total' => $t, 'ventas' => (int) $suma->{'n_'.$m},
                'porcentaje' => $cobrado > 0 ? round($t / $cobrado * 100, 1) : 0];
        }
        $medios = collect($pagos)->where('total', '>', 0)->map(fn ($p) => ['medio' => $p['nombre'], 'total' => $p['total']])->values()->all();

        $ingreso = $porDia->sum('ingreso');
        $costo = $porDia->sum('costo');

        return response()->json([
            'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(),
            'porDia' => $porDia, 'top' => $top, 'medios' => $medios,
            'totales' => [
                'ingreso' => round($ingreso, 2), 'costo' => round($costo, 2), 'ganancia' => round($ingreso - $costo, 2),
                'ventas' => $nVentas = (clone $ordenes)->count(), 'descuentos' => round((float) (clone $ordenes)->sum('descuento'), 2),
                'cobrado' => $cobrado, 'ticket' => $nVentas ? round($cobrado / $nVentas, 2) : 0,
                'margen' => $ingreso > 0 ? round(($ingreso - $costo) / $ingreso * 100, 1) : 0,
            ],
            'pagos' => $pagos,
            'sucursal' => $multi ? ($sid === null ? 'todas' : (string) $sid) : null,
            'sucursales' => $puedeTodas ? $this->comparar($desde, $hasta) : null,
        ]);
    }

    /** ventas, costo y ganancia de cada sucursal en el periodo, para compararlas */
    private function comparar($desde, $hasta): array
    {
        $prod = DB::table('orders_products')->join('orders', 'orders.id', '=', 'orders_products.id_order')
            ->where('orders.completada', 1)->where('orders.id_type', Order::TIPO_PRODUCTOS)->whereBetween('orders.created_at', [$desde, $hasta])
            ->groupBy('orders.id_sucursal')
            ->selectRaw('orders.id_sucursal, SUM(orders_products.cantidad * orders_products.monto) as ingreso, SUM(orders_products.cantidad * orders_products.costo_x_uni) as costo')
            ->get()->keyBy('id_sucursal');
        $ord = DB::table('orders')->where('completada', 1)->where('id_type', Order::TIPO_PRODUCTOS)->whereBetween('created_at', [$desde, $hasta])
            ->groupBy('id_sucursal')->selectRaw('id_sucursal, COUNT(*) as ventas, SUM(descuento) as descuentos')->get()->keyBy('id_sucursal');

        return Sucursal::orderBy('id')->get(['id', 'nombre', 'activa'])
            ->filter(fn ($s) => $s->activa || isset($ord[$s->id]))
            ->map(function ($s) use ($prod, $ord) {
                $ingreso = round((float) ($prod[$s->id]->ingreso ?? 0), 2);
                $costo = round((float) ($prod[$s->id]->costo ?? 0), 2);
                $ventas = (int) ($ord[$s->id]->ventas ?? 0);

                return ['id' => $s->id, 'nombre' => $s->nombre, 'ventas' => $ventas, 'ingreso' => $ingreso, 'costo' => $costo,
                    'ganancia' => round($ingreso - $costo, 2), 'descuentos' => round((float) ($ord[$s->id]->descuentos ?? 0), 2),
                    'ticket' => $ventas ? round($ingreso / $ventas, 2) : 0];
            })->values()->all();
    }
}
