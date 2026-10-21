<?php

namespace App\Services;

use App\Models\Combo;
use App\Models\Deuda;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderService;
use App\Models\OrderVale;
use App\Models\Product;
use App\Models\User;
use App\Models\Vale;
use App\Support\FormasPago;
use App\Support\Stock;
use Illuminate\Support\Facades\DB;

/**
 * Logica de la venta (punto de venta). Todo importe se calcula en el servidor:
 * en control-soft el formulario mandaba monto, pagos y "completada" y el
 * servidor los guardaba tal cual.
 */
class Ventas
{
    public function __construct(private Caja $caja)
    {
    }

    /** Venta nueva: siempre un registro nuevo, con la fecha y hora de ahora. */
    public function nueva(User $encargado): Order
    {
        $this->exigirCaja();

        // si ya tiene una venta abierta y vacia (toco "Nueva venta" y no cargo nada), se reusa
        $vacia = Order::where('id_encargado', $encargado->id)->where('deHoy', 1)->where('completada', 0)->where('id_type', Order::TIPO_PRODUCTOS)
            ->whereDoesntHave('productos')->whereDoesntHave('servicios')->latest('id')->first();
        if ($vacia) {
            $vacia->forceFill(['id_cliente' => User::ID_CLIENTE_GENERAL, 'canal_id' => 1, 'id_forma_pago' => FormasPago::EFECTIVO, 'monto' => 0, 'descuento' => 0, 'fiado' => 0,
                'created_at' => now()])->save(); // con la fecha y hora de ahora (no la de cuando se toco el boton)

            return $vacia;
        }

        return Order::create([
            'id_encargado' => $encargado->id,
            'id_cliente' => User::ID_CLIENTE_GENERAL,
            'id_type' => Order::TIPO_PRODUCTOS,
            'canal_id' => 1,
            'id_forma_pago' => FormasPago::EFECTIVO,
            'monto' => 0, 'descuento' => 0, 'fiado' => 0,
            'completada' => 0, 'deHoy' => 1,
        ]);
    }

    /**
     * Busca productos para el campo "Producto": primero por codigo exacto
     * (lector de barras), despues por codigo o nombre parecido.
     */
    public function buscar(string $q, int $limite = 10)
    {
        $r = $this->buscarEnCatalogo($q, $limite);
        Stock::cargar($r); // stock de la sucursal donde se vende

        return $r;
    }

