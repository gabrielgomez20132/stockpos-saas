<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferenciaItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['transferencia_id', 'id_producto', 'cantidad'];

    protected function casts(): array
    {
        return ['cantidad' => 'float'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'id_producto');
    }
}
