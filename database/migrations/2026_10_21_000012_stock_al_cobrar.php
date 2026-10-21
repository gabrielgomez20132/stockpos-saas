<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El stock se descuenta al COBRAR (antes se descontaba al cargar cada producto).
 * - Las ventas cobradas ya descontaron: quedan marcadas.
 * - Las ventas SIN cobrar que existen hoy tenian el stock tomado: se lo devuelve
 *   (asi al cobrarlas se descuenta una sola vez, con la regla nueva).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'stock_descontado')) {
            Schema::table('orders', fn (Blueprint $t) => $t->boolean('stock_descontado')->default(false));
        }

        DB::transaction(function () {
            DB::table('orders')->where('completada', 1)->update(['stock_descontado' => 1]);

            $abiertas = DB::table('orders_products')->join('orders', 'orders.id', '=', 'orders_products.id_order')
                ->where('orders.completada', 0)->where('orders.stock_descontado', 0)
                ->groupBy('orders.id_sucursal', 'orders_products.id_producto')
                ->selectRaw('orders.id_sucursal, orders_products.id_producto, SUM(orders_products.cantidad) as cant')->get();
            foreach ($abiertas as $r) {
                $sid = (int) ($r->id_sucursal ?: 1);
                $cant = round((float) $r->cant, 3);
                if ($sid === 1 || ! Schema::hasTable('stock_sucursal')) {
                    DB::table('products')->where('id', $r->id_producto)->update(['quedan' => DB::raw('quedan + '.$cant)]);
                } else {
                    $hay = DB::table('stock_sucursal')->where('id_sucursal', $sid)->where('id_producto', $r->id_producto)->exists();
                    $hay
                        ? DB::table('stock_sucursal')->where('id_sucursal', $sid)->where('id_producto', $r->id_producto)->update(['quedan' => DB::raw('quedan + '.$cant)])
                        : DB::table('stock_sucursal')->insert(['id_sucursal' => $sid, 'id_producto' => $r->id_producto, 'quedan' => $cant]);
                }
            }
        });
    }

    public function down(): void
    {
    }
};
