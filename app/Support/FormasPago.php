<?php

namespace App\Support;

/**
 * Formas de pago. Los ids son los de la tabla formas_pago de control-soft (no
 * se pueden cambiar: las ventas viejas los referencian). Aca se traducen a
 * "medios" simples, asi la pantalla deja elegir uno o dos medios y el servidor
 * busca la combinacion, en lugar de una lista de 32 opciones.
 */
final class FormasPago
{
    public const EFECTIVO = 1;
    public const FIADO = 4;
    public const VALE = 28;

    /** medio => columna de orders */
    public const COLUMNAS = [
        'efectivo' => 'pago_efec',
        'debito' => 'pago_tarj_debito',
        'credito' => 'pago_tarj_cred',
        'qr' => 'pago_qr',
        'transferencia' => 'pago_transf',
        'cheque' => 'pago_cheque',
        'dolares' => 'pago_dolares',
        'vale' => 'pago_vale',
    ];

    public const ETIQUETAS = [
        'efectivo' => 'Efectivo',
        'debito' => 'Tarjeta débito',
        'credito' => 'Tarjeta crédito',
        'qr' => 'QR',
        'transferencia' => 'Transferencia',
        'cheque' => 'Cheque',
        'dolares' => 'Dólares',
        'vale' => 'Vale',
        'fiado' => 'Cuenta corriente',
    ];

    /** id => medios, en el orden del nombre de la forma. */
    public const MAPA = [
        1 => ['efectivo'], 2 => ['debito'], 3 => ['efectivo', 'debito'], 4 => ['fiado'],
        5 => ['transferencia'], 6 => ['cheque'], 7 => ['dolares'], 8 => ['efectivo', 'transferencia'],
        9 => ['efectivo', 'cheque'], 10 => ['efectivo', 'dolares'], 11 => ['transferencia', 'debito'],
        12 => ['transferencia', 'cheque'], 13 => ['transferencia', 'dolares'], 14 => ['debito', 'cheque'],
        15 => ['debito', 'dolares'], 16 => ['cheque', 'dolares'], 17 => ['credito'],
        18 => ['credito', 'cheque'], 19 => ['credito', 'debito'], 20 => ['credito', 'transferencia'],
        21 => ['credito', 'efectivo'], 22 => ['qr'], 23 => ['qr', 'efectivo'], 24 => ['qr', 'debito'],
        25 => ['qr', 'credito'], 26 => ['qr', 'transferencia'], 27 => ['qr', 'cheque'],
        28 => ['vale'], 29 => ['efectivo', 'vale'], 30 => ['debito', 'vale'],
        31 => ['transferencia', 'vale'], 32 => ['qr', 'vale'],
    ];

    /**
     * Medios que se ofrecen al cobrar. Dolares quedo fuera por decision del
     * cliente (control-soft ocultaba las formas 7, 10, 13, 15 y 16).
     */
    public const MEDIOS_VISIBLES = ['efectivo', 'debito', 'credito', 'qr', 'transferencia', 'cheque'];

    public static function medios(int $id): array
    {
        return self::MAPA[$id] ?? [];
    }

    /** Busca la forma de pago para uno o dos medios (en cualquier orden). */
    public static function buscar(array $medios): ?int
    {
        $medios = array_values(array_unique(array_filter($medios)));
        sort($medios);
        foreach (self::MAPA as $id => $m) {
            $s = $m;
            sort($s);
            if ($s === $medios) {
                return $id;
            }
        }

        return null;
    }

    public static function usaVale(int $id): bool
    {
        return in_array('vale', self::medios($id), true);
    }

    /** Lista para la pantalla de cobro. */
    public static function paraPantalla(): array
    {
        return array_map(fn ($m) => ['id' => $m, 'nombre' => self::ETIQUETAS[$m]], self::MEDIOS_VISIBLES);
    }

    /** "Efectivo $1.000 + Débito $500" a partir de una orden. */
    public static function detalle(object $order): string
    {
        $partes = [];
        foreach (self::COLUMNAS as $medio => $col) {
            $v = (float) ($order->{$col} ?? 0);
            if ($v > 0) {
                $partes[] = self::ETIQUETAS[$medio].' $'.Dinero::formato($v);
            }
        }
        if ((float) ($order->fiado ?? 0) > 0) {
            $partes[] = 'Cuenta corriente $'.Dinero::formato((float) $order->fiado);
        }

        return implode(' + ', $partes);
    }
}
