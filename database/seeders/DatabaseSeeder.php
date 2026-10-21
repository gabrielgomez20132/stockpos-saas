<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Datos base. Todos los seeders son idempotentes: se pueden correr sobre una
 * base con datos reales sin borrar ni duplicar nada (los de control-soft hacian
 * TRUNCATE de usuarios, roles y formas de pago).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BaseSeeder::class,
            RolesSeeder::class,
        ]);

        // productos de ejemplo solo en una base vacia (nunca sobre datos reales)
        if (! \Illuminate\Support\Facades\DB::table('products')->exists()) {
            $this->call(CatalogoEjemploSeeder::class);
        }
    }
}
