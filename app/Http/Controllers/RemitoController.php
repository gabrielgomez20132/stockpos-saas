<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderService;
use App\Models\Remito;
use App\Models\RemitoItem;
use App\Models\User;
use App\Services\Documentos;
use App\Support\Stock;
use App\Support\SucursalActual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Remitos de entrega.
 * - De una venta: la mercaderia ya salio del stock con la venta, el remito no lo toca.
 *   Se puede entregar en partes: cada remito no puede pasarse de lo que falta entregar.
 * - Sueltos: entrega sin venta (se cobra despues): descuentan stock y, si se anulan, vuelve.
 */
class RemitoController extends Controller
{
    private const CANT = 0.0005;

    public function index(Request $request)
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        $lista = Remito::with('user:id,nombre')->withCount('items')
            ->when($q !== '', function ($w) use ($q) {
                $num = ltrim(preg_replace('/^\d{1,4}-/', '', $q), '0');
                $w->where(fn ($x) => $x->where('cliente_nombre', 'like', '%'.addcslashes($q, '%_\\').'%')
                    ->when(ctype_digit($num) && $num !== '', fn ($y) => $y->orWhere('id', (int) $num)->orWhere('id_order', (int) $num)));
            })
            ->orderByDesc('id')->paginate(20)->withQueryString()
            ->through(fn (Remito $r) => ['id' => $r->id, 'numero' => $r->numero(), 'fecha' => $r->created_at, 'cliente' => $r->cliente_nombre,
                'venta' => $r->id_order, 'stock' => $r->descuenta_stock, 'anulado' => (bool) $r->anulado_at, 'total' => $r->total,
                'items' => $r->items_count, 'usuario' => $r->user?->nombre]);

