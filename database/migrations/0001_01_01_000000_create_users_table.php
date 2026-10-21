<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usuarios del sistema. Misma estructura que control-soft (tabla users con
 * id_uType: 1 encargado, 2 cliente, 3 proveedor) para poder reutilizar la base
 * de datos existente. Todas las migraciones son "guardadas": si la tabla o la
 * columna ya existe no hacen nada, asi que se pueden correr sobre una copia de
 * la base vieja sin romperla.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_types')) {
            Schema::create('user_types', function (Blueprint $table) {
                $table->increments('id');
                $table->string('nombre', 25);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->increments('id');
                $table->string('nombre', 50);
                $table->integer('dni')->unsigned()->unique()->nullable();
                $table->string('email', 50)->unique()->nullable();
                $table->string('telefono', 40)->nullable();
                $table->string('cuit', 20)->nullable();
                $table->string('contacto', 80)->nullable();
                $table->string('direccion', 150)->nullable();
                $table->text('observaciones')->nullable();
                $table->string('password')->nullable();
                $table->integer('id_uType')->unsigned();
                $table->boolean('activo')->default(true);
                $table->rememberToken();
                $table->timestamps();

                $table->foreign('id_uType')->references('id')->on('user_types');
            });
        } else {
            // base vieja anterior a los proveedores
            Schema::table('users', function (Blueprint $table) {
                foreach ([
                    'telefono' => fn () => $table->string('telefono', 40)->nullable(),
                    'cuit' => fn () => $table->string('cuit', 20)->nullable(),
                    'contacto' => fn () => $table->string('contacto', 80)->nullable(),
                    'direccion' => fn () => $table->string('direccion', 150)->nullable(),
                    'observaciones' => fn () => $table->text('observaciones')->nullable(),
                ] as $col => $add) {
                    if (! Schema::hasColumn('users', $col)) {
                        $add();
                    }
                }
            });
        }

        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->unsignedInteger('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    public function down(): void
    {
        // Intencionalmente vacio: nunca borrar datos del negocio con un rollback.
    }
};
