<?php

namespace App\Http\Controllers;

use App\Models\Atributo;
use App\Models\AtributoValor;
use App\Models\Configuracion;
use App\Models\Control;
use App\Models\Product;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\SucursalActual;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Configuracion general: por ahora, variantes (talle, color, tamaño...). */
class ConfiguracionController extends Controller
{
    public function show(Request $request)
    {
        $multi = SucursalActual::habilitado();

        return Inertia::render('Configuracion/Index', [
            'listasPrecios' => (bool) Configuracion::get('listas_precios_habilitadas', false),
            'multisucursal' => $multi,
            'presupuestos' => (bool) Configuracion::get('presupuestos_habilitadas', false),
            'remitos' => (bool) Configuracion::get('remitos_habilitadas', false),
            'puedeSucursales' => $request->user()->hasPermission('encargados'),
            'sucursales' => Sucursal::orderBy('id')->get()->map(fn ($s) => $s->only(['id', 'nombre', 'direccion', 'telefono', 'activa']) + [
                'encargados' => User::where('id_sucursal', $s->id)->count(),
                'caja' => Control::withoutGlobalScope('sucursal')->where('id_sucursal', $s->id)->where('caja_abierta', 1)->exists(),
            ]),
            'variantes' => Configuracion::variantes(),
            'atributos' => Atributo::with('valores')->orderBy('orden')->orderBy('id')->get(),
            'productosConVariantes' => Product::where('tiene_variantes', 1)->count(),
        ]);
    }

    public function variantes(Request $request)
    {
        $d = $request->validate(['habilitadas' => ['required', 'boolean']]);
        Configuracion::set('variantes_habilitadas', (bool) $d['habilitadas']);

        return $this->ok($d['habilitadas']
            ? 'Variantes habilitadas: ya podés crearlas desde cada producto.'
            : 'Variantes deshabilitadas. Las que ya existen se siguen vendiendo normalmente.');
    }

    public function listasPrecios(Request $request)
    {
        $d = $request->validate(['habilitadas' => ['required', 'boolean']]);
        Configuracion::set('listas_precios_habilitadas', (bool) $d['habilitadas']);

        return $this->ok($d['habilitadas']
            ? 'Listas de precios habilitadas: las encontrás en el menú, en Mercadería.'
            : 'Listas de precios deshabilitadas. El historial se conserva.');
    }

    public function presupuestos(Request $request)
    {
        $d = $request->validate(['habilitadas' => ['required', 'boolean']]);
        Configuracion::set('presupuestos_habilitadas', (bool) $d['habilitadas']);

        return $this->ok($d['habilitadas']
            ? 'Presupuestos habilitados: los encontrás en el menú, en Ventas.'
            : 'Presupuestos deshabilitados. Los que ya hiciste se conservan.');
    }

    public function remitos(Request $request)
    {
        $d = $request->validate(['habilitadas' => ['required', 'boolean']]);
        Configuracion::set('remitos_habilitadas', (bool) $d['habilitadas']);

        return $this->ok($d['habilitadas']
            ? 'Remitos habilitados: los encontrás en el menú y en el detalle de cada venta.'
            : 'Remitos deshabilitados. Los que ya hiciste se conservan.');
    }

    public function multisucursal(Request $request)
    {
        abort_unless($request->user()->hasPermission('encargados'), 403);
        $d = $request->validate(['habilitado' => ['required', 'boolean']]);
        if (! $d['habilitado'] && Control::withoutGlobalScope('sucursal')->where('id_sucursal', '!=', SucursalActual::CENTRAL)->where('caja_abierta', 1)->exists()) {
            throw new \DomainException('Hay sucursales con la caja abierta: cerralas antes de deshabilitar el módulo.');
        }
        Configuracion::set('multisucursal_habilitado', (bool) $d['habilitado']);
        if (! $d['habilitado']) {
            $request->session()->forget('sucursal_id');
        }

        return $this->ok($d['habilitado']
            ? 'Multisucursal habilitado. Creá tus sucursales acá abajo y elegí en cuál trabajás desde el menú.'
            : 'Multisucursal deshabilitado: se trabaja solo en Casa Central. Los datos de las otras sucursales se conservan.');
    }

    public function crearAtributo(Request $request)
    {
        $d = $request->validate(['nombre' => ['required', 'string', 'max:30', 'unique:atributos,nombre']], [], ['nombre' => 'el nombre']);
        Atributo::create(['nombre' => trim($d['nombre']), 'orden' => (int) Atributo::max('orden') + 1, 'activo' => 1]);

        return $this->ok('Atributo "'.$d['nombre'].'" creado. Ahora agregale valores.');
    }

    public function actualizarAtributo(Request $request, Atributo $atributo)
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:30', Rule::unique('atributos', 'nombre')->ignore($atributo->id)],
            'activo' => ['boolean'],
        ]);
        $atributo->update($d);

        return $this->ok('Atributo actualizado.');
    }

    public function borrarAtributo(Atributo $atributo)
    {
        $atributo->delete();

        return $this->ok('Atributo eliminado. Las variantes ya creadas no cambian.');
    }

    public function agregarValor(Request $request, Atributo $atributo)
    {
        $d = $request->validate(['valor' => ['required', 'string', 'max:30',
            Rule::unique('atributo_valores', 'valor')->where('atributo_id', $atributo->id)]], ['valor.unique' => 'Ese valor ya existe.'], ['valor' => 'el valor']);
        AtributoValor::create(['atributo_id' => $atributo->id, 'valor' => trim($d['valor']), 'orden' => (int) $atributo->valores()->max('orden') + 1]);

        return back();
    }

    public function borrarValor(AtributoValor $valor)
    {
        $valor->delete();

        return back();
    }
}
