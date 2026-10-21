<?php

namespace App\Http\Controllers;

use App\Models\Presupuesto;
use App\Models\PresupuestoItem;
use App\Services\Documentos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Presupuestos: solo se arman e imprimen (no tocan stock ni caja). */
class PresupuestoController extends Controller
{
    public function index(Request $request)
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        $lista = Presupuesto::with('user:id,nombre')->withCount('items')
            ->when($q !== '', function ($w) use ($q) {
                $num = ltrim(preg_replace('/^\d{1,4}-/', '', $q), '0');
                $w->where(fn ($x) => $x->where('cliente_nombre', 'like', '%'.addcslashes($q, '%_\\').'%')
                    ->when(ctype_digit($num) && $num !== '', fn ($y) => $y->orWhere('id', (int) $num)));
            })
            ->orderByDesc('id')->paginate(20)->withQueryString()
            ->through(fn (Presupuesto $p) => ['id' => $p->id, 'numero' => $p->numero(), 'fecha' => $p->created_at, 'vence' => $p->vence()->toDateString(),
                'vencido' => $p->vence()->endOfDay()->isPast(), 'cliente' => $p->cliente_nombre, 'total' => $p->total,
                'items' => $p->items_count, 'usuario' => $p->user?->nombre]);

        return Inertia::render('Presupuestos/Index', ['presupuestos' => $lista, 'filtros' => ['q' => $q]]);
    }

    private function formulario(?Presupuesto $p, bool $copia = false)
    {
        return Inertia::render('Presupuestos/Form', [
            'presupuesto' => $p ? [
                'id' => $copia ? null : $p->id, 'numero' => $copia ? null : $p->numero(),
                'cliente' => Documentos::datosCliente($p), 'validez_dias' => $p->validez_dias, 'observaciones' => $p->observaciones,
                'descuento' => $p->descuento, 'items' => Documentos::items($p->loadMissing('items')),
            ] : null,
            'copiaDe' => $copia ? $p->numero() : null,
        ]);
    }

    public function create(Request $request)
    {
        $desde = $request->integer('copiar');

        return $this->formulario($desde ? Presupuesto::findOrFail($desde) : null, (bool) $desde);
    }

    private function guardar(Request $request, ?Presupuesto $p): Presupuesto
    {
        $d = $request->validate(Documentos::reglas() + [
            'validez_dias' => ['required', 'integer', 'min:1', 'max:365'],
            'descuento' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ], Documentos::mensajes());
        $renglones = Documentos::renglones($d['items']);
        $subtotal = Documentos::subtotal($renglones);
        $descuento = round((float) ($d['descuento'] ?? 0), 2);
        if ($descuento > $subtotal) {
            throw new \DomainException('El descuento no puede ser mayor que el subtotal.');
        }

        return DB::transaction(function () use ($d, $p, $renglones, $subtotal, $descuento, $request) {
            $datos = Documentos::cliente($d) + [
                'validez_dias' => $d['validez_dias'], 'observaciones' => $d['observaciones'] ?? null,
                'subtotal' => $subtotal, 'descuento' => $descuento, 'total' => round($subtotal - $descuento, 2),
            ];
            if ($p) {
                $p->update($datos);
                $p->items()->delete();
            } else {
                $p = Presupuesto::create($datos + ['id_user' => $request->user()->id]);
            }
            foreach (Documentos::filas($renglones) as $r) {
                PresupuestoItem::create($r + ['presupuesto_id' => $p->id]);
            }

            return $p;
        });
    }

    public function store(Request $request)
    {
        $p = $this->guardar($request, null);

        return redirect()->route('presupuestos.show', $p)->with('ok', 'Presupuesto '.$p->numero().' guardado.');
    }

    public function show(Presupuesto $presupuesto)
    {
        $presupuesto->load(['items', 'user:id,nombre']);

        return Inertia::render('Presupuestos/Show', [
            'presupuesto' => [
                'id' => $presupuesto->id, 'numero' => $presupuesto->numero(), 'fecha' => $presupuesto->created_at, 'vence' => $presupuesto->vence()->toDateString(),
                'vencido' => $presupuesto->vence()->endOfDay()->isPast(), 'validez_dias' => $presupuesto->validez_dias,
                'usuario' => $presupuesto->user?->nombre, 'cliente' => Documentos::datosCliente($presupuesto), 'observaciones' => $presupuesto->observaciones,
                'subtotal' => $presupuesto->subtotal, 'descuento' => $presupuesto->descuento, 'total' => $presupuesto->total,
                'items' => Documentos::items($presupuesto),
            ],
        ]);
    }

    public function edit(Presupuesto $presupuesto)
    {
        return $this->formulario($presupuesto);
    }

    public function update(Request $request, Presupuesto $presupuesto)
    {
        $this->guardar($request, $presupuesto);

        return redirect()->route('presupuestos.show', $presupuesto)->with('ok', 'Presupuesto '.$presupuesto->numero().' actualizado.');
    }

    public function destroy(Presupuesto $presupuesto)
    {
        $n = $presupuesto->numero();
        $presupuesto->delete();

        return redirect()->route('presupuestos.index')->with('ok', 'Presupuesto '.$n.' eliminado.');
    }

    public function imprimir(Presupuesto $presupuesto)
    {
        $presupuesto->load('items');

        return Inertia::render('Print/Documento', [
            'tipo' => 'presupuesto',
            'doc' => [
                'numero' => $presupuesto->numero(), 'fecha' => $presupuesto->created_at, 'vence' => $presupuesto->vence()->toDateString(),
                'cliente' => Documentos::datosCliente($presupuesto), 'observaciones' => $presupuesto->observaciones,
                'subtotal' => $presupuesto->subtotal, 'descuento' => $presupuesto->descuento, 'total' => $presupuesto->total,
                'items' => Documentos::items($presupuesto),
            ],
            'emisor' => Documentos::emisor($presupuesto->id_sucursal),
        ]);
    }

    public function productos(Request $request)
    {
        return Documentos::buscarProductos($request);
    }

    public function clientes(Request $request)
    {
        return Documentos::buscarClientes($request);
    }
}
