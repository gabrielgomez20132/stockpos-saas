<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ventas hechas sin conexion: el codigo con que las guardo el navegador (evita duplicarlas al reenviar). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'offline_uuid')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->string('offline_uuid', 40)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
    }
};
