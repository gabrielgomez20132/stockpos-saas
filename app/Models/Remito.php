<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Remito extends Model
{
    use Concerns\DeSucursal;

    protected $fillable = ['id_sucursal', 'id_user', 'id_order', 'id_cliente', 'cliente_nombre', 'cliente_doc', 'cliente_direccion', 'cliente_telefono',
        'descuenta_stock', 'observaciones', 'total', 'anulado_at', 'anulado_por'];

    protected function casts(): array
    {
        return ['total' => 'float', 'descuenta_stock' => 'boolean', 'anulado_at' => 'datetime', 'id_order' => 'integer', 'id_cliente' => 'integer', 'id_sucursal' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RemitoItem::class)->orderBy('orden')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    public function anulador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    public function numero(): string
    {
        return str_pad((string) $this->id_sucursal, 4, '0', STR_PAD_LEFT).'-'.str_pad((string) $this->id, 8, '0', STR_PAD_LEFT);
    }
}
