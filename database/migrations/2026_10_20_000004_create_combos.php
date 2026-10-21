<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Combos / promociones: un precio fijo por un conjunto de productos.
 * Al venderlo, cada producto del combo queda como un renglon de la venta
 * (orders_products) con su parte del precio, marcado con id_combo y combo_grupo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('combos')) {
            Schema::create('combos', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->string('nombre', 80);
                $t->string('codigo', 100)->nullable()->unique();
                $t->decimal('precio', 12, 2);
                $t->date('desde')->nullable();
                $t->date('hasta')->nullable();
                $t->boolean('activo')->default(true);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('combo_items')) {
            Schema::create('combo_items', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->integer('combo_id')->unsigned();
                $t->integer('id_producto')->unsigned()->index();
                $t->decimal('cantidad', 12, 3);
                $t->foreign('combo_id')->references('id')->on('combos')->onDelete('cascade');
            });
        }

        Schema::table('orders_products', function (Blueprint $t) {
            if (! Schema::hasColumn('orders_products', 'id_combo')) {
                $t->integer('id_combo')->unsigned()->nullable()->index();
            }
            if (! Schema::hasColumn('orders_products', 'combo_grupo')) {
                $t->integer('combo_grupo')->unsigned()->nullable();
            }
        });
    }

    public function down(): void
    {
    }
};
