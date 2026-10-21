<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        Permission::sincronizar();

        $roles = [
            Role::SUPER_ADMIN => 'Acceso total al sistema',
            'Dueño' => 'Dueño del local',
            'Encargado' => 'Encargado de turno',
            'Empleado' => 'Personal de atención',
        ];
        foreach ($roles as $nombre => $desc) {
            $rol = Role::firstOrCreate(['name' => $nombre], ['description' => $desc]);
            // solo se asignan permisos a roles nuevos: no pisar lo configurado a mano
            if ($rol->wasRecentlyCreated) {
                $todos = Permission::pluck('id', 'slug');
                $ids = $nombre === 'Empleado'
                    ? $todos->except(['productos', 'categorias', 'control', 'afip', 'afip-config', 'roles-permisos', 'encargados',
                        'devoluciones', 'proveedores', 'combos', 'listas-precios', 'transferencias', 'configuracion', 'remitos', 'produccion'])->values()
                    : $todos->values();
                $rol->permissions()->sync($ids);
            }
        }

        $admin = User::find(User::ID_ADMIN);
        $super = Role::where('name', Role::SUPER_ADMIN)->first();
        if ($admin && $super) {
            $admin->roles()->syncWithoutDetaching([$super->id]);
        }
    }
}
