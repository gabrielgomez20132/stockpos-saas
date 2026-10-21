<?php

namespace App\Http\Controllers;

use App\Models\Combo;
use App\Models\OrderProduct;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Combos y promociones: precio fijo por un conjunto de productos. */
class ComboController extends Controller
{
    public function index()
    {
        $combos = Combo::with('items.producto:id,nombre,monto,costo,quedan')->orderByDesc('activo')->orderBy('nombre')->get();

        // ventas cobradas de cada combo: cuantas veces y cuanto se cobro
        $ventas = DB::table('orders_products')->join('orders', 'orders.id', '=', 'orders_products.id_order')
            ->where('orders.completada', 1)->whereNotNull('orders_products.id_combo')
            ->groupBy('orders_products.id_combo')
            ->selectRaw('orders_products.id_combo, COUNT(DISTINCT orders_products.id_order * 1000 + orders_products.combo_grupo) as veces,
                SUM(orders_products.cantidad * orders_products.monto) as cobrado,
                SUM((orders_products.monto - orders_products.costo_x_uni) * orders_products.cantidad) as ganancia')
            ->get()->keyBy('id_combo');

        $lista = $combos->map(function (Combo $c) use ($ventas) {
            $lista = $c->items->sum(fn ($i) => ($i->producto?->monto ?? 0) * $i->cantidad);
            $costo = $c->items->sum(fn ($i) => ($i->producto?->costo ?? 0) * $i->cantidad);
            $alcanza = $c->items->map(fn ($i) => $i->cantidad > 0 ? floor(($i->producto?->quedan ?? 0) / $i->cantidad) : 0)->min() ?? 0;
            $v = $ventas[$c->id] ?? null;

            return [
                'id' => $c->id, 'nombre' => $c->nombre, 'codigo' => $c->codigo, 'precio' => $c->precio,
                'desde' => $c->desde?->toDateString(), 'hasta' => $c->hasta?->toDateString(), 'activo' => $c->activo, 'estado' => $c->estado(),
                'precio_lista' => round($lista, 2), 'costo' => round($costo, 2), 'alcanza' => (int) $alcanza,
                'productos' => $c->items->map(fn ($i) => ['nombre' => $i->producto?->nombre ?? 'Producto eliminado', 'cantidad' => $i->cantidad])->values(),
                'veces' => (int) ($v->veces ?? 0), 'cobrado' => round((float) ($v->cobrado ?? 0), 2), 'ganancia' => round((float) ($v->ganancia ?? 0), 2),
            ];
        });

        return Inertia::render('Combos/Index', [
            'combos' => $lista,
            'resumen' => [
                'vigentes' => $lista->where('estado', 'vigente')->count(),
                'total' => $lista->count(),
                'veces' => $lista->sum('veces'),
                'ganancia' => round($lista->sum('ganancia'), 2),
            ],
        ]);
    }

    private function reglas(?Combo $c = null): array
    {
        return [
            'nombre' => ['required', 'string', 'max:80'],
            'codigo' => ['nullable', 'string', 'max:100', Rule::unique('combos', 'codigo')->ignore($c?->id), Rule::unique('products', 'codigo')],
            'precio' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'activo' => ['boolean'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.id_producto' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:99999'],
        ];
    }

    private function mensajes(): array
    {
        return [
            'codigo.unique' => 'Ese código ya lo usa otro combo o un producto.',
            'items.required' => 'Agregá al menos un producto al combo.',
            'items.*.id_producto.distinct' => 'Hay un producto repetido: sumá la cantidad en un solo renglón.',
            'hasta.after_or_equal' => 'La fecha "hasta" no puede ser anterior a "desde".',
        ];
    }

    private function datosForm(?Combo $c = null): array
    {
        return [
            'combo' => $c ? [
                'id' => $c->id, 'nombre' => $c->nombre, 'codigo' => $c->codigo, 'precio' => $c->precio,
                'desde' => $c->desde?->toDateString(), 'hasta' => $c->hasta?->toDateString(), 'activo' => $c->activo,
                'items' => $c->items()->with('producto:id,nombre,codigo,monto,costo,quedan')->get()->map(fn ($i) => [
                    'id_producto' => $i->id_producto, 'cantidad' => $i->cantidad, 'nombre' => $i->producto?->nombre,
                    'codigo' => $i->producto?->codigo, 'monto' => $i->producto?->monto, 'costo' => $i->producto?->costo,
                ]),
            ] : null,
        ];
    }

    public function create()
    {
        return Inertia::render('Combos/Form', $this->datosForm());
    }

    public function edit(Combo $combo)
    {
        return Inertia::render('Combos/Form', $this->datosForm($combo));
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->reglas(), $this->mensajes());
        DB::transaction(function () use ($d) {
            $c = Combo::create(collect($d)->except('items')->all() + ['activo' => $d['activo'] ?? true]);
            $c->items()->createMany($d['items']);
        });

        return redirect()->route('combos.index')->with('ok', 'Combo "'.$d['nombre'].'" creado.');
    }

    public function update(Request $request, Combo $combo)
    {
        $d = $request->validate($this->reglas($combo), $this->mensajes());
        DB::transaction(function () use ($d, $combo) {
            $combo->update(collect($d)->except('items')->all());
            $combo->items()->delete();
            $combo->items()->createMany($d['items']);
        });

        return redirect()->route('combos.index')->with('ok', 'Combo actualizado.');
    }

    public function activar(Combo $combo)
    {
        $combo->update(['activo' => ! $combo->activo]);

        return $this->ok($combo->activo ? 'Combo activado.' : 'Combo pausado: ya no aparece en la venta.');
    }

    /** Si ya se vendio se pausa (para no perder el historial); si no, se borra. */
    public function destroy(Combo $combo)
    {
        if (OrderProduct::where('id_combo', $combo->id)->exists()) {
            $combo->update(['activo' => 0]);

            return $this->ok('El combo ya tiene ventas: quedó pausado en lugar de borrarse.');
        }
        $combo->delete();

        return $this->ok('Combo eliminado.');
    }
}
