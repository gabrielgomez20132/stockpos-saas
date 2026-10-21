<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderVale extends Model
{
    protected $table = 'order_vales';

    protected $fillable = ['id_order', 'id_vale', 'monto_aplicado'];

    protected function casts(): array
    {
        return ['monto_aplicado' => 'float'];
    }
}
