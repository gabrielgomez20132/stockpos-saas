<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Stock;
use App\Support\SucursalActual;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;

/** Etiquetas con codigo de barras y precio, para impresora de etiquetas (rollo) o en hojas A4. */
class EtiquetaController extends Controller
{
    public const MAX_ETIQUETAS = 10000;

    public const DEFECTO = [
        'ancho' => 40, 'alto' => 30, 'formato' => 'rollo', 'hoja' => 'A4', 'columnas' => 4, 'margen' => 8, 'separacion' => 2,
        'nombre' => true, 'precio' => true, 'barras' => true, 'codigo' => true, 'sucursal' => false, 'fecha' => false,
        'tam_precio' => 'grande', 'guias' => true,
    ];

    private function config(): array
    {
        return array_merge(self::DEFECTO, array_intersect_key((array) Configuracion::get('etiquetas', []), self::DEFECTO));
    }

    private function reglasConfig(): array
    {
        return [
            'config.ancho' => ['required', 'numeric', 'min:15', 'max:200'],
            'config.alto' => ['required', 'numeric', 'min:10', 'max:200'],
            'config.formato' => ['required', Rule::in(['rollo', 'hoja'])],
            'config.hoja' => ['required', Rule::in(['A4', 'Carta', 'Oficio'])],
            'config.columnas' => ['required', 'integer', 'min:1', 'max:12'],
            'config.margen' => ['required', 'numeric', 'min:0', 'max:30'],
            'config.separacion' => ['required', 'numeric', 'min:0', 'max:20'],
            'config.nombre' => ['boolean'], 'config.precio' => ['boolean'], 'config.barras' => ['boolean'], 'config.codigo' => ['boolean'],
            'config.sucursal' => ['boolean'], 'config.fecha' => ['boolean'], 'config.guias' => ['boolean'],
            'config.tam_precio' => ['required', Rule::in(['chico', 'mediano', 'grande'])],
        ];
    }

    private function fila(Product $p): array
    {
        return ['id' => $p->id, 'nombre' => $p->nombre, 'codigo' => (string) $p->codigo, 'monto' => $p->monto, 'quedan' => $p->quedan,
            'unidad' => $p->category?->unidad, 'categoria' => $p->category?->nombre];
    }

    /** productos vendibles; los que tienen variantes se reemplazan por sus variantes */
    private function expandir($ids)
    {
        $productos = Product::with('category:id,nombre,unidad')->whereIn('id', $ids)->get();
        $bases = $productos->where('tiene_variantes', true)->pluck('id');
        $lista = $productos->where('tiene_variantes', false)
            ->concat($bases->isEmpty() ? [] : Product::with('category:id,nombre,unidad')->whereIn('id_padre', $bases)->where('activo', 1)->orderBy('id')->get());
        Stock::cargar($lista);

        return $lista->values();
    }

    public function index(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids', '')))->map(fn ($i) => (int) $i)->filter()->take(500);

        return Inertia::render('Etiquetas/Index', [
            'categorias' => ProductCategory::where('activa', 1)->orderBy('nombre')->get(['id', 'nombre']),
            'config' => $this->config(),
            'defecto' => self::DEFECTO,
            'seleccion' => $ids->isEmpty() ? [] : $this->expandir($ids)->map(fn ($p) => $this->fila($p) + ['copias' => 1]),
            'multisucursal' => SucursalActual::habilitado(),
        ]);
    }

