<?php

namespace App\Support;

use App\Models\Product;
use App\Models\StockSucursal;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stock por sucursal. La sucursal 1 usa products.quedan (como siempre); las
 * demas, la tabla stock_sucursal. Todo movimiento de stock pasa por aca.
 */
class Stock
{
    private static function sid(?int $sid): int
    {
        return $sid ?? SucursalActual::id();
    }

    /** stock de un producto en una sucursal (con $bloquear, dentro de una transaccion) */
    public static function de(int $idProducto, ?int $sid = null, bool $bloquear = false): float
    {
        $sid = self::sid($sid);
        if ($sid === SucursalActual::CENTRAL) {
            $q = Product::whereKey($idProducto);

            return (float) ($bloquear ? $q->lockForUpdate() : $q)->value('quedan');
        }
        $q = StockSucursal::where('id_producto', $idProducto)->where('id_sucursal', $sid);

        return (float) ($bloquear ? $q->lockForUpdate() : $q)->value('quedan');
    }

    public static function sumar(int $idProducto, float $delta, ?int $sid = null): void
    {
        $delta = round($delta, 3);
        if (abs($delta) < 1e-9) {
            return;
        }
        $sid = self::sid($sid);
        if ($sid === SucursalActual::CENTRAL) {
            Product::whereKey($idProducto)->update(['quedan' => DB::raw('CASE WHEN quedan + ('.$delta.') < 0 THEN 0 ELSE quedan + ('.$delta.') END')]);

            return;
        }
        $fila = StockSucursal::where('id_producto', $idProducto)->where('id_sucursal', $sid)->lockForUpdate()->first();
        if ($fila) {
            $fila->quedan = max(0, round($fila->quedan + $delta, 3));
            $fila->save();
        } else {
            StockSucursal::create(['id_producto' => $idProducto, 'id_sucursal' => $sid, 'quedan' => max(0, $delta)]);
        }
    }

    public static function fijar(int $idProducto, float $valor, ?int $sid = null): void
    {
        $valor = max(0, round($valor, 3));
        $sid = self::sid($sid);
        if ($sid === SucursalActual::CENTRAL) {
            Product::whereKey($idProducto)->update(['quedan' => $valor]);

            return;
        }
        StockSucursal::updateOrCreate(['id_producto' => $idProducto, 'id_sucursal' => $sid], ['quedan' => $valor]);
    }

    /** expresion SQL del stock de la sucursal, para usar con conStock() */
    public static function columna(?int $sid = null): string
    {
        return self::sid($sid) === SucursalActual::CENTRAL ? 'products.quedan' : 'COALESCE(ss.quedan, 0)';
    }

    /** la consulta de productos trae "quedan" = stock de la sucursal */
    public static function conStock(Builder $q, ?int $sid = null): Builder
    {
        $sid = self::sid($sid);
        if ($sid === SucursalActual::CENTRAL) {
            return $q;
        }

        // se conservan las columnas ya pedidas (ej. withCount), cambiando "*" por "products.*"
        $cols = collect($q->getQuery()->columns ?? [])->map(fn ($c) => $c === '*' ? 'products.*' : $c);
        if (! $cols->contains('products.*')) {
            $cols->prepend('products.*');
        }

        return $q->leftJoin('stock_sucursal as ss', function ($j) use ($sid) {
            $j->on('ss.id_producto', '=', 'products.id')->where('ss.id_sucursal', '=', $sid);
        })->select([...$cols->all(), DB::raw('COALESCE(ss.quedan, 0) as quedan')]);
    }

    /**
     * Pone en memoria el stock de la sucursal en cada producto (para mostrar).
     * Esos productos quedan marcados y no se pueden guardar con ese stock.
     */
    public static function cargar(iterable $productos, ?int $sid = null): void
    {
        $sid = self::sid($sid);
        if ($sid === SucursalActual::CENTRAL) {
            return;
        }
        $lista = collect($productos)->filter();
        if ($lista->isEmpty()) {
            return;
        }
        $stock = StockSucursal::where('id_sucursal', $sid)->whereIn('id_producto', $lista->pluck('id'))->pluck('quedan', 'id_producto');
        foreach ($lista as $p) {
            $p->setAttribute('quedan', (float) ($stock[$p->id] ?? 0));
            $p->syncOriginalAttribute('quedan');
            $p->stockDeSucursal = true;
        }
    }

    /** suma del stock de las variantes de cada producto base, en la sucursal */
    public static function deVariantes(array $idsBase, ?int $sid = null): array
    {
        if (! $idsBase) {
            return [];
        }
        $sid = self::sid($sid);
        $q = DB::table('products')->whereIn('products.id_padre', $idsBase)->where('products.activo', 1);
        if ($sid !== SucursalActual::CENTRAL) {
            $q->leftJoin('stock_sucursal as ss', fn ($j) => $j->on('ss.id_producto', '=', 'products.id')->where('ss.id_sucursal', '=', $sid));
        }

        return $q->groupBy('products.id_padre')->selectRaw('products.id_padre, SUM('.self::columna($sid).') as total')
            ->pluck('total', 'id_padre')->map(fn ($v) => round((float) $v, 3))->all();
    }

    /** stock en cada sucursal activa: [['id','nombre','quedan'], ...] */
    public static function porSucursal(int $idProducto): array
    {
        $otras = StockSucursal::where('id_producto', $idProducto)->pluck('quedan', 'id_sucursal');

        return Sucursal::where('activa', 1)->orderBy('id')->get(['id', 'nombre'])->map(fn ($s) => [
            'id' => $s->id, 'nombre' => $s->nombre,
            'quedan' => $s->id === SucursalActual::CENTRAL ? (float) Product::whereKey($idProducto)->value('quedan') : (float) ($otras[$s->id] ?? 0),
        ])->all();
    }

    public static function total(int $idProducto): float
    {
        return round((float) Product::whereKey($idProducto)->value('quedan')
            + (float) StockSucursal::where('id_producto', $idProducto)->sum('quedan'), 3);
    }
}
