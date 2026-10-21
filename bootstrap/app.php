<?php

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/*
 * Apache en Windows (Laragon) corre todos los sitios en un mismo proceso: el
 * .env de OTRO proyecto Laravel (ej. DB_DATABASE=stocky_saas) queda en el
 * entorno del proceso y Laravel no lo pisa con el nuestro. Por eso:
 *  - no escribimos nuestras variables en el entorno compartido (putenv), y
 *  - las de nuestro .env siempre ganan para este sitio.
 * Con "php artisan config:cache" esto no hace falta (ya no se lee el .env).
 */
Illuminate\Support\Env::disablePutenv();
if (PHP_SAPI !== 'cli' && ! is_file(__DIR__.'/cache/config.php') && is_file($envPropio = dirname(__DIR__).'/.env')) {
    foreach (Dotenv\Dotenv::parse((string) file_get_contents($envPropio)) as $clave => $valor) {
        if ($valor !== null) {
            $_ENV[$clave] = $_SERVER[$clave] = $valor;
        }
    }
    unset($envPropio, $clave, $valor);
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // En el VPS se entra por nginx-proxy -> nginx -> php: se confia solo en
        // los proxies de las redes internas (Docker), para saber que el pedido vino por HTTPS.
        $middleware->trustProxies(
            at: ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->web(append: [
            \App\Http\Middleware\ResolverSucursal::class,
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);
        $middleware->alias([
            'staff' => EnsureStaff::class,
            'permiso' => EnsurePermission::class,
            'modulo' => \App\Http\Middleware\EnsureModulo::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('inicio'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia')),
        );

        // Errores de negocio (caja cerrada, sin stock...) -> mensaje amable
        $exceptions->render(function (\DomainException $e, Request $request) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        });

        // Paginas de error dentro de la app (403, 404, 500, 503)
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            $esJson = $request->expectsJson() && ! $request->header('X-Inertia');

            if ($status === 419 && ! $esJson) {
                return back()->with('error', 'La página venció por inactividad. Probá de nuevo.');
            }
            if (! $esJson && in_array($status, [403, 404, 500, 503], true)
                && ! ($status >= 500 && config('app.debug'))) {
                return Inertia::render('Error', [
                    'status' => $status,
                    'mensaje' => $status === 403 ? ($e->getMessage() ?: null) : null,
                ])->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });
    })->create();
