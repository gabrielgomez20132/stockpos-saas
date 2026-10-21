<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComboItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['combo_id', 'id_producto', 'cantidad'];

    protected function casts(): array
    {
        return ['cantidad' => 'float', 'id_producto' => 'integer'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'id_producto');
    }
}
