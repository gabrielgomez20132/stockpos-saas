<?php

namespace App\Support;

use App\Models\Configuracion;
use App\Models\Sucursal;
use App\Models\User;

/**
 * Sucursal en la que se esta trabajando. Sin el modulo multisucursal es
 * siempre la 1 (Casa Central), asi todo funciona igual que antes.
 */
class SucursalActual
{
    public const CENTRAL = 1;

    private static ?int $id = null;

    public static function habilitado(): bool
    {
        return (bool) Configuracion::get('multisucursal_habilitado', false);
    }

    public static function id(): int
    {
        return self::habilitado() ? (self::$id ?? self::CENTRAL) : self::CENTRAL;
    }

    public static function fijar(?int $id): void
    {
        self::$id = $id;
    }

    /** la que corresponde al usuario: la suya si tiene una asignada, o la elegida en la sesion */
    public static function resolver(?User $user, ?int $elegida): int
    {
        if (! self::habilitado()) {
            return self::CENTRAL;
        }
        $activas = Sucursal::where('activa', 1)->pluck('id')->all();
        if ($user?->id_sucursal) {
            return in_array((int) $user->id_sucursal, $activas, true) ? (int) $user->id_sucursal : self::CENTRAL;
        }

        return $elegida && in_array($elegida, $activas, true) ? $elegida : self::CENTRAL;
    }

    /** filtra una consulta (Eloquent o DB::table) por la sucursal actual */
    public static function filtrar($q, ?string $tabla = null, ?int $id = null)
    {
        return $q->where(($tabla ? $tabla.'.' : '').'id_sucursal', $id ?? self::id());
    }

    public static function nombre(?int $id = null): string
    {
        return Sucursal::whereKey($id ?? self::id())->value('nombre') ?? 'Casa Central';
    }
}