    private function buscarEnCatalogo(string $q, int $limite)
    {
        $q = trim($q);
        if ($q === '') {
            return collect();
        }

        $exacto = Product::with('category:id,nombre,unidad')->where('activo', 1)->where('codigo', $q)->get();
        // codigo de un producto con variantes: se ofrecen sus variantes
        if ($exacto->count() === 1 && $exacto->first()->tiene_variantes) {
            return Product::with('category:id,nombre,unidad')->where('activo', 1)->where('id_padre', $exacto->first()->id)->orderBy('id')->get();
        }
        $exacto = $exacto->where('tiene_variantes', false)->values();
        if ($exacto->isNotEmpty()) {
            return $exacto;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';

        return Product::with('category:id,nombre,unidad')
            ->where('activo', 1)->where('tiene_variantes', 0)
            ->where(fn ($w) => $w->where('codigo', 'like', $like)->orWhere('nombre', 'like', $like))
            ->orderByRaw('CASE WHEN codigo LIKE ? THEN 0 ELSE 1 END, nombre ASC', [$q.'%'])
            ->limit($limite)
            ->get();
    }

    public function agregarProducto(Order $order, Product $product, float $cantidad): OrderProduct
    {
        $this->exigirCaja();
        $cantidad = $this->cantidadValida($cantidad, $product);

        return DB::transaction(function () use ($order, $product, $cantidad) {
            $order = $this->ordenEditable($order);
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (! $product->activo) {
                throw new \DomainException('El producto '.$product->nombre.' está dado de baja.');
            }
            if ($product->tiene_variantes) {
                throw new \DomainException('Elegí la variante de '.$product->nombre.' (talle, color...).');
            }
            // el stock se descuenta al COBRAR; aca solo se controla que alcance (contando lo que ya esta en esta venta)
            $this->exigirStock($product, (int) $order->id_sucursal, $this->enVenta($order, $product->id) + $cantidad);

            // si el producto ya esta en la venta, se suma al mismo renglon
            $item = OrderProduct::where('id_order', $order->id)->where('id_producto', $product->id)
                ->where('monto', $product->monto)->whereNull('id_combo')->first();
            if ($item) {
                $item->cantidad = round($item->cantidad + $cantidad, 3);
                $item->save();
            } else {
                $item = OrderProduct::create([
                    'id_order' => $order->id,
                    'id_producto' => $product->id,
                    'cantidad' => $cantidad,
                    'costo_x_uni' => $product->costo, // control-soft no lo guardaba en una de las rutas: la ganancia daba de mas
                    'monto' => $product->monto,
                ]);
            }

            $this->recalcular($order);

            return $item;
        });
    }

    /** Combos vigentes que coinciden con el codigo (exacto) o el nombre. */
    public function buscarCombos(string $q, int $limite = 5)
    {
        $q = trim($q);
        if ($q === '' || ! \App\Models\Configuracion::combos()) {
            return collect();
        }
        $exacto = Combo::vigentes()->where('codigo', $q)->get();
        if ($exacto->isNotEmpty()) {
            return $exacto;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';

        return Combo::vigentes()->where(fn ($w) => $w->where('nombre', 'like', $like)->orWhere('codigo', 'like', $like))
            ->orderBy('nombre')->limit($limite)->get();
    }

    /**
     * Agrega un combo: cada producto entra como renglon con su parte del precio.
     * El precio se reparte en proporcion al precio de lista de cada producto,
     * en pesos enteros (el ultimo renglon absorbe la diferencia), asi la suma
     * de los renglones da exactamente el precio del combo.
     */
    public function agregarCombo(Order $order, Combo $combo, int $veces = 1): void
    {
        $this->exigirCaja();
        if (! \App\Models\Configuracion::combos()) {
            throw new \DomainException('Los combos y promos están deshabilitados. Habilitalos en Configuración.');
        }
        if ($veces < 1 || $veces > 999) {
            throw new \DomainException('La cantidad de combos tiene que ser un número entero mayor a cero.');
        }

        DB::transaction(function () use ($order, $combo, $veces) {
            $order = $this->ordenEditable($order);
            $combo = Combo::with('items')->whereKey($combo->id)->firstOrFail();
            if (! $combo->vigente()) {
                throw new \DomainException('El combo "'.$combo->nombre.'" no está vigente.');
            }
            if ($combo->items->isEmpty()) {
                throw new \DomainException('El combo "'.$combo->nombre.'" no tiene productos.');
            }

            $productos = Product::with('category')->whereIn('id', $combo->items->pluck('id_producto'))->lockForUpdate()->get()->keyBy('id');
            $lineas = [];
            foreach ($combo->items as $it) {
                $p = $productos[$it->id_producto] ?? null;
                if (! $p || ! $p->activo) {
                    throw new \DomainException('Un producto del combo "'.$combo->nombre.'" está dado de baja.');
                }
                $cant = round($it->cantidad * $veces, 3);
                $hay = Stock::de($p->id, (int) $order->id_sucursal, true);
                if ($hay + 1e-9 < $this->enVenta($order, $p->id) + $cant) {
                    throw new \DomainException('No alcanza el stock para el combo: de '.$p->nombre.' quedan '.$this->cant($hay).'.');
                }
                $lineas[] = ['p' => $p, 'cant' => $cant, 'lista' => $p->monto * $cant];
            }

            $this->insertarCombo($order, $combo, $lineas, round($combo->precio * $veces, 2));
            $this->recalcular($order);
        });
    }

    /** reparte el precio y crea los renglones del combo */
    private function insertarCombo(Order $order, Combo $combo, array $lineas, float $precio): void
    {
        $grupo = (int) OrderProduct::where('id_order', $order->id)->max('combo_grupo') + 1;
        $totalLista = array_sum(array_column($lineas, 'lista'));
        $resto = $precio;
        foreach ($lineas as $i => $l) {
            $ultimo = $i === count($lineas) - 1;
            $parte = $ultimo ? $resto
                : floor($totalLista > 0 ? $precio * $l['lista'] / $totalLista : $precio / count($lineas));
            $parte = max(0, min($parte, $resto));
            $resto = round($resto - $parte, 2);
            // precio unitario redondeado HACIA ABAJO al centavo: asi ceil(monto * cant) da exacto la parte
            $unit = floor($parte / $l['cant'] * 100) / 100;
            OrderProduct::create([
                'id_order' => $order->id, 'id_producto' => $l['p']->id, 'cantidad' => $l['cant'],
                'costo_x_uni' => $l['p']->costo, 'monto' => $unit, 'id_combo' => $combo->id, 'combo_grupo' => $grupo,
            ]);
        }
    }

    /** cambia cuantas veces va un combo ya cargado (quita y vuelve a cargar el grupo) */
    private function cambiarVecesCombo(Order $order, OrderProduct $item, float $veces): void
    {
        if (abs($veces - round($veces)) > 1e-9 || $veces < 1) {
            throw new \DomainException('La cantidad de combos tiene que ser un número entero.');
        }
        $combo = Combo::with('items')->findOrFail($item->id_combo);
        $grupo = OrderProduct::where('id_order', $order->id)->where('combo_grupo', $item->combo_grupo)->get();
        $base = $combo->items->firstWhere('id_producto', $item->id_producto)?->cantidad ?: 1;
        $vecesActual = (int) round($item->cantidad / $base);
        $precioUnitCombo = $vecesActual > 0 ? round($grupo->sum(fn ($r) => $r->subtotal()) / $vecesActual, 2) : $combo->precio;

        foreach ($grupo as $r) {
            $r->delete();
        }

        $productos = Product::whereIn('id', $combo->items->pluck('id_producto'))->lockForUpdate()->get()->keyBy('id');
        $lineas = [];
        foreach ($combo->items as $it) {
            $p = $productos[$it->id_producto];
            $cant = round($it->cantidad * $veces, 3);
            $hay = Stock::de($p->id, (int) $order->id_sucursal, true);
            if ($hay + 1e-9 < $this->enVenta($order, $p->id) + $cant) {
                throw new \DomainException('No alcanza el stock: de '.$p->nombre.' quedan '.$this->cant($hay).'.');
            }
            $lineas[] = ['p' => $p, 'cant' => $cant, 'lista' => $p->monto * $cant];
        }
        $this->insertarCombo($order, $combo, $lineas, round($precioUnitCombo * $veces, 2));
        $this->recalcular($order);
    }

    public function agregarServicio(Order $order, string $descripcion, float $monto): OrderService
    {
        $this->exigirCaja();
        if ($monto <= 0) {
            throw new \DomainException('El importe tiene que ser mayor a cero.');
        }

        return DB::transaction(function () use ($order, $descripcion, $monto) {
            $order = $this->ordenEditable($order);
            $s = OrderService::create(['id_order' => $order->id, 'description' => $descripcion, 'monto' => round($monto, 2)]);
            $this->recalcular($order);

            return $s;
        });
    }

    public function cambiarCantidad(OrderProduct $item, float $nueva): void
    {
        $this->exigirCaja();

        DB::transaction(function () use ($item, $nueva) {
            $item = OrderProduct::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $order = $this->ordenEditable($item->order);
            if ($item->id_combo) {
                $this->cambiarVecesCombo($order, $item, $nueva);

                return;
            }
            $product = Product::whereKey($item->id_producto)->lockForUpdate()->firstOrFail();
            $nueva = $this->cantidadValida($nueva, $product);

            if ($nueva > $item->cantidad) {
                $otros = $this->enVenta($order, $product->id, $item->id);
                $hay = Stock::de($product->id, (int) $order->id_sucursal, true);
                if ($hay + 1e-9 < $otros + $nueva) {
                    throw new \DomainException('Con el stock actual podés llegar hasta '.$this->cant(max(0, $hay - $otros)).' '.$this->unidad($product, 2).'.');
                }
            }
            $item->cantidad = $nueva;
            $item->save();
            $this->recalcular($order);
        });
    }

    public function quitarProducto(OrderProduct $item): void
    {
        DB::transaction(function () use ($item) {
            $item = OrderProduct::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $order = $this->ordenEditable($item->order);
            $renglones = $item->id_combo
                ? OrderProduct::where('id_order', $order->id)->where('combo_grupo', $item->combo_grupo)->get()
                : collect([$item]);
            foreach ($renglones as $r) {
                $r->delete(); // sin cobrar todavia no tomo stock
            }
            $this->recalcular($order);
        });
    }

    public function quitarServicio(OrderService $s): void
    {
        DB::transaction(function () use ($s) {
            $order = $this->ordenEditable($s->order);
            $s->delete();
            $this->recalcular($order);
        });
    }

    /** Anula una venta SIN cobrar y la borra (no tomo stock: se descuenta recien al cobrar). */
    public function anular(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order = $this->ordenEditable($order);
            if ($order->stock_descontado) { // por las dudas (no deberia pasar con una venta sin cobrar)
                foreach ($order->productos as $p) {
                    Stock::sumar($p->id_producto, $p->cantidad, (int) $order->id_sucursal);
                }
            }
            $order->productos()->delete();
            $order->servicios()->delete();
            $order->delete();
        });
    }

