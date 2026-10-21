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

    public static function limpiarCache(): void
    {
        self::$cache = [];
    }
}
