<?php

namespace App\Models\Concerns;

use App\Support\SucursalActual;

/** Guarda en que sucursal se creo el registro (sin filtrar las consultas). */
trait RegistraSucursal
{
    protected static function bootRegistraSucursal(): void
    {
        static::creating(function ($m) {
            if (empty($m->id_sucursal)) {
                $m->id_sucursal = SucursalActual::id();
            }
        });
    }
}
