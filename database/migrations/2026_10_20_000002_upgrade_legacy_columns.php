<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes para bases que vienen de control-soft.
 *
 * orders.fiado se creo en 2019 como INTEGER: un fiado de $1.250,50 quedaba
 * guardado como 1250 y la cuenta del cliente no cerraba nunca. Se pasa a
 * decimal como el resto de los importes. En una base nueva no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'fiado')
            && in_array(strtolower(Schema::getColumnType('orders', 'fiado')), ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint'], true)) {
            Schema::table('orders', fn (Blueprint $t) => $t->decimal('fiado', 12, 2)->default(0)->change());
        }

        if (Schema::hasColumn('products', 'archivo')) {
            Schema::table('products', fn (Blueprint $t) => $t->string('archivo', 100)->default('sinImagen.png')->change());
        }
    }

    public function down(): void
    {
    }
};
