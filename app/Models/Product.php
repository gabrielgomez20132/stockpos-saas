<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    public const SIN_IMAGEN = 'sinImagen.png';

    protected $fillable = [
        'id_categoria', 'codigo', 'nombre', 'pedido', 'quedan', 'aviso',
        'costo', 'monto', 'archivo', 'activo', 'id_padre', 'variante', 'tiene_variantes',
    ];

    protected $appends = ['imagen_url'];

    /** true cuando "quedan" tiene cargado el stock de otra sucursal (solo para mostrar) */
    public bool $stockDeSucursal = false;

    protected static function booted(): void
    {
        // products.quedan es el stock de Casa Central: el de otra sucursal se mueve con App\Support\Stock
        static::saving(function (Product $p) {
            $otra = \App\Support\SucursalActual::id() !== \App\Support\SucursalActual::CENTRAL;
            if ($p->exists && $p->isDirty('quedan') && ($p->stockDeSucursal || $otra)) {
                throw new \LogicException('El stock de una sucursal se cambia con App\\Support\\Stock.');
            }
            if (! $p->exists && $otra && (float) $p->quedan > 0) {
                throw new \LogicException('Producto nuevo en otra sucursal: crealo con stock 0 y cargalo con Stock::fijar.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'pedido' => 'float', 'quedan' => 'float', 'aviso' => 'float',
            'costo' => 'float', 'monto' => 'float', 'activo' => 'boolean',
            'id_categoria' => 'integer', 'id_padre' => 'integer', 'variante' => 'array', 'tiene_variantes' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'id_categoria');
    }

    public function variantes(): HasMany
    {
        return $this->hasMany(Product::class, 'id_padre')->orderBy('id');
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'id_padre');
    }

    /** "M / Negro" */
    public function textoVariante(): string
    {
        return implode(' / ', array_values($this->variante ?? []));
    }

    public function ingresos(): HasMany
    {
        return $this->hasMany(IngresoProducto::class, 'id_producto');
    }

    public function getImagenUrlAttribute(): ?string
    {
        if (! $this->archivo || $this->archivo === self::SIN_IMAGEN) {
            return null;
        }

        return '/uploads/'.rawurlencode($this->archivo);
    }

    public function stockBajo(): bool
    {
        return $this->quedan <= $this->aviso;
    }
}