        return Inertia::render('Remitos/Index', ['remitos' => $lista, 'filtros' => ['q' => $q]]);
    }

    /** lo vendido en la venta y cuanto falta entregar de cada producto */
    private function pendiente(Order $order): array
    {
        $vendido = OrderProduct::with('producto:id,nombre,codigo,id_categoria', 'producto.category:id,unidad')->where('id_order', $order->id)->orderBy('id')->get();
        $entregado = RemitoItem::query()->join('remitos', 'remitos.id', '=', 'remito_items.remito_id')
            ->where('remitos.id_order', $order->id)->whereNull('remitos.anulado_at')->whereNotNull('remito_items.id_producto')
            ->groupBy('remito_items.id_producto')->selectRaw('remito_items.id_producto, SUM(remito_items.cantidad) as c')->pluck('c', 'id_producto');

        $lineas = [];
        foreach ($vendido->groupBy('id_producto') as $id => $filas) {
            $p = $filas->first()->producto;
            $total = round($filas->sum('cantidad'), 3);
            $falta = round($total - (float) ($entregado[$id] ?? 0), 3);
            $monto = $filas->sum(fn ($f) => $f->monto * $f->cantidad);
            $lineas[] = ['id_producto' => (int) $id, 'codigo' => $p?->codigo ? (string) $p->codigo : null, 'descripcion' => $p?->nombre ?? 'Producto eliminado',
                'vendido' => $total, 'cantidad' => max(0, $falta), 'precio' => $total > 0 ? round($monto / $total, 2) : 0,
                'unidad' => $p?->category?->unidad, 'fraccionable' => (bool) $p?->category?->esPorPeso(), 'existe' => (bool) $p];
        }

        return $lineas;
    }

    public function create(Request $request)
    {
        $order = null;
        if ($request->filled('venta')) {
            $order = Order::with('cliente:id,nombre,dni,direccion,telefono')->find($request->integer('venta'));
            if (! $order || ! $order->completada) {
                return redirect()->route('remitos.create')->with('error', 'Esa venta no existe en esta sucursal o todavía no está cobrada.');
            }
        }
        $c = $order?->cliente && $order->id_cliente !== User::ID_CLIENTE_GENERAL ? $order->cliente : null;

        return Inertia::render('Remitos/Form', [
            'venta' => $order ? [
                'id' => $order->id, 'fecha' => $order->created_at, 'total' => $order->totalFinal(),
                'lineas' => $this->pendiente($order),
                'servicios' => OrderService::where('id_order', $order->id)->orderBy('id')->get(['description', 'monto'])
                    ->map(fn ($s) => ['descripcion' => mb_substr((string) $s->description, 0, 150), 'precio' => $s->monto])->values(),
                'cliente' => $c ? ['id' => $c->id, 'nombre' => $c->nombre, 'doc' => $c->dni ? (string) $c->dni : null, 'direccion' => $c->direccion, 'telefono' => $c->telefono] : null,
            ] : null,
            'sucursalNombre' => SucursalActual::nombre(),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(Documentos::reglas() + ['id_order' => ['nullable', 'integer']], Documentos::mensajes());
        $sid = SucursalActual::id();

        $r = DB::transaction(function () use ($d, $sid, $request) {
            $order = null;
            if (! empty($d['id_order'])) {
                $order = Order::lockForUpdate()->find($d['id_order']);
                if (! $order || ! $order->completada) {
                    throw new \DomainException('La venta no existe en esta sucursal o todavía no está cobrada.');
                }
            }
            $renglones = Documentos::renglones($d['items'], bloquear: ! $order);

            // cantidades por producto
            $porProducto = [];
            foreach ($renglones as $x) {
                if ($x['id_producto']) {
                    $porProducto[$x['id_producto']] = round(($porProducto[$x['id_producto']] ?? 0) + $x['cantidad'], 3);
                }
            }

            if ($order) {
                $falta = collect($this->pendiente($order))->keyBy('id_producto');
                foreach ($porProducto as $id => $cant) {
                    $l = $falta[$id] ?? null;
                    if (! $l) {
                        throw new \DomainException('Uno de los productos no está en la venta #'.$order->id.'.');
                    }
                    if ($cant > $l['cantidad'] + self::CANT) {
                        throw new \DomainException($l['cantidad'] <= 0
                            ? $l['descripcion'].' ya se entregó completo en otro remito.'
                            : 'De '.$l['descripcion'].' faltan entregar '.$this->num($l['cantidad']).': no se puede poner '.$this->num($cant).'.');
                    }
                }
            } else {
                foreach ($porProducto as $id => $cant) {
                    $hay = Stock::de($id, $sid, true);
                    if ($hay + self::CANT < $cant) {
                        $nombre = collect($renglones)->firstWhere('id_producto', $id)['producto']->nombre;
                        throw new \DomainException($hay > 0 ? 'De '.$nombre.' solo quedan '.$this->num($hay).' en stock.' : $nombre.' no tiene stock.');
                    }
                }
            }

            $cliente = Documentos::cliente($d);
            if ($order && ! $cliente['id_cliente'] && ! $cliente['cliente_nombre'] && $order->id_cliente !== User::ID_CLIENTE_GENERAL) {
                $cliente = Documentos::cliente(['id_cliente' => $order->id_cliente]);
            }
            $r = Remito::create($cliente + [
                'id_user' => $request->user()->id, 'id_order' => $order?->id, 'descuenta_stock' => ! $order,
                'observaciones' => $d['observaciones'] ?? null, 'total' => Documentos::subtotal($renglones),
            ]);
            foreach (Documentos::filas($renglones) as $x) {
                RemitoItem::create($x + ['remito_id' => $r->id]);
            }
            if (! $order) {
                foreach ($porProducto as $id => $cant) {
                    Stock::sumar($id, -$cant, $sid);
                }
            }

            return $r;
        });

        return redirect()->route('remitos.show', $r)->with('ok', 'Remito '.$r->numero().' guardado'.($r->descuenta_stock ? ': el stock ya se descontó.' : '.'));
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
    }

    private function datos(Remito $remito): array
    {
        return [
            'id' => $remito->id, 'numero' => $remito->numero(), 'fecha' => $remito->created_at, 'venta' => $remito->id_order,
            'stock' => $remito->descuenta_stock, 'anulado' => $remito->anulado_at, 'anulado_por' => $remito->anulador?->nombre,
            'usuario' => $remito->user?->nombre, 'cliente' => Documentos::datosCliente($remito), 'observaciones' => $remito->observaciones,
            'total' => $remito->total, 'items' => Documentos::items($remito),
        ];
    }

    public function show(Remito $remito)
    {
        $remito->load(['items', 'user:id,nombre', 'anulador:id,nombre']);

        return Inertia::render('Remitos/Show', ['remito' => $this->datos($remito)]);
    }

    public function anular(Request $request, Remito $remito)
    {
        DB::transaction(function () use ($remito, $request) {
            $r = Remito::lockForUpdate()->findOrFail($remito->id);
            if ($r->anulado_at) {
                throw new \DomainException('El remito ya estaba anulado.');
            }
            $r->update(['anulado_at' => now(), 'anulado_por' => $request->user()->id]);
            if ($r->descuenta_stock) {
                foreach ($r->items()->whereNotNull('id_producto')->get() as $i) {
                    if (\App\Models\Product::whereKey($i->id_producto)->exists()) {
                        Stock::sumar($i->id_producto, $i->cantidad, (int) $r->id_sucursal);
                    }
                }
            }
        });

        return redirect()->route('remitos.show', $remito)->with('ok', 'Remito '.$remito->numero().' anulado'.($remito->descuenta_stock ? ': el stock volvió.' : '.'));
    }

    public function imprimir(Request $request, Remito $remito)
    {
        $remito->load('items');

        return Inertia::render('Print/Documento', [
            'tipo' => 'remito',
            'doc' => $this->datos($remito),
            'emisor' => Documentos::emisor($remito->id_sucursal),
            'precios' => $request->boolean('precios'),
        ]);
    }

    public function productos(Request $request)
    {
        return Documentos::buscarProductos($request);
    }

    public function clientes(Request $request)
    {
        return Documentos::buscarClientes($request);
    }
}
