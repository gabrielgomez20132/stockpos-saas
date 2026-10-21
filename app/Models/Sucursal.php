<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sucursal extends Model
{
    protected $table = 'sucursales';

    protected $fillable = ['nombre', 'direccion', 'telefono', 'activa'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }
}
