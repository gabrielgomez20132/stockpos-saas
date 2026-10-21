<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Models\Sucursal;
use App\Support\SucursalActual;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

/** Encargados: las personas que entran al sistema (con DNI o email y contraseña). */
class EncargadoController extends Controller
{
    private function listado(bool $activos)
    {
        return Inertia::render('Encargados/Index', [
            'encargados' => User::with('roles:id,name')->tipo(User::TIPO_ENCARGADO)->where('id', '!=', User::ID_ADMIN)
                ->where('activo', $activos)->orderBy('nombre')->get(['id', 'nombre', 'dni', 'email', 'telefono', 'id_sucursal']),
            'activos' => $activos,
            'sucursales' => SucursalActual::habilitado() ? Sucursal::pluck('nombre', 'id') : null,
        ]);
    }

    public function index()
    {
        return $this->listado(true);
    }

    public function papelera()
    {
        return $this->listado(false);
    }

    private function rolesAsignables(Request $request)
    {
        $u = $request->user();
        if (! $u->hasPermission('roles-permisos')) {
            return collect();
        }

        return Role::orderBy('id')->get(['id', 'name', 'description'])
            ->filter(fn ($r) => $r->name !== Role::SUPER_ADMIN || $u->isSuperAdmin())->values();
    }

    /** sucursales para asignar (null si el modulo esta apagado) */
    private function sucursales(Request $request)
    {
        if (! SucursalActual::habilitado()) {
            return null;
        }
        $yo = $request->user();

        return Sucursal::where('activa', 1)->when($yo->id_sucursal, fn ($q) => $q->whereKey($yo->id_sucursal))->orderBy('id')->get(['id', 'nombre']);
    }

    /** quien esta asignado a una sucursal solo puede asignar gente a esa misma sucursal */
    private function sucursalPedida(Request $request, array $d, ?User $e): ?int
    {
        if (! SucursalActual::habilitado()) {
            return $e?->id_sucursal;
        }
        $yo = $request->user();
        $pedida = isset($d['id_sucursal']) ? (int) $d['id_sucursal'] : null;
        if ($yo->id_sucursal && $pedida !== (int) $yo->id_sucursal) {
            throw new \DomainException('Solo podés asignar encargados a tu sucursal.');
        }

        return $pedida;
    }

    private function reglas(?User $e = null): array
    {
        return [
            'nombre' => ['required', 'string', 'max:50'],
            'dni' => ['required', 'integer', 'min:1', 'max:4294967295', Rule::unique('users', 'dni')->ignore($e?->id)],
            'email' => ['nullable', 'email', 'max:50', Rule::unique('users', 'email')->ignore($e?->id)],
            'telefono' => ['nullable', 'string', 'max:40'],
            'password' => [$e ? 'nullable' : 'required', 'confirmed', Password::min(6)],
            'roles' => ['array'],
            'roles.*' => ['integer', 'exists:roles,id'],
            'id_sucursal' => ['nullable', 'integer', Rule::exists('sucursales', 'id')->where('activa', 1)],
        ];
    }

    /** Solo se tocan los roles que el usuario actual puede asignar. */
    private function syncRoles(Request $request, User $e, array $pedidos): void
    {
        $asignables = $this->rolesAsignables($request)->pluck('id');
        if ($asignables->isEmpty()) {
            return;
        }
        $fuera = $e->roles()->pluck('roles.id')->diff($asignables); // ej: Super admin si no lo sos
        $e->roles()->sync($asignables->intersect(array_map('intval', $pedidos))->merge($fuera)->unique()->values()->all());
    }

    public function create(Request $request)
    {
        return Inertia::render('Encargados/Form', ['encargado' => null, 'roles' => $this->rolesAsignables($request), 'sucursales' => $this->sucursales($request)]);
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->reglas(), [], ['password' => 'contraseña']);
        $e = User::create([
            'nombre' => $d['nombre'], 'dni' => $d['dni'], 'email' => $d['email'] ?? null, 'telefono' => $d['telefono'] ?? null,
            'password' => $d['password'], 'id_uType' => User::TIPO_ENCARGADO, 'activo' => 1,
            'id_sucursal' => $this->sucursalPedida($request, $d, null),
        ]);
        $this->syncRoles($request, $e, $d['roles'] ?? []);

        return redirect()->route('encargados.index')->with('ok', 'Encargado creado. Ya puede entrar con su DNI.');
    }

    public function edit(Request $request, User $encargado)
    {
        $this->autorizarSobre($request, $encargado);
        return Inertia::render('Encargados/Form', [
            'encargado' => $encargado->only(['id', 'nombre', 'dni', 'email', 'telefono', 'id_sucursal']) + ['roles' => $encargado->roles()->pluck('roles.id')],
            'roles' => $this->rolesAsignables($request),
            'sucursales' => $this->sucursales($request),
        ]);
    }

    /**
     * Nadie puede editar (ni cambiarle la clave) a alguien con mas permisos que
     * uno: si no, con solo el permiso "encargados" se podia tomar la cuenta del dueño.
     */
    private function autorizarSobre(Request $request, User $encargado): void
    {
        $yo = $request->user();
        if ($yo->isSuperAdmin() || $yo->id === $encargado->id) {
            return;
        }
        if ($encargado->isSuperAdmin() || array_diff($encargado->permisos(), $yo->permisos())) {
            abort(403, 'No podés modificar a un usuario con más permisos que vos.');
        }
    }

    public function update(Request $request, User $encargado)
    {
        $this->autorizarSobre($request, $encargado);
        $d = $request->validate($this->reglas($encargado), [], ['password' => 'contraseña']);
        $encargado->fill(collect($d)->only(['nombre', 'dni', 'email', 'telefono'])->all());
        if (! empty($d['password'])) {
            $encargado->password = $d['password'];
        }
        if (SucursalActual::habilitado() && $request->has('id_sucursal')) {
            $encargado->id_sucursal = $this->sucursalPedida($request, $d, $encargado);
        }
        $encargado->save();
        $this->syncRoles($request, $encargado, $d['roles'] ?? []);

        return redirect()->route('encargados.index')->with('ok', 'Encargado actualizado.');
    }

    public function destroy(Request $request, User $encargado)
    {
        if ($encargado->id === $request->user()->id) {
            return back()->with('error', 'No podés darte de baja a vos mismo.');
        }
        $this->autorizarSobre($request, $encargado);
        $encargado->update(['activo' => 0]);

        return $this->ok($encargado->nombre.' ya no puede entrar al sistema.');
    }

    public function restaurar(User $encargado)
    {
        $encargado->update(['activo' => 1]);

        return $this->ok($encargado->nombre.' recuperado.');
    }
}
