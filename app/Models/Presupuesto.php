<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Presupuesto extends Model
{
    use Concerns\DeSucursal;

    protected $fillable = ['id_sucursal', 'id_user', 'id_cliente', 'cliente_nombre', 'cliente_doc', 'cliente_direccion', 'cliente_telefono',
        'validez_dias', 'observaciones', 'subtotal', 'descuento', 'total'];

    protected function casts(): array
    {
        return ['subtotal' => 'float', 'descuento' => 'float', 'total' => 'float', 'validez_dias' => 'integer', 'id_cliente' => 'integer', 'id_sucursal' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PresupuestoItem::class)->orderBy('orden')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    public function numero(): string
    {
        return str_pad((string) $this->id_sucursal, 4, '0', STR_PAD_LEFT).'-'.str_pad((string) $this->id, 8, '0', STR_PAD_LEFT);
    }

    public function vence(): \Carbon\CarbonInterface
    {
        return $this->created_at->copy()->addDays($this->validez_dias);
    }
}