    public function buscar(Request $request)
    {
        $d = $request->validate(['q' => ['nullable', 'string', 'max:60'], 'categoria' => ['nullable', 'integer'], 'todos' => ['nullable', 'boolean'], 'catalogo' => ['nullable', 'boolean']]);
        $q = trim((string) ($d['q'] ?? ''));
        if (! empty($d['catalogo'])) { // todos los productos activos, sin buscar ni filtrar
            $q = '';
            $d['categoria'] = null;
            $d['todos'] = true;
        } elseif ($q === '' && empty($d['categoria'])) {
            return response()->json([]);
        }
        $like = '%'.addcslashes($q, '%_\\').'%';
        $r = Product::with('category:id,nombre,unidad')->where('activo', 1)->where('tiene_variantes', 0)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('codigo', $q)->orWhere('nombre', 'like', $like)->orWhere('codigo', 'like', $like)))
            ->when($d['categoria'] ?? null, fn ($w, $c) => $w->where('id_categoria', $c))
            ->orderByRaw('CASE WHEN codigo = ? THEN 0 ELSE 1 END', [$q])->orderBy('nombre')
            ->limit(! empty($d['catalogo']) ? 5000 : (! empty($d['todos']) ? 500 : 30))->get();
        Stock::cargar($r);

        return response()->json($r->map(fn ($p) => $this->fila($p)));
    }

    private function validar(Request $request): array
    {
        $d = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:5000'],
            'items.*.id' => ['required', 'integer'],
            'items.*.copias' => ['required', 'integer', 'min:1', 'max:999'],
        ] + $this->reglasConfig(), ['items.required' => 'Elegí al menos un producto.']);
        $total = array_sum(array_column($d['items'], 'copias'));
        if ($total > self::MAX_ETIQUETAS) {
            throw new \DomainException('Son '.$total.' etiquetas: el máximo por vez es '.self::MAX_ETIQUETAS.'.');
        }
        $cfg = array_merge(self::DEFECTO, $d['config']);
        foreach (['nombre', 'precio', 'barras', 'codigo', 'sucursal', 'fecha', 'guias'] as $b) {
            $cfg[$b] = (bool) ($d['config'][$b] ?? false);
        }
        Configuracion::set('etiquetas', $cfg); // queda como la configuracion de la proxima vez

        return [$d['items'], $cfg];
    }

    /** lista de etiquetas (cada producto repetido segun las copias) */
    private function etiquetas(array $items): array
    {
        $productos = Product::with('category:id,unidad')->whereIn('id', array_column($items, 'id'))->get()->keyBy('id');
        $out = [];
        foreach ($items as $it) {
            $p = $productos[$it['id']] ?? null;
            if (! $p || $p->tiene_variantes) {
                continue;
            }
            for ($i = 0; $i < $it['copias']; $i++) {
                $out[] = $p;
            }
        }
        if (! $out) {
            throw new \DomainException('Ninguno de los productos elegidos existe.');
        }

        return $out;
    }

    public static function barras(string $codigo): ?string
    {
        try {
            if ($codigo === '' || preg_match('/[^\x20-\x7E]/', $codigo)) {
                return null;
            }

            return (new SvgRenderer())->render((new TypeCode128())->getBarcode($codigo), 300, 80);
        } catch (\Throwable) {
            return null;
        }
    }

    /** guarda lo pedido y devuelve la direccion de la hoja para imprimir */
    public function preparar(Request $request)
    {
        [$items, $cfg] = $this->validar($request);
        $this->etiquetas($items);
        $request->session()->put('etiquetas', ['items' => $items, 'config' => $cfg]);

        return response()->json(['url' => route('etiquetas.imprimir')]);
    }

    public function imprimir(Request $request)
    {
        $pedido = $request->session()->get('etiquetas');
        abort_unless($pedido, 404, 'Volvé a Etiquetas y elegí los productos.');
        $lista = $this->etiquetas($pedido['items']);
        $svgs = [];
        foreach ($lista as $p) {
            if (! array_key_exists((string) $p->codigo, $svgs)) {
                $svg = $pedido['config']['barras'] ? self::barras((string) $p->codigo) : null;
                $svgs[(string) $p->codigo] = $svg ? 'data:image/svg+xml;base64,'.base64_encode($svg) : null;
            }
        }

        return Inertia::render('Print/Etiquetas', [
            'config' => $pedido['config'],
            'etiquetas' => array_map(fn ($p) => ['nombre' => $p->nombre, 'codigo' => (string) $p->codigo, 'monto' => $p->monto], $lista),
            'barras' => $svgs,
            'sucursal' => SucursalActual::habilitado() ? SucursalActual::nombre() : null,
            'negocio' => config('app.name'),
        ]);
    }

    /** datos para armar el PDF en el navegador: etiquetas y las barras de cada codigo (vectoriales) */
    public function pdf(Request $request)
    {
        [$items, $cfg] = $this->validar($request);
        $lista = $this->etiquetas($items);
        $barras = [];
        foreach ($lista as $p) {
            $c = (string) $p->codigo;
            if (! $cfg['barras'] || array_key_exists($c, $barras)) {
                continue;
            }
            $barras[$c] = self::barrasVectoriales($c);
        }

        return response()->json([
            'config' => $cfg,
            'etiquetas' => array_map(fn ($p) => ['nombre' => $p->nombre, 'codigo' => (string) $p->codigo, 'monto' => $p->monto], $lista),
            'barras' => $barras,
            'sucursal' => SucursalActual::habilitado() ? SucursalActual::nombre() : null,
        ]);
    }

    /** Code 128 como lista de barras [inicio, ancho] en modulos, mas el ancho total */
    public static function barrasVectoriales(string $codigo): ?array
    {
        try {
            if ($codigo === '' || preg_match('/[^\x20-\x7E]/', $codigo)) {
                return null;
            }
            $b = (new TypeCode128())->getBarcode($codigo);
            $x = 0;
            $out = [];
            foreach ($b->getBars() as $bar) {
                if ($bar->isBar()) {
                    $out[] = [$x, $bar->getWidth()];
                }
                $x += $bar->getWidth();
            }

            return ['total' => $b->getWidth(), 'barras' => $out];
        } catch (\Throwable) {
            return null;
        }
    }

    public function barra(Request $request)
    {
        $svg = self::barras(mb_substr((string) $request->query('codigo', ''), 0, 60));

        return response($svg ?? '<svg xmlns="http://www.w3.org/2000/svg"/>', 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=3600']);
    }
}
