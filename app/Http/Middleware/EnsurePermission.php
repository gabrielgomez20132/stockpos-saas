<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permiso)
    {
        if (! $request->user()?->hasPermission($permiso)) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => 'No tenés permiso para esta acción.'], 403);
            }
            abort(403, 'No tenés permiso para acceder a esta sección.');
        }

        return $next($request);
    }
}
