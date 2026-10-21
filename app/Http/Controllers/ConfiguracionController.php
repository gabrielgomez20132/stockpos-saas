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
            'combos' => Configuracion::combos(),
            'afip' => Configuracion::afip(),
            'afipListo' => \App\Models\AfipConfig::actual()->listo(),
            'negocio' => \App\Models\AfipConfig::actual()->only(['razon_social', 'domicilio', 'localidad']),
            'logo' => \App\Http\Middleware\HandleInertiaRequests::logoNegocio(),
            'panaderia' => \App\Support\Panaderia::config(),
            'cantidadCombos' => \App\Models\Combo::count(),
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

    public function panaderia(Request $request)
    {
        $d = $request->validate([
            'habilitada' => ['required', 'boolean'], 'importe' => ['boolean'], 'balanza' => ['boolean'], 'produccion' => ['boolean'],
            'prefijos' => ['required', 'string', 'max:40', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/'],
            'digitos_codigo' => ['required', 'integer', 'min:1', 'max:7'],
            'tipo' => ['required', Rule::in(['peso', 'precio'])],
            'decimales' => ['required', 'integer', 'min:0', 'max:3'],
            'verificar' => ['boolean'],
        ], ['prefijos.regex' => 'Los prefijos son números de 1 a 3 cifras separados por coma (ej: 20 o 20,21).']);
        $largo = 12 - max(array_map('strlen', \App\Support\Panaderia::prefijos($d))) - (int) $d['digitos_codigo'];
        if ($largo < 3) {
            throw new \DomainException('Con ese prefijo y esos dígitos de código no queda lugar para el peso o el precio (quedan '.max(0, $largo).' dígitos, hacen falta al menos 3).');
        }
        foreach (['importe', 'balanza', 'produccion', 'verificar'] as $b) {
            $d[$b] = (bool) ($d[$b] ?? false);
        }
        $d['habilitada'] = (bool) $d['habilitada'];
        $d['prefijos'] = implode(',', \App\Support\Panaderia::prefijos($d));
        Configuracion::set('panaderia', $d);

        return $this->ok($d['habilitada'] ? 'Panadería: configuración guardada.' : 'Panadería deshabilitada. La producción cargada se conserva.');
    }

    /** prueba una etiqueta de balanza con la configuracion que se esta editando (sin guardarla) */
    public function probarBalanza(Request $request)
    {
        $d = $request->validate([
            'codigo' => ['required', 'string', 'max:20'],
            'prefijos' => ['required', 'string', 'max:40'], 'digitos_codigo' => ['required', 'integer', 'min:1', 'max:7'],
            'tipo' => ['required', Rule::in(['peso', 'precio'])], 'decimales' => ['required', 'integer', 'min:0', 'max:3'], 'verificar' => ['boolean'],
        ]);
        $cfg = ['prefijos' => $d['prefijos'], 'digitos_codigo' => (int) $d['digitos_codigo'], 'tipo' => $d['tipo'], 'decimales' => (int) $d['decimales'], 'verificar' => (bool) ($d['verificar'] ?? false)];
        try {
            $l = \App\Support\Panaderia::leer($d['codigo'], $cfg);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }
        if (! $l) {
            return response()->json(['ok' => false, 'message' => 'No es una etiqueta de balanza con esa configuración (tiene que tener 13 números y empezar con '.$cfg['prefijos'].').']);
        }
        $p = Product::with('category:id,unidad')->where('activo', 1)->whereIn('codigo', \App\Support\Panaderia::codigosPlu($l['plu']))->first();
        $cant = $p ? ($l['tipo'] === 'precio' ? \App\Support\Panaderia::cantidadPorImporte($l['valor'], (float) $p->monto) : $l['valor']) : null;

        return response()->json(['ok' => true, 'plu' => $l['plu'], 'tipo' => $l['tipo'], 'valor' => $l['valor'],
            'producto' => $p ? ['nombre' => $p->nombre, 'codigo' => $p->codigo, 'monto' => $p->monto, 'unidad' => $p->category?->unidad] : null,
            'cantidad' => $cant, 'importe' => $p && $cant !== null ? ceil(round($cant * $p->monto, 4)) : null]);
    }

    public function afip(Request $request)
    {
        $d = $request->validate(['habilitada' => ['required', 'boolean']]);
        Configuracion::set('afip_habilitada', (bool) $d['habilitada']);

        return $this->ok($d['habilitada']
            ? 'Facturación AFIP habilitada: completá los datos y los certificados en Configuración AFIP.'
            : 'Facturación AFIP deshabilitada: no se factura desde las ventas. Los comprobantes emitidos y los certificados se conservan.');
    }

    /** datos del negocio que salen en tickets, presupuestos y remitos (aunque no se use AFIP) */
    public function negocio(Request $request)
    {
        $d = $request->validate([
            'razon_social' => ['required', 'string', 'max:120'],
            'domicilio' => ['nullable', 'string', 'max:150'],
            'localidad' => ['nullable', 'string', 'max:100'],
        ], ['razon_social.required' => 'Poné el nombre del negocio: sale en los tickets, presupuestos y remitos.']);
        \App\Models\AfipConfig::obtener()->fill($d)->save();

        return $this->ok('Datos del negocio guardados.');
    }

    public function combos(Request $request)
    {
        $d = $request->validate(['habilitadas' => ['required', 'boolean']]);
        Configuracion::set('combos_habilitadas', (bool) $d['habilitadas']);

        return $this->ok($d['habilitadas']
            ? 'Combos y promos habilitados: los encontrás en el menú, en Mercadería, y se venden escaneando su código.'
            : 'Combos y promos deshabilitados: no aparecen al vender. Los que ya cargaste se conservan.');
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
