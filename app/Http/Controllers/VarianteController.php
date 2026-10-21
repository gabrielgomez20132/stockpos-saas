<?php

namespace App\Http\Controllers;

use App\Models\Atributo;
use App\Models\Configuracion;
use App\Models\IngresoProducto;
use App\Models\Product;
use App\Services\Variantes;
use App\Support\Stock;
use App\Support\SucursalActual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class VarianteController extends Controller
{
    public function __construct(private Variantes $servicio)
    {
    }

    private function base(Product $producto): Product
    {
        abort_if($producto->id_padre, 404, 'Esto es una variante: entrá al producto principal.');

        return $producto;
    }

    public function show(Product $producto)
    {
        $base = $this->base($producto);

        return Inertia::render('Productos/Variantes', [
            'producto' => $base->only(['id', 'nombre', 'codigo', 'costo', 'monto', 'quedan', 'tiene_variantes', 'imagen_url']) + ['unidad' => $base->category?->unidad],
            'variantes' => $base->variantes()->get(['id', 'codigo', 'nombre', 'variante', 'costo', 'monto', 'quedan', 'aviso', 'activo'])->tap(fn ($l) => Stock::cargar($l)),
            'sucursal' => SucursalActual::habilitado() ? SucursalActual::nombre() : null,
            'atributos' => Atributo::with('valores')->where('activo', 1)->orderBy('orden')->get(),
            'habilitadas' => Configuracion::variantes(),
        ]);
    }

    public function generar(Request $request, Product $producto)
    {
        $base = $this->base($producto);
        $d = $request->validate([
            'seleccion' => ['required', 'array'],
            'seleccion.*' => ['array'],
            'seleccion.*.*' => ['string', 'max:30'],
        ]);
        $n = $this->servicio->generar($base, $d['seleccion'], $request->user());

        return $this->ok($n ? "Se crearon {$n} variantes. Cargales el stock y, si querés, su código de barras." : 'Esas combinaciones ya existían: no se creó ninguna nueva.');
    }

    /** guarda la tabla de variantes de una vez */
    public function guardar(Request $request, Product $producto)
    {
        $base = $this->base($producto);
        $ids = $base->variantes()->pluck('id');
        $d = $request->validate([
            'variantes' => ['required', 'array'],
            'variantes.*.id' => ['required', 'integer', Rule::in($ids)],
            'variantes.*.codigo' => ['required', 'string', 'max:100', 'distinct'],
            'variantes.*.costo' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'variantes.*.monto' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'variantes.*.quedan' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'variantes.*.aviso' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'variantes.*.activo' => ['boolean'],
        ], ['variantes.*.codigo.distinct' => 'Hay dos variantes con el mismo código.'], ['variantes.*.codigo' => 'el código']);

        $fraccionable = (bool) $base->category?->esPorPeso();
        DB::transaction(function () use ($d, $request, $fraccionable) {
            foreach ($d['variantes'] as $f) {
                $v = Product::lockForUpdate()->findOrFail($f['id']);
                if (Product::where('codigo', $f['codigo'])->where('id', '!=', $v->id)->exists() || \App\Models\Combo::where('codigo', $f['codigo'])->exists()) {
                    throw new \DomainException('El código '.$f['codigo'].' ya lo usa otro producto o combo.');
                }
                if (! $fraccionable && abs($f['quedan'] - round($f['quedan'])) > 1e-9) {
                    throw new \DomainException('El stock de '.$v->nombre.' tiene que ser un número entero.');
                }
                $antes = Stock::de($v->id, null, true);
                $v->fill(['codigo' => trim($f['codigo']), 'costo' => $f['costo'], 'monto' => $f['monto'],
                    'aviso' => $f['aviso'], 'activo' => $f['activo'] ?? true]);
                $cambio = $v->isDirty(['costo', 'monto']) || abs($antes - (float) $f['quedan']) > 1e-9;
                $v->save();
                Stock::fijar($v->id, (float) $f['quedan']);
                if ($cambio) {
                    IngresoProducto::create(['id_producto' => $v->id, 'id_user' => $request->user()->id,
                        'cantidad' => max(0, round((float) $f['quedan'] - $antes, 3)), 'costo' => $v->costo, 'monto' => $v->monto]);
                }
            }
        });

        return $this->ok('Variantes guardadas.');
    }

    public function quitar(Product $producto, Product $variante)
    {
        $base = $this->base($producto);
        abort_unless($variante->id_padre === $base->id, 404);

        return $this->ok($this->servicio->quitar($variante));
    }
}
