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
            'puedeExportar' => $request->user()->hasPermission('control'),
        ]);
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
                'listo' => $cfg->listo(),
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

    /** Agrega por codigo / nombre (campo Producto) o por id (cuando se eligio de la lista). */
    public function agregar(Request $request, Order $order)
    {
        $datos = $request->validate([
            'codigo' => ['nullable', 'string', 'max:100', 'required_without_all:product_id,combo_id'],
            'product_id' => ['nullable', 'integer'],
            'combo_id' => ['nullable', 'integer'],
            'cantidad' => ['required'],
        ]);
        $cantidad = Dinero::parse($datos['cantidad']);
        if ($cantidad === null || $cantidad <= 0) {
            return response()->json(['message' => 'La cantidad tiene que ser un número mayor a cero.'], 422);
        }

        if (! empty($datos['combo_id'])) {
            return $this->agregarCombo($order, Combo::findOrFail($datos['combo_id']), $cantidad);
        }

        if (! empty($datos['product_id'])) {
            $product = Product::where('activo', 1)->findOrFail($datos['product_id']);
        } else {
            $encontrados = $this->ventas->buscar($datos['codigo']);
            $combos = $this->ventas->buscarCombos($datos['codigo']);
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

        $this->ventas->agregarProducto($order, $product, $cantidad);
        $u = $product->category?->unidad ?? '';
        if ($cantidad == 1 && str_ends_with($u, 's')) {
            $u = substr($u, 0, -1);
        }

        return $this->respuestaItems($order, '+ '.rtrim(rtrim(number_format($cantidad, 3, ',', ''), '0'), ',').' '.$u.' de '.$product->nombre);
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
