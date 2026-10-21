<?php

namespace App\Http\Controllers;

use App\Models\Afip;
use App\Models\AfipConfig;
use App\Models\Canal;
use App\Models\Combo;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderService;
use App\Models\Product;
use App\Models\User;
use App\Services\Caja;
use App\Services\Ventas;
use App\Support\Dinero;
use App\Support\Exportador;
use App\Support\FormasPago;
use App\Support\Panaderia;
use App\Support\SucursalActual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class VentaController extends Controller
{
    public function __construct(private Ventas $ventas, private Caja $caja)
    {
    }

    /** Ventas del turno, o historial por fechas. */
    public function index(Request $request)
    {
        $rango = $this->rango($request);
        if (! $rango && ! $this->caja->abierta()) {
            return Inertia::render('Ventas/Index', ['ventas' => [], 'filtro' => null, 'cajaCerrada' => true, 'totales' => null]);
        }

        $q = $this->consulta();
        $rango ? $q->whereBetween('orders.created_at', $rango) : $q->where('orders.deHoy', 1);
        $ventas = $q->orderByDesc('orders.id')->limit(3000)->get();

        $cobradas = $ventas->where('completada', 1);

        return Inertia::render('Ventas/Index', [
            'ventas' => $ventas,
            'filtro' => $rango ? ['desde' => $rango[0]->toDateString(), 'hasta' => $rango[1]->toDateString()] : null,
            'cajaCerrada' => false,
            'totales' => ['cantidad' => $cobradas->count(), 'total' => round($cobradas->sum(fn ($v) => $v->monto - $v->descuento), 2)],
            'resumen' => $this->resumen($rango, $request->user()->hasPermission('control')),
            'puedeExportar' => $request->user()->hasPermission('control'),
        ]);
    }

    /** numeros del periodo (turno o rango): todo sumado en la base, sin el limite del listado */
    private function resumen(?array $rango, bool $verGanancia): array
    {
        $ordenes = fn () => tap(SucursalActual::filtrar(DB::table('orders'), 'orders')->where('orders.id_type', Order::TIPO_PRODUCTOS),
            fn ($q) => $rango ? $q->whereBetween('orders.created_at', $rango) : $q->where('orders.deHoy', 1));
        $cobradas = fn () => $ordenes()->where('orders.completada', 1);

        $t = $cobradas()->selectRaw('COUNT(*) as n, COALESCE(SUM(monto),0) as bruto, COALESCE(SUM(descuento),0) as descuentos, COALESCE(SUM(fiado),0) as fiado,
            MIN(created_at) as primera, MAX(created_at) as ultima')->first();
        $neto = round((float) $t->bruto - (float) $t->descuentos, 2);
        $n = (int) $t->n;

        $medios = [];
        foreach (FormasPago::COLUMNAS + ['fiado' => 'fiado'] as $m => $col) {
            $r = $cobradas()->where("orders.$col", '>', 0)->selectRaw("COUNT(*) as n, SUM($col) as total")->first();
            if ((int) $r->n > 0) {
                $medios[] = ['medio' => $m, 'nombre' => FormasPago::ETIQUETAS[$m], 'total' => round((float) $r->total, 2), 'ventas' => (int) $r->n];
            }
        }

        $fact = $cobradas()->where('orders.idAfipFct', '>', 0)->where(fn ($w) => $w->whereNull('orders.idAfipNdc')->orWhere('orders.idAfipNdc', 0))
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(monto - descuento),0) as total')->first();

        $sinCobrar = $ordenes()->where('orders.completada', 0)
            ->where(fn ($w) => $w->whereExists(fn ($e) => $e->selectRaw('1')->from('orders_products')->whereColumn('orders_products.id_order', 'orders.id'))
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('orders_services')->whereColumn('orders_services.id_order', 'orders.id')))
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(monto),0) as total')->first();

        $prod = $cobradas()->join('orders_products as op', 'op.id_order', '=', 'orders.id');
        $p = (clone $prod)->selectRaw('COALESCE(SUM(op.cantidad * op.monto),0) as ingreso, COALESCE(SUM(op.cantidad * op.costo_x_uni),0) as costo, COUNT(DISTINCT op.id_producto) as distintos')->first();
        $top = (clone $prod)->leftJoin('products as p', 'p.id', '=', 'op.id_producto')->leftJoin('product_categories as pc', 'pc.id', '=', 'p.id_categoria')
            ->groupBy('op.id_producto', 'p.nombre', 'pc.unidad')
            ->selectRaw('p.nombre, pc.unidad, SUM(op.cantidad) as cantidad, SUM(op.cantidad * op.monto) as total')
            ->orderByDesc('total')->limit(5)->get()
            ->map(fn ($r) => ['nombre' => $r->nombre ?? 'Producto eliminado', 'unidad' => $r->unidad, 'cantidad' => round((float) $r->cantidad, 3), 'total' => round((float) $r->total, 2)]);

        $porEncargado = $cobradas()->leftJoin('users as e', 'e.id', '=', 'orders.id_encargado')->groupBy('orders.id_encargado', 'e.nombre')
            ->selectRaw('e.nombre, COUNT(*) as ventas, SUM(orders.monto - orders.descuento) as total')->orderByDesc('total')->get()
            ->map(fn ($r) => ['nombre' => $r->nombre ?? '—', 'ventas' => (int) $r->ventas, 'total' => round((float) $r->total, 2)]);

        // hora con mas ventas (se agrupa en PHP: funciona igual en MySQL y SQLite)
        $horas = $cobradas()->pluck('created_at')->countBy(fn ($f) => (int) substr((string) $f, 11, 2));
        $pico = $horas->isEmpty() ? null : ['hora' => $horas->sortDesc()->keys()->first(), 'ventas' => $horas->max()];

        $ganancia = round((float) $t->bruto - (float) $p->costo - (float) $t->descuentos, 2); // lo vendido (productos + otros conceptos) - costo - descuentos

        return [
            'ventas' => $n, 'bruto' => round((float) $t->bruto, 2), 'descuentos' => round((float) $t->descuentos, 2), 'neto' => $neto,
            'ticket' => $n ? round($neto / $n, 2) : 0, 'fiado' => round((float) $t->fiado, 2),
            'cobrado' => round($neto - (float) $t->fiado, 2),
            'facturadas' => ['ventas' => (int) $fact->n, 'total' => round((float) $fact->total, 2)],
            'sinCobrar' => ['ventas' => (int) $sinCobrar->n, 'total' => round((float) $sinCobrar->total, 2)],
            'ganancia' => $verGanancia ? $ganancia : null,
            'margen' => $verGanancia && $neto > 0 ? round($ganancia / $neto * 100, 1) : null,
            'productos' => (int) $p->distintos,
            'medios' => $medios, 'top' => $top, 'encargados' => $porEncargado, 'pico' => $pico,
            'primera' => $t->primera, 'ultima' => $t->ultima,
        ];
    }

    private function consulta()
    {
        return SucursalActual::filtrar(DB::table('orders'), 'orders')
            ->leftJoin('users as c', 'c.id', '=', 'orders.id_cliente')
            ->leftJoin('users as e', 'e.id', '=', 'orders.id_encargado')
            ->leftJoin('formas_pago as f', 'f.id', '=', 'orders.id_forma_pago')
            ->leftJoin('canales as k', 'k.id', '=', 'orders.canal_id')
            ->where('orders.id_type', Order::TIPO_PRODUCTOS)
            ->select('orders.id', 'orders.monto', 'orders.descuento', 'orders.fiado', 'orders.completada', 'orders.created_at',
                'orders.idAfipFct', 'orders.idAfipNdc', 'orders.id_forma_pago',
                'c.nombre as cliente', 'e.nombre as encargado', 'f.nombre as forma_pago', 'k.nombre as canal',
                DB::raw('(orders.completada = 0 AND NOT EXISTS (SELECT 1 FROM orders_products op WHERE op.id_order = orders.id)
                    AND NOT EXISTS (SELECT 1 FROM orders_services os WHERE os.id_order = orders.id)) as vacia'),
                ...array_map(fn ($c) => 'orders.'.$c, Order::COLUMNAS_PAGO));
    }

    public function nueva(Request $request)
    {
        $order = $this->ventas->nueva($request->user());

        return redirect()->route('ventas.show', $order);
    }

    public function show(Order $order)
    {
        abort_unless($order->id_type === Order::TIPO_PRODUCTOS, 404);
        $order->load(['cliente:id,nombre', 'encargado:id,nombre', 'canal:id,nombre', 'formaPago:id,nombre']);
        $cfg = AfipConfig::actual();

        $props = [
            'venta' => [
                'id' => $order->id,
                'completada' => $order->completada,
                'monto' => $order->monto,
                'descuento' => $order->descuento,
                'fiado' => $order->fiado,
                'pagado' => $order->totalPagado(),
                'pago_vale' => $order->pago_vale,
                'detalle_pago' => FormasPago::detalle($order),
                'forma_pago' => $order->formaPago?->nombre,
                'cliente' => $order->cliente?->nombre ?? 'General',
                'id_cliente' => $order->id_cliente,
                'encargado' => $order->encargado?->nombre,
                'canal' => $order->canal?->nombre,
                'created_at' => $order->created_at,
                'deHoy' => $order->deHoy,
                'factura' => $order->idAfipFct ? Afip::find($order->idAfipFct, ['id', 'nroCbte', 'tipoCbteNum', 'impTotal']) : null,
                'nota_credito' => $order->idAfipNdc ? Afip::find($order->idAfipNdc, ['id', 'nroCbte', 'tipoCbteNum', 'impTotal']) : null,
            ],
            'items' => $this->listaItems($order),
            'afip' => [
                'habilitada' => $afipOn = \App\Models\Configuracion::afip(),
                'listo' => $afipOn && $cfg->listo(),
                'monotributo' => $cfg->esMonotributo(),
            ],
        ];

        // remitos de esta venta (modulo habilitado y con permiso)
        $props['remitos'] = $order->completada && \App\Models\Configuracion::get('remitos_habilitadas', false) && request()->user()->hasPermission('remitos')
            ? \App\Models\Remito::where('id_order', $order->id)->orderBy('id')->get()->map(fn ($r) => ['id' => $r->id, 'numero' => $r->numero(), 'anulado' => (bool) $r->anulado_at])
            : null;

        if (! $order->completada) {
            $props['clientes'] = User::tipo(User::TIPO_CLIENTE)->where('activo', 1)->orderByRaw('id = ? DESC', [User::ID_CLIENTE_GENERAL])
                ->orderBy('nombre')->get(['id', 'nombre']);
            $props['canales'] = Canal::orderBy('id')->get(['id', 'nombre']);
            $props['medios'] = FormasPago::paraPantalla();
            $props['cajaAbierta'] = $this->caja->abierta();
            $props['puedeCrearClientes'] = request()->user()->hasPermission('clientes');
        }

        return Inertia::render('Ventas/Show', $props);
    }

    private function listaItems(Order $order): array
    {
        $prods = OrderProduct::with(['producto:id,nombre,codigo,archivo,id_categoria', 'producto.category:id,unidad', 'combo:id,nombre,precio'])
            ->where('id_order', $order->id)->orderBy('id')->get();

        $sueltos = $prods->whereNull('id_combo')->map(fn (OrderProduct $p) => [
            'id' => $p->id, 'tipo' => 'prod', 'nombre' => $p->producto?->nombre ?? 'Producto eliminado',
            'codigo' => $p->producto?->codigo, 'cantidad' => $p->cantidad, 'unidad' => $p->producto?->category?->unidad,
            'fraccionable' => (bool) $p->producto?->category?->esPorPeso(), 'precio' => $p->monto,
            'subtotal' => $p->subtotal(), 'imagen' => $p->producto?->imagen_url,
        ]);

        // cada combo se muestra como un solo renglon con sus productos adentro
        $combos = $prods->whereNotNull('id_combo')->groupBy('combo_grupo')->map(function ($g) {
            $primero = $g->first();
            $total = $g->sum(fn (OrderProduct $p) => $p->subtotal());
            $item = \App\Models\ComboItem::where('combo_id', $primero->id_combo)->where('id_producto', $primero->id_producto)->value('cantidad') ?: 1;
            $veces = max(1, (int) round($primero->cantidad / $item));

            return [
                'id' => $primero->id, 'tipo' => 'combo', 'nombre' => $primero->combo?->nombre ?? 'Combo', 'codigo' => null,
                'cantidad' => $veces, 'unidad' => null, 'fraccionable' => false, 'precio' => round($total / $veces, 2),
                'subtotal' => $total, 'imagen' => null,
                'detalle' => $g->map(fn ($p) => rtrim(rtrim(number_format($p->cantidad, 3, ',', ''), '0'), ',').' '.($p->producto?->nombre ?? '—'))->values(),
            ];
        })->values();

        $prods = $sueltos->concat($combos);
        $servs = OrderService::where('id_order', $order->id)->orderBy('id')->get()
            ->map(fn (OrderService $s) => [
                'id' => $s->id, 'tipo' => 'svc', 'nombre' => $s->description, 'codigo' => null, 'cantidad' => 1,
                'unidad' => null, 'fraccionable' => false, 'precio' => $s->monto, 'subtotal' => ceil(round($s->monto, 4)), 'imagen' => null,
            ]);

        return $prods->concat($servs)->values()->all();
    }

    private function respuestaItems(Order $order, string $mensaje)
    {
        $order->refresh();

        return response()->json(['message' => $mensaje, 'items' => $this->listaItems($order), 'monto' => $order->monto]);
    }

    public function items(Order $order)
    {
        return response()->json(['items' => $this->listaItems($order), 'monto' => $order->monto]);
    }

    /**
     * Agrega por codigo / nombre (campo Producto) o por id (cuando se eligio de la lista).
     * Panaderia: "importe" en vez de cantidad (calcula el peso) y etiquetas de balanza.
     */
    public function agregar(Request $request, Order $order)
    {
        $datos = $request->validate([
            'codigo' => ['nullable', 'string', 'max:100', 'required_without_all:product_id,combo_id'],
            'product_id' => ['nullable', 'integer'],
            'combo_id' => ['nullable', 'integer'],
            'cantidad' => ['required_without:importe'],
            'importe' => ['nullable'],
        ]);
        $porImporte = isset($datos['importe']) && $datos['importe'] !== '';
        if ($porImporte) {
            if (! Panaderia::opcion('importe')) {
                return response()->json(['message' => 'La venta por importe está deshabilitada (Configuración → Panadería).'], 422);
            }
            // en plata "1.500" es mil quinientos (el punto separa los miles)
            $raw = trim((string) $datos['importe']);
            $importe = Dinero::parse(preg_match('/^\$?\s*\d{1,3}(\.\d{3})+$/', $raw) ? str_replace(['.', '$', ' '], '', $raw) : ltrim($raw, '$ '));
            if ($importe === null || $importe <= 0) {
                return response()->json(['message' => 'El importe tiene que ser mayor a cero.'], 422);
            }
            if (! empty($datos['combo_id'])) {
                return response()->json(['message' => 'Los combos no se venden por importe.'], 422);
            }
            $cantidad = 0.0;
        } else {
            $cantidad = Dinero::parse($datos['cantidad']);
            if ($cantidad === null || $cantidad <= 0) {
                return response()->json(['message' => 'La cantidad tiene que ser un número mayor a cero.'], 422);
            }
        }

        if (! empty($datos['combo_id'])) {
            return $this->agregarCombo($order, Combo::findOrFail($datos['combo_id']), $cantidad);
        }

        $etiqueta = null;
        if (! empty($datos['product_id'])) {
            $product = Product::where('activo', 1)->findOrFail($datos['product_id']);
        } elseif (Panaderia::opcion('balanza') && ! Product::where('codigo', trim($datos['codigo']))->exists()
            && ($etiqueta = Panaderia::leer($datos['codigo']))) {
            // etiqueta de la balanza: el producto sale del codigo (PLU) y la cantidad del peso o del precio
            $product = Product::with('category')->where('activo', 1)->where('tiene_variantes', 0)
                ->whereIn('codigo', Panaderia::codigosPlu($etiqueta['plu']))->first();
            if (! $product) {
                return response()->json(['message' => 'La etiqueta es del producto con código '.ltrim($etiqueta['plu'], '0').', pero no hay ningún producto activo con ese código. Cargalo con el mismo código que tiene en la balanza.'], 422);
            }
            $cantidad = $etiqueta['tipo'] === 'precio' ? Panaderia::cantidadPorImporte($etiqueta['valor'], (float) $product->monto) : $etiqueta['valor'];
        } else {
            $encontrados = $this->ventas->buscar($datos['codigo']);
            $combos = $porImporte ? collect() : $this->ventas->buscarCombos($datos['codigo']);
            if ($encontrados->isEmpty() && $combos->count() === 1) {
                return $this->agregarCombo($order, $combos->first(), $cantidad);
            }
            if ($combos->isNotEmpty() && ($encontrados->isNotEmpty() || $combos->count() > 1)) {
                return response()->json(['elegir' => $combos->map(fn ($c) => self::comboParaLista($c))
                    ->concat($encontrados->map(fn ($p) => [
                        'id' => $p->id, 'tipo' => 'prod', 'nombre' => $p->nombre, 'codigo' => $p->codigo, 'monto' => $p->monto,
                        'quedan' => $p->quedan, 'unidad' => $p->category?->unidad, 'imagen' => $p->imagen_url,
                    ]))->values()]);
            }
            if ($encontrados->isEmpty()) {
                return response()->json(['message' => 'No hay ningún producto con el código o nombre "'.$datos['codigo'].'".'], 422);
            }
            if ($encontrados->count() > 1) {
                return response()->json(['elegir' => $encontrados->map(fn ($p) => [
                    'id' => $p->id, 'tipo' => 'prod', 'nombre' => $p->nombre, 'codigo' => $p->codigo, 'monto' => $p->monto,
                    'quedan' => $p->quedan, 'unidad' => $p->category?->unidad, 'imagen' => $p->imagen_url,
                ])]);
            }
            $product = $encontrados->first();
        }

        if ($porImporte && ! $etiqueta) {
            $product->loadMissing('category');
            if (! $product->category?->esPorPeso()) {
                return response()->json(['message' => $product->nombre.' se vende por unidad: la venta por importe es para productos por peso (Kg).'], 422);
            }
            $cantidad = Panaderia::cantidadPorImporte($importe, (float) $product->monto);
            if ($cantidad < 0.001) {
                return response()->json(['message' => 'Con $'.number_format($importe, 0, ',', '.').' no llega a 1 gramo de '.$product->nombre.'.'], 422);
            }
        }

        $this->ventas->agregarProducto($order, $product, $cantidad);
        $u = $product->category?->unidad ?? '';
        if ($cantidad == 1 && str_ends_with($u, 's')) {
            $u = substr($u, 0, -1);
        }
        $msg = '+ '.rtrim(rtrim(number_format($cantidad, 3, ',', ''), '0'), ',').' '.$u.' de '.$product->nombre;
        if ($porImporte && ! $etiqueta) {
            $msg .= ' ($'.number_format(ceil(round($cantidad * $product->monto, 4)), 0, ',', '.').')';
        } elseif ($etiqueta) {
            $msg .= ' (etiqueta de balanza)';
        }

        return $this->respuestaItems($order, $msg);
    }

    private function agregarCombo(Order $order, Combo $combo, float $cantidad)
    {
        if (abs($cantidad - round($cantidad)) > 1e-9) {
            return response()->json(['message' => 'Los combos se venden por unidad entera.'], 422);
        }
        $this->ventas->agregarCombo($order, $combo, (int) round($cantidad));

        return $this->respuestaItems($order, '+ '.(int) round($cantidad).' combo '.$combo->nombre);
    }

    public static function comboParaLista(Combo $c): array
    {
        return ['id' => $c->id, 'tipo' => 'combo', 'nombre' => $c->nombre, 'codigo' => $c->codigo, 'monto' => $c->precio,
            'quedan' => null, 'unidad' => null, 'imagen' => null];
    }

    public function servicio(Request $request, Order $order)
    {
        $datos = $request->validate([
            'descripcion' => ['required', 'string', 'max:200'],
            'monto' => ['required'],
        ], [], ['descripcion' => 'concepto']);
        $monto = Dinero::parse($datos['monto']);
        if ($monto === null || $monto <= 0 || $monto > 9999999999) {
            return response()->json(['message' => 'El importe tiene que ser un número mayor a cero.'], 422);
        }
        $this->ventas->agregarServicio($order, $datos['descripcion'], $monto);

        return $this->respuestaItems($order, '+ '.$datos['descripcion']);
    }

    public function cantidad(Request $request, OrderProduct $item)
    {
        $request->validate(['cantidad' => ['required']]);
        $cant = Dinero::parse($request->input('cantidad'));
        if ($cant === null || $cant <= 0) {
            return response()->json(['message' => 'La cantidad tiene que ser mayor a cero. Para sacar el producto usá el tacho.'], 422);
        }
        $this->ventas->cambiarCantidad($item, $cant);

        return $this->respuestaItems($item->order, 'Cantidad actualizada');
    }

    public function quitar(OrderProduct $item)
    {
        $order = $item->order;
        $nombre = $item->producto?->nombre;
        $this->ventas->quitarProducto($item);

        return $this->respuestaItems($order, '- '.$nombre);
    }

    public function quitarServicio(OrderService $servicio)
    {
        $order = $servicio->order;
        $this->ventas->quitarServicio($servicio);

        return $this->respuestaItems($order, '- '.$servicio->description);
    }

    public function cobrar(Request $request, Order $order)
    {
        $datos = $request->validate([
            'id_cliente' => ['required', 'integer', Rule::exists('users', 'id')->where('id_uType', User::TIPO_CLIENTE)->where('activo', 1)],
            'canal_id' => ['required', 'integer', Rule::exists('canales', 'id')],
            'medios' => ['required', 'array', 'min:1', 'max:2'],
            'medios.*' => ['string', Rule::in(array_merge(array_keys(FormasPago::COLUMNAS), ['fiado']))],
            'descuento_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'descuento_monto' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'pago' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'monto_primero' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'id_vale' => ['nullable', 'integer'],
        ], [], ['id_cliente' => 'cliente', 'canal_id' => 'canal', 'medios' => 'medio de pago']);
        if ((($datos['descuento_pct'] ?? 0) > 0 || ($datos['descuento_monto'] ?? 0) > 0) && ! $request->user()->hasPermission('descuentos')) {
            throw new \DomainException('No tenés permiso para hacer descuentos.');
        }

        $this->ventas->cobrar($order, $datos);

        return redirect()->route('ventas.show', $order)->with('ok', 'Venta #'.$order->id.' cobrada.');
    }

    public function anular(Order $order)
    {
        $this->ventas->anular($order);

        return redirect()->route('ventas.index')->with('ok', 'Venta #'.$order->id.' anulada. El stock volvió.');
    }

    /** Ticket no fiscal ("comprobante no válido como factura"). */
    public function ticket(Order $order)
    {
        return Inertia::render('Print/Ticket', [
            'venta' => [
                'id' => $order->id, 'fecha' => $order->created_at, 'monto' => $order->monto, 'descuento' => $order->descuento,
                'total' => $order->totalFinal(), 'detalle_pago' => FormasPago::detalle($order), 'cliente' => $order->cliente?->nombre,
                'completada' => $order->completada,
            ],
            'items' => $this->listaItems($order),
            'emisor' => $this->emisor(),
        ]);
    }

    public static function emisor(): array
    {
        $c = AfipConfig::actual();

        return [
            'razon_social' => $c->razon_social ?: config('app.name'), 'domicilio' => $c->domicilio, 'localidad' => $c->localidad,
            'cuit' => $c->cuitFormateado(), 'condicion_iva' => $c->condicion_iva, 'iibb' => $c->iibb,
            'inicio_actividades' => $c->inicio_actividades?->format('d/m/Y'),
            'logo' => \App\Http\Middleware\HandleInertiaRequests::logoNegocio(),
            'sucursal' => SucursalActual::habilitado() ? \App\Models\Sucursal::find(SucursalActual::id())?->only(['nombre', 'direccion', 'telefono']) : null,
        ];
    }

    public function exportar(Request $request)
    {
        $request->validate(['formato' => ['required', Rule::in(['csv', 'xlsx'])]]);
        $rango = $this->rango($request);

        $q = $this->consulta()->where('orders.completada', 1);
        $rango ? $q->whereBetween('orders.created_at', $rango) : $q->where('orders.deHoy', 1);
        $ventas = $q->orderByDesc('orders.id')->get();

        $cab = ['ID Venta', 'Cliente', 'Encargado', 'Forma de pago', 'Canal', 'Monto total', 'Descuento', 'Monto final', 'Efectivo',
            'Tarjeta débito', 'Tarjeta crédito', 'QR', 'Transferencia', 'Cheque', 'Dólares', 'Vale', 'Cuenta corriente', 'Fecha', 'Hora'];
        $filas = $ventas->map(fn ($v) => [
            (int) $v->id, $v->cliente, $v->encargado, $v->forma_pago, $v->canal, (float) $v->monto, (float) $v->descuento,
            round($v->monto - $v->descuento, 2), (float) $v->pago_efec, (float) $v->pago_tarj_debito, (float) $v->pago_tarj_cred,
            (float) $v->pago_qr, (float) $v->pago_transf, (float) $v->pago_cheque, (float) $v->pago_dolares, (float) $v->pago_vale,
            (float) $v->fiado, date('d/m/Y', strtotime($v->created_at)), date('H:i:s', strtotime($v->created_at)),
        ])->all();

        $nombre = $rango ? 'ventas_'.$rango[0]->toDateString().'_al_'.$rango[1]->toDateString() : 'ventas_del_turno_'.date('Y-m-d');

        return Exportador::descargar($request->input('formato'), $nombre, $cab, $filas);
    }
}
