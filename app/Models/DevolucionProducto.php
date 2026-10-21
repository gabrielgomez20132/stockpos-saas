<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevolucionProducto extends Model
{
    protected $table = 'devolucion_productos';

    protected $fillable = ['id_devolucion', 'id_producto', 'cantidad_devuelta', 'precio_unitario', 'subtotal'];

    protected function casts(): array
    {
        return ['cantidad_devuelta' => 'float', 'precio_unitario' => 'float', 'subtotal' => 'float'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'id_producto');
    }
}
