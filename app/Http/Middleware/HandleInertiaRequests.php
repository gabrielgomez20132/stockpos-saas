<?php

namespace App\Http\Middleware;

use App\Models\AfipConfig;
use App\Models\Configuracion;
use App\Models\Sucursal;
use App\Support\SucursalActual;
use App\Services\Caja;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => [
                'nombre' => config('app.name'),
                'logo' => self::logoNegocio(),
            ],
            'auth' => $user ? [
                'user' => [
                    'id' => $user->id,
                    'nombre' => $user->nombre,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name'),
                    'super' => $user->isSuperAdmin(),
                ],
                'permisos' => $user->permisos(),
            ] : null,
            'modulos' => fn () => [
                'listas_precios' => (bool) Configuracion::get('listas_precios_habilitadas', false),
                'multisucursal' => SucursalActual::habilitado(),
                'presupuestos' => (bool) Configuracion::get('presupuestos_habilitadas', false),
                'remitos' => (bool) Configuracion::get('remitos_habilitadas', false),
            ],
            'sucursal' => fn () => $user && SucursalActual::habilitado() ? [
                'id' => SucursalActual::id(),
                'nombre' => SucursalActual::nombre(),
                'puedeCambiar' => ! $user->id_sucursal,
                'lista' => $user->id_sucursal ? [] : Sucursal::where('activa', 1)->orderBy('id')->get(['id', 'nombre']),
            ] : null,
            'csrf_token' => fn () => csrf_token(),
            'caja' => fn () => $user ? ['abierta' => app(Caja::class)->abierta()] : null,
            'flash' => [
                'ok' => fn () => $request->session()->get('ok'),
                'error' => fn () => $request->session()->get('error'),
                'abrir' => fn () => $request->session()->get('abrir'),
                'importacion' => fn () => $request->session()->get('importacion'),
            ],
        ];
    }

    public static function logoNegocio(): ?string
    {
        foreach (['png', 'jpg', 'webp'] as $ext) {
            $f = public_path('uploads/logo-negocio.'.$ext);
            if (is_file($f)) {
                return '/uploads/logo-negocio.'.$ext.'?v='.filemtime($f);
            }
        }

        return null;
    }
}
