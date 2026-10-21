<?php

namespace App\Services;

use App\Models\IngresoProducto;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Carga masiva de productos desde Excel (.xlsx) o CSV.
 *
 * - La primera fila son los titulos (se aceptan con o sin tildes, en cualquier orden).
 * - Si el codigo ya existe se ACTUALIZA el producto; si no, se CREA.
 * - La categoria se busca por nombre; si no existe se crea (unidad Uds o Kg).
 * - El stock de productos existentes solo se pisa si se pide expresamente.
 * - Todo o nada: si una fila tiene error no se guarda ninguna.
 */
class ImportadorProductos
{
    public const MAX_FILAS = 5000;

    /** titulo normalizado => campo */
    private const ALIAS = [
        'codigo' => 'codigo', 'codigo de barras' => 'codigo', 'cod' => 'codigo', 'ean' => 'codigo',
        'nombre' => 'nombre', 'producto' => 'nombre', 'descripcion' => 'nombre',
        'categoria' => 'categoria', 'rubro' => 'categoria',
        'unidad' => 'unidad', 'se vende por' => 'unidad',
        'costo' => 'costo', 'precio de costo' => 'costo',
        'precio' => 'monto', 'precio de venta' => 'monto', 'venta' => 'monto', 'monto' => 'monto',
        'stock' => 'stock', 'cantidad' => 'stock', 'quedan' => 'stock', 'stock actual' => 'stock',
        'aviso' => 'aviso', 'stock minimo' => 'aviso', 'minimo' => 'aviso', 'avisar con' => 'aviso',
        'activo' => 'activo',
    ];

    public const CABECERA = ['Código', 'Nombre', 'Categoría', 'Unidad', 'Costo', 'Precio de venta', 'Stock', 'Stock mínimo', 'Activo'];

