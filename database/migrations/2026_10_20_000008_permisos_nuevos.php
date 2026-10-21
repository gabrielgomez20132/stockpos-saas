<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Permisos mas finos (descuentos, anular ventas, devoluciones, proveedores,
 * combos, listas de precios, transferencias, configuracion). Cada rol que ya
 * existe recibe los nuevos segun el permiso que antes cubria esa seccion,
 * asi nadie pierde acceso a nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions') && Schema::hasTable('roles')) {
            Permission::sincronizar();
        }
    }

    public function down(): void
    {
    }
};
