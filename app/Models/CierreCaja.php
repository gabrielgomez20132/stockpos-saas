<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CierreCaja extends Model
{
    use \App\Models\Concerns\DeSucursal;

    protected $table = 'cierres_caja';

    protected $fillable = ['id_sucursal', 'apertura', 'abierto_por', 'cierre', 'cerrado_por', 'cant_ventas', 'total_efectivo', 'total_turno', 'resumen'];

    protected function casts(): array
    {
        return [
            'resumen' => 'array', 'apertura' => 'datetime', 'cierre' => 'datetime',
            'total_efectivo' => 'float', 'total_turno' => 'float',
        ];
    }
}
