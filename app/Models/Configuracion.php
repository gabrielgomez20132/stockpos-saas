<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** Ajustes generales del sistema (clave / valor). */
class Configuracion extends Model
{
    protected $table = 'configuracion';

    protected $primaryKey = 'clave';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['clave', 'valor'];

    private static array $cache = [];

    public static function get(string $clave, mixed $defecto = null): mixed
    {
        if (! array_key_exists($clave, self::$cache)) {
            try {
                $v = Schema::hasTable('configuracion') ? static::find($clave)?->valor : null;
            } catch (\Throwable) {
                $v = null;
            }
            self::$cache[$clave] = $v === null ? null : json_decode($v, true);
        }

        return self::$cache[$clave] ?? $defecto;
    }

    public static function set(string $clave, mixed $valor): void
    {
        static::updateOrCreate(['clave' => $clave], ['valor' => json_encode($valor)]);
        self::$cache[$clave] = $valor;
    }

    public static function variantes(): bool
    {
        return (bool) static::get('variantes_habilitadas', false);
    }

    /** combos y promos: si nunca se tocó el interruptor, quedan habilitados solo si ya hay combos cargados */
    public static function combos(): bool
    {
        $v = static::get('combos_habilitadas');

        return $v === null ? Combo::query()->exists() : (bool) $v;
    }

    /** facturacion AFIP: si nunca se toco el interruptor, queda habilitada si ya se uso (comprobantes o CUIT cargado) */
    public static function afip(): bool
    {
        $v = static::get('afip_habilitada');
        if ($v !== null) {
            return (bool) $v;
        }
        try {
            return Afip::query()->exists() || AfipConfig::query()->whereNotNull('cuit')->where('cuit', '!=', '')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function limpiarCache(): void
    {
        self::$cache = [];
    }
}
