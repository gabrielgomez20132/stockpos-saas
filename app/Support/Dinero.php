<?php

namespace App\Support;

final class Dinero
{
    /** Normaliza "1.234,50", "1234.5" o 1234.5 a float. Devuelve null si no es un numero. */
    public static function parse(mixed $valor): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }
        $s = trim((string) $valor);
        if (str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    public static function formato(float $v, int $decimales = 0): string
    {
        if ($decimales === 0 && abs($v - round($v)) >= 0.005) {
            $decimales = 2;
        }

        return number_format($v, $decimales, ',', '.');
    }

    public static function r(float $v): float
    {
        return round($v, 2);
    }
}
