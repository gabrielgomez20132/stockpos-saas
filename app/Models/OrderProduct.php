<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderProduct extends Model
{
    protected $table = 'orders_products';

    protected $fillable = ['id_order', 'id_producto', 'cantidad', 'costo_x_uni', 'monto', 'id_combo', 'combo_grupo'];

    protected function casts(): array
    {
        return ['cantidad' => 'float', 'costo_x_uni' => 'float', 'monto' => 'float', 'id_order' => 'integer', 'id_producto' => 'integer', 'id_combo' => 'integer', 'combo_grupo' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'id_order');
    }

    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class, 'id_combo');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'id_producto');
    }

    /** Mismo redondeo que control-soft: cada renglon se redondea hacia arriba al peso. */
    public function subtotal(): float
    {
        return (float) ceil(round($this->monto * $this->cantidad, 4));
    }
}
