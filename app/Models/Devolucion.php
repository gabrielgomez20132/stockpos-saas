<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Devolucion extends Model
{
    use \App\Models\Concerns\DeSucursal;

    protected $table = 'devoluciones';

    protected $fillable = ['id_sucursal', 'id_order_original', 'id_cliente', 'id_encargado', 'monto_total', 'observaciones'];

    protected function casts(): array
    {
        return ['monto_total' => 'float'];
    }

    public function orderOriginal(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'id_order_original');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_cliente');
    }

    public function encargado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_encargado');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(DevolucionProducto::class, 'id_devolucion');
    }

    public function vale(): HasOne
    {
        return $this->hasOne(Vale::class, 'id_devolucion');
    }
}
