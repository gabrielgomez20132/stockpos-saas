<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockSucursal extends Model
{
    protected $table = 'stock_sucursal';

    protected $fillable = ['id_producto', 'id_sucursal', 'quedan'];

    protected function casts(): array
    {
        return ['quedan' => 'float'];
    }
}
