<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProduccionTanda extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['produccion_item_id', 'cantidad', 'id_user'];

    protected function casts(): array
    {
        return ['cantidad' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_user');
    }
}
