<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema completo del sistema de stock / punto de venta.
 *
 * Mantiene los MISMOS nombres de tablas y columnas que control-soft (Laravel
 * 5.5) para que la base de datos existente sirva tal cual. Cada tabla y cada
 * columna se crea solo si falta: en una base nueva arma todo, y sobre una copia
 * de la base vieja solo completa lo que no tenga.
 */
return new class extends Migration
{
    private function crear(string $tabla, Closure $def): void
    {
        if (! Schema::hasTable($tabla)) {
            Schema::create($tabla, function (Blueprint $t) use ($def) {
                $t->engine = 'InnoDB';
                $def($t);
            });
        }
    }

    private function columna(string $tabla, string $columna, Closure $def): void
    {
        if (! Schema::hasColumn($tabla, $columna)) {
            Schema::table($tabla, fn (Blueprint $t) => $def($t));
        }
    }

    public function up(): void
    {
        // ---------------------------------------------------------- roles
        $this->crear('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->string('description')->nullable();
            $t->timestamps();
        });

        $this->crear('permissions', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('description')->nullable();
            $t->timestamps();
        });

        $this->crear('permission_role', function (Blueprint $t) {
            $t->integer('permission_id')->unsigned();
            $t->integer('role_id')->unsigned();
            $t->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
            $t->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $t->primary(['permission_id', 'role_id']);
        });

        $this->crear('role_user', function (Blueprint $t) {
            $t->integer('role_id')->unsigned();
            $t->integer('user_id')->unsigned();
            $t->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $t->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $t->primary(['role_id', 'user_id']);
        });

        // ------------------------------------------------------- catalogo
        $this->crear('product_categories', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 25);
            $t->boolean('activa')->default(true);
            $t->string('unidad', 15);
            $t->timestamps();
        });

        $this->crear('products', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('id_categoria')->unsigned();
            $t->string('codigo')->unique();
            $t->string('nombre', 55);
            $t->decimal('pedido', 12, 3)->unsigned()->default(0);
            $t->decimal('quedan', 12, 3)->unsigned()->default(0);
            $t->decimal('aviso', 12, 3)->unsigned()->default(0);
            $t->decimal('costo', 12, 2)->default(0);
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('archivo', 100)->default('sinImagen.png');
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->foreign('id_categoria')->references('id')->on('product_categories');
        });
        // nuevo: los productos con ventas no se borran, se desactivan
        $this->columna('products', 'activo', fn (Blueprint $t) => $t->boolean('activo')->default(true));

        $this->crear('ingreso_producto', function (Blueprint $t) {
            $t->increments('id');
            $t->decimal('cantidad', 12, 3)->default(0);
            $t->decimal('costo', 12, 2)->default(0);
            $t->decimal('monto', 12, 2)->default(0);
            $t->integer('id_producto')->unsigned()->index();
            $t->integer('id_user')->unsigned();
            $t->timestamps();
        });

        // --------------------------------------------------------- ventas
        $this->crear('orders_type', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre');
            $t->timestamps();
        });

        $this->crear('formas_pago', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 200);
            $t->timestamps();
        });

        $this->crear('canales', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 50);
            $t->timestamps();
        });

        $this->crear('orders', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('id_encargado')->unsigned();
            $t->integer('id_cliente')->unsigned();
            $t->integer('id_type')->unsigned();
            $t->integer('canal_id')->unsigned()->default(1);
            $t->integer('id_forma_pago')->unsigned();
            $t->decimal('monto', 12, 2)->default(0);
            $t->decimal('pago_efec', 12, 2)->default(0);
            $t->decimal('pago_tarj_debito', 12, 2)->default(0);
            $t->decimal('pago_transf', 12, 2)->default(0);
            $t->decimal('pago_cheque', 12, 2)->default(0);
            $t->decimal('pago_dolares', 12, 2)->default(0);
            $t->decimal('pago_vale', 12, 2)->default(0);
            $t->decimal('pago_tarj_cred', 12, 2)->default(0);
            $t->decimal('pago_qr', 12, 2)->default(0);
            $t->decimal('fiado', 12, 2)->default(0);
            $t->decimal('descuento', 12, 2)->default(0);
            $t->boolean('completada')->default(false);
            $t->boolean('deHoy')->default(true);
            $t->integer('id_deuda')->unsigned()->default(0);
            $t->integer('idAfipFct')->default(0);
            $t->integer('idAfipNdc')->default(0);
            $t->timestamps();

            $t->index(['deHoy', 'completada']);
            $t->index('created_at');
            $t->foreign('id_type')->references('id')->on('orders_type');
            $t->foreign('id_forma_pago')->references('id')->on('formas_pago');
            $t->foreign('canal_id')->references('id')->on('canales');
        });
        $this->columna('orders', 'pago_vale', fn (Blueprint $t) => $t->decimal('pago_vale', 12, 2)->default(0));
        $this->columna('orders', 'pago_qr', fn (Blueprint $t) => $t->decimal('pago_qr', 12, 2)->default(0));
        $this->columna('orders', 'pago_tarj_cred', fn (Blueprint $t) => $t->decimal('pago_tarj_cred', 12, 2)->default(0));

        $this->crear('orders_products', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('id_order')->unsigned();
            $t->integer('id_producto')->unsigned()->index();
            $t->decimal('cantidad', 12, 3)->unsigned();
            $t->decimal('costo_x_uni', 12, 2)->default(0);
            $t->decimal('monto', 12, 2)->default(0);
            $t->timestamps();
            $t->foreign('id_order')->references('id')->on('orders');
        });

        $this->crear('orders_services', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('id_order')->unsigned()->index();
            $t->string('description', 200);
            $t->decimal('monto', 12, 2)->default(0);
            $t->timestamps();
        });

        // ------------------------------------------- caja / cuentas corrientes
        $this->crear('controls', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('id_desc');
            $t->integer('id_proveedor')->unsigned()->nullable()->index();
            $t->string('admin', 50);
            $t->string('detalle', 100)->default('');
            $t->decimal('monto', 12, 2)->default(0);
            $t->boolean('caja_abierta')->default(true);
            $t->timestamps();
            $t->index(['caja_abierta', 'id_desc']);
        });
        $this->columna('controls', 'id_proveedor', fn (Blueprint $t) => $t->integer('id_proveedor')->unsigned()->nullable()->index());

        $this->crear('deudas', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('id_cliente')->unsigned();
            $t->string('tipo_cuenta', 10)->default('cliente');
            $t->integer('id_encargado')->unsigned();
            $t->decimal('monto', 12, 2)->default(0);
            $t->string('tipo', 1); // D = debe / compra, P = pago
            $t->string('detalle', 150)->nullable();
            $t->boolean('deHoy')->default(true);
            $t->timestamps();
            $t->index(['tipo_cuenta', 'id_cliente']);
            $t->index(['tipo_cuenta', 'deHoy']);
        });
        $this->columna('deudas', 'tipo_cuenta', fn (Blueprint $t) => $t->string('tipo_cuenta', 10)->default('cliente'));
        $this->columna('deudas', 'detalle', fn (Blueprint $t) => $t->string('detalle', 150)->nullable());

        $this->crear('cierres_caja', function (Blueprint $t) {
            $t->increments('id');
            $t->dateTime('apertura')->nullable();
            $t->dateTime('cierre');
            $t->string('cerrado_por', 100)->nullable();
            $t->integer('cant_ventas')->default(0);
            $t->decimal('total_efectivo', 12, 2)->default(0);
            $t->decimal('total_turno', 12, 2)->default(0);
            $t->longText('resumen');
            $t->timestamps();
        });

        // ------------------------------------------------ devoluciones / vales
        $this->crear('devoluciones', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('id_order_original')->unsigned()->index();
            $t->integer('id_cliente')->unsigned();
            $t->integer('id_encargado')->unsigned();
            $t->decimal('monto_total', 12, 2)->default(0);
            $t->text('observaciones')->nullable();
            $t->timestamps();
        });

        $this->crear('devolucion_productos', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('id_devolucion')->unsigned()->index();
            $t->integer('id_producto')->unsigned()->index();
            $t->decimal('cantidad_devuelta', 12, 3);
            $t->decimal('precio_unitario', 12, 2);
            $t->decimal('subtotal', 12, 2);
            $t->timestamps();
        });

        $this->crear('vales', function (Blueprint $t) {
            $t->increments('id');
            $t->string('codigo_vale', 50)->unique();
            $t->integer('id_cliente')->unsigned()->index();
            $t->decimal('monto_original', 12, 2);
            $t->decimal('monto_usado', 12, 2)->default(0);
            $t->decimal('monto_disponible', 12, 2);
            $t->date('fecha_emision');
            $t->date('fecha_vencimiento');
            $t->integer('id_devolucion')->unsigned()->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });

        $this->crear('order_vales', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('id_order')->unsigned()->index();
            $t->integer('id_vale')->unsigned()->index();
            $t->decimal('monto_aplicado', 12, 2);
            $t->timestamps();
        });

        // ------------------------------------------------------------ AFIP
        $this->crear('afip', function (Blueprint $t) {
            $t->increments('id');
            $t->dateTime('cbteFch');
            $t->unsignedTinyInteger('tipoCbteNum');
            $t->string('nroCbte', 17);
            $t->string('caeNum', 17);
            $t->date('caeFvt');
            $t->tinyInteger('docTipo');
            $t->string('docNro', 15);
            $t->string('nombreRS', 35)->nullable();
            $t->char('tipoPago', 4);
            $t->decimal('impNeto', 11, 2);
            $t->decimal('impIVA', 11, 2);
            $t->decimal('descuento', 11, 2)->default(0);
            $t->decimal('impTotal', 11, 2);
            $t->boolean('pagada')->default(false);
            $t->string('nroCbteAsoc', 17)->nullable();
            $t->string('codigoBarra', 45)->nullable();
            $t->string('detalleOpcional', 100)->nullable();
            $t->decimal('montoOpcional', 11, 2)->nullable();
            $t->string('concepto', 100)->nullable();
        });

        $this->crear('afip_config', function (Blueprint $t) {
            $t->increments('id');
            $t->string('ambiente', 15)->default('homologacion');
            $t->string('cuit', 11)->nullable();
            $t->unsignedSmallInteger('punto_venta')->default(1);
            $t->string('razon_social', 120)->nullable();
            $t->string('domicilio', 150)->nullable();
            $t->string('localidad', 100)->nullable();
            $t->string('condicion_iva', 40)->nullable();
            $t->string('iibb', 40)->nullable();
            $t->date('inicio_actividades')->nullable();
            $t->text('passphrase_produccion')->nullable();
            $t->text('passphrase_homologacion')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Intencionalmente vacio: un rollback no debe borrar ventas ni stock.
    }
};
