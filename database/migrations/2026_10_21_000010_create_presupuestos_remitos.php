<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presupuestos y remitos (documentos no validos como factura, en hoja A4 con
 * el logo del negocio). Se habilitan desde Configuracion y tienen su permiso.
 */
return new class extends Migration
{
    private function cliente(Blueprint $t): void
    {
        $t->integer('id_cliente')->unsigned()->nullable()->index();
        $t->string('cliente_nombre', 100)->nullable();
        $t->string('cliente_doc', 20)->nullable();
        $t->string('cliente_direccion', 150)->nullable();
        $t->string('cliente_telefono', 40)->nullable();
    }

    private function items(Blueprint $t, string $padre, string $tabla): void
    {
        $t->engine = 'InnoDB';
        $t->increments('id');
        $t->integer($padre)->unsigned();
        $t->integer('id_producto')->unsigned()->nullable();
        $t->string('codigo', 60)->nullable();
        $t->string('descripcion', 150);
        $t->decimal('cantidad', 12, 3);
        $t->decimal('precio', 14, 2)->default(0);
        $t->smallInteger('orden')->unsigned()->default(0);
        $t->foreign($padre)->references('id')->on($tabla)->onDelete('cascade');
    }

    public function up(): void
    {
        if (! Schema::hasTable('presupuestos')) {
            Schema::create('presupuestos', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->integer('id_sucursal')->unsigned()->default(1)->index();
                $t->integer('id_user')->unsigned()->nullable();
                $this->cliente($t);
                $t->smallInteger('validez_dias')->unsigned()->default(7);
                $t->string('observaciones', 1000)->nullable();
                $t->decimal('subtotal', 14, 2)->default(0);
                $t->decimal('descuento', 14, 2)->default(0);
                $t->decimal('total', 14, 2)->default(0);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('presupuesto_items')) {
            Schema::create('presupuesto_items', fn (Blueprint $t) => $this->items($t, 'presupuesto_id', 'presupuestos'));
        }
        if (! Schema::hasTable('remitos')) {
            Schema::create('remitos', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->integer('id_sucursal')->unsigned()->default(1)->index();
                $t->integer('id_user')->unsigned()->nullable();
                $t->integer('id_order')->unsigned()->nullable()->index();
                $this->cliente($t);
                $t->boolean('descuenta_stock')->default(false);
                $t->string('observaciones', 1000)->nullable();
                $t->decimal('total', 14, 2)->default(0);
                $t->timestamp('anulado_at')->nullable();
                $t->integer('anulado_por')->unsigned()->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('remito_items')) {
            Schema::create('remito_items', fn (Blueprint $t) => $this->items($t, 'remito_id', 'remitos'));
        }

        if (Schema::hasTable('permissions') && Schema::hasTable('roles')) {
            Permission::sincronizar();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('remito_items');
        Schema::dropIfExists('remitos');
        Schema::dropIfExists('presupuesto_items');
        Schema::dropIfExists('presupuestos');
    }
};
