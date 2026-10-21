<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Solo encargados activos (o Super admin) entran al panel. */
class EnsureStaff
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if (! $u || ! $u->activo || ! $u->isStaff()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Tu usuario no tiene acceso al sistema.');
        }

        return $next($request);
    }
}
