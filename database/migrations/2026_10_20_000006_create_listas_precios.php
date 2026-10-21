<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listas de precios de proveedores: se sube el Excel/CSV del proveedor, se
 * vinculan sus codigos con los productos y se aplican los costos nuevos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('listas_precios')) {
            Schema::create('listas_precios', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->integer('id_proveedor')->unsigned()->index();
                $t->integer('id_user')->unsigned()->nullable();
                $t->string('archivo', 150);
                $t->string('estado', 15)->default('pendiente'); // pendiente | aplicada | descartada
                $t->text('opciones')->nullable(); // json: columnas, descuento, iva, politica de precio
                $t->integer('filas')->unsigned()->default(0);
                $t->integer('vinculados')->unsigned()->default(0);
                $t->integer('aplicados')->unsigned()->default(0);
                $t->decimal('variacion_promedio', 8, 2)->nullable();
                $t->timestamp('aplicada_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('lista_precio_items')) {
            Schema::create('lista_precio_items', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->integer('lista_id')->unsigned();
                $t->integer('fila')->unsigned();
                $t->string('codigo', 100);
                $t->string('descripcion', 200)->nullable();
                $t->decimal('costo_lista', 12, 2); // tal cual viene en el archivo
                $t->decimal('costo_nuevo', 12, 2); // con descuento / iva aplicados
                $t->integer('id_producto')->unsigned()->nullable()->index();
                $t->decimal('costo_anterior', 12, 2)->nullable();
                $t->decimal('precio_anterior', 12, 2)->nullable();
                $t->decimal('precio_nuevo', 12, 2)->nullable();
                $t->boolean('aplicar')->default(true);
                $t->boolean('aplicado')->default(false);
                $t->foreign('lista_id')->references('id')->on('listas_precios')->onDelete('cascade');
            });
        }

        // codigo que usa cada proveedor para cada producto (puede no ser el codigo de barras)
        if (! Schema::hasTable('producto_proveedor')) {
            Schema::create('producto_proveedor', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->integer('id_producto')->unsigned();
                $t->integer('id_proveedor')->unsigned();
                $t->string('codigo_proveedor', 100);
                $t->timestamps();
                $t->unique(['id_proveedor', 'codigo_proveedor']);
                $t->index('id_producto');
            });
        }
    }

    public function down(): void
    {
    }
};
