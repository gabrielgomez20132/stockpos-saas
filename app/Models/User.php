<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Todas las personas del sistema viven en la misma tabla (como en control-soft):
 *   id_uType 1 = encargado (entra al sistema), 2 = cliente, 3 = proveedor.
 * El id 1 es el Administrador y el id 2 el cliente "General" (consumidor final).
 */
class User extends Authenticatable
{
    use Notifiable;

    public const TIPO_ENCARGADO = 1;
    public const TIPO_CLIENTE = 2;
    public const TIPO_PROVEEDOR = 3;

    public const ID_ADMIN = 1;
    public const ID_CLIENTE_GENERAL = 2;

    public const TIPOS = [
        'encargado' => self::TIPO_ENCARGADO,
        'cliente' => self::TIPO_CLIENTE,
        'proveedor' => self::TIPO_PROVEEDOR,
    ];

    protected $fillable = ['id_sucursal', 
        'nombre', 'dni', 'email', 'password', 'id_uType', 'activo',
        'telefono', 'cuit', 'contacto', 'direccion', 'observaciones',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'activo' => 'boolean',
            'id_uType' => 'integer',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function scopeTipo(Builder $q, int $tipo): Builder
    {
        return $q->where('id_uType', $tipo);
    }

    public function isStaff(): bool
    {
        return $this->id_uType === self::TIPO_ENCARGADO || $this->isSuperAdmin();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN);
    }

    public function hasRole(string $nombre): bool
    {
        return $this->roles->contains('name', $nombre);
    }

    /** Permisos efectivos (slugs). El Super admin tiene todos. */
    public function permisos(): array
    {
        $enBase = once(fn () => Permission::pluck('slug')->all()); // permisos que existen en la base (una vez por pedido)
        if ($this->isSuperAdmin()) {
            return array_values(array_unique(array_merge($enBase, array_keys(Permission::LISTA))));
        }

        $mios = $this->roles->loadMissing('permissions')
            ->flatMap(fn ($r) => $r->permissions->pluck('slug'))
            ->unique()->values()->all();
        // si todavia no se corrio la migracion de permisos nuevos, se heredan del permiso viejo
        foreach (Permission::HEREDA as $nuevo => $viejo) {
            if (! in_array($nuevo, $enBase, true) && ($viejo === '*' || in_array($viejo, $mios, true))) {
                $mios[] = $nuevo;
            }
        }

        return array_values(array_unique($mios));
    }

    public function hasPermission(string $slug): bool
    {
        return $this->isSuperAdmin() || in_array($slug, $this->permisos(), true);
    }
}
