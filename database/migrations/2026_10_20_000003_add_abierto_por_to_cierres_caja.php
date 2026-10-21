<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Quien abrio la caja (el que cargo la primera caja inicial del turno). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cierres_caja', 'abierto_por')) {
            Schema::table('cierres_caja', fn (Blueprint $t) => $t->string('abierto_por', 100)->nullable()->after('apertura'));
        }
    }

    public function down(): void
    {
    }
};
