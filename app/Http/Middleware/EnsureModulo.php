<?php

namespace App\Http\Middleware;

use App\Models\Configuracion;
use App\Support\SucursalActual;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** El modulo tiene que estar habilitado en Configuracion. */
class EnsureModulo
{
    private const NOMBRES = ['listas_precios' => 'Listas de precios', 'multisucursal' => 'Multisucursal'];

    public function handle(Request $request, Closure $next, string $modulo): Response
    {
        $on = $modulo === 'multisucursal' ? SucursalActual::habilitado() : (bool) Configuracion::get($modulo.'_habilitadas', false);
        if (! $on) {
            $msg = 'El módulo "'.(self::NOMBRES[$modulo] ?? $modulo).'" está deshabilitado. Habilitalo en Configuración.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $msg], 404);
            }

            return redirect()->route('inicio')->with('error', $msg);
        }

        return $next($request);
    }
}