    /** @return array{creados:int, actualizados:int, categorias:int, errores:array<int,string>} */
    public function importar(string $ruta, string $extension, User $user, bool $actualizarStock): array
    {
        $filas = $this->leer($ruta, $extension);
        if (count($filas) < 2) {
            throw new \DomainException('El archivo está vacío o solo tiene los títulos.');
        }

        $mapa = [];
        foreach ($filas[0] as $i => $titulo) {
            $k = $this->normalizar((string) $titulo);
            if (isset(self::ALIAS[$k])) {
                $mapa[self::ALIAS[$k]] = $i;
            }
        }
        foreach (['codigo', 'nombre', 'monto'] as $obligatorio) {
            if (! isset($mapa[$obligatorio])) {
                throw new \DomainException('Falta la columna "'.['codigo' => 'Código', 'nombre' => 'Nombre', 'monto' => 'Precio de venta'][$obligatorio].'". Descargá la plantilla para ver el formato.');
            }
        }

        $datos = array_slice($filas, 1);
        if (count($datos) > self::MAX_FILAS) {
            throw new \DomainException('El archivo tiene más de '.self::MAX_FILAS.' productos. Dividilo en partes.');
        }

        // validar todo antes de tocar la base
        $errores = [];
        $validas = [];
        $vistos = [];
        foreach ($datos as $n => $fila) {
            $nroFila = $n + 2;
            $v = fn (string $campo) => isset($mapa[$campo]) ? trim((string) ($fila[$mapa[$campo]] ?? '')) : '';
            if (implode('', array_map(fn ($c) => trim((string) $c), $fila)) === '') {
                continue; // fila vacia
            }

            $codigo = $v('codigo');
            $codigo = preg_match('/^\d+\.0+$/', $codigo) ? explode('.', $codigo)[0] : $codigo; // 7790895000997.0 de Excel
            $nombre = $v('nombre');
            $e = [];
            if ($codigo === '' || mb_strlen($codigo) > 100) {
                $e[] = 'código vacío o demasiado largo';
            } elseif (isset($vistos[$codigo])) {
                $e[] = 'código repetido (ya está en la fila '.$vistos[$codigo].')';
            }
            if ($nombre === '' || mb_strlen($nombre) > 55) {
                $e[] = 'nombre vacío o de más de 55 letras';
            }

            $num = function (string $campo, bool $obligatorio = false) use ($v, &$e) {
                $s = $v($campo);
                if ($s === '') {
                    if ($obligatorio) {
                        $e[] = $campo === 'monto' ? 'falta el precio de venta' : 'falta '.$campo;
                    }

                    return null;
                }
                $n = Dinero::parse(str_replace(['$', ' '], '', $s));
                if ($n === null || $n < 0 || $n > 9999999999) {
                    $e[] = '"'.$s.'" no es un número válido ('.($campo === 'monto' ? 'precio' : $campo).')';

                    return null;
                }

                return $n;
            };
            $monto = $num('monto', true);
            $costo = $num('costo');
            $stock = $num('stock');
            $aviso = $num('aviso');

            $unidad = $this->normalizar($v('unidad'));
            $unidad = $unidad === '' ? null : (str_starts_with($unidad, 'k') || str_contains($unidad, 'peso') ? 'Kg' : 'Uds');
            $categoria = mb_substr($v('categoria'), 0, 25) ?: 'Sin categoría';
            $activo = $v('activo');
            $activo = $activo === '' ? null : ! in_array($this->normalizar($activo), ['0', 'no', 'false', 'baja', 'inactivo'], true);

            if ($stock !== null && $unidad !== 'Kg' && abs($stock - round($stock)) > 1e-9 && ! $this->categoriaFraccionable($categoria)) {
                $e[] = 'el stock '.$stock.' tiene decimales pero el producto se vende por unidad (poné "Kg" en Unidad)';
            }

            if ($e) {
                $errores[] = 'Fila '.$nroFila.' ('.($codigo ?: 'sin código').'): '.implode('; ', $e).'.';

                continue;
            }
            $vistos[$codigo] = $nroFila;
            $validas[] = compact('codigo', 'nombre', 'monto', 'costo', 'stock', 'aviso', 'unidad', 'categoria', 'activo');
        }

        if ($errores) {
            return ['creados' => 0, 'actualizados' => 0, 'categorias' => 0, 'errores' => array_slice($errores, 0, 50)];
        }

        $res = ['creados' => 0, 'actualizados' => 0, 'categorias' => 0, 'errores' => []];

        DB::transaction(function () use ($validas, $user, $actualizarStock, &$res) {
            $cats = [];
            foreach ($validas as $f) {
                $clave = mb_strtolower($f['categoria']);
                if (! isset($cats[$clave])) {
                    $cat = ProductCategory::whereRaw('LOWER(nombre) = ?', [$clave])->first();
                    if (! $cat) {
                        $cat = ProductCategory::create(['nombre' => $f['categoria'], 'unidad' => $f['unidad'] ?? 'Uds', 'activa' => 1]);
                        $res['categorias']++;
                    } elseif (! $cat->activa) {
                        $cat->update(['activa' => 1]);
                    }
                    $cats[$clave] = $cat;
                }
                $cat = $cats[$clave];

                $p = Product::where('codigo', $f['codigo'])->lockForUpdate()->first();
                if ($p) {
                    $p->fill(array_filter([
                        'nombre' => $f['nombre'], 'id_categoria' => $cat->id, 'monto' => $f['monto'],
                        'costo' => $f['costo'], 'aviso' => $f['aviso'], 'activo' => $f['activo'],
                    ], fn ($x) => $x !== null));
                    $ingreso = 0;
                    $cambiaStock = $actualizarStock && $f['stock'] !== null;
                    if ($cambiaStock) {
                        $ingreso = round($f['stock'] - \App\Support\Stock::de($p->id, null, true), 3);
                    }
                    $cambio = $p->isDirty(['costo', 'monto']) || $ingreso != 0;
                    $p->save();
                    if ($cambiaStock) {
                        \App\Support\Stock::fijar($p->id, (float) $f['stock']); // stock de la sucursal actual
                    }
                    if ($cambio) {
                        IngresoProducto::create(['id_producto' => $p->id, 'id_user' => $user->id, 'cantidad' => max(0, $ingreso), 'costo' => $p->costo, 'monto' => $p->monto]);
                    }
                    $res['actualizados']++;
                } else {
                    $stock = $f['stock'] ?? 0;
                    $p = Product::create([
                        'codigo' => $f['codigo'], 'nombre' => $f['nombre'], 'id_categoria' => $cat->id,
                        'costo' => $f['costo'] ?? 0, 'monto' => $f['monto'], 'aviso' => $f['aviso'] ?? 0,
                        'pedido' => $stock, 'quedan' => 0, 'archivo' => Product::SIN_IMAGEN, 'activo' => $f['activo'] ?? true,
                    ]);
                    \App\Support\Stock::fijar($p->id, (float) $stock);
                    IngresoProducto::create(['id_producto' => $p->id, 'id_user' => $user->id, 'cantidad' => $stock, 'costo' => $p->costo, 'monto' => $p->monto]);
                    $res['creados']++;
                }
            }
        });

        return $res;
    }

    private function categoriaFraccionable(string $nombre): bool
    {
        return (bool) ProductCategory::whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->first()?->esPorPeso();
    }

    private function leer(string $ruta, string $ext): array
    {
        if ($ext === 'csv' || $ext === 'txt') {
            $primera = (string) fgets(fopen($ruta, 'r'));
            $sep = substr_count($primera, ';') >= substr_count($primera, ',') ? ';' : ',';
            $reader = new CsvReader(new CsvOptions(FIELD_DELIMITER: $sep));
        } else {
            $reader = new XlsxReader();
        }

        $filas = [];
        try {
            $reader->open($ruta);
            foreach ($reader->getSheetIterator() as $hoja) {
                foreach ($hoja->getRowIterator() as $row) {
                    $filas[] = array_map(function ($c) {
                        if ($c instanceof \DateTimeInterface) {
                            return $c->format('Y-m-d');
                        }
                        if (is_float($c) && floor($c) == $c && abs($c) < 1e15) {
                            return (string) (int) $c; // codigos de barras numericos
                        }

                        return is_string($c) ? preg_replace('/^\xEF\xBB\xBF/', '', $c) : (string) $c;
                    }, $row->toArray());
                    if (count($filas) > self::MAX_FILAS + 1) {
                        break 2;
                    }
                }
                break; // solo la primera hoja
            }
        } catch (\Throwable $e) {
            throw new \DomainException('No se pudo leer el archivo. Guardalo como Excel (.xlsx) o CSV y probá de nuevo.');
        } finally {
            $reader->close();
        }

        return $filas;
    }

    private function normalizar(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($s))));
    }
}
