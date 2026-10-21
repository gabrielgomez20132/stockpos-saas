<?php

namespace App\Support;

use App\Models\Configuracion;

/**
 * Modulo Panaderia (se habilita en Configuracion):
 * - importe:    venta por importe ("dame $1.500 de pan": calcula los gramos)
 * - balanza:    lee la etiqueta de la balanza (EAN-13 con el peso o el precio adentro)
 * - produccion: lo que sale del horno y lo que sobra al cierre del dia
 *
 * Etiqueta de balanza (EAN-13, 13 digitos):  PP CCCCC VVVVV D
 *   PP = prefijo (20 a 29, se configura), C = codigo del producto (PLU),
 *   V = peso o precio, D = digito verificador. Los largos se configuran.
 */
final class Panaderia
{
    public const DEFECTO = [
        'habilitada' => false, 'importe' => true, 'balanza' => false, 'produccion' => true,
        'prefijos' => '20', 'digitos_codigo' => 5, 'tipo' => 'peso', 'decimales' => 3, 'verificar' => true,
    ];

    public static function config(): array
    {
        $c = array_merge(self::DEFECTO, array_intersect_key((array) Configuracion::get('panaderia', []), self::DEFECTO));
        $c['digitos_codigo'] = (int) $c['digitos_codigo'];
        $c['decimales'] = (int) $c['decimales'];

        return $c;
    }

    public static function habilitada(): bool
    {
        return (bool) self::config()['habilitada'];
    }

    /** opcion activa (y el modulo habilitado) */
    public static function opcion(string $o): bool
    {
        $c = self::config();

        return $c['habilitada'] && ! empty($c[$o]);
    }

    /** lo que necesita la pantalla (y la venta sin conexion) */
    public static function paraPantalla(): array
    {
        $c = self::config();

        return [
            'importe' => self::opcion('importe'), 'produccion' => self::opcion('produccion'), 'balanza' => self::opcion('balanza'),
            'etiqueta' => self::opcion('balanza') ? array_intersect_key($c, array_flip(['prefijos', 'digitos_codigo', 'tipo', 'decimales', 'verificar'])) : null,
        ];
    }

    public static function prefijos(array $c): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $c['prefijos'])), fn ($p) => preg_match('/^\d{1,3}$/', $p)));
    }

    public static function digitoOk(string $ean): bool
    {
        $s = 0;
        for ($i = 0; $i < 12; $i++) {
            $s += (int) $ean[$i] * ($i % 2 ? 3 : 1);
        }

        return (10 - $s % 10) % 10 === (int) $ean[12];
    }

    /**
     * Lee una etiqueta de balanza. Devuelve null si no es una etiqueta (asi se busca como codigo comun).
     *
     * @return array{plu:string, tipo:string, valor:float}|null
     */
    public static function leer(string $codigo, ?array $c = null): ?array
    {
        $c ??= self::config();
        $codigo = trim($codigo);
        if (! preg_match('/^\d{13}$/', $codigo)) {
            return null;
        }
        $pref = null;
        foreach (self::prefijos($c) as $p) {
            if (str_starts_with($codigo, $p)) {
                $pref = $p;
                break;
            }
        }
        if ($pref === null) {
            return null;
        }
        if ($c['verificar'] && ! self::digitoOk($codigo)) {
            throw new \DomainException('La etiqueta de la balanza no se leyó bien (dígito verificador). Volvé a escanearla.');
        }
        $largoValor = 12 - strlen($pref) - $c['digitos_codigo'];
        if ($c['digitos_codigo'] < 1 || $largoValor < 3) {
            throw new \DomainException('La configuración de la balanza no cierra: revisá los dígitos del código en Configuración.');
        }
        $plu = substr($codigo, strlen($pref), $c['digitos_codigo']);
        $valor = (int) substr($codigo, strlen($pref) + $c['digitos_codigo'], $largoValor) / (10 ** $c['decimales']);

        return ['plu' => $plu, 'tipo' => $c['tipo'], 'valor' => round($valor, 3)];
    }

    /** codigos con los que se puede haber cargado el producto (con o sin ceros adelante) */
    public static function codigosPlu(string $plu): array
    {
        $sin = ltrim($plu, '0');

        return array_values(array_unique(array_filter([$plu, $sin === '' ? '0' : $sin])));
    }

    /** cantidad por importe: el peso que corresponde a esa plata, al gramo */
    public static function cantidadPorImporte(float $importe, float $precio): float
    {
        if ($precio <= 0) {
            throw new \DomainException('El producto no tiene precio: no se puede vender por importe.');
        }

        return round($importe / $precio, 3);
    }
}
