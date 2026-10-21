<?php

namespace App\Http\Controllers;

use App\Models\IngresoProducto;
use App\Models\Order;
use App\Models\Product;
use App\Models\Produccion;
use App\Models\ProduccionItem;
use App\Models\ProduccionTanda;
use App\Services\Documentos;
use App\Support\Stock;
use App\Support\SucursalActual;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Panaderia: produccion del dia.
 * - "Salio del horno": suma al stock de la sucursal (cada tanda queda registrada; se puede corregir).
 * - Cierre del dia: se anota lo que sobro y, si se descarta, sale del stock. Queda el desperdicio.
 */
class ProduccionController extends Controller
{
    /** vendido en el dia (ventas cobradas de la sucursal) por producto */
    private function vendido(Carbon $dia, array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return DB::table('orders_products')->join('orders', 'orders.id', '=', 'orders_products.id_order')
            ->where('orders.id_sucursal', SucursalActual::id())->where('orders.completada', 1)->where('orders.id_type', Order::TIPO_PRODUCTOS)
            ->whereBetween('orders.created_at', [$dia->copy()->startOfDay(), $dia->copy()->endOfDay()])
            ->whereIn('orders_products.id_producto', $ids)
            ->groupBy('orders_products.id_producto')->selectRaw('orders_products.id_producto, SUM(orders_products.cantidad) as c')
            ->pluck('c', 'id_producto')->map(fn ($v) => round((float) $v, 3))->all();
    }

    private function hoy(): Carbon
    {
        return now()->startOfDay();
    }

