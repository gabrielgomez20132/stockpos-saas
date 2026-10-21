<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use \App\Models\Concerns\DeSucursal;

    public const TIPO_PRODUCTOS = 1;

    public const COLUMNAS_PAGO = [
        'pago_efec', 'pago_tarj_debito', 'pago_tarj_cred', 'pago_qr',
        'pago_transf', 'pago_cheque', 'pago_dolares', 'pago_vale',
    ];

    protected $fillable = ['id_sucursal', 'offline_uuid', 'stock_descontado', 
        'id_encargado', 'id_cliente', 'id_type', 'canal_id', 'id_forma_pago',
        'pago_efec', 'pago_tarj_debito', 'pago_tarj_cred', 'pago_qr', 'pago_cheque',
        'pago_transf', 'pago_dolares', 'pago_vale', 'fiado', 'monto', 'descuento',
        'completada', 'deHoy', 'id_deuda', 'idAfipFct', 'idAfipNdc',
    ];

    protected function casts(): array
    {
        $c = [
            'monto' => 'float', 'descuento' => 'float', 'fiado' => 'float',
            'completada' => 'boolean', 'deHoy' => 'boolean', 'stock_descontado' => 'boolean',
            'id_cliente' => 'integer', 'id_encargado' => 'integer', 'id_forma_pago' => 'integer',
            'canal_id' => 'integer', 'id_deuda' => 'integer', 'idAfipFct' => 'integer', 'idAfipNdc' => 'integer',
        ];
        foreach (self::COLUMNAS_PAGO as $col) {
            $c[$col] = 'float';
        }

        return $c;
    }

    public function productos(): HasMany
    {
        return $this->hasMany(OrderProduct::class, 'id_order');
    }

    public function servicios(): HasMany
    {
        return $this->hasMany(OrderService::class, 'id_order');
    }

    public function canal(): BelongsTo
    {
        return $this->belongsTo(Canal::class, 'canal_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_cliente');
    }

    public function encargado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_encargado');
    }

    public function formaPago(): BelongsTo
    {
        return $this->belongsTo(FormaPago::class, 'id_forma_pago');
    }

    public function vales(): BelongsToMany
    {
        return $this->belongsToMany(Vale::class, 'order_vales', 'id_order', 'id_vale')
            ->withPivot('monto_aplicado')->withTimestamps();
    }

    /** sin cobrar = abierta y con al menos un producto u otro concepto (las vacias no cuentan) */
    public function scopeSinCobrar(Builder $q): Builder
    {
        return $q->where('completada', 0)->where(fn ($w) => $w->whereHas('productos')->orWhereHas('servicios'));
    }

    public function scopeDelTurno(Builder $q): Builder
    {
        return $q->where('deHoy', 1);
    }

    /** Lo que efectivamente se pago (sin vale ni fiado). */
    public function totalPagado(): float
    {
        return round($this->pago_efec + $this->pago_tarj_debito + $this->pago_tarj_cred + $this->pago_qr
            + $this->pago_transf + $this->pago_cheque + $this->pago_dolares, 2);
    }

    public function totalFinal(): float
    {
        return round($this->monto - $this->descuento, 2);
    }
}
