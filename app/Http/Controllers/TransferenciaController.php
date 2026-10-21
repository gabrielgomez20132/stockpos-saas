<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sucursal;
use App\Models\Transferencia;
use App\Models\TransferenciaItem;
use App\Support\Stock;
use App\Support\SucursalActual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Mercaderia que se manda de una sucursal a otra: sale del stock de una y entra en la otra. */
class TransferenciaController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        $sid = SucursalActual::id();
        $lista = Transferencia::with(['sucursalOrigen:id,nombre', 'sucursalDestino:id,nombre', 'user:id,nombre'])->withCount('items')
            ->when($u->id_sucursal, fn ($q) => $q->where(fn ($w) => $w->where('origen', $sid)->orWhere('destino', $sid)))
            ->orderByDesc('id')->paginate(20);

        return Inertia::render('Transferencias/Index', ['transferencias' => $lista, 'actual' => $sid]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Transferencias/Create', [
            'origen' => ['id' => SucursalActual::id(), 'nombre' => SucursalActual::nombre()],
            'destinos' => Sucursal::where('activa', 1)->where('id', '!=', SucursalActual::id())->orderBy('id')->get(['id', 'nombre']),
        ]);
    }

    /** productos con su stock en la sucursal de origen */
    public function productos(Request $request)
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        if ($q === '') {
            return response()->json([]);
        }
        $like = '%'.addcslashes($q, '%_\\').'%';
        $r = Product::with('category:id,unidad')->where('activo', 1)->where('tiene_variantes', 0)
            ->where(fn ($w) => $w->where('codigo', $q)->orWhere('nombre', 'like', $like)->orWhere('codigo', 'like', $like))
            ->orderByRaw('CASE WHEN codigo = ? THEN 0 ELSE 1 END', [$q])->orderBy('nombre')->limit(15)->get();
        Stock::cargar($r);

        return response()->json($r->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre, 'codigo' => $p->codigo, 'quedan' => $p->quedan,
            'unidad' => $p->category?->unidad, 'fraccionable' => (bool) $p->category?->esPorPeso()]));
    }

    public function store(Request $request)
    {
        $origen = SucursalActual::id();
        $d = $request->validate([
            'destino' => ['required', 'integer', Rule::exists('sucursales', 'id')->where('activa', 1), Rule::notIn([$origen])],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.id_producto' => ['required', 'integer', 'distinct'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'observaciones' => ['nullable', 'string', 'max:300'],
        ], ['destino.not_in' => 'El destino tiene que ser otra sucursal.', 'items.required' => 'Agregá al menos un producto.', 'items.*.id_producto.distinct' => 'Hay un producto repetido.']);

        $t = DB::transaction(function () use ($d, $origen, $request) {
            $productos = Product::with('category')->whereIn('id', array_column($d['items'], 'id_producto'))->lockForUpdate()->get()->keyBy('id');
            $t = Transferencia::create(['origen' => $origen, 'destino' => $d['destino'], 'id_user' => $request->user()->id, 'observaciones' => $d['observaciones'] ?? null]);
            foreach ($d['items'] as $it) {
                $p = $productos[$it['id_producto']] ?? null;
                if (! $p || $p->tiene_variantes) {
                    throw new \DomainException('Uno de los productos no existe o es un producto con variantes (elegí la variante).');
                }
                $cant = round((float) $it['cantidad'], 3);
                if (! $p->category?->esPorPeso() && abs($cant - round($cant)) > 1e-9) {
                    throw new \DomainException($p->nombre.' se transfiere por unidad entera.');
                }
                $hay = Stock::de($p->id, $origen, true);
                if ($hay + 1e-9 < $cant) {
                    throw new \DomainException('De '.$p->nombre.' hay '.rtrim(rtrim(number_format($hay, 3, ',', ''), '0'), ',').' en esta sucursal: no alcanza para mandar '.$cant.'.');
                }
                Stock::sumar($p->id, -$cant, $origen);
                Stock::sumar($p->id, $cant, (int) $d['destino']);
                TransferenciaItem::create(['transferencia_id' => $t->id, 'id_producto' => $p->id, 'cantidad' => $cant]);
            }

            return $t;
        });

        return redirect()->route('transferencias.show', $t)->with('ok', 'Transferencia #'.$t->id.' registrada: el stock ya se movió.');
    }

    public function show(Request $request, Transferencia $transferencia)
    {
        $u = $request->user();
        abort_if($u->id_sucursal && ! in_array($u->id_sucursal, [$transferencia->origen, $transferencia->destino]), 404);
        $transferencia->load(['sucursalOrigen:id,nombre', 'sucursalDestino:id,nombre', 'user:id,nombre', 'items.producto:id,nombre,codigo,id_categoria', 'items.producto.category:id,unidad']);

        return Inertia::render('Transferencias/Show', [
            'transferencia' => [
                'id' => $transferencia->id, 'fecha' => $transferencia->created_at, 'origen' => $transferencia->sucursalOrigen?->nombre,
                'destino' => $transferencia->sucursalDestino?->nombre, 'usuario' => $transferencia->user?->nombre, 'observaciones' => $transferencia->observaciones,
                'items' => $transferencia->items->map(fn ($i) => ['nombre' => $i->producto?->nombre ?? '—', 'codigo' => $i->producto?->codigo,
                    'cantidad' => $i->cantidad, 'unidad' => $i->producto?->category?->unidad]),
            ],
        ]);
    }
}
