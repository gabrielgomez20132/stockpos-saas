<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Comprobante electronico emitido (factura o nota de credito). */
class Afip extends Model
{
    public const FACTURA_A = 1;
    public const NDC_A = 3;
    public const FACTURA_B = 6;
    public const NDC_B = 8;
    public const FACTURA_C = 11;
    public const NDC_C = 13;

    protected $table = 'afip';

    public $timestamps = false;

    protected $fillable = [
        'cbteFch', 'tipoCbteNum', 'nroCbte', 'caeNum', 'caeFvt', 'docTipo', 'docNro', 'nombreRS',
        'tipoPago', 'impNeto', 'impIVA', 'descuento', 'impTotal', 'pagada', 'nroCbteAsoc',
        'codigoBarra', 'detalleOpcional', 'montoOpcional', 'concepto',
    ];

    protected function casts(): array
    {
        return [
            'impNeto' => 'float', 'impIVA' => 'float', 'descuento' => 'float', 'impTotal' => 'float',
            'montoOpcional' => 'float', 'pagada' => 'boolean', 'tipoCbteNum' => 'integer', 'docTipo' => 'integer',
        ];
    }

    public function esNotaCredito(): bool
    {
        return in_array($this->tipoCbteNum, [self::NDC_A, self::NDC_B, self::NDC_C], true);
    }

    public function letra(): string
    {
        return match ($this->tipoCbteNum) {
            self::FACTURA_A, self::NDC_A => 'A',
            self::FACTURA_B, self::NDC_B => 'B',
            default => 'C',
        };
    }

    /** Totales del libro (las notas de credito restan). Mismo calculo que control-soft. */
    public static function totales($registros): array
    {
        $imp = $neto = $iva = 0;
        foreach ($registros as $r) {
            $signo = $r->esNotaCredito() ? -1 : 1;
            $imp += $signo * $r->impTotal;
            if ($r->letra() === 'C') {
                $neto += $signo * $r->impTotal; // exento de IVA
            } else {
                $neto += $signo * $r->impNeto;
                $iva += $signo * $r->impIVA;
            }
        }

        return ['impTotal' => round($imp, 2), 'netoTotal' => round($neto, 2), 'ivaTotal' => round($iva, 2), 'comprobantes' => count($registros)];
    }
}
