<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuentas corrientes. La misma tabla lleva dos cuentas opuestas:
 *   tipo_cuenta 'cliente'   -> plata a COBRAR  (D = fiado, P = pago del cliente)
 *   tipo_cuenta 'proveedor' -> plata a PAGAR   (D = compra, P = pago al proveedor)
 * id_cliente apunta al cliente o al proveedor segun la cuenta.
 */
class Deuda extends Model
{
    use \App\Models\Concerns\RegistraSucursal;

    public const DEBE = 'D';
    public const PAGO = 'P';

    protected $table = 'deudas';

    protected $fillable = ['id_sucursal', 'id_cliente', 'tipo_cuenta', 'id_encargado', 'monto', 'tipo', 'detalle', 'deHoy'];

    protected function casts(): array
    {
        return ['monto' => 'float', 'deHoy' => 'boolean', 'id_cliente' => 'integer'];
    }

    public function scopeDeClientes(Builder $q): Builder
    {
        return $q->where('tipo_cuenta', 'cliente');
    }

    public function scopeDeProveedores(Builder $q): Builder
    {
        return $q->where('tipo_cuenta', 'proveedor');
    }

    /** Saldo de una cuenta: positivo = lo que se debe (cliente nos debe / le debemos al proveedor). */
    public static function saldo(string $cuenta, int $id): float
    {
        $q = static::where('tipo_cuenta', $cuenta)->where('id_cliente', $id);
        $d = (clone $q)->where('tipo', self::DEBE)->sum('monto');
        $p = (clone $q)->where('tipo', self::PAGO)->sum('monto');

        return round($d - $p, 2);
    }

    /** Saldos de muchas cuentas en una sola consulta: [id => saldo]. */
    public static function saldos(string $cuenta): array
    {
        return static::where('tipo_cuenta', $cuenta)
            ->selectRaw("id_cliente, SUM(CASE WHEN tipo = 'D' THEN monto ELSE -monto END) as saldo")
            ->groupBy('id_cliente')
            ->pluck('saldo', 'id_cliente')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }
}
