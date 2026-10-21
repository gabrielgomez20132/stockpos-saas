<?php

namespace App\Services;

use App\Models\IngresoProducto;
use App\Models\ListaPrecio;
use App\Models\ListaPrecioItem;
use App\Models\Product;
use App\Models\ProductoProveedor;
use App\Models\User;
use App\Support\Dinero;
use App\Support\LectorPlanilla;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Listas de precios de proveedores:
 *  1. se sube el archivo del proveedor,
 *  2. se eligen las columnas (codigo, descripcion, costo) y como calcular,
 *  3. se revisa que cambia (sube / baja / sin vincular) y
 *  4. se aplican los costos (y precios de venta) elegidos.
 */
class ListasPrecios
{
    public const MAX_FILAS = 10000;

    public const POLITICAS = ['margen', 'fijo', 'no'];

    public function ruta(ListaPrecio $l): string
    {
        return Storage::disk('local')->path('listas/'.($l->opciones['archivo_interno'] ?? ''));
    }

    public function filas(ListaPrecio $l): array
    {
        $ruta = $this->ruta($l);
        if (! is_file($ruta)) {
            throw new \DomainException('El archivo de esta lista ya no está. Volvé a subirlo.');
        }

        return LectorPlanilla::leer($ruta, $l->opciones['ext'] ?? 'xlsx', self::MAX_FILAS + 30);
    }

