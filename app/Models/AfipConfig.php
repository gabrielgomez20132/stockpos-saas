<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Configuracion fiscal y credenciales de AFIP (ARCA).
 *
 * Unica fuente de CUIT, punto de venta, ambiente, datos del emisor y ubicacion
 * del certificado / clave privada. Las credenciales viven en
 * storage/app/private/afip (fuera de public/, nunca se sirven por web).
 */
class AfipConfig extends Model
{
    public const AMBIENTES = ['homologacion', 'produccion'];

    protected $table = 'afip_config';

    protected $fillable = [
        'ambiente', 'cuit', 'punto_venta', 'razon_social', 'domicilio', 'localidad',
        'condicion_iva', 'iibb', 'inicio_actividades',
    ];

    protected $hidden = ['passphrase_produccion', 'passphrase_homologacion'];

    protected function casts(): array
    {
        return ['punto_venta' => 'integer', 'inicio_actividades' => 'date'];
    }

    public static function actual(): self
    {
        return static::first() ?: new static(['ambiente' => 'homologacion', 'punto_venta' => 1]);
    }

    public static function obtener(): self
    {
        return static::first() ?: static::create(['ambiente' => 'homologacion', 'punto_venta' => 1]);
    }

    // ------------------------------------------------------------ rutas
    public static function dir(): string
    {
        return storage_path('app/private/afip');
    }

    public static function asegurarDir(): void
    {
        if (! is_dir(self::dir())) {
            mkdir(self::dir(), 0770, true);
        }
    }

    private function amb(?string $ambiente = null): string
    {
        $a = $ambiente ?: $this->ambiente;

        return in_array($a, self::AMBIENTES, true) ? $a : 'homologacion';
    }

    public function rutaCert(?string $ambiente = null): string
    {
        return self::dir().'/'.$this->amb($ambiente).'.crt';
    }

    public function rutaKey(?string $ambiente = null): string
    {
        return self::dir().'/'.$this->amb($ambiente).'.key';
    }

    /** Ticket de acceso de WSAA, uno por ambiente. */
    public function rutaTA(?string $ambiente = null): string
    {
        return self::dir().'/TA-'.$this->amb($ambiente).'.xml';
    }

    public function esProduccion(): bool
    {
        return $this->amb() === 'produccion';
    }

    // -------------------------------------------------- endpoints de AFIP
    public function wsaaUrl(): string
    {
        return $this->esProduccion()
            ? 'https://wsaa.afip.gov.ar/ws/services/LoginCms'
            : 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';
    }

    public function wsfeUrl(): string
    {
        return $this->esProduccion()
            ? 'https://servicios1.afip.gov.ar/wsfev1/service.asmx'
            : 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx';
    }

    public function wsaaWsdl(): string
    {
        return resource_path('afip/'.($this->esProduccion() ? 'wsaaProd.wsdl' : 'wsaa.wsdl'));
    }

    public function wsfeWsdl(): string
    {
        return resource_path('afip/'.($this->esProduccion() ? 'wsfev1Prod.wsdl' : 'wsfev1.wsdl'));
    }

    // ------------------------------------------------------- passphrase
    public function passphrase(?string $ambiente = null): string
    {
        $valor = $this->{'passphrase_'.$this->amb($ambiente)};
        if (! $valor) {
            return '';
        }
        try {
            return Crypt::decryptString($valor);
        } catch (\Throwable) {
            try {
                return (string) Crypt::decrypt($valor); // formato de control-soft
            } catch (\Throwable) {
                return '';
            }
        }
    }

    public function setPassphrase(string $ambiente, ?string $texto): void
    {
        $this->{'passphrase_'.$this->amb($ambiente)} = ($texto === null || $texto === '') ? null : Crypt::encryptString($texto);
    }

    // ---------------------------------------------------------- formatos
    public function cuitDigitos(): string
    {
        return preg_replace('/\D/', '', (string) $this->cuit);
    }

    public function cuitFormateado(): string
    {
        $c = $this->cuitDigitos();

        return strlen($c) === 11 ? substr($c, 0, 2).'-'.substr($c, 2, 8).'-'.substr($c, 10) : $c;
    }

    public function esMonotributo(): bool
    {
        return stripos((string) $this->condicion_iva, 'monotrib') !== false;
    }

    // ------------------------------------------------------ diagnostico
    /** Revisa certificado y clave de un ambiente sin exponer su contenido. */
    public function estado(?string $ambiente = null): array
    {
        $amb = $this->amb($ambiente);
        $e = [
            'ambiente' => $amb, 'hay_cert' => is_file($this->rutaCert($amb)), 'hay_key' => is_file($this->rutaKey($amb)),
            'cert_ok' => false, 'key_ok' => false, 'coinciden' => false, 'vence' => null, 'dias' => null,
            'vencido' => false, 'cuit_cert' => null, 'titular' => null, 'problemas' => [],
        ];

        $x509 = null;
        $pkey = null;

        if ($e['hay_cert']) {
            $x509 = @openssl_x509_read((string) file_get_contents($this->rutaCert($amb)));
            if ($x509) {
                $info = openssl_x509_parse($x509);
                $e['cert_ok'] = true;
                $e['vence'] = date('Y-m-d', $info['validTo_time_t']);
                $e['dias'] = (int) floor(($info['validTo_time_t'] - time()) / 86400);
                $e['vencido'] = $info['validTo_time_t'] < time();
                $e['titular'] = $info['subject']['CN'] ?? null;
                if (preg_match('/(\d{11})/', (string) ($info['subject']['serialNumber'] ?? ''), $m)) {
                    $e['cuit_cert'] = $m[1];
                }
            } else {
                $e['problemas'][] = 'El certificado no se puede leer (¿es el .crt que descargaste de AFIP?).';
            }
        } else {
            $e['problemas'][] = 'Falta el certificado.';
        }

        if ($e['hay_key']) {
            $pkey = @openssl_pkey_get_private((string) file_get_contents($this->rutaKey($amb)), $this->passphrase($amb));
            if ($pkey) {
                $e['key_ok'] = true;
            } else {
                $e['problemas'][] = 'La clave privada no se puede abrir (archivo incorrecto o contraseña equivocada).';
            }
        } else {
            $e['problemas'][] = 'Falta la clave privada.';
        }

        if ($x509 && $pkey) {
            $e['coinciden'] = openssl_x509_check_private_key($x509, $pkey);
            if (! $e['coinciden']) {
                $e['problemas'][] = 'El certificado y la clave no son pareja: el certificado se generó con otra clave privada.';
            }
        }

        if ($e['vencido']) {
            $e['problemas'][] = 'El certificado está vencido.';
        }

        $cuit = $this->cuitDigitos();
        if ($e['cuit_cert'] && $cuit && $e['cuit_cert'] !== $cuit) {
            $e['problemas'][] = 'El certificado es del CUIT '.$e['cuit_cert'].', pero el CUIT configurado es '.$cuit.'.';
        }

        $e['ok'] = empty($e['problemas']);

        return $e;
    }

    public function datosCompletos(): bool
    {
        return strlen($this->cuitDigitos()) === 11 && $this->punto_venta > 0 && trim((string) $this->razon_social) !== '';
    }

    /** ¿Se puede facturar ya, en el ambiente elegido? */
    public function listo(): bool
    {
        return $this->exists && $this->datosCompletos() && $this->estado()['ok'] && extension_loaded('soap');
    }
}
