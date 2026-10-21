<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panaderia: produccion del dia. Lo que sale del horno entra al stock (cada
 * tanda queda registrada) y al cierre se anota lo que sobro y si se descarta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('producciones')) {
            Schema::create('producciones', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->integer('id_sucursal')->unsigned()->default(1);
                $t->date('fecha');
                $t->integer('id_user')->unsigned()->nullable();
                $t->timestamp('cerrada_at')->nullable();
                $t->integer('cerrada_por')->unsigned()->nullable();
                $t->timestamps();
                $t->unique(['id_sucursal', 'fecha']);
            });
        }
        if (! Schema::hasTable('produccion_items')) {
            Schema::create('produccion_items', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->integer('produccion_id')->unsigned();
                $t->integer('id_producto')->unsigned();
                $t->decimal('horneado', 12, 3)->default(0);
                $t->decimal('vendido', 12, 3)->nullable();   // se guarda al cerrar el dia
                $t->decimal('sobrante', 12, 3)->nullable();  // lo que se conto al cierre
                $t->decimal('descartado', 12, 3)->default(0); // lo que se tiro / dono (sale del stock)
                $t->decimal('costo', 14, 2)->default(0);
                $t->decimal('monto', 14, 2)->default(0);
                $t->unique(['produccion_id', 'id_producto']);
                $t->foreign('produccion_id')->references('id')->on('producciones')->onDelete('cascade');
            });
        }
        if (! Schema::hasTable('produccion_tandas')) {
            Schema::create('produccion_tandas', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->integer('produccion_item_id')->unsigned();
                $t->decimal('cantidad', 12, 3); // negativa = correccion
                $t->integer('id_user')->unsigned()->nullable();
                $t->timestamp('created_at')->nullable();
                $t->foreign('produccion_item_id')->references('id')->on('produccion_items')->onDelete('cascade');
            });
        }
        if (Schema::hasTable('permissions') && Schema::hasTable('roles')) {
            Permission::sincronizar();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('produccion_tandas');
        Schema::dropIfExists('produccion_items');
        Schema::dropIfExists('producciones');
    }
};
