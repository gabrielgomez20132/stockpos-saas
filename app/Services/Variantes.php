<?php

namespace App\Services;

use App\Models\DevolucionProducto;
use App\Models\IngresoProducto;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Variantes
{
    public function nombre(Product $base, array $variante): string
    {
        return mb_substr($base->nombre.' - '.implode(' / ', array_values($variante)), 0, 120);
    }

    /**
     * Crea las combinaciones que falten.
     *
     * @param  array<string, string[]>  $seleccion  ['Talle' => ['S','M'], 'Color' => ['Negro']]
     * @return int cantidad de variantes nuevas
     */
    public function generar(Product $base, array $seleccion, User $user): int
    {
        if ($base->id_padre) {
            throw new \DomainException('Una variante no puede tener variantes propias.');
        }
        $seleccion = array_filter(array_map(fn ($v) => array_values(array_unique(array_filter(array_map('trim', (array) $v)))), $seleccion));
        if (! $seleccion) {
            throw new \DomainException('Elegí al menos un valor (por ejemplo, un talle o un color).');
        }

        // producto cartesiano: S/Negro, S/Blanco, M/Negro...
        $combos = [[]];
        foreach ($seleccion as $atributo => $valores) {
            $nuevo = [];
            foreach ($combos as $c) {
                foreach ($valores as $v) {
                    $nuevo[] = $c + [$atributo => $v];
                }
            }
            $combos = $nuevo;
        }
        if (count($combos) > 300) {
            throw new \DomainException('Son '.count($combos).' combinaciones: elegí menos valores (máximo 300).');
        }

        return DB::transaction(function () use ($base, $combos, $user) {
            $base = Product::whereKey($base->id)->lockForUpdate()->firstOrFail();
            $stockBase = \App\Support\Stock::total($base->id);
            if (! $base->tiene_variantes && $stockBase > 0) {
                throw new \DomainException('El producto tiene '.$stockBase.' en stock (sumando todas las sucursales). Pasá ese stock a 0 (o cargalo después en cada variante) antes de crear variantes: el stock se lleva por variante.');
            }

            $existentes = $base->variantes()->get()->map(fn ($v) => $this->clave($v->variante ?? []))->all();
            $n = $base->variantes()->count();
            $creadas = 0;
            foreach ($combos as $c) {
                if (in_array($this->clave($c), $existentes, true)) {
                    continue;
                }
                do {
                    $codigo = $base->codigo.'-'.(++$n);
                } while (Product::where('codigo', $codigo)->exists());

                $v = Product::create([
                    'id_padre' => $base->id, 'variante' => $c, 'nombre' => $this->nombre($base, $c), 'codigo' => $codigo,
                    'id_categoria' => $base->id_categoria, 'costo' => $base->costo, 'monto' => $base->monto, 'aviso' => $base->aviso,
                    'pedido' => 0, 'quedan' => 0, 'archivo' => $base->archivo, 'activo' => 1,
                ]);
                IngresoProducto::create(['id_producto' => $v->id, 'id_user' => $user->id, 'cantidad' => 0, 'costo' => $v->costo, 'monto' => $v->monto]);
                $creadas++;
            }

            $base->tiene_variantes = true;
            $base->save();

            return $creadas;
        });
    }

    /** si cambia el nombre o la categoria del producto base, se actualizan sus variantes */
    public function sincronizar(Product $base, ?string $archivoAnterior = null): void
    {
        foreach ($base->variantes()->get() as $v) {
            $v->nombre = $this->nombre($base, $v->variante ?? []);
            $v->id_categoria = $base->id_categoria;
            // las variantes que usaban la foto del producto toman la nueva; las que tienen foto propia la conservan
            if ($archivoAnterior !== null && ($v->archivo === $archivoAnterior || $v->archivo === Product::SIN_IMAGEN || ! $v->archivo)) {
                $v->archivo = $base->archivo;
            }
            $v->save();
        }
    }

    /**
     * Alta de un producto con todas sus variantes de una vez ("Múltiples productos").
     *
     * @param  array{nombre:string,codigo:?string,id_categoria:int,costo:float,monto:float,archivo:string}  $datos
     * @param  array<int, array{variante:array<string,string>,codigo:?string,cantidad:float,aviso:float,archivo:?string}>  $filas
     */
    public function crearConVariantes(array $datos, array $filas, User $user): Product
    {
        if (! $filas) {
            throw new \DomainException('Agregá al menos una variante.');
        }
        $vistas = [];
        foreach ($filas as $i => $f) {
            if (! $f['variante']) {
                throw new \DomainException('La variante '.($i + 1).' no tiene ningún talle, color o tamaño elegido.');
            }
            $k = $this->clave($f['variante']);
            if (isset($vistas[$k])) {
                throw new \DomainException('Las variantes '.($vistas[$k] + 1).' y '.($i + 1).' son iguales ('.implode(' / ', $f['variante']).').');
            }
            $vistas[$k] = $i;
        }

        return DB::transaction(function () use ($datos, $filas, $user) {
            $codigo = trim((string) ($datos['codigo'] ?? ''));
            if ($codigo === '') {
                do {
                    $codigo = '2'.str_pad((string) random_int(0, 99999999999), 11, '0', STR_PAD_LEFT);
                } while (Product::where('codigo', $codigo)->exists());
            }
            $base = Product::create([
                'nombre' => $datos['nombre'], 'codigo' => $codigo, 'id_categoria' => $datos['id_categoria'],
                'costo' => $datos['costo'], 'monto' => $datos['monto'], 'aviso' => 0,
                'pedido' => 0, 'quedan' => 0, 'archivo' => $datos['archivo'], 'activo' => 1, 'tiene_variantes' => true,
            ]);
            IngresoProducto::create(['id_producto' => $base->id, 'id_user' => $user->id, 'cantidad' => 0, 'costo' => $base->costo, 'monto' => $base->monto]);

            $n = 0;
            $usados = [];
            foreach ($filas as $f) {
                $cod = trim((string) ($f['codigo'] ?? ''));
                if ($cod === '') {
                    do {
                        $cod = $base->codigo.'-'.(++$n);
                    } while (in_array($cod, $usados, true) || Product::where('codigo', $cod)->exists());
                }
                $usados[] = $cod;
                $v = Product::create([
                    'id_padre' => $base->id, 'variante' => $f['variante'], 'nombre' => $this->nombre($base, $f['variante']), 'codigo' => $cod,
                    'id_categoria' => $base->id_categoria, 'costo' => $base->costo, 'monto' => $base->monto, 'aviso' => $f['aviso'],
                    'pedido' => $f['cantidad'], 'quedan' => 0, 'archivo' => $f['archivo'] ?: $base->archivo, 'activo' => 1,
                ]);
                \App\Support\Stock::fijar($v->id, $f['cantidad']);
                IngresoProducto::create(['id_producto' => $v->id, 'id_user' => $user->id, 'cantidad' => $f['cantidad'], 'costo' => $v->costo, 'monto' => $v->monto]);
            }

            return $base;
        });
    }

    /** borra la variante; si ya se vendio, la da de baja */
    public function quitar(Product $v): string
    {
        $historia = OrderProduct::where('id_producto', $v->id)->exists() || DevolucionProducto::where('id_producto', $v->id)->exists();
        $base = $v->padre;
        if ($historia) {
            $v->update(['activo' => 0]);
            $msg = 'La variante tiene ventas: quedó dada de baja.';
        } else {
            IngresoProducto::where('id_producto', $v->id)->delete();
            $v->delete();
            $msg = 'Variante eliminada.';
        }
        if ($base && ! $base->variantes()->exists()) {
            $base->update(['tiene_variantes' => false]);
        }

        return $msg;
    }

    private function clave(array $c): string
    {
        ksort($c);

        return mb_strtolower(json_encode($c, JSON_UNESCAPED_UNICODE));
    }
}
