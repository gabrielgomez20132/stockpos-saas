<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    /** Unidades validas: 'Uds' (por unidad) y 'Kg' (por peso, admite decimales). */
    public const UNIDADES = ['Uds', 'Kg'];

    protected $fillable = ['nombre', 'unidad', 'activa'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Product::class, 'id_categoria');
    }

    /** Kg, Lts, Gr, Mts... admiten cantidades con decimales. 'Uds' no. */
    public function esPorPeso(): bool
    {
        return in_array(strtolower(substr((string) $this->unidad, 0, 2)), ['kg', 'lt', 'gr', 'mt'], true);
    }
}
