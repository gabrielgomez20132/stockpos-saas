<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        // en produccion con https, los links y redirecciones siempre salen con https
        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // en desarrollo, avisar de atributos no permitidos y relaciones sin cargar
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        RateLimiter::for('login', function (Request $request) {
            $usuario = mb_strtolower((string) $request->input('usuario'));

            return [
                Limit::perMinute(5)->by($usuario.'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Las tres "personas" comparten la tabla users: cada ruta solo acepta su tipo.
        // El Administrador (id 1) y el cliente General (id 2) no se editan desde las listas.
        Route::bind('cliente', fn ($id) => User::tipo(User::TIPO_CLIENTE)->where('id', '!=', User::ID_CLIENTE_GENERAL)->findOrFail($id));
        Route::bind('proveedor', fn ($id) => User::tipo(User::TIPO_PROVEEDOR)->findOrFail($id));
        Route::bind('encargado', fn ($id) => User::tipo(User::TIPO_ENCARGADO)->where('id', '!=', User::ID_ADMIN)->findOrFail($id));
        Route::bind('categoria', fn ($id) => \App\Models\ProductCategory::findOrFail($id));
        Route::bind('producto', fn ($id) => \App\Models\Product::findOrFail($id));
        Route::bind('variante', fn ($id) => \App\Models\Product::findOrFail($id));
        Route::bind('valor', fn ($id) => \App\Models\AtributoValor::findOrFail($id));
        Route::bind('item', fn ($id) => \App\Models\OrderProduct::whereHas('order')->findOrFail($id)); // la venta tiene que ser de esta sucursal
        Route::bind('servicio', fn ($id) => \App\Models\OrderService::whereHas('order')->findOrFail($id));
        Route::bind('lista', fn ($id) => \App\Models\ListaPrecio::findOrFail($id));
        Route::bind('renglon', fn ($id) => \App\Models\ListaPrecioItem::findOrFail($id));
        Route::bind('sucursal', fn ($id) => \App\Models\Sucursal::findOrFail($id));
        Route::bind('transferencia', fn ($id) => \App\Models\Transferencia::findOrFail($id));
        Route::bind('cierre', fn ($id) => \App\Models\CierreCaja::findOrFail($id));
        Route::bind('devolucion', fn ($id) => \App\Models\Devolucion::findOrFail($id));
    }
}
