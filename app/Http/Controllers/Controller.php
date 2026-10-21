<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Rango de fechas de un historial (?desde=AAAA-MM-DD&hasta=...). null si no se pidio. */
    protected function rango(Request $request, int $maxDias = 400): ?array
    {
        if (! $request->filled('desde') && ! $request->filled('hasta')) {
            return null;
        }
        $v = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ], ['hasta.after_or_equal' => 'La fecha "hasta" no puede ser anterior a "desde".']);

        $desde = Carbon::parse($v['desde'])->startOfDay();
        $hasta = Carbon::parse($v['hasta'])->endOfDay();
        if ($desde->diffInDays($hasta) > $maxDias) {
            throw \Illuminate\Validation\ValidationException::withMessages(['hasta' => 'El rango no puede superar '.$maxDias.' días.']);
        }

        return [$desde, $hasta];
    }

    protected function ok(string $mensaje)
    {
        return back()->with('ok', $mensaje);
    }
}
