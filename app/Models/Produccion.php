<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Produccion extends Model
{
    use Concerns\DeSucursal;

    protected $table = 'producciones';

    protected $fillable = ['id_sucursal', 'fecha', 'id_user', 'cerrada_at', 'cerrada_por'];

    protected function casts(): array
    {
        return ['cerrada_at' => 'datetime', 'id_sucursal' => 'integer']; // fecha queda como texto AAAA-MM-DD
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProduccionItem::class);
    }
}
