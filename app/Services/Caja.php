<?php

namespace App\Services;

use App\Models\CierreCaja;
use App\Models\Control;
use App\Models\Deuda;
use App\Models\Order;
use App\Models\User;
use App\Support\SucursalActual;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Turno de caja. Igual que en control-soft:
 *  - la caja esta abierta mientras haya algun movimiento con caja_abierta = 1
 *    (se abre cargando la caja inicial);
 *  - las ventas y fiados del turno llevan deHoy = 1;
 *  - al cerrar se guarda una foto del turno en cierres_caja y se apagan las banderas.
 */
class Caja
{
    public function abierta(): bool
    {
        return Control::where('caja_abierta', 1)->exists();
    }

    /** Numeros del turno abierto. */
    public function datosTurno(): array
    {
        return $this->calcular(
            controles: fn (Builder $q) => $q->where('caja_abierta', 1),
            ordenes: fn ($q) => $q->where('orders.deHoy', 1),
            deudas: fn (Builder $q) => $q->where('deHoy', 1),
        );
    }

    /** Mismos numeros, pero para un rango de fechas (historial). */
    public function datosRango(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $rango = [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()];

        return $this->calcular(
            controles: fn (Builder $q) => $q->whereBetween('created_at', $rango),
            ordenes: fn ($q) => $q->whereBetween('orders.created_at', $rango),
            deudas: fn (Builder $q) => $q->whereBetween('created_at', $rango),
        );
    }

