<?php

namespace App\Services;

use App\Models\IngresoProducto;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\FormasPago;
use App\Support\Stock;
use App\Support\SucursalActual;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Ventas que se hicieron sin conexion y llegan cuando vuelve la red.
 *
 * - Cada venta trae un codigo (uuid): si ya se registro, no se duplica.
 * - Se respeta la fecha y hora en que se vendio (hasta 7 dias atras).
 * - El precio que cobro el navegador se acepta si es el precio vigente o el
 *   que tenia el producto a esa hora; si no, se usa el actual y se avisa.
 * - Lo vendido ya salio del local: se descuenta el stock aunque no alcance
 *   (queda en 0) y se avisa.
 */
class VentasSinConexion
{
    public const MEDIOS = ['efectivo', 'debito', 'credito', 'qr', 'transferencia'];

    public function __construct(private Ventas $ventas, private Caja $caja)
    {
    }

    /** @return array{estado:string, mensaje?:string, venta?:int, avisos?:array} */
    public function registrar(array $v, User $user): array
    {
        if (Order::withoutGlobalScope('sucursal')->where('offline_uuid', $v['uuid'])->exists()) {
            return ['estado' => 'ya', 'venta' => Order::withoutGlobalScope('sucursal')->where('offline_uuid', $v['uuid'])->value('id')];
        }

        $anterior = SucursalActual::id();
        $sid = (int) ($v['id_sucursal'] ?? $anterior);
        if ($sid !== $anterior) {
            $puede = SucursalActual::habilitado() && ! $user->id_sucursal && Sucursal::whereKey($sid)->exists();
            if (! $puede) {
                return ['estado' => 'error', 'mensaje' => 'La venta se hizo en otra sucursal a la que no tenés acceso.'];
            }
            SucursalActual::fijar($sid);
        }

        try {
            if (! $this->caja->abierta()) {
                return ['estado' => 'caja', 'mensaje' => 'La caja de '.SucursalActual::nombre().' está cerrada: abrila para registrar las ventas hechas sin conexión.'];
            }
            if (($v['descuento'] ?? 0) > 0 && ! $user->hasPermission('descuentos')) {
                $v['descuento'] = 0;
                $avisoDesc = 'Se quitó el descuento: no tenés permiso para hacer descuentos.';
            }

            $fecha = $this->fecha($v['fecha'] ?? null);
            $avisos = isset($avisoDesc) ? [$avisoDesc] : [];

            $order = DB::transaction(function () use ($v, $user, $fecha, &$avisos) {
                $order = Order::create([
                    'offline_uuid' => $v['uuid'],
                    'id_encargado' => $user->id,
                    'id_cliente' => User::ID_CLIENTE_GENERAL,
                    'id_type' => Order::TIPO_PRODUCTOS,
                    'canal_id' => 1,
                    'id_forma_pago' => FormasPago::EFECTIVO,
                    'monto' => 0, 'descuento' => 0, 'fiado' => 0,
                    'completada' => 0, 'deHoy' => 1,
                ]);
                $productos = Product::with('category')->whereIn('id', array_column($v['items'], 'id'))->lockForUpdate()->get()->keyBy('id');
                foreach ($v['items'] as $it) {
                    $p = $productos[$it['id']] ?? null;
                    if (! $p || $p->tiene_variantes) {
                        $avisos[] = 'Un producto de la venta ya no existe y no se registró ($'.number_format($it['monto'] * $it['cantidad'], 0, ',', '.').').';

                        continue;
                    }
                    $cant = round((float) $it['cantidad'], 3);
                    if (! $p->category?->esPorPeso() && abs($cant - round($cant)) > 1e-9) {
                        $cant = max(1, round($cant));
                    }
                    $monto = $this->precio($p, (float) $it['monto'], $fecha);
                    if (abs($monto - (float) $it['monto']) >= 0.01) {
                        $avisos[] = $p->nombre.': se cobró $'.number_format($it['monto'], 2, ',', '.').' pero el precio es $'.number_format($monto, 2, ',', '.').'; se registró con el precio actual.';
                    }
                    $hay = Stock::de($p->id, null, true);
                    if ($hay + 1e-9 < $cant) {
                        $avisos[] = $p->nombre.': se vendieron '.$cant.' y en el sistema había '.$hay.'. El stock quedó en 0: revisalo.';
                    }
                    OrderProduct::create(['id_order' => $order->id, 'id_producto' => $p->id, 'cantidad' => $cant,
                        'costo_x_uni' => $p->costo, 'monto' => $monto]);
                    Stock::sumar($p->id, -$cant);
                }
                if (! $order->productos()->exists()) {
                    throw new \DomainException('Ningún producto de la venta existe ya en el sistema.');
                }

                $medio = in_array($v['medio'] ?? '', self::MEDIOS, true) ? $v['medio'] : 'efectivo';
                $order = $this->ventas->cobrar($order, [
                    'id_cliente' => User::ID_CLIENTE_GENERAL, 'canal_id' => 1, 'medios' => [$medio],
                    'descuento_monto' => max(0, (float) ($v['descuento'] ?? 0)),
                ]);
                // la fecha y hora en que se vendio de verdad
                Order::whereKey($order->id)->update(['created_at' => $fecha, 'updated_at' => now()]);

                return $order;
            });

            if (isset($v['total']) && abs(round($order->monto - $order->descuento, 2) - (float) $v['total']) >= 0.01) {
                $avisos[] = 'El total quedó en $'.number_format($order->monto - $order->descuento, 2, ',', '.').' (en el momento se cobró $'.number_format((float) $v['total'], 2, ',', '.').').';
            }

            return ['estado' => 'ok', 'venta' => $order->id, 'avisos' => $avisos];
        } catch (UniqueConstraintViolationException) {
            return ['estado' => 'ya', 'venta' => Order::withoutGlobalScope('sucursal')->where('offline_uuid', $v['uuid'])->value('id')];
        } catch (\DomainException $e) {
            return ['estado' => 'error', 'mensaje' => $e->getMessage()];
        } finally {
            SucursalActual::fijar($anterior);
        }
    }

    private function fecha(?string $f): Carbon
    {
        try {
            $d = $f ? Carbon::parse($f)->setTimezone(config('app.timezone')) : now();
        } catch (\Throwable) {
            $d = now();
        }

        return $d->isFuture() || $d->lt(now()->subDays(7)) ? now() : $d;
    }

    /** el precio cobrado vale si es el actual o el que tenia el producto a esa hora */
    private function precio(Product $p, float $cobrado, Carbon $fecha): float
    {
        if (abs($cobrado - $p->monto) < 0.01) {
            return $p->monto;
        }
        $deEseMomento = IngresoProducto::where('id_producto', $p->id)->where('created_at', '<=', $fecha)->latest('id')->value('monto');

        return $deEseMomento !== null && abs($cobrado - (float) $deEseMomento) < 0.01 ? round($cobrado, 2) : $p->monto;
    }
}
