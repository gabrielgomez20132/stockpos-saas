<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ListaPrecio extends Model
{
    protected $table = 'listas_precios';

    protected $fillable = ['id_proveedor', 'id_user', 'archivo', 'estado', 'opciones', 'filas', 'vinculados', 'aplicados', 'variacion_promedio', 'aplicada_at'];

    protected function casts(): array
    {
        return ['opciones' => 'array', 'aplicada_at' => 'datetime', 'variacion_promedio' => 'float'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ListaPrecioItem::class, 'lista_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_proveedor');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_user');
    }
}
