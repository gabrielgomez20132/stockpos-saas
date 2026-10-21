<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Vale de credito generado por una devolucion. Se consume en ventas futuras. */
class Vale extends Model
{
    public const DIAS_VIGENCIA = 30;

    protected $fillable = [
        'codigo_vale', 'id_cliente', 'monto_original', 'monto_usado', 'monto_disponible',
        'fecha_emision', 'fecha_vencimiento', 'id_devolucion', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'monto_original' => 'float', 'monto_usado' => 'float', 'monto_disponible' => 'float',
            'fecha_emision' => 'date', 'fecha_vencimiento' => 'date', 'activo' => 'boolean',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_cliente');
    }

    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(Devolucion::class, 'id_devolucion');
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'order_vales', 'id_vale', 'id_order')
            ->withPivot('monto_aplicado')->withTimestamps();
    }

    /** Vence al final del dia de fecha_vencimiento. */
    public function isVencido(): bool
    {
        return now()->startOfDay()->gt($this->fecha_vencimiento);
    }

    public function disponible(): bool
    {
        return $this->activo && ! $this->isVencido() && $this->monto_disponible > 0;
    }

    public function scopeDisponibles(Builder $q): Builder
    {
        return $q->where('activo', true)
            ->whereDate('fecha_vencimiento', '>=', now()->toDateString())
            ->where('monto_disponible', '>', 0);
    }

    public static function generarCodigo(): string
    {
        $fecha = now()->format('Ymd');
        do {
            $codigo = 'VALE-'.$fecha.'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (static::where('codigo_vale', $codigo)->exists());

        return $codigo;
    }
}
