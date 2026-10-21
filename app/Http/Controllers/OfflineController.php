<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\VentasSinConexion;
use App\Support\Stock;
use App\Support\SucursalActual;
use Illuminate\Http\Request;

/** Venta sin conexion: catalogo para guardar en el navegador y envio de lo vendido. */
class OfflineController extends Controller
{
    public function ping()
    {
        return response()->json(['ok' => true, 'hora' => now()->toIso8601String()]);
    }

    /** productos vendibles con precio y stock de la sucursal (sin costos) */
    public function catalogo(Request $request)
    {
        $q = Product::with('category:id,unidad')->where('products.activo', 1)->where('products.tiene_variantes', 0);
        Stock::conStock($q);
        $productos = $q->orderBy('products.nombre')->limit(20000)->get()->map(fn ($p) => [
            'id' => $p->id, 'c' => (string) $p->codigo, 'n' => $p->nombre, 'm' => $p->monto, 'q' => $p->quedan,
            'u' => $p->category?->unidad, 'f' => (bool) $p->category?->esPorPeso(),
        ]);

        return response()->json([
            'generado' => now()->toIso8601String(),
            'sucursal' => SucursalActual::id(),
            'usuario' => $request->user()->id,
            'productos' => $productos,
        ]);
    }

    public function sincronizar(Request $request, VentasSinConexion $srv)
    {
        $d = $request->validate([
            'ventas' => ['required', 'array', 'min:1', 'max:200'],
            'ventas.*.uuid' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            'ventas.*.fecha' => ['required', 'string', 'max:40'],
            'ventas.*.id_sucursal' => ['nullable', 'integer'],
            'ventas.*.medio' => ['required', 'string', 'max:20'],
            'ventas.*.descuento' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'ventas.*.total' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'ventas.*.items' => ['required', 'array', 'min:1', 'max:300'],
            'ventas.*.items.*.id' => ['required', 'integer'],
            'ventas.*.items.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'ventas.*.items.*.monto' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ]);

        $res = [];
        foreach ($d['ventas'] as $v) {
            $res[$v['uuid']] = $srv->registrar($v, $request->user());
        }

        return response()->json(['resultados' => $res]);
    }
}