    public function index(Request $request)
    {
        $request->validate(['fecha' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $dia = $request->filled('fecha') ? Carbon::parse($request->query('fecha'))->startOfDay() : $this->hoy();
        $prod = Produccion::with(['items.producto:id,nombre,codigo,id_categoria,quedan', 'items.producto.category:id,unidad', 'items.tandas.user:id,nombre'])
            ->whereDate('fecha', $dia->toDateString())->first();

        $items = collect();
        if ($prod) {
            $abierta = ! $prod->cerrada_at;
            $vendido = $abierta ? $this->vendido($dia, $prod->items->pluck('id_producto')->all()) : [];
            $productos = $prod->items->pluck('producto')->filter();
            Stock::cargar($productos);
            $items = $prod->items->map(fn (ProduccionItem $i) => [
                'id_producto' => $i->id_producto, 'nombre' => $i->producto?->nombre ?? 'Producto eliminado', 'codigo' => $i->producto?->codigo,
                'unidad' => $i->producto?->category?->unidad, 'fraccionable' => (bool) $i->producto?->category?->esPorPeso(),
                'horneado' => $i->horneado, 'vendido' => $abierta ? ($vendido[$i->id_producto] ?? 0) : (float) $i->vendido,
                'stock' => $i->producto ? (float) $i->producto->quedan : 0, 'sobrante' => $i->sobrante, 'descartado' => $i->descartado,
                'costo' => $i->costo, 'monto' => $i->monto,
                'tandas' => $i->tandas->sortBy('id')->map(fn ($t) => ['cantidad' => $t->cantidad, 'hora' => $t->created_at?->format('H:i'), 'usuario' => $t->user?->nombre])->values(),
            ])->sortBy('nombre')->values();
        }

        return Inertia::render('Produccion/Index', [
            'fecha' => $dia->toDateString(),
            'esHoy' => $dia->isSameDay($this->hoy()),
            'produccion' => $prod ? ['id' => $prod->id, 'cerrada' => (bool) $prod->cerrada_at, 'cerrada_at' => $prod->cerrada_at] : null,
            'items' => $items,
            'historial' => $this->historial(),
        ]);
    }

    /** ultimos 30 dias con produccion: todo en plata para poder sumar panes, facturas y tortas */
    private function historial(): array
    {
        return Produccion::with('items')->orderByDesc('fecha')->limit(30)->get()->map(function (Produccion $p) {
            $fecha = substr((string) $p->fecha, 0, 10);
            $vendido = $p->cerrada_at ? null : $this->vendido(Carbon::parse($fecha), $p->items->pluck('id_producto')->all());
            $horneado = $p->items->sum(fn ($i) => $i->horneado * $i->monto);
            $vend = $p->items->sum(fn ($i) => min($i->horneado, $vendido === null ? (float) $i->vendido : ($vendido[$i->id_producto] ?? 0)) * $i->monto);
            $descartadoVenta = $p->items->sum(fn ($i) => $i->descartado * $i->monto);

            return ['fecha' => $fecha, 'cerrada' => (bool) $p->cerrada_at, 'productos' => $p->items->count(),
                'horneado' => round($horneado, 2), 'vendido' => round($vend, 2),
                'descartado' => round($descartadoVenta, 2), 'perdida' => round($p->items->sum(fn ($i) => $i->descartado * $i->costo), 2),
                'desperdicio' => $horneado > 0 ? round($descartadoVenta / $horneado * 100, 1) : 0];
        })->all();
    }

    public function productos(Request $request)
    {
        return Documentos::buscarProductos($request);
    }

    /** salio del horno (o correccion con cantidad negativa): mueve el stock al instante */
    public function hornear(Request $request)
    {
        $d = $request->validate([
            'id_producto' => ['required', 'integer'],
            'cantidad' => ['required', 'numeric', 'not_in:0', 'min:-99999', 'max:99999'],
        ], ['cantidad.not_in' => 'La cantidad no puede ser 0.']);
        $sid = SucursalActual::id();

        $r = DB::transaction(function () use ($d, $sid, $request) {
            $p = Product::with('category')->where('activo', 1)->lockForUpdate()->find($d['id_producto']);
            if (! $p || $p->tiene_variantes) {
                throw new \DomainException('El producto no existe, está dado de baja o tiene variantes (elegí la variante).');
            }
            $cant = round((float) $d['cantidad'], 3);
            if (! $p->category?->esPorPeso() && abs($cant - round($cant)) > 1e-9) {
                throw new \DomainException($p->nombre.' va por unidad entera.');
            }
            $hoy = $this->hoy()->toDateString();
            $prod = Produccion::whereDate('fecha', $hoy)->lockForUpdate()->first()
                ?? Produccion::create(['id_sucursal' => $sid, 'fecha' => $hoy, 'id_user' => $request->user()->id]);
            if ($prod->cerrada_at) {
                throw new \DomainException('El día ya está cerrado: lo que se hornee ahora cargalo con "Entra mercadería" en el producto.');
            }
            $item = ProduccionItem::firstOrCreate(['produccion_id' => $prod->id, 'id_producto' => $p->id], ['costo' => $p->costo, 'monto' => $p->monto]);
            if ($cant < 0) {
                $hay = Stock::de($p->id, $sid, true);
                if ($item->horneado + $cant < -1e-9) {
                    throw new \DomainException('No se puede descontar más de lo que salió del horno hoy ('.$this->num($item->horneado).').');
                }
                if ($hay + $cant < -1e-9) {
                    throw new \DomainException('No se puede descontar '.$this->num(-$cant).': en stock quedan '.$this->num($hay).' (el resto ya se vendió).');
                }
            }
            $item->update(['horneado' => round($item->horneado + $cant, 3), 'costo' => $p->costo, 'monto' => $p->monto]);
            ProduccionTanda::create(['produccion_item_id' => $item->id, 'cantidad' => $cant, 'id_user' => $request->user()->id]);
            Stock::sumar($p->id, $cant, $sid);
            if ($cant > 0) {
                IngresoProducto::create(['id_producto' => $p->id, 'id_user' => $request->user()->id, 'cantidad' => $cant, 'costo' => $p->costo, 'monto' => $p->monto]);
            }

            return [$p, $cant];
        });
        [$p, $cant] = $r;

        return back()->with('ok', $cant > 0
            ? '+ '.$this->num($cant).' '.($p->category?->unidad ?? '').' de '.$p->nombre.' salieron del horno: ya están en el stock.'
            : 'Se corrigió: '.$this->num($cant).' de '.$p->nombre.'.');
    }

    /** cierre del dia: lo que sobro de cada producto y si se descarta */
    public function cerrar(Request $request)
    {
        $d = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'items' => ['required', 'array', 'max:500'],
            'items.*.id_producto' => ['required', 'integer', 'distinct'],
            'items.*.sobrante' => ['required', 'numeric', 'min:0', 'max:99999'],
            'items.*.descartar' => ['boolean'],
        ], ['items.*.sobrante.required' => 'Anotá lo que sobró de cada producto (0 si no sobró nada).']);
        $sid = SucursalActual::id();

        DB::transaction(function () use ($d, $sid, $request) {
            $prod = Produccion::with('items')->whereDate('fecha', $d['fecha'])->lockForUpdate()->first();
            if (! $prod) {
                throw new \DomainException('Ese día no tiene producción cargada.');
            }
            if ($prod->cerrada_at) {
                throw new \DomainException('Ese día ya estaba cerrado.');
            }
            $vendido = $this->vendido(Carbon::parse($d['fecha']), $prod->items->pluck('id_producto')->all());
            $datos = collect($d['items'])->keyBy('id_producto');
            foreach ($prod->items as $i) {
                $x = $datos[$i->id_producto] ?? null;
                if (! $x) {
                    throw new \DomainException('Falta anotar lo que sobró de un producto.');
                }
                $sobrante = round((float) $x['sobrante'], 3);
                $descartado = 0.0;
                if (! empty($x['descartar']) && $sobrante > 0) {
                    $hay = Stock::de($i->id_producto, $sid, true);
                    $descartado = round(min($sobrante, max(0, $hay)), 3);
                    if ($descartado > 0) {
                        Stock::sumar($i->id_producto, -$descartado, $sid);
                    }
                }
                $i->update(['sobrante' => $sobrante, 'descartado' => $descartado, 'vendido' => $vendido[$i->id_producto] ?? 0]);
            }
            $prod->update(['cerrada_at' => now(), 'cerrada_por' => $request->user()->id]);
        });

        return back()->with('ok', 'Día cerrado. Lo que se descartó ya salió del stock.');
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
    }
}
