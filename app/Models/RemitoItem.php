<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RemitoItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['remito_id', 'id_producto', 'codigo', 'descripcion', 'cantidad', 'precio', 'orden'];

    protected function casts(): array
    {
        return ['cantidad' => 'float', 'precio' => 'float', 'id_producto' => 'integer'];
    }
}
