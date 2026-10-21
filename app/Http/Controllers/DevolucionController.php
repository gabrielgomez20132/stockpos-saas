<?php

namespace App\Http\Controllers;

use App\Models\Devolucion;
use App\Models\Order;
use App\Services\Devoluciones;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;

class DevolucionController extends Controller
{
    public function __construct(private Devoluciones $devs)
    {
    }

    public function index(Request $request)
    {
        $q = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:60']])['q'] ?? ''));

        return Inertia::render('Devoluciones/Index', [
            'devoluciones' => Devolucion::with(['cliente:id,nombre', 'encargado:id,nombre', 'vale:id,id_devolucion,codigo_vale,monto_disponible,fecha_vencimiento,activo'])
                ->when($q !== '', fn ($w) => $this->buscar($w, $q))
                ->orderByDesc('id')->paginate(20)->withQueryString(),
            'q' => $q,
        ]);
    }

    /** Nº de venta o de devolución, cliente, código de vale o fecha (20/10, 20/10/26, 20/10/2026, 2026-10-20). */
    private function buscar($w, string $q): void
    {
        $num = preg_replace('/^(#|n[º°o]?\s*)/iu', '', $q);
        if (ctype_digit($num)) {
            $w->where(fn ($x) => $x->where('id_order_original', (int) $num)->orWhere('id', (int) $num)
                ->orWhereHas('vale', fn ($v) => $v->where('codigo_vale', $num)));

            return;
        }
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})(?:[/-](\d{2,4}))?$#', $q, $m) || preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $q, $iso)) {
            [$d, $mes, $a] = isset($iso) && $iso ? [(int) $iso[3], (int) $iso[2], (int) $iso[1]] : [(int) $m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : null];
            if ($a !== null && $a < 100) {
                $a += 2000;
            }
            if (checkdate($mes, $d, $a ?? 2000)) {
                $w->whereMonth('created_at', $mes)->whereDay('created_at', $d)->when($a, fn ($x) => $x->whereYear('created_at', $a));

                return;
            }
        }
        $like = '%'.addcslashes($q, '%_\\').'%';
        $w->where(fn ($x) => $x->whereHas('cliente', fn ($c) => $c->where('nombre', 'like', $like))
            ->orWhereHas('vale', fn ($v) => $v->where('codigo_vale', 'like', $like)));
    }

    public function create()
    {
        $ventas = Order::with('cliente:id,nombre')->where('completada', 1)->where('idAfipNdc', 0)->where('monto', '>', 0)
            ->where('created_at', '>=', now()->subDays(60))
            ->orderByDesc('id')->limit(500)->get(['id', 'id_cliente', 'monto', 'descuento', 'created_at'])
            ->map(fn ($o) => ['id' => $o->id, 'cliente' => $o->cliente?->nombre ?? 'General', 'total' => $o->totalFinal(), 'fecha' => $o->created_at]);

        return Inertia::render('Devoluciones/Create', ['ventas' => $ventas]);
    }

    public function venta(Order $order)
    {
        try {
            $this->devs->validarOrden($order);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $order->id,
            'cliente' => $order->cliente?->nombre ?? 'General',
            'fecha' => $order->created_at,
            'total' => $order->totalFinal(),
            'descuento' => $order->descuento,
            'productos' => $this->devs->disponibles($order),
        ]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'id_order' => ['required', 'integer', 'exists:orders,id'],
            'productos' => ['required', 'array', 'min:1', 'max:200'],
            'productos.*.id_producto' => ['required', 'integer'],
            'productos.*.cantidad' => ['required', 'numeric', 'min:0', 'max:999999'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);

        $dev = $this->devs->registrar(Order::findOrFail($datos['id_order']), $datos['productos'], $request->user(), $datos['observaciones'] ?? null);

        return redirect()->route('devoluciones.show', $dev)
            ->with('ok', 'Devolución registrada. Se generó el vale '.$dev->vale->codigo_vale.'.')
            ->with('abrir', route('devoluciones.ticket', $dev));
    }

    private function datos(Devolucion $d): array
    {
        $d->load(['productos.producto:id,nombre,codigo', 'cliente:id,nombre', 'encargado:id,nombre', 'vale', 'orderOriginal:id,created_at']);

        return [
            'id' => $d->id, 'fecha' => $d->created_at, 'venta' => $d->id_order_original, 'venta_fecha' => $d->orderOriginal?->created_at,
            'cliente' => $d->cliente?->nombre ?? 'General', 'encargado' => $d->encargado?->nombre, 'total' => $d->monto_total,
            'observaciones' => $d->observaciones,
            'productos' => $d->productos->map(fn ($p) => ['nombre' => $p->producto?->nombre ?? '—', 'cantidad' => $p->cantidad_devuelta, 'precio' => $p->precio_unitario, 'subtotal' => $p->subtotal]),
            'vale' => $d->vale ? [
                'codigo' => $d->vale->codigo_vale, 'original' => $d->vale->monto_original, 'disponible' => $d->vale->monto_disponible,
                'usado' => $d->vale->monto_usado, 'emision' => $d->vale->fecha_emision?->toDateString(), 'vence' => $d->vale->fecha_vencimiento?->toDateString(),
                'activo' => $d->vale->activo, 'vencido' => $d->vale->isVencido(),
            ] : null,
        ];
    }

    public function show(Devolucion $devolucion)
    {
        return Inertia::render('Devoluciones/Show', ['devolucion' => $this->datos($devolucion)]);
    }

    public function ticket(Devolucion $devolucion)
    {
        $d = $this->datos($devolucion);
        $barras = null;
        if ($d['vale']) {
            $svg = (new SvgRenderer())->render((new TypeCode128())->getBarcode($d['vale']['codigo']), 260, 60);
            $barras = 'data:image/svg+xml;base64,'.base64_encode($svg);
        }

        return Inertia::render('Print/Vale', ['devolucion' => $d, 'barras' => $barras, 'emisor' => VentaController::emisor()]);
    }
}
