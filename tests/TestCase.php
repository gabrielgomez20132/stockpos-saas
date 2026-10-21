<?php

namespace Tests;

use App\Models\Control;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BaseSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([BaseSeeder::class, RolesSeeder::class]);
        $this->admin = User::find(1);
        $this->withoutVite();
        \App\Models\Configuracion::limpiarCache();
        \App\Support\SucursalActual::fijar(null);
    }

    protected function encargado(string $rol = 'Empleado', array $extra = []): User
    {
        static $dni = 5000;
        $u = User::create($extra + ['nombre' => 'Persona '.$dni, 'dni' => $dni++, 'password' => 'secreto1', 'id_uType' => User::TIPO_ENCARGADO, 'activo' => 1]);
        $u->roles()->sync([Role::where('name', $rol)->value('id')]);

        return $u->fresh();
    }

    protected function cliente(string $nombre = 'Juan'): User
    {
        return User::create(['nombre' => $nombre, 'id_uType' => User::TIPO_CLIENTE, 'activo' => 1]);
    }

    protected function abrirCaja(float $monto = 1000): void
    {
        Control::create(['id_desc' => Control::INICIAL, 'admin' => 'test', 'monto' => $monto, 'detalle' => '', 'caja_abierta' => 1]);
    }

    protected function producto(array $a = []): Product
    {
        $cat = ProductCategory::firstOrCreate(['nombre' => $a['_cat'] ?? 'Gaseosas'], ['unidad' => $a['_unidad'] ?? 'Uds', 'activa' => 1]);
        unset($a['_cat'], $a['_unidad']);
        static $n = 1000;

        return Product::create($a + ['id_categoria' => $cat->id, 'codigo' => (string) $n++, 'nombre' => 'Prod '.$n, 'pedido' => 10,
            'quedan' => 10, 'aviso' => 2, 'costo' => 600, 'monto' => 1000, 'archivo' => Product::SIN_IMAGEN, 'activo' => 1]);
    }
}