    /**
     * Cobra la venta.
     *
     * $datos:
     *   id_cliente, canal_id
     *   medios         ['efectivo'] | ['efectivo','debito'] | ['fiado'] | ['vale'] | ['vale','efectivo'] ...
     *   descuento_pct  0..100      descuento_monto >= 0
     *   pago           con cuanto paga el cliente (opcional; vacio = paga justo)
     *   monto_primero  en pagos mixtos, cuanto va por el primer medio (el resto por el segundo)
     *   id_vale        para pagar con vale
     */
    public function cobrar(Order $order, array $datos): Order
    {
        $this->exigirCaja();

        return DB::transaction(function () use ($order, $datos) {
            $order = $this->ordenEditable($order);
            $this->recalcular($order);

            $total = $order->monto;
            if ($total <= 0) {
                throw new \DomainException('Agregá al menos un producto antes de cobrar.');
            }

            $idCliente = (int) $datos['id_cliente'];
            $general = $idCliente === User::ID_CLIENTE_GENERAL;

            $pct = max(0, min(100, (float) ($datos['descuento_pct'] ?? 0)));
            $desc = ceil(round($total * $pct / 100, 4)) + max(0, (float) ($datos['descuento_monto'] ?? 0));
            $desc = round(min($desc, $total), 2);
            $aPagar = round($total - $desc, 2);

            $medios = array_values(array_unique($datos['medios'] ?? []));
            $pagos = array_fill_keys(Order::COLUMNAS_PAGO, 0.0);
            $fiado = 0.0;
            $vale = null;
            $montoVale = 0.0;

            if ($medios === ['fiado']) {
                // Cuenta corriente: todo queda debiendo. (control-soft fiaba el
                // total SIN restar el descuento.)
                if ($general) {
                    throw new \DomainException('Para dejar en cuenta corriente elegí un cliente con nombre.');
                }
                $fiado = $aPagar;
                $idForma = FormasPago::FIADO;
            } elseif (in_array('vale', $medios, true)) {
                $otros = array_values(array_diff($medios, ['vale']));
                if (count($otros) > 1 || ($otros && ! in_array($otros[0], ['efectivo', 'debito', 'transferencia', 'qr'], true))) {
                    throw new \DomainException('Con vale solo se puede completar en efectivo, débito, transferencia o QR.');
                }
                $vale = Vale::whereKey($datos['id_vale'] ?? 0)->lockForUpdate()->first();
                if (! $vale) {
                    throw new \DomainException('Elegí el vale con el que paga.');
                }
                if (! $vale->activo || $vale->monto_disponible <= 0) {
                    throw new \DomainException('El vale '.$vale->codigo_vale.' ya fue usado por completo.');
                }
                if ($vale->isVencido()) {
                    throw new \DomainException('El vale '.$vale->codigo_vale.' venció el '.$vale->fecha_vencimiento->format('d/m/Y').'.');
                }
                if (! $general && $vale->id_cliente !== $idCliente && $vale->id_cliente !== User::ID_CLIENTE_GENERAL) {
                    throw new \DomainException('El vale '.$vale->codigo_vale.' es de otro cliente.');
                }

                // siempre se usa lo maximo posible del vale
                $montoVale = round(min($vale->monto_disponible, $aPagar), 2);
                $resto = round($aPagar - $montoVale, 2);
                $pagos['pago_vale'] = $montoVale;

                $segundo = $otros[0] ?? null;
                if ($resto > 0) {
                    // "Vale" solo y no alcanza: la diferencia va en efectivo (igual que control-soft)
                    $segundo ??= 'efectivo';
                    $pagos[FormasPago::COLUMNAS[$segundo]] = $resto;
                }
                $idForma = FormasPago::buscar($resto > 0 ? ['vale', $segundo] : ['vale']);
            } else {
                if (count($medios) < 1 || count($medios) > 2) {
                    throw new \DomainException('Elegí uno o dos medios de pago.');
                }
                foreach ($medios as $m) {
                    if (! in_array($m, FormasPago::MEDIOS_VISIBLES, true)) {
                        throw new \DomainException('Medio de pago inválido.');
                    }
                }
                $idForma = FormasPago::buscar($medios);
                if (! $idForma) {
                    throw new \DomainException('Esa combinación de medios de pago no existe.');
                }

                $pago = isset($datos['pago']) && $datos['pago'] !== null && $datos['pago'] !== '' ? max(0, (float) $datos['pago']) : $aPagar;
                $pagado = round(min($pago, $aPagar), 2);
                $fiado = round($aPagar - $pagado, 2);

                if ($fiado > 0 && $general) {
                    throw new \DomainException('Faltan $'.number_format($fiado, 0, ',', '.').'. Para dejar un saldo debiendo elegí un cliente con nombre.');
                }

                if (count($medios) === 1) {
                    $pagos[FormasPago::COLUMNAS[$medios[0]]] = $pagado;
                } else {
                    $primero = round(max(0, min((float) ($datos['monto_primero'] ?? 0), $pagado)), 2);
                    $pagos[FormasPago::COLUMNAS[$medios[0]]] = $primero;
                    $pagos[FormasPago::COLUMNAS[$medios[1]]] = round($pagado - $primero, 2);
                }
            }

            // stock: se descuenta ACA, todo junto. Las filas de los productos se bloquean (en orden de id,
            // para que dos cajas no se traben entre si): si otra caja cobro la ultima unidad, esta espera
            // y despues ve el stock real, asi dos ventas nunca se llevan la misma unidad.
            $this->descontarStock($order);

            $idDeuda = 0;
            if ($fiado > 0) {
                $idDeuda = Deuda::create([
                    'id_cliente' => $idCliente,
                    'tipo_cuenta' => 'cliente',
                    'id_encargado' => $order->id_encargado,
                    'monto' => $fiado,
                    'tipo' => Deuda::DEBE,
                    'detalle' => 'Venta #'.$order->id,
                    'deHoy' => 1,
                ])->id;
            }

            if ($vale) {
                $vale->monto_usado = round($vale->monto_usado + $montoVale, 2);
                $vale->monto_disponible = round($vale->monto_disponible - $montoVale, 2);
                if ($vale->monto_disponible <= 0) {
                    $vale->monto_disponible = 0;
                    $vale->activo = false;
                }
                $vale->save();
                OrderVale::create(['id_order' => $order->id, 'id_vale' => $vale->id, 'monto_aplicado' => $montoVale]);
            }

            $order->fill($pagos + [
                'id_cliente' => $idCliente,
                'canal_id' => (int) $datos['canal_id'],
                'id_forma_pago' => $idForma,
                'descuento' => $desc,
                'fiado' => $fiado,
                'id_deuda' => $idDeuda,
                'completada' => 1,
                'stock_descontado' => 1,
            ])->save();

            return $order->fresh();
        });
    }

