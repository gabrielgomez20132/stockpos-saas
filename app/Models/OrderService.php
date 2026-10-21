<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Otro concepto" cobrado en una venta (fotocopias, recargas, delivery...). */
class OrderService extends Model
{
    protected $table = 'orders_services';

    protected $fillable = ['id_order', 'description', 'monto'];

    protected function casts(): array
    {
        return ['monto' => 'float', 'id_order' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'id_order');
    }
}
