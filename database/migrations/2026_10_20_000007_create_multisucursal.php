<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multisucursal. La sucursal 1 ("Casa Central") usa el stock de siempre
 * (products.quedan), asi todo lo existente sigue igual; las demas llevan su
 * stock en stock_sucursal. Ventas, caja, cierres y devoluciones quedan
 * marcadas con la sucursal donde se hicieron (por defecto la 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sucursales')) {
            Schema::create('sucursales', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->string('nombre', 60);
                $t->string('direccion', 120)->nullable();
                $t->string('telefono', 40)->nullable();
                $t->boolean('activa')->default(true);
                $t->timestamps();
            });
        }
        if (! DB::table('sucursales')->where('id', 1)->exists()) {
            DB::table('sucursales')->insert(['id' => 1, 'nombre' => 'Casa Central', 'activa' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (['orders', 'controls', 'cierres_caja', 'devoluciones', 'deudas', 'ingreso_producto'] as $tabla) {
            if (Schema::hasTable($tabla) && ! Schema::hasColumn($tabla, 'id_sucursal')) {
                Schema::table($tabla, function (Blueprint $t) {
                    $t->integer('id_sucursal')->unsigned()->default(1)->index();
                });
            }
        }

        if (! Schema::hasColumn('users', 'id_sucursal')) {
            Schema::table('users', function (Blueprint $t) {
                $t->integer('id_sucursal')->unsigned()->nullable(); // null = puede trabajar en todas
            });
        }

        if (! Schema::hasTable('stock_sucursal')) {
            Schema::create('stock_sucursal', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->integer('id_producto')->unsigned();
                $t->integer('id_sucursal')->unsigned();
                $t->decimal('quedan', 12, 3)->default(0);
                $t->timestamps();
                $t->unique(['id_producto', 'id_sucursal']);
                $t->index('id_sucursal');
            });
        }

        if (! Schema::hasTable('transferencias')) {
            Schema::create('transferencias', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->integer('origen')->unsigned()->index();
                $t->integer('destino')->unsigned()->index();
                $t->integer('id_user')->unsigned()->nullable();
                $t->string('observaciones', 300)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('transferencia_items')) {
            Schema::create('transferencia_items', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->integer('transferencia_id')->unsigned();
                $t->integer('id_producto')->unsigned();
                $t->decimal('cantidad', 12, 3);
                $t->foreign('transferencia_id')->references('id')->on('transferencias')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
    }
};
