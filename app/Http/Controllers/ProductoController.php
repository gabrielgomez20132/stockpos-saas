<?php

namespace App\Http\Controllers;

use App\Models\DevolucionProducto;
use App\Models\IngresoProducto;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Atributo;
use App\Models\Configuracion;
use App\Services\ImportadorProductos;
use App\Services\Variantes;
use App\Support\Exportador;
use App\Support\Stock;
use App\Support\SucursalActual;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProductoController extends Controller
{
    public function index(Request $request)
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'categoria' => ['nullable', 'integer'],
            'stock' => ['nullable', Rule::in(['bajo', 'sin'])],
            'baja' => ['nullable', 'boolean'],
        ]);
        $q = trim((string) ($f['q'] ?? ''));
        $like = '%'.addcslashes($q, '%_\\').'%';

        // la lista muestra productos sueltos y productos base; las variantes van adentro de su base.
        // Al filtrar por stock se muestran las variantes (es donde esta el stock).
        $porStock = ! empty($f['stock']);
        $col = Stock::columna();
        $consulta = Product::with('category:id,nombre,unidad')->withCount('variantes');
        Stock::conStock($consulta);
        $productos = $consulta
            ->where('products.activo', ! ($f['baja'] ?? false))
            ->when(! $porStock, fn ($w) => $w->whereNull('products.id_padre'))
            ->when($porStock, fn ($w) => $w->where('products.tiene_variantes', 0))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('products.nombre', 'like', $like)->orWhere('products.codigo', 'like', $like)
                ->orWhereHas('variantes', fn ($v) => $v->where('codigo', 'like', $like))))
            ->when($f['categoria'] ?? null, fn ($w, $c) => $w->where('products.id_categoria', $c))
            ->when(($f['stock'] ?? null) === 'bajo', fn ($w) => $w->whereRaw($col.' <= products.aviso'))
            ->when(($f['stock'] ?? null) === 'sin', fn ($w) => $w->whereRaw($col.' <= 0'))
            ->orderBy('products.nombre')
            ->paginate(25)->withQueryString();
        $sumas = Stock::deVariantes($productos->getCollection()->where('tiene_variantes', true)->pluck('id')->all());
        $productos->getCollection()->each(fn ($p) => $p->setAttribute('stock_variantes', $sumas[$p->id] ?? 0));
        $valor = Product::query()->where('products.activo', 1);
        Stock::conStock($valor);

        return Inertia::render('Productos/Index', [
            'productos' => $productos,
            'categorias' => ProductCategory::where('activa', 1)->orderBy('nombre')->get(['id', 'nombre', 'unidad']),
            'filtros' => ['q' => $q, 'categoria' => $f['categoria'] ?? null, 'stock' => $f['stock'] ?? null, 'baja' => (bool) ($f['baja'] ?? false)],
            'variantesHabilitadas' => Configuracion::variantes(),
            'valorStock' => round((float) $valor->whereRaw($col.' > 0')->sum(DB::raw($col.' * products.costo')), 2),
        ]);
    }

    private function reglas(?Product $p = null): array
    {
        return [
            'nombre' => ['required', 'string', 'max:55'],
            'codigo' => ['required', 'string', 'max:100', Rule::unique('products', 'codigo')->ignore($p?->id), Rule::unique('combos', 'codigo')],
            'id_categoria' => ['required', 'integer', Rule::exists('product_categories', 'id')],
            'costo' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'monto' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'aviso' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'archivo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:4096'],
            'quitar_imagen' => ['nullable', 'boolean'],
        ];
    }

    private function mensajes(): array
    {
        return [
            'codigo.unique' => 'Ese código de barras ya está cargado en otro producto.',
            'archivo.mimes' => 'La foto tiene que ser JPG, PNG, GIF o WEBP.',
            'archivo.max' => 'La foto no puede pesar más de 4 MB.',
        ];
    }

    public function create()
    {
        return Inertia::render('Productos/Form', [
            'producto' => null,
            'variantes' => ['habilitadas' => Configuracion::variantes(), 'cantidad' => 0, 'stock' => 0],
            'categorias' => ProductCategory::where('activa', 1)->orderBy('nombre')->get(['id', 'nombre', 'unidad']),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->reglas() + ['stock_inicial' => ['required', 'numeric', 'min:0', 'max:99999999']], $this->mensajes());

        $p = DB::transaction(function () use ($request, $d) {
            $p = Product::create([
                'nombre' => $d['nombre'], 'codigo' => trim($d['codigo']), 'id_categoria' => $d['id_categoria'],
                'costo' => $d['costo'], 'monto' => $d['monto'], 'aviso' => $d['aviso'],
                'pedido' => $d['stock_inicial'], 'quedan' => 0,
                'archivo' => $request->hasFile('archivo') ? $this->guardarImagen($request->file('archivo')) : Product::SIN_IMAGEN,
                'activo' => 1,
            ]);
            Stock::fijar($p->id, (float) $d['stock_inicial']);
            IngresoProducto::create(['id_producto' => $p->id, 'id_user' => $request->user()->id, 'cantidad' => $d['stock_inicial'], 'costo' => $d['costo'], 'monto' => $d['monto']]);

            return $p;
        });

        return redirect()->route($request->boolean('otro') ? 'productos.create' : 'productos.index')->with('ok', 'Producto "'.$p->nombre.'" creado.');
    }

    public function edit(Product $producto)
    {
        Stock::cargar([$producto]);
        $multi = SucursalActual::habilitado();
        $nombres = $multi ? \App\Models\Sucursal::pluck('nombre', 'id') : [];

        return Inertia::render('Productos/Form', [
            'stockSucursal' => $multi ? ['nombre' => SucursalActual::nombre(), 'stock' => $producto->tiene_variantes ? [] : Stock::porSucursal($producto->id)] : null,
            'producto' => $producto->only(['id', 'nombre', 'codigo', 'id_categoria', 'costo', 'monto', 'aviso', 'quedan', 'activo', 'imagen_url', 'tiene_variantes', 'id_padre'])
                + ['texto_variante' => $producto->textoVariante(), 'padre' => $producto->padre?->only(['id', 'nombre'])],
            'variantes' => [
                'habilitadas' => Configuracion::variantes(),
                'cantidad' => $producto->variantes()->count(),
                'stock' => Stock::deVariantes([$producto->id])[$producto->id] ?? 0,
            ],
            'categorias' => ProductCategory::where('activa', 1)->orWhere('id', $producto->id_categoria)->orderBy('nombre')->get(['id', 'nombre', 'unidad']),
            'ingresos' => IngresoProducto::with('user:id,nombre')->where('id_producto', $producto->id)->latest('id')->limit(30)->get()
                ->each(fn ($i) => $i->setAttribute('sucursal', $multi ? ($nombres[$i->id_sucursal] ?? '') : null)),
            'vendido30' => round((float) OrderProduct::join('orders', 'orders.id', '=', 'orders_products.id_order')
                ->where('orders.completada', 1)->where('orders_products.id_producto', $producto->id)
                ->where('orders.created_at', '>=', now()->subDays(30))->sum('orders_products.cantidad'), 3),
        ]);
    }

    /**
     * Edicion. "quedan" es el stock actual (se puede corregir) e "ingreso" lo
     * que entra ahora y se suma al stock (igual que el campo "pedido" de control-soft).
     */
    public function update(Request $request, Product $producto)
    {
        $d = $request->validate($this->reglas($producto) + [
            'quedan' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'ingreso' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'activo' => ['nullable', 'boolean'],
        ], $this->mensajes());

        if ($producto->tiene_variantes) {
            $d['quedan'] = 0;
            $d['ingreso'] = 0;
        }

        $archivoAnterior = $producto->archivo;
        DB::transaction(function () use ($request, $producto, $d, $archivoAnterior) {
            $ingreso = (float) ($d['ingreso'] ?? 0);
            $producto->fill([
                'nombre' => $d['nombre'], 'codigo' => trim($d['codigo']), 'id_categoria' => $d['id_categoria'],
                'costo' => $d['costo'], 'monto' => $d['monto'], 'aviso' => $d['aviso'],
                'activo' => $d['activo'] ?? $producto->activo,
            ]);
            if ($ingreso > 0) {
                $producto->pedido = $ingreso;
            }
            if ($request->hasFile('archivo')) {
                $this->borrarImagen($producto->archivo);
                $producto->archivo = $this->guardarImagen($request->file('archivo'));
            } elseif ($request->boolean('quitar_imagen')) {
                $this->borrarImagen($producto->archivo);
                $producto->archivo = Product::SIN_IMAGEN;
            }
            $cambioPrecio = $producto->isDirty(['costo', 'monto']);
            $producto->save();
            Stock::fijar($producto->id, round((float) $d['quedan'] + $ingreso, 3));

            if ($ingreso > 0 || $cambioPrecio) {
                IngresoProducto::create(['id_producto' => $producto->id, 'id_user' => $request->user()->id, 'cantidad' => $ingreso, 'costo' => $producto->costo, 'monto' => $producto->monto]);
            }
            if ($producto->tiene_variantes) {
                app(Variantes::class)->sincronizar($producto, $archivoAnterior);
            }
        });

        return redirect()->route('productos.index')->with('ok', 'Producto actualizado.');
    }

    /** Si tiene ventas o devoluciones se da de baja (no se borra, para no romper el historial). */
    public function destroy(Product $producto)
    {
        if ($producto->tiene_variantes) {
            $srv = app(Variantes::class);
            foreach ($producto->variantes()->get() as $v) {
                $srv->quitar($v);
            }
            $producto->refresh();
        }

        $tieneHistoria = OrderProduct::where('id_producto', $producto->id)->exists()
            || DevolucionProducto::where('id_producto', $producto->id)->exists();

        if ($tieneHistoria) {
            $producto->update(['activo' => 0]);

            return $this->ok('"'.$producto->nombre.'" tiene ventas registradas: quedó dado de baja (podés reactivarlo).');
        }

        $this->borrarImagen($producto->archivo);
        IngresoProducto::where('id_producto', $producto->id)->delete();
        $producto->delete();

        return $this->ok('Producto eliminado.');
    }

    /** "Múltiples productos": un producto con todas sus variantes (talle, color...) en un solo formulario. */
    public function multiples()
    {
        if (! Configuracion::variantes()) {
            return redirect()->route('productos.index')->with('error', 'Primero habilitá las variantes en Configuración.');
        }

        return Inertia::render('Productos/Multiples', [
            'categorias' => ProductCategory::where('activa', 1)->orderBy('nombre')->get(['id', 'nombre', 'unidad']),
            'atributos' => Atributo::with('valores')->where('activo', 1)->orderBy('orden')->orderBy('id')->get(['id', 'nombre']),
        ]);
    }

    public function guardarMultiples(Request $request, Variantes $servicio)
    {
        if (! Configuracion::variantes()) {
            throw new \DomainException('Las variantes están deshabilitadas en Configuración.');
        }
        $atributos = Atributo::with('valores')->where('activo', 1)->orderBy('orden')->orderBy('id')->get();
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:55'],
            'codigo' => ['nullable', 'string', 'max:90', Rule::unique('products', 'codigo'), Rule::unique('combos', 'codigo')],
            'id_categoria' => ['required', 'integer', Rule::exists('product_categories', 'id')],
            'costo' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'monto' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'filas' => ['required', 'array', 'min:1', 'max:300'],
            'filas.*.valores' => ['nullable', 'array'],
            'filas.*.valores.*' => ['nullable', 'string', 'max:30'],
            'filas.*.codigo' => ['nullable', 'string', 'max:100', 'distinct', Rule::unique('products', 'codigo'), Rule::unique('combos', 'codigo')],
            'filas.*.cantidad' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'filas.*.aviso' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'filas.*.archivo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:4096'],
        ], $this->mensajes() + [
            'filas.required' => 'Agregá al menos una variante.',
            'filas.*.codigo.distinct' => 'Hay dos variantes con el mismo código.',
            'filas.*.codigo.unique' => 'Ese código ya está cargado en otro producto.',
            'filas.*.archivo.mimes' => 'La foto tiene que ser JPG, PNG, GIF o WEBP.',
            'filas.*.archivo.max' => 'La foto no puede pesar más de 4 MB.',
        ], ['filas.*.cantidad' => 'la cantidad', 'filas.*.aviso' => 'la cantidad mínima', 'filas.*.codigo' => 'el código']);

        $porPeso = (bool) ProductCategory::find($d['id_categoria'])?->esPorPeso();
        $filas = [];
        foreach (array_values($d['filas']) as $i => $f) {
            $variante = [];
            foreach ($atributos as $a) {
                $valor = trim((string) ($f['valores'][$a->id] ?? ''));
                if ($valor === '') {
                    continue;
                }
                if (! $a->valores->contains(fn ($v) => $v->valor === $valor)) {
                    throw ValidationException::withMessages(["filas.{$i}.valores.{$a->id}" => "\"{$valor}\" no es un {$a->nombre} cargado."]);
                }
                $variante[$a->nombre] = $valor;
            }
            if (! $porPeso && (abs($f['cantidad'] - round($f['cantidad'])) > 1e-9 || abs($f['aviso'] - round($f['aviso'])) > 1e-9)) {
                throw ValidationException::withMessages(["filas.{$i}.cantidad" => 'Tiene que ser un número entero (la categoría se vende por unidad).']);
            }
            $filas[] = ['variante' => $variante, 'codigo' => $f['codigo'] ?? null, 'cantidad' => (float) $f['cantidad'], 'aviso' => (float) $f['aviso'],
                'archivo' => null];
        }
        // las fotos se guardan recien cuando todas las filas estan bien
        foreach ($filas as $i => &$f) {
            if ($request->hasFile("filas.{$i}.archivo")) {
                $f['archivo'] = $this->guardarImagen($request->file("filas.{$i}.archivo"));
            }
        }
        unset($f);

        // el producto principal lleva su propia copia de la primera foto (si se cambia, no borra la de la variante)
        $archivoBase = Product::SIN_IMAGEN;
        $primera = collect($filas)->pluck('archivo')->filter()->first();
        if ($primera) {
            $archivoBase = 'p-'.Str::lower(Str::random(20)).'.'.pathinfo($primera, PATHINFO_EXTENSION);
            Storage::disk('uploads')->copy($primera, $archivoBase);
        }

        try {
            $base = $servicio->crearConVariantes([
                'nombre' => $d['nombre'], 'codigo' => $d['codigo'] ?? null, 'id_categoria' => $d['id_categoria'],
                'costo' => $d['costo'], 'monto' => $d['monto'], 'archivo' => $archivoBase,
            ], $filas, $request->user());
        } catch (\Throwable $e) {
            foreach (array_filter([...array_column($filas, 'archivo'), $archivoBase]) as $a) {
                $this->borrarImagen($a);
            }
            throw $e;
        }

        return redirect()->route('variantes.show', $base->id)->with('ok', 'Se crearon "'.$base->nombre.'" y sus '.count($filas).' variantes.');
    }

    public function precios(Request $request)
    {
        $d = $request->validate([
            'porcentaje' => ['required', 'numeric', 'min:-90', 'max:1000', 'not_in:0'],
            'id_categoria' => ['nullable', 'integer'],
            'costo' => ['boolean'],
        ], ['porcentaje.not_in' => 'El porcentaje no puede ser cero.']);

        $f = 1 + $d['porcentaje'] / 100;
        $n = 0;
        Product::where('activo', 1)->when($d['id_categoria'] ?? null, fn ($q, $c) => $q->where('id_categoria', $c))
            ->chunkById(200, function ($productos) use ($f, $d, &$n) {
                foreach ($productos as $p) {
                    $p->monto = ceil(round($p->monto * $f, 4));
                    if ($d['costo'] ?? true) {
                        $p->costo = ceil(round($p->costo * $f, 4));
                    }
                    $p->save();
                    $n++;
                }
            });

        return $this->ok('Precios de '.$n.' productos '.($d['porcentaje'] > 0 ? 'aumentados' : 'bajados').' un '.abs($d['porcentaje']).'%.');
    }

    /** Exporta todos los productos (o los filtrados por categoria) en el mismo formato que la importacion. */
    public function exportar(Request $request)
    {
        $request->validate(['formato' => ['required', Rule::in(['csv', 'xlsx'])], 'baja' => ['nullable', 'boolean']]);

        $filas = Product::with('category:id,nombre,unidad')
            ->when(! $request->boolean('baja'), fn ($q) => $q->where('activo', 1))
            ->orderBy('nombre')->get()
            ->tap(fn ($l) => Stock::cargar($l))
            ->map(fn (Product $p) => [
                (string) $p->codigo, $p->nombre, $p->category?->nombre, $p->category?->unidad,
                $p->costo, $p->monto, $p->quedan, $p->aviso, $p->activo ? 'Sí' : 'No',
            ])->all();

        return Exportador::descargar($request->input('formato'), 'productos_'.date('Y-m-d'), ImportadorProductos::CABECERA, $filas);
    }

    /**
     * Modelo de Excel para cargar productos: hoja "Productos" con los titulos y
     * ejemplos, y hoja "Instrucciones" con que va en cada columna y las
     * categorias que ya existen.
     */
    public function plantilla()
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mod');
        $titulo = (new \OpenSpout\Common\Entity\Style\Style())->withFontBold(true)->withFontColor('FFFFFF')->withBackgroundColor('0E3B43');
        $negrita = (new \OpenSpout\Common\Entity\Style\Style())->withFontBold(true);
        $Row = \OpenSpout\Common\Entity\Row::class;

        $w = new \OpenSpout\Writer\XLSX\Writer();
        $w->openToFile($tmp);
        $hoja = $w->getCurrentSheet();
        $hoja->setName('Productos');
        foreach ([18, 34, 18, 9, 12, 16, 10, 14, 9] as $i => $ancho) {
            $hoja->setColumnWidth($ancho, $i + 1);
        }
        $w->addRow($Row::fromValuesWithStyle(ImportadorProductos::CABECERA, $titulo));
        foreach ([
            ['7790895000997', 'Coca-Cola 500 ml', 'Gaseosas', 'Uds', 900, 1400, 24, 6, 'Sí'],
            ['7791234000011', 'Alfajor triple chocolate', 'Golosinas', 'Uds', 950, 1500, 30, 10, 'Sí'],
            ['2001', 'Jamón cocido', 'Fiambrería', 'Kg', 7200, 11000, 5.5, 1, 'Sí'],
            ['2101', 'Pan francés', 'Panadería', 'Kg', 1600, 2600, 10, 2, 'Sí'],
        ] as $f) {
            $w->addRow($Row::fromValues($f));
        }

        $w->addNewSheetAndMakeItCurrent()->setName('Instrucciones');
        $w->getCurrentSheet()->setColumnWidth(22, 1);
        $w->getCurrentSheet()->setColumnWidth(95, 2);
        $w->addRow($Row::fromValuesWithStyle(['Columna', 'Qué va'], $titulo));
        foreach ([
            ['Código', 'OBLIGATORIO. Código de barras o código interno. Si ya existe un producto con ese código, se actualiza; si no, se crea.'],
            ['Nombre', 'OBLIGATORIO. Hasta 55 letras.'],
            ['Categoría', 'Nombre de la categoría. Si no existe se crea. Si se deja vacío queda en "Sin categoría".'],
            ['Unidad', '"Uds" (se vende por unidad) o "Kg" (por peso, admite decimales: 0,350). Solo se usa al crear una categoría nueva.'],
            ['Costo', 'Lo que te cuesta. En productos por peso, el costo POR KILO.'],
            ['Precio de venta', 'OBLIGATORIO. En productos por peso, el precio POR KILO.'],
            ['Stock', 'Cantidad actual (unidades o kilos). En productos que ya existen solo se reemplaza si marcás esa opción al importar.'],
            ['Stock mínimo', 'Cuando el stock llega a este número, el producto aparece en "Para reponer".'],
            ['Activo', '"Sí" o "No". Los productos en "No" no se pueden vender.'],
            ['', ''],
            ['Importante', 'No cambies los títulos de la primera fila de la hoja Productos. Se importa solo la primera hoja.'],
            ['', 'Si alguna fila tiene un error no se importa nada y el sistema te dice qué fila corregir.'],
            ['', 'Para modificar precios de todo lo que ya tenés: Productos > Excel (exportar), cambiá los precios y volvé a importar ese mismo archivo.'],
        ] as $f) {
            $w->addRow($Row::fromValues($f));
        }
        $w->addRow($Row::fromValues(['', '']));
        $w->addRow($Row::fromValuesWithStyle(['Categorías cargadas', 'Unidad'], $negrita));
        foreach (ProductCategory::where('activa', 1)->orderBy('nombre')->get(['nombre', 'unidad']) as $c) {
            $w->addRow($Row::fromValues([$c->nombre, $c->unidad]));
        }
        $w->close();

        return response()->download($tmp, 'modelo_productos.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    public function importar(Request $request, ImportadorProductos $importador)
    {
        $request->validate([
            'archivo' => ['required', 'file', 'max:10240', 'mimes:xlsx,csv,txt'],
            'actualizar_stock' => ['nullable', 'boolean'],
        ], ['archivo.mimes' => 'El archivo tiene que ser Excel (.xlsx) o CSV.', 'archivo.max' => 'El archivo no puede pesar más de 10 MB.']);

        $file = $request->file('archivo');
        $ext = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $r = $importador->importar($file->getRealPath(), $ext, $request->user(), $request->boolean('actualizar_stock'));

        if ($r['errores']) {
            return back()->with('error', 'No se importó nada: hay '.count($r['errores']).' filas con errores. Corregilas y volvé a subir el archivo.')
                ->with('importacion', $r);
        }

        return back()->with('ok', "Importación lista: {$r['creados']} productos nuevos, {$r['actualizados']} actualizados"
            .($r['categorias'] ? ", {$r['categorias']} categorías nuevas" : '').'.')->with('importacion', $r);
    }

    private function guardarImagen(UploadedFile $file): string
    {
        // el tipo real sale del contenido, no del nombre que manda el navegador
        $info = @getimagesize($file->getRealPath());
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
        if (! $ext) {
            throw new \DomainException('El archivo no es una imagen válida.');
        }
        $nombre = 'p-'.Str::lower(Str::random(20)).'.'.$ext;
        Storage::disk('uploads')->putFileAs('', $file, $nombre);

        return $nombre;
    }

    private function borrarImagen(?string $archivo): void
    {
        if ($archivo && $archivo !== Product::SIN_IMAGEN && basename($archivo) === $archivo && ! str_starts_with($archivo, 'logo-negocio')) {
            Storage::disk('uploads')->delete($archivo);
        }
    }
}
