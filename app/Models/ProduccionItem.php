<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProduccionItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['produccion_id', 'id_producto', 'horneado', 'vendido', 'sobrante', 'descartado', 'costo', 'monto'];

    protected function casts(): array
    {
        return ['horneado' => 'float', 'vendido' => 'float', 'sobrante' => 'float', 'descartado' => 'float', 'costo' => 'float', 'monto' => 'float', 'id_producto' => 'integer'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'id_producto');
    }

    public function tandas(): HasMany
    {
        return $this->hasMany(ProduccionTanda::class);
    }
}
