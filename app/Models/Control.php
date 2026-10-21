<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Movimiento de caja. id_desc dice que es:
 *   1 caja inicial, 3 gastos varios, 4 servicios, 6 retiro, 7 pago a proveedor.
 * caja_abierta = 1 mientras pertenece al turno actual.
 */
class Control extends Model
{
    use \App\Models\Concerns\DeSucursal;

    public const INICIAL = 1;
    public const GASTO_VARIOS = 3;
    public const GASTO_SERVICIOS = 4;
    public const RETIRO = 6;
    public const GASTO_PROVEEDORES = 7;

    public const GASTOS = [
        'varios' => self::GASTO_VARIOS,
        'servicios' => self::GASTO_SERVICIOS,
        'proveedores' => self::GASTO_PROVEEDORES,
    ];

    protected $fillable = ['id_sucursal', 'id_desc', 'id_proveedor', 'admin', 'detalle', 'monto', 'caja_abierta'];

    protected function casts(): array
    {
        return ['monto' => 'float', 'caja_abierta' => 'boolean', 'id_desc' => 'integer'];
    }
}
