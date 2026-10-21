<?php

namespace App\Models\Concerns;

use App\Support\SucursalActual;
use Illuminate\Database\Eloquent\Builder;

/**
 * El registro pertenece a una sucursal: se guarda con la sucursal actual y
 * las consultas ven solo los de la sucursal actual (sin el modulo, la 1).
 * Para ver todas: Modelo::withoutGlobalScope('sucursal').
 */
trait DeSucursal
{
    use RegistraSucursal;

    protected static function bootDeSucursal(): void
    {
        static::addGlobalScope('sucursal', function (Builder $q) {
            $q->where($q->getModel()->getTable().'.id_sucursal', SucursalActual::id());
        });
    }
}
