<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Roles y permisos. Reglas para que nadie se de mas poder del que tiene:
 *  - el rol "Super admin" solo lo puede asignar o quitar otro Super admin, y no se puede editar ni borrar;
 *  - nadie puede quitarse a si mismo el permiso de roles (para no quedar afuera).
 */
class RolController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Roles/Index', [
            'roles' => Role::with('permissions:id,slug')->withCount('users')->orderBy('id')->get(),
            'permisos' => Permission::orderBy('id')->get(['id', 'name', 'slug', 'description'])
                ->map(fn ($p) => $p->only(['id', 'name', 'slug', 'description']) + ['grupo' => Permission::DETALLE[$p->slug][1] ?? 'Otros'])
                ->sortBy(fn ($p) => array_search($p['slug'], array_keys(Permission::DETALLE), true) === false ? 999 : array_search($p['slug'], array_keys(Permission::DETALLE), true))
                ->values(),
            'usuarios' => User::with('roles:id')->tipo(User::TIPO_ENCARGADO)->where('activo', 1)->orderBy('nombre')->get(['id', 'nombre', 'dni'])
                ->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->nombre, 'dni' => $u->dni, 'roles' => $u->roles->pluck('id')]),
            'soySuper' => $request->user()->isSuperAdmin(),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:roles,name'],
            'description' => ['nullable', 'string', 'max:150'],
            'copiar_de' => ['nullable', 'integer', Rule::exists('roles', 'id')],
        ], [], ['name' => 'nombre']);
        $rol = Role::create(['name' => $d['name'], 'description' => $d['description'] ?? null]);
        if (! empty($d['copiar_de'])) {
            $origen = Role::with('permissions')->find($d['copiar_de']);
            // de Super admin se copia "todo"; nadie se da permisos que no tiene
            $ids = $origen->name === Role::SUPER_ADMIN ? Permission::pluck('id') : $origen->permissions->pluck('id');
            $yo = $request->user();
            if (! $yo->isSuperAdmin()) {
                $mios = Permission::whereIn('slug', $yo->permisos())->pluck('id');
                $ids = $ids->intersect($mios);
            }
            $rol->permissions()->sync($ids->values()->all());
        }

        return $this->ok('Rol "'.$rol->name.'" creado. Elegí sus permisos y guardalos.');
    }

    public function update(Request $request, Role $role)
    {
        if ($role->name === Role::SUPER_ADMIN) {
            return back()->with('error', 'El rol Super admin no se puede renombrar.');
        }
        $d = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('roles', 'name')->ignore($role->id)],
            'description' => ['nullable', 'string', 'max:150'],
        ], [], ['name' => 'nombre']);
        $role->update($d);

        return $this->ok('Rol actualizado.');
    }

    public function permisos(Request $request, Role $role)
    {
        if ($role->name === Role::SUPER_ADMIN) {
            return back()->with('error', 'El Super admin tiene siempre todos los permisos.');
        }
        $d = $request->validate(['permisos' => ['array'], 'permisos.*' => ['integer', 'exists:permissions,id']]);
        $ids = collect($d['permisos'] ?? [])->map(fn ($i) => (int) $i);

        $yo = $request->user();
        $permRoles = (int) Permission::where('slug', 'roles-permisos')->value('id');
        $yo->loadMissing('roles.permissions');
        if (! $yo->isSuperAdmin() && $yo->roles->contains('id', $role->id) && ! $ids->contains($permRoles)
            && $yo->roles->where('id', '!=', $role->id)->filter(fn ($r) => $r->permissions->contains('id', $permRoles))->isEmpty()) {
            return back()->with('error', 'No podés quitarle "Roles y permisos" a tu propio rol: te quedarías sin acceso.');
        }

        $role->permissions()->sync($ids->all());

        return $this->ok('Permisos de "'.$role->name.'" actualizados.');
    }

    public function destroy(Role $role)
    {
        if ($role->name === Role::SUPER_ADMIN) {
            return back()->with('error', 'El rol Super admin no se puede borrar.');
        }
        if ($role->users()->exists()) {
            return back()->with('error', 'El rol tiene usuarios asignados: sacáselo primero.');
        }
        $role->delete();

        return $this->ok('Rol eliminado.');
    }

    public function usuario(Request $request, User $user)
    {
        abort_unless($user->id_uType === User::TIPO_ENCARGADO, 404);
        $d = $request->validate(['roles' => ['array'], 'roles.*' => ['integer', Rule::exists('roles', 'id')]]);
        $pedidos = collect($d['roles'] ?? [])->map(fn ($i) => (int) $i);
        $yo = $request->user();
        $super = (int) Role::where('name', Role::SUPER_ADMIN)->value('id');

        if (! $yo->isSuperAdmin()) {
            // ni dar ni sacar Super admin
            $tiene = $user->roles()->where('roles.id', $super)->exists();
            $pedidos = $pedidos->reject(fn ($id) => $id === $super);
            if ($tiene) {
                $pedidos->push($super);
            }
        } elseif ($user->id === $yo->id && ! $pedidos->contains($super)) {
            return back()->with('error', 'No podés sacarte el rol Super admin a vos mismo.');
        }

        $user->roles()->sync($pedidos->unique()->values()->all());

        return $this->ok('Roles de '.$user->nombre.' actualizados.');
    }
}
