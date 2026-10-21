<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngresoProducto extends Model
{
    use \App\Models\Concerns\RegistraSucursal;

    protected $table = 'ingreso_producto';

    protected $fillable = ['id_sucursal', 'cantidad', 'costo', 'monto', 'id_producto', 'id_user'];

    protected function casts(): array
    {
        return ['cantidad' => 'float', 'costo' => 'float', 'monto' => 'float'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'id_producto');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_user');
    }
}
