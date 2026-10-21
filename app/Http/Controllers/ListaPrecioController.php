<?php

namespace App\Http\Controllers;

use App\Models\ListaPrecio;
use App\Models\ListaPrecioItem;
use App\Models\Product;
use App\Models\User;
use App\Services\ListasPrecios;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Listas de precios de proveedores (Excel / CSV). */
class ListaPrecioController extends Controller
{
    public function __construct(private ListasPrecios $srv)
    {
    }

    private function proveedores()
    {
        return User::tipo(User::TIPO_PROVEEDOR)->where('activo', 1)->orderBy('nombre')->get(['id', 'nombre']);
    }

    public function index(Request $request)
    {
        $prov = $request->integer('proveedor') ?: null;
        $listas = ListaPrecio::with(['proveedor:id,nombre', 'user:id,nombre'])
            ->where('estado', '!=', 'borrador')
            ->when($prov, fn ($q) => $q->where('id_proveedor', $prov))
            ->orderByDesc('id')->paginate(15)->withQueryString();

        $ultimas = ListaPrecio::where('estado', 'aplicada')->groupBy('id_proveedor')
            ->selectRaw('id_proveedor, MAX(aplicada_at) as ultima')->pluck('ultima', 'id_proveedor');

        return Inertia::render('ListasPrecios/Index', [
            'proveedores' => $this->proveedores()->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre, 'ultima' => $ultimas[$p->id] ?? null]),
            'listas' => $listas,
            'proveedor' => $prov,
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'id_proveedor' => ['required', 'integer', Rule::exists('users', 'id')->where('id_uType', User::TIPO_PROVEEDOR)],
            'archivo' => ['required', 'file', 'max:30720', 'mimes:xlsx,ods,csv,txt'],
        ], [
            'id_proveedor.required' => 'Elegí el proveedor.',
            'archivo.mimes' => 'El archivo tiene que ser Excel (.xlsx), LibreOffice (.ods) o CSV. Si es un .xls viejo, abrilo y guardalo como .xlsx.',
            'archivo.max' => 'El archivo no puede pesar más de 30 MB.',
        ]);
        // listas que se subieron y nunca se terminaron de configurar: se borran con su archivo
        ListaPrecio::where('estado', 'borrador')->where('created_at', '<', now()->subDay())->get()
            ->each(function ($l) { $this->srv->borrarArchivo($l); $l->delete(); });

        $f = $request->file('archivo');
        $ext = strtolower($f->getClientOriginalExtension());
        $ext = in_array($ext, ['xlsx', 'ods', 'csv', 'txt'], true) ? $ext : 'xlsx';
        $interno = Str::lower(Str::random(32)).'.'.$ext;
        $f->storeAs('listas', $interno, 'local');

        $lista = ListaPrecio::create([
            'id_proveedor' => $d['id_proveedor'], 'id_user' => $request->user()->id,
            'archivo' => mb_substr($f->getClientOriginalName(), 0, 150), 'estado' => 'borrador',
            'opciones' => ['archivo_interno' => $interno, 'ext' => $ext],
        ]);
        try {
            $filas = $this->srv->filas($lista);
        } catch (\DomainException $e) {
            $this->srv->borrarArchivo($lista);
            $lista->delete();
            throw $e;
        }
        if (count($filas) < 2) {
            $this->srv->borrarArchivo($lista);
            $lista->delete();
            throw new \DomainException('El archivo está vacío.');
        }

        return redirect()->route('listas.columnas', $lista);
    }

    public function columnas(ListaPrecio $lista)
    {
        abort_unless(in_array($lista->estado, ['borrador', 'pendiente'], true), 404);
        $filas = $this->srv->filas($lista);
        $ancho = min(26, max(array_map('count', array_slice($filas, 0, 40)) ?: [0]));
        $muestra = array_map(fn ($f) => array_slice(array_pad($f, $ancho, ''), 0, $ancho), array_slice($filas, 0, 25));
        $previas = ListaPrecio::where('id_proveedor', $lista->id_proveedor)->where('estado', 'aplicada')->latest('id')->value('opciones');
        $op = $lista->opciones ?? [];
        $sug = $this->srv->sugerir($filas);
        $guardadas = array_intersect_key($previas ?? [], array_flip(['fila_titulos', 'codigo', 'descripcion', 'costo', 'descuento', 'iva', 'politica', 'margen', 'redondeo']));

        return Inertia::render('ListasPrecios/Columnas', [
            'lista' => ['id' => $lista->id, 'archivo' => $lista->archivo, 'proveedor' => $lista->proveedor?->nombre, 'filas' => count($filas)],
            'muestra' => $muestra,
            'opciones' => array_merge(['descuento' => 0, 'iva' => 0, 'politica' => 'margen', 'margen' => 50, 'redondeo' => 10], $sug, $guardadas,
                array_intersect_key($op, array_flip(['fila_titulos', 'codigo', 'descripcion', 'costo', 'descuento', 'iva', 'politica', 'margen', 'redondeo']))),
            'deLaAnterior' => (bool) $guardadas,
        ]);
    }

    public function procesar(Request $request, ListaPrecio $lista)
    {
        abort_unless(in_array($lista->estado, ['borrador', 'pendiente'], true), 404);
        $op = $request->validate([
            'fila_titulos' => ['nullable', 'integer', 'min:0', 'max:50'],
            'codigo' => ['required', 'integer', 'min:0', 'max:60'],
            'descripcion' => ['nullable', 'integer', 'min:0', 'max:60'],
            'costo' => ['required', 'integer', 'min:0', 'max:60', 'different:codigo'],
            'descuento' => ['required', 'numeric', 'min:0', 'max:90'],
            'iva' => ['required', 'numeric', Rule::in([0, 10.5, 21, 27])],
            'politica' => ['required', Rule::in(ListasPrecios::POLITICAS)],
            'margen' => ['nullable', 'required_if:politica,fijo', 'numeric', 'min:0', 'max:1000'],
            'redondeo' => ['required', 'integer', Rule::in([1, 10, 50, 100])],
        ], [
            'codigo.required' => 'Elegí la columna del código.',
            'costo.required' => 'Elegí la columna del precio.',
            'costo.different' => 'El código y el precio no pueden ser la misma columna.',
        ]);
        foreach (['fila_titulos', 'descripcion', 'margen'] as $k) {
            $op[$k] = isset($op[$k]) ? $op[$k] + 0 : null;
        }
        $this->srv->procesar($lista, $op);

        return redirect()->route('listas.show', $lista);
    }

    public function show(ListaPrecio $lista)
    {
        abort_if($lista->estado === 'borrador', 404);
        $items = $lista->items()->with('producto:id,nombre,codigo,tiene_variantes,activo')->orderBy('fila')->get()
            ->map(fn (ListaPrecioItem $i) => $this->renglon($i));

        return Inertia::render('ListasPrecios/Show', [
            'lista' => [
                'id' => $lista->id, 'archivo' => $lista->archivo, 'estado' => $lista->estado, 'proveedor' => $lista->proveedor?->nombre,
                'created_at' => $lista->created_at, 'aplicada_at' => $lista->aplicada_at, 'aplicados' => $lista->aplicados,
                'opciones' => array_intersect_key($lista->opciones ?? [], array_flip(['descuento', 'iva', 'politica', 'margen', 'redondeo'])),
                'variacion' => $lista->variacion_promedio, 'usuario' => $lista->user?->nombre,
            ],
            'items' => $items,
        ]);
    }

    private function renglon(ListaPrecioItem $i): array
    {
        return [
            'id' => $i->id, 'fila' => $i->fila, 'codigo' => $i->codigo, 'descripcion' => $i->descripcion,
            'costo_lista' => $i->costo_lista, 'costo_nuevo' => $i->costo_nuevo, 'costo_anterior' => $i->costo_anterior,
            'precio_anterior' => $i->precio_anterior, 'precio_nuevo' => $i->precio_nuevo, 'aplicar' => $i->aplicar, 'aplicado' => $i->aplicado,
            'producto' => $i->producto ? ['id' => $i->producto->id, 'nombre' => $i->producto->nombre, 'codigo' => $i->producto->codigo,
                'variantes' => (bool) $i->producto->tiene_variantes, 'activo' => (bool) $i->producto->activo] : null,
        ];
    }

    /** productos para vincular a mano (incluye productos con variantes: se aplica a todas) */
    public function productos(Request $request)
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        if ($q === '') {
            return response()->json([]);
        }
        $like = '%'.addcslashes($q, '%_\\').'%';

        return response()->json(Product::whereNull('id_padre')
            ->where(fn ($w) => $w->where('nombre', 'like', $like)->orWhere('codigo', 'like', $like))
            ->orderByDesc('activo')->orderBy('nombre')->limit(15)->get(['id', 'nombre', 'codigo', 'costo', 'monto', 'tiene_variantes', 'activo']));
    }

    public function vincular(Request $request, ListaPrecio $lista, ListaPrecioItem $renglon)
    {
        abort_unless($renglon->lista_id === $lista->id, 404);
        if ($lista->estado !== 'pendiente') {
            throw new \DomainException('Esta lista ya no se puede modificar.');
        }
        $d = $request->validate(['id_producto' => ['nullable', 'integer', Rule::exists('products', 'id')->whereNull('id_padre')]]);
        $item = $this->srv->vincular($renglon, isset($d['id_producto']) ? Product::find($d['id_producto']) : null);

        return response()->json($this->renglon($item));
    }

    public function aplicar(Request $request, ListaPrecio $lista)
    {
        $d = $request->validate(['items' => ['present', 'array', 'max:10000'], 'items.*' => ['integer']]);
        if (! $d['items']) {
            throw new \DomainException('No elegiste ningún producto para actualizar.');
        }
        $n = $this->srv->aplicar($lista, $d['items'], $request->user());

        return redirect()->route('listas.show', $lista)->with('ok', "Listo: se actualizaron {$n} productos con la lista de {$lista->proveedor?->nombre}.");
    }

    public function descartar(ListaPrecio $lista)
    {
        $this->srv->descartar($lista);

        return redirect()->route('listas.index')->with('ok', 'Lista descartada: no se cambió ningún precio.');
    }

    /** lista de ejemplo para probar: codigos de productos cargados + algunos que no existen */
    public function ejemplo()
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lst');
        $Row = \OpenSpout\Common\Entity\Row::class;
        $negrita = (new \OpenSpout\Common\Entity\Style\Style())->withFontBold(true);
        $w = new \OpenSpout\Writer\XLSX\Writer();
        $w->openToFile($tmp);
        $w->getCurrentSheet()->setName('Lista');
        $w->addRow($Row::fromValuesWithStyle(['DISTRIBUIDORA NORTE S.A. - LISTA DE PRECIOS', '', '', ''], $negrita));
        $w->addRow($Row::fromValues(['Vigencia: '.now()->format('d/m/Y').' · Precios sin IVA', '', '', '']));
        $w->addRow($Row::fromValues(['', '', '', '']));
        $w->addRow($Row::fromValuesWithStyle(['Código', 'Descripción', 'Precio Lista', 'Unidad'], $negrita));
        foreach (Product::where('activo', 1)->whereNull('id_padre')->where('costo', '>', 0)->orderBy('id')->limit(12)->get() as $i => $p) {
            $w->addRow($Row::fromValues([(string) $p->codigo, mb_strtoupper($p->nombre), round($p->costo * (1 + [8, 12, 5, 15, 0, 10, -3][$i % 7] / 100), 2), 'UN']));
        }
        $w->addRow($Row::fromValues(['NV-1001', 'PRODUCTO NUEVO DE EJEMPLO 1', 1500, 'UN']));
        $w->addRow($Row::fromValues(['NV-1002', 'PRODUCTO NUEVO DE EJEMPLO 2', 2350.5, 'UN']));
        $w->close();

        return response()->download($tmp, 'lista_ejemplo_distribuidora_norte.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
