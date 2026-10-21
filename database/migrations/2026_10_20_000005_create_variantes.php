<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Variantes (talle, color, tamano...).
 *
 * Cada variante es un producto propio (su codigo, stock y precio) que apunta a
 * un producto "base" con id_padre. Asi ventas, stock, cierres, devoluciones y
 * AFIP siguen funcionando igual. El producto base no se vende directo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('configuracion')) {
            Schema::create('configuracion', function (Blueprint $t) {
                $t->string('clave', 60)->primary();
                $t->text('valor')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atributos')) {
            Schema::create('atributos', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->string('nombre', 30)->unique();
                $t->unsignedSmallInteger('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atributo_valores')) {
            Schema::create('atributo_valores', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->increments('id');
                $t->integer('atributo_id')->unsigned();
                $t->string('valor', 30);
                $t->unsignedSmallInteger('orden')->default(0);
                $t->unique(['atributo_id', 'valor']);
                $t->foreign('atributo_id')->references('id')->on('atributos')->onDelete('cascade');
            });
        }

        Schema::table('products', function (Blueprint $t) {
            if (! Schema::hasColumn('products', 'id_padre')) {
                $t->integer('id_padre')->unsigned()->nullable()->index();
            }
            if (! Schema::hasColumn('products', 'variante')) {
                $t->text('variante')->nullable(); // {"Talle":"M","Color":"Negro"}
            }
            if (! Schema::hasColumn('products', 'tiene_variantes')) {
                $t->boolean('tiene_variantes')->default(false);
            }
        });

        // "Remera basica - XL / Azul marino" no entra en 55 letras
        Schema::table('products', fn (Blueprint $t) => $t->string('nombre', 120)->change());

        // atributos tipicos de ropa / calzado para arrancar
        if (! DB::table('atributos')->exists()) {
            $base = [
                'Talle' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
                'Color' => ['Negro', 'Blanco', 'Gris', 'Azul', 'Rojo', 'Verde', 'Beige'],
                'Tamaño' => ['Chico', 'Mediano', 'Grande'],
            ];
            $o = 0;
            foreach ($base as $nombre => $valores) {
                $id = DB::table('atributos')->insertGetId(['nombre' => $nombre, 'orden' => $o++, 'activo' => 1, 'created_at' => now(), 'updated_at' => now()]);
                foreach ($valores as $i => $v) {
                    DB::table('atributo_valores')->insert(['atributo_id' => $id, 'valor' => $v, 'orden' => $i]);
                }
            }
        }
    }

    public function down(): void
    {
    }
};
