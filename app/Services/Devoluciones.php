<?php

namespace App\Services;

use App\Models\Devolucion;
use App\Models\DevolucionProducto;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Models\Vale;
use Illuminate\Support\Facades\DB;

/**
 * Devolucion de productos de una venta cobrada: el stock vuelve y se emite un
 * vale de credito (30 dias) por lo devuelto.
 */
class Devoluciones
{
    /** Lo que todavia se puede devolver de cada producto de la venta. */
    public function disponibles(Order $order): array
    {
        $vendidos = OrderProduct::where('id_order', $order->id)
            ->selectRaw('id_producto, SUM(cantidad) as cantidad, SUM(cantidad * monto) / SUM(cantidad) as monto')
            ->groupBy('id_producto')->get();

        $devueltos = DevolucionProducto::join('devoluciones', 'devoluciones.id', '=', 'devolucion_productos.id_devolucion')
            ->where('devoluciones.id_order_original', $order->id)
            ->selectRaw('devolucion_productos.id_producto, SUM(devolucion_productos.cantidad_devuelta) as cant')
            ->groupBy('devolucion_productos.id_producto')
            ->pluck('cant', 'id_producto');

        $factor = $this->factorDescuento($order);
        $productos = Product::with('category:id,unidad')->whereIn('id', $vendidos->pluck('id_producto'))->get()->keyBy('id');

        return $vendidos->map(function ($v) use ($devueltos, $factor, $productos) {
            $p = $productos[$v->id_producto] ?? null;
            $vendido = round((float) $v->cantidad, 3);
            $devuelto = round((float) ($devueltos[$v->id_producto] ?? 0), 3);

            return [
                'id_producto' => (int) $v->id_producto,
                'nombre' => $p?->nombre ?? 'Producto eliminado',
                'codigo' => $p?->codigo,
                'unidad' => $p?->category?->unidad,
                'fraccionable' => (bool) $p?->category?->esPorPeso(),
                'precio_venta' => round((float) $v->monto, 2),
                'precio_devolucion' => round((float) $v->monto * $factor, 2),
                'vendido' => $vendido,
                'devuelto' => $devuelto,
                'disponible' => max(0, round($vendido - $devuelto, 3)),
            ];
        })->values()->all();
    }

    /**
     * Si la venta tuvo descuento, se devuelve lo que el cliente realmente pago
     * (en control-soft el vale salia por el precio de lista).
     */
    public function factorDescuento(Order $order): float
    {
        return $order->monto > 0 ? max(0, ($order->monto - $order->descuento) / $order->monto) : 1;
    }

    public function validarOrden(Order $order): void
    {
        if (! $order->completada) {
            throw new \DomainException('Solo se pueden devolver productos de ventas cobradas.');
        }
        if ($order->idAfipNdc) {
            throw new \DomainException('Esta venta ya fue anulada con una nota de crédito.');
        }
    }

    /** @param array<int, array{id_producto:int, cantidad:float}> $items */
    public function registrar(Order $order, array $items, User $encargado, ?string $observaciones): Devolucion
    {
        return DB::transaction(function () use ($order, $items, $encargado, $observaciones) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->validarOrden($order);

            $disp = collect($this->disponibles($order))->keyBy('id_producto');
            $lineas = [];
            $total = 0;

            foreach ($items as $it) {
                $cant = round((float) $it['cantidad'], 3);
                if ($cant <= 0) {
                    continue;
                }
                $d = $disp[(int) $it['id_producto']] ?? null;
                if (! $d) {
                    throw new \DomainException('Ese producto no está en la venta #'.$order->id.'.');
                }
                if (! $d['fraccionable'] && abs($cant - round($cant)) > 1e-9) {
                    throw new \DomainException($d['nombre'].' se devuelve por unidad entera.');
                }
                if ($cant > $d['disponible'] + 1e-9) {
                    throw new \DomainException('De '.$d['nombre'].' se pueden devolver como máximo '.$d['disponible'].'.');
                }
                $sub = round($cant * $d['precio_devolucion'], 2);
                $total += $sub;
                $lineas[] = ['id_producto' => $d['id_producto'], 'cantidad_devuelta' => $cant, 'precio_unitario' => $d['precio_devolucion'], 'subtotal' => $sub];
            }

            if (! $lineas) {
                throw new \DomainException('Elegí al menos un producto y una cantidad para devolver.');
            }

            $total = round($total, 2);
            $dev = Devolucion::create([
                'id_order_original' => $order->id,
                'id_cliente' => $order->id_cliente,
                'id_encargado' => $encargado->id,
                'monto_total' => $total,
                'observaciones' => $observaciones,
            ]);

            foreach ($lineas as $l) {
                DevolucionProducto::create($l + ['id_devolucion' => $dev->id]);
                \App\Support\Stock::sumar($l['id_producto'], $l['cantidad_devuelta'], (int) $order->id_sucursal);
            }

            Vale::create([
                'codigo_vale' => Vale::generarCodigo(),
                'id_cliente' => $order->id_cliente,
                'monto_original' => $total,
                'monto_usado' => 0,
                'monto_disponible' => $total,
                'fecha_emision' => now()->toDateString(),
                'fecha_vencimiento' => now()->addDays(Vale::DIAS_VIGENCIA)->toDateString(),
                'id_devolucion' => $dev->id,
                'activo' => true,
            ]);

            return $dev->load('vale');
        });
    }
}
