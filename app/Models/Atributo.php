<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Atributo de variante: Talle, Color, Tamaño... */
class Atributo extends Model
{
    protected $fillable = ['nombre', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'orden' => 'integer'];
    }

    public function valores(): HasMany
    {
        return $this->hasMany(AtributoValor::class)->orderBy('orden')->orderBy('id');
    }
}
