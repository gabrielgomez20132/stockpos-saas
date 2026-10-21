<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Vale;
use App\Services\Ventas;
use Illuminate\Http\Request;

/** Busquedas en vivo de la pantalla de venta (JSON). */
class BusquedaController extends Controller
{
    public function productos(Request $request, Ventas $ventas)
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        if ($q === '') {
            return response()->json([]);
        }

        $combos = $ventas->buscarCombos($q, 4)->map(fn ($c) => VentaController::comboParaLista($c));

        return response()->json($combos->concat($ventas->buscar($q, 12)->map(fn ($p) => [
            'id' => $p->id, 'tipo' => 'prod', 'codigo' => $p->codigo, 'nombre' => $p->nombre, 'monto' => $p->monto,
            'quedan' => $p->quedan, 'unidad' => $p->category?->unidad, 'imagen' => $p->imagen_url,
        ]))->values());
    }

    public function clientes(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';

        return response()->json(User::tipo(User::TIPO_CLIENTE)->where('activo', 1)
            ->when($q !== '', fn ($w) => $w->where('nombre', 'like', $like))
            ->orderBy('nombre')->limit(30)->get(['id', 'nombre']));
    }

    public function valesCliente(User $cliente)
    {
        abort_unless($cliente->id_uType === User::TIPO_CLIENTE, 404);

        return response()->json(Vale::where('id_cliente', $cliente->id)->disponibles()
            ->orderBy('fecha_vencimiento')->get(['id', 'codigo_vale', 'monto_disponible', 'fecha_vencimiento']));
    }

    /** Vale "al portador": por codigo completo o por los ultimos digitos (minimo 4). */
    public function valePorCodigo(Request $request)
    {
        $codigo = strtoupper(trim((string) $request->query('codigo', '')));
        if (mb_strlen($codigo) < 4) {
            return response()->json(null, 404);
        }

        $vale = Vale::disponibles()->where('codigo_vale', $codigo)->first()
            ?? Vale::disponibles()->where('codigo_vale', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $codigo))->first();

        return $vale
            ? response()->json($vale->only(['id', 'codigo_vale', 'monto_disponible', 'fecha_vencimiento', 'id_cliente']))
            : response()->json(null, 404);
    }
}
