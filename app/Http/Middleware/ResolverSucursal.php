<?php

namespace App\Http\Middleware;

use App\Support\SucursalActual;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Fija en que sucursal trabaja este pedido (la asignada al encargado o la elegida). */
class ResolverSucursal
{
    public function handle(Request $request, Closure $next): Response
    {
        $elegida = $request->hasSession() ? (int) $request->session()->get('sucursal_id') : null;
        SucursalActual::fijar(SucursalActual::resolver($request->user(), $elegida ?: null));

        return $next($request);
    }
}