    private function calcular(\Closure $controles, \Closure $ordenes, \Closure $deudas): array
    {
        $ctrl = fn (int $tipo) => (float) $controles(Control::query())->where('id_desc', $tipo)->sum('monto');

        $completadas = fn () => $ordenes(SucursalActual::filtrar(DB::table('orders'), 'orders'))->where('orders.completada', 1)->where('orders.id_type', Order::TIPO_PRODUCTOS);
        $todas = fn () => $ordenes(SucursalActual::filtrar(DB::table('orders'), 'orders'))->where('orders.id_type', Order::TIPO_PRODUCTOS);

        $d = [];
        $d['caja_inicial'] = $ctrl(Control::INICIAL);
        $d['ingXmercaderias'] = (float) $completadas()->sum('orders.monto');
        $d['ganXmercaderias'] = round((float) $completadas()
            ->join('orders_products', 'orders_products.id_order', '=', 'orders.id')
            ->sum(DB::raw('(orders_products.monto - orders_products.costo_x_uni) * orders_products.cantidad')), 2);
        $d['ganXservicios'] = (float) $completadas()
            ->join('orders_services', 'orders_services.id_order', '=', 'orders.id')
            ->sum('orders_services.monto');

        $suma = fn (string $col) => (float) $todas()->sum('orders.'.$col);
        $d['ingXprod_efec'] = $suma('pago_efec');
        $d['ingXprod_tarj'] = $suma('pago_tarj_debito');
        $d['ingXprod_tarj_cred'] = $suma('pago_tarj_cred');
        $d['ingXprod_qr'] = $suma('pago_qr');
        $d['ingXprod_transf'] = $suma('pago_transf');
        $d['ingXprod_cheque'] = $suma('pago_cheque');
        $d['ingXprod_dolares'] = $suma('pago_dolares');
        $d['ingXprod_vale'] = $suma('pago_vale');

        // solo pagos de CLIENTES entran a la caja; pagarle a un proveedor no
        $d['ingXpago_deudas'] = (float) $deudas(SucursalActual::filtrar(Deuda::deClientes(), 'deudas'))->where('tipo', Deuda::PAGO)->sum('monto');
        $d['descuentos'] = (float) $completadas()->sum('orders.descuento');
        $d['fiado'] = (float) $completadas()->sum('orders.fiado');

        $d['gastosVarios'] = $ctrl(Control::GASTO_VARIOS);
        $d['gastXserv'] = $ctrl(Control::GASTO_SERVICIOS);
        $d['gastXprov'] = $ctrl(Control::GASTO_PROVEEDORES);
        $d['retiros'] = $ctrl(Control::RETIRO);

        $d['total_efec'] = round($d['caja_inicial'] + $d['ingXprod_efec'] + $d['ingXpago_deudas']
            - $d['gastosVarios'] - $d['gastXserv'] - $d['gastXprov'] - $d['retiros'], 2);
        $d['total_tarj'] = $d['ingXprod_tarj'];
        $d['total_tarj_cred'] = $d['ingXprod_tarj_cred'];
        $d['total_qr'] = $d['ingXprod_qr'];
        $d['total_transf'] = $d['ingXprod_transf'];
        $d['total_cheque'] = $d['ingXprod_cheque'];
        $d['total_dolares'] = $d['ingXprod_dolares'];
        $d['total_vale'] = $d['ingXprod_vale'];
        $d['total_general'] = round($d['total_efec'] + $d['total_tarj'] + $d['total_tarj_cred'] + $d['total_qr']
            + $d['total_transf'] + $d['total_cheque'] + $d['total_dolares'], 2);
        $d['cant_ventas'] = (int) $completadas()->count();

        return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $d);
    }

    /**
     * Cierra la caja. Primero guarda la foto del turno (para poder volver a
     * verla e imprimirla aunque despues cambien precios o nombres) y recien
     * despues apaga las banderas. Todo en una transaccion.
     */
    public function cerrar(User $user): CierreCaja
    {
        return DB::transaction(function () use ($user) {
            // bloquea los movimientos abiertos: dos cierres simultaneos no pueden duplicar la foto
            $abiertos = Control::where('caja_abierta', 1)->lockForUpdate()->get();
            if ($abiertos->isEmpty()) {
                throw new \DomainException('La caja ya está cerrada.');
            }

            $r = $this->datosTurno();
            $primera = $abiertos->where('id_desc', Control::INICIAL)->sortBy('created_at')->first() ?? $abiertos->sortBy('created_at')->first();
            $r['apertura'] = $primera?->created_at?->toDateTimeString();
            $r['abierto_por'] = $primera?->admin;
            $r['cierre'] = now()->toDateTimeString();
            $r['cerrado_por'] = $user->nombre;

            $r['productos'] = SucursalActual::filtrar(DB::table('orders'), 'orders')
                ->join('orders_products', 'orders_products.id_order', '=', 'orders.id')
                ->join('products', 'products.id', '=', 'orders_products.id_producto')
                ->leftJoin('product_categories', 'product_categories.id', '=', 'products.id_categoria')
                ->where('orders.deHoy', 1)->where('orders.completada', 1)
                ->groupBy('products.id', 'products.nombre', 'products.codigo', 'product_categories.unidad')
                ->orderByRaw('SUM(orders_products.cantidad) DESC')
                ->select('products.nombre', 'products.codigo', 'product_categories.unidad',
                    DB::raw('SUM(orders_products.cantidad) as cantidad'),
                    DB::raw('SUM(orders_products.monto * orders_products.cantidad) as total'))
                ->get()
                ->map(fn ($p) => ['nombre' => $p->nombre, 'codigo' => $p->codigo, 'unidad' => $p->unidad,
                    'cantidad' => round((float) $p->cantidad, 3), 'total' => round((float) $p->total, 2)])
                ->all();

            $pendientes = Order::where('deHoy', 1)->sinCobrar();
            $r['pendientes_cant'] = (clone $pendientes)->count();
            $r['pendientes_monto'] = round((float) (clone $pendientes)->sum('monto'), 2);

            $cierre = CierreCaja::create([
                'apertura' => $r['apertura'],
                'abierto_por' => $r['abierto_por'],
                'cierre' => $r['cierre'],
                'cerrado_por' => $r['cerrado_por'],
                'cant_ventas' => $r['cant_ventas'],
                'total_efectivo' => $r['total_efec'],
                'total_turno' => round($r['total_efec'] + $r['total_tarj'] + $r['total_tarj_cred']
                    + $r['total_qr'] + $r['total_transf'] + $r['total_cheque'], 2),
                'resumen' => $r,
            ]);

            Control::where('caja_abierta', 1)->update(['caja_abierta' => 0]);
            Order::where('deHoy', 1)->where('completada', 1)->update(['deHoy' => 0]);
            SucursalActual::filtrar(Deuda::where('deHoy', 1))->update(['deHoy' => 0]);

            // Ventas sin cobrar: en control-soft quedaban invisibles para siempre
            // con el stock ya descontado. Ahora las vacias se borran y las que
            // tienen productos pasan al turno siguiente para cobrarlas o anularlas.
            Order::where('completada', 0)
                ->whereDoesntHave('productos')->whereDoesntHave('servicios')
                ->delete();

            return $cierre;
        });
    }
}
