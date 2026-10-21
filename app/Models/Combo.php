<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Combo extends Model
{
    protected $fillable = ['nombre', 'codigo', 'precio', 'desde', 'hasta', 'activo'];

    protected function casts(): array
    {
        return ['precio' => 'float', 'activo' => 'boolean', 'desde' => 'date', 'hasta' => 'date'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComboItem::class);
    }

    /** activo y dentro de las fechas (si las tiene) */
    public function vigente(): bool
    {
        $hoy = now()->startOfDay();

        return $this->activo
            && (! $this->desde || $this->desde->lte($hoy))
            && (! $this->hasta || $this->hasta->gte($hoy));
    }

    public function estado(): string
    {
        if (! $this->activo) {
            return 'inactivo';
        }
        if ($this->desde && $this->desde->gt(now()->startOfDay())) {
            return 'programado';
        }
        if ($this->hasta && $this->hasta->lt(now()->startOfDay())) {
            return 'vencido';
        }

        return 'vigente';
    }

    public function scopeVigentes(Builder $q): Builder
    {
        $hoy = now()->toDateString();

        return $q->where('activo', 1)
            ->where(fn ($w) => $w->whereNull('desde')->orWhereDate('desde', '<=', $hoy))
            ->where(fn ($w) => $w->whereNull('hasta')->orWhereDate('hasta', '>=', $hoy));
    }
}
