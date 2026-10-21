<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListaPrecioItem extends Model
{
    protected $table = 'lista_precio_items';

    public $timestamps = false;

    protected $fillable = ['lista_id', 'fila', 'codigo', 'descripcion', 'costo_lista', 'costo_nuevo', 'id_producto',
        'costo_anterior', 'precio_anterior', 'precio_nuevo', 'aplicar', 'aplicado'];

    protected function casts(): array
    {
        return ['costo_lista' => 'float', 'costo_nuevo' => 'float', 'costo_anterior' => 'float', 'precio_anterior' => 'float',
            'precio_nuevo' => 'float', 'aplicar' => 'boolean', 'aplicado' => 'boolean', 'id_producto' => 'integer'];
    }

    public function lista(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class, 'lista_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'id_producto');
    }
}