    /** fila de titulos y columnas probables */
    public function sugerir(array $filas): array
    {
        $palabras = [
            'codigo' => ['codigo', 'cod', 'articulo', 'art', 'sku', 'ean', 'código', 'item', 'ref', 'referencia'],
            'descripcion' => ['descripcion', 'detalle', 'producto', 'nombre', 'articulo'],
            'costo' => ['precio', 'costo', 'lista', 'p. lista', 'importe', 'neto', 'valor', 'unitario'],
        ];
        $norm = fn ($s) => trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii((string) $s))));
        $mejor = ['fila' => 0, 'puntos' => -1];
        foreach (array_slice($filas, 0, 20) as $i => $f) {
            $puntos = 0;
            foreach ($f as $c) {
                $c = $norm($c);
                foreach ($palabras as $lista) {
                    foreach ($lista as $p) {
                        if ($c !== '' && str_contains($c, $p)) {
                            $puntos++;
                            break 2;
                        }
                    }
                }
            }
            if ($puntos > $mejor['puntos']) {
                $mejor = ['fila' => $i, 'puntos' => $puntos];
            }
        }
        $cab = array_map($norm, $filas[$mejor['fila']] ?? []);
        $col = function (array $ps, array $usadas = []) use ($cab) {
            foreach ($ps as $p) {
                foreach ($cab as $i => $c) {
                    if ($c !== '' && ! in_array($i, $usadas, true) && str_contains($c, $p)) {
                        return $i;
                    }
                }
            }

            return null;
        };
        $codigo = $col($palabras['codigo']);
        $desc = $col($palabras['descripcion'], array_filter([$codigo], fn ($x) => $x !== null));
        $costo = $col($palabras['costo'], array_filter([$codigo, $desc], fn ($x) => $x !== null));

        return ['fila_titulos' => $mejor['puntos'] > 0 ? $mejor['fila'] : null, 'codigo' => $codigo, 'descripcion' => $desc, 'costo' => $costo];
    }

    /**
     * Lee el archivo con las opciones elegidas, vincula cada codigo con un
     * producto y calcula costo y precio nuevos. Reemplaza los renglones anteriores.
     */
    public function procesar(ListaPrecio $l, array $op): void
    {
        $filas = $this->filas($l);
        $desde = $op['fila_titulos'] === null ? 0 : $op['fila_titulos'] + 1;
        $datos = array_slice($filas, $desde, null, true);
        if (count($datos) > self::MAX_FILAS) {
            throw new \DomainException('La lista tiene más de '.self::MAX_FILAS.' renglones.');
        }

        $renglones = [];
        $vistos = [];
        foreach ($datos as $n => $f) {
            $codigo = trim((string) ($f[$op['codigo']] ?? ''));
            $codigo = preg_match('/^\d+\.0+$/', $codigo) ? explode('.', $codigo)[0] : $codigo;
            $costo = Dinero::parse(str_replace(['$', ' ', "\u{a0}"], '', (string) ($f[$op['costo']] ?? '')));
            if ($codigo === '' || $costo === null || $costo < 0 || mb_strlen($codigo) > 100 || isset($vistos[$codigo])) {
                continue; // titulos de rubro, renglones vacios, repetidos
            }
            $vistos[$codigo] = true;
            $renglones[] = [
                'fila' => $n + 1, 'codigo' => $codigo,
                'descripcion' => $op['descripcion'] !== null ? mb_substr(trim((string) ($f[$op['descripcion']] ?? '')), 0, 200) : null,
                'costo_lista' => round($costo, 2),
            ];
        }
        if (! $renglones) {
            throw new \DomainException('No encontré renglones con código y precio en esas columnas. Revisá la fila de títulos y las columnas elegidas.');
        }

        DB::transaction(function () use ($l, $op, $renglones) {
            $l->items()->delete();
            $vinculos = ProductoProveedor::where('id_proveedor', $l->id_proveedor)->whereIn('codigo_proveedor', array_column($renglones, 'codigo'))
                ->pluck('id_producto', 'codigo_proveedor');
            $porCodigo = Product::whereIn('codigo', array_column($renglones, 'codigo'))->whereNull('id_padre')->pluck('id', 'codigo');
            $productos = Product::whereIn('id', $vinculos->values()->merge($porCodigo->values())->unique())->get()->keyBy('id');

            foreach (array_chunk($renglones, 500) as $parte) {
                $insert = [];
                foreach ($parte as $r) {
                    $pid = $vinculos[$r['codigo']] ?? $porCodigo[$r['codigo']] ?? null;
                    $insert[] = $this->calcular($r, $pid ? ($productos[$pid] ?? null) : null, $op) + ['lista_id' => $l->id, 'aplicado' => false];
                }
                ListaPrecioItem::insert($insert);
            }
            $l->opciones = array_merge($l->opciones ?? [], $op);
            $l->estado = 'pendiente';
            $this->resumir($l);
        });
    }

    /** costo nuevo (con bonificacion e IVA) y precio nuevo segun la politica */
    public function calcular(array $r, ?Product $p, array $op): array
    {
        $costo = round($r['costo_lista'] * (1 - ($op['descuento'] ?? 0) / 100) * (1 + ($op['iva'] ?? 0) / 100), 2);
        $base = ['fila' => $r['fila'], 'codigo' => $r['codigo'], 'descripcion' => $r['descripcion'], 'costo_lista' => $r['costo_lista'],
            'costo_nuevo' => $costo, 'id_producto' => null, 'costo_anterior' => null, 'precio_anterior' => null, 'precio_nuevo' => null, 'aplicar' => false];
        if (! $p) {
            return $base;
        }
        $precio = $this->precio($p->costo, $p->monto, $costo, $op);

        return array_merge($base, [
            'id_producto' => $p->id, 'costo_anterior' => $p->costo, 'precio_anterior' => $p->monto, 'precio_nuevo' => $precio,
            'aplicar' => abs($p->costo - $costo) >= 0.01 || abs($p->monto - $precio) >= 0.01,
        ]);
    }

    public function precio(float $costoAnt, float $precioAnt, float $costoNuevo, array $op): float
    {
        $precio = match ($op['politica'] ?? 'margen') {
            'no' => $precioAnt,
            'fijo' => $costoNuevo * (1 + ($op['margen'] ?? 0) / 100),
            default => $costoAnt > 0 ? $precioAnt * $costoNuevo / $costoAnt : $precioAnt,
        };
        if (($op['politica'] ?? 'margen') === 'no') {
            return round($precio, 2);
        }
        $r = max(1, (int) ($op['redondeo'] ?? 1));

        return (float) (ceil(round($precio / $r, 6)) * $r);
    }

    /** vincula a mano un renglon con un producto (y recuerda el codigo del proveedor) */
    public function vincular(ListaPrecioItem $item, ?Product $p): ListaPrecioItem
    {
        $l = $item->lista;
        $item->fill($this->calcular($item->only(['fila', 'codigo', 'descripcion', 'costo_lista']), $p, $l->opciones ?? []))->save();
        if ($p) {
            ProductoProveedor::updateOrCreate(['id_proveedor' => $l->id_proveedor, 'codigo_proveedor' => $item->codigo], ['id_producto' => $p->id]);
        }
        $this->resumir($l);

        return $item->fresh('producto');
    }

    /** aplica los renglones elegidos: costo y precio de venta (a las variantes tambien) */
    public function aplicar(ListaPrecio $l, array $ids, User $user): int
    {
        if ($l->estado !== 'pendiente') {
            throw new \DomainException('Esta lista ya fue '.($l->estado === 'aplicada' ? 'aplicada' : 'descartada').'.');
        }

        return DB::transaction(function () use ($l, $ids, $user) {
            $l = ListaPrecio::whereKey($l->id)->lockForUpdate()->firstOrFail();
            $items = $l->items()->whereIn('id', $ids)->whereNotNull('id_producto')->get();
            $n = 0;
            foreach ($items as $it) {
                $p = Product::lockForUpdate()->find($it->id_producto);
                if (! $p) {
                    continue;
                }
                $destinos = $p->tiene_variantes ? $p->variantes()->get()->prepend($p) : collect([$p]);
                foreach ($destinos as $d) {
                    $precio = $d->id === $p->id ? $it->precio_nuevo : $this->precio($d->costo, $d->monto, $it->costo_nuevo, $l->opciones ?? []);
                    $d->costo = $it->costo_nuevo;
                    $d->monto = $precio;
                    if ($d->isDirty(['costo', 'monto'])) {
                        $d->save();
                        IngresoProducto::create(['id_producto' => $d->id, 'id_user' => $user->id, 'cantidad' => 0, 'costo' => $d->costo, 'monto' => $d->monto]);
                    }
                }
                ProductoProveedor::updateOrCreate(['id_proveedor' => $l->id_proveedor, 'codigo_proveedor' => $it->codigo], ['id_producto' => $p->id]);
                $it->update(['aplicado' => true, 'aplicar' => true]);
                $n++;
            }
            $l->items()->whereNotIn('id', $items->pluck('id'))->update(['aplicar' => false]);
            $l->estado = 'aplicada';
            $l->aplicados = $n;
            $l->aplicada_at = now();
            $l->save();
            $this->borrarArchivo($l);

            return $n;
        });
    }

    public function descartar(ListaPrecio $l): void
    {
        if ($l->estado === 'aplicada') {
            throw new \DomainException('Una lista aplicada no se puede descartar.');
        }
        $l->update(['estado' => 'descartada']);
        $this->borrarArchivo($l);
    }

    public function borrarArchivo(ListaPrecio $l): void
    {
        $f = $l->opciones['archivo_interno'] ?? null;
        if ($f && basename($f) === $f) {
            Storage::disk('local')->delete('listas/'.$f);
        }
    }

    private function resumir(ListaPrecio $l): void
    {
        $q = $l->items();
        $l->filas = (clone $q)->count();
        $l->vinculados = (clone $q)->whereNotNull('id_producto')->count();
        $var = (clone $q)->whereNotNull('id_producto')->where('costo_anterior', '>', 0)
            ->selectRaw('AVG((costo_nuevo - costo_anterior) / costo_anterior * 100) as v')->value('v');
        $l->variacion_promedio = $var === null ? null : round((float) $var, 2);
        $l->save();
    }
}