    // ------------------------------------------------------------ helpers

    /** cuanto de ese producto ya hay cargado en la venta (menos un renglon, si se esta cambiando) */
    private function enVenta(Order $order, int $idProducto, ?int $menosRenglon = null): float
    {
        return round((float) OrderProduct::where('id_order', $order->id)->where('id_producto', $idProducto)
            ->when($menosRenglon, fn ($q) => $q->where('id', '!=', $menosRenglon))->sum('cantidad'), 3);
    }

    private function exigirStock(Product $p, int $sid, float $necesita): void
    {
        $hay = Stock::de($p->id, $sid, true);
        if ($hay + 1e-9 < $necesita) {
            throw new \DomainException($hay > 0
                ? 'Solo quedan '.$this->cant($hay).' '.$this->unidad($p, $hay).' de '.$p->nombre.' en stock.'
                : $p->nombre.' no tiene stock.');
        }
    }

    /** al cobrar: controla todo el stock de la venta con las filas bloqueadas y recien ahi lo descuenta */
    private function descontarStock(Order $order): void
    {
        if ($order->stock_descontado) {
            return;
        }
        $sid = (int) $order->id_sucursal;
        $porProducto = OrderProduct::where('id_order', $order->id)->get()->groupBy('id_producto')
            ->map(fn ($g) => round($g->sum('cantidad'), 3))->sortKeys();
        $productos = Product::whereIn('id', $porProducto->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $faltan = [];
        foreach ($porProducto as $id => $cant) {
            $p = $productos[$id] ?? null;
            if (! $p) {
                throw new \DomainException('Un producto de la venta ya no existe: sacalo antes de cobrar.');
            }
            $hay = Stock::de($p->id, $sid, true);
            if ($hay + 1e-9 < $cant) {
                $faltan[] = $p->nombre.' (quedan '.$this->cant(max(0, $hay)).', en la venta hay '.$this->cant($cant).')';
            }
        }
        if ($faltan) {
            throw new \DomainException('No alcanza el stock para cobrar (se vendió mientras tanto): '.implode('; ', $faltan).'. Ajustá la cantidad y volvé a cobrar.');
        }
        foreach ($porProducto as $id => $cant) {
            Stock::sumar($id, -$cant, $sid);
        }
    }

    /** monto = suma de cada renglon redondeado hacia arriba (igual que control-soft). */
    public function recalcular(Order $order): void
    {
        $prod = $order->productos()->get()->sum(fn (OrderProduct $p) => $p->subtotal());
        $serv = $order->servicios()->get()->sum(fn (OrderService $s) => ceil(round($s->monto, 4)));
        $order->monto = round($prod + $serv, 2);
        $order->save();
    }

    private function ordenEditable(Order $order): Order
    {
        $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
        if ($o->completada) {
            throw new \DomainException('La venta #'.$o->id.' ya fue cobrada y no se puede modificar.');
        }

        return $o;
    }

    private function exigirCaja(): void
    {
        if (! $this->caja->abierta()) {
            throw new \DomainException('La caja está cerrada. Abrila cargando la caja inicial para poder vender.');
        }
    }

    private function cantidadValida(float $cantidad, Product $product): float
    {
        $cantidad = round($cantidad, 3);
        if ($cantidad <= 0) {
            throw new \DomainException('La cantidad tiene que ser mayor a cero.');
        }
        $product->loadMissing('category');
        if (! ($product->category?->esPorPeso()) && abs($cantidad - round($cantidad)) > 1e-9) {
            throw new \DomainException($product->nombre.' se vende por unidad: la cantidad tiene que ser un número entero.');
        }

        return $cantidad;
    }

    private function unidad(Product $p, float $cant): string
    {
        $u = (string) ($p->category?->unidad ?? '');
        if ((float) $cant == 1.0 && str_ends_with($u, 's')) {
            $u = substr($u, 0, -1);
        }

        return $u;
    }

    private function cant(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', ''), '0'), ',');
    }
}
