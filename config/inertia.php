<?php

/*
 * Solo se cambia el registro de DevTools de Inertia: viene encendido en APP_ENV=local
 * y guarda en disco cada pedido con todos sus datos, accesible sin login en local.
 * Queda apagado salvo que se active a mano (INERTIA_DEVTOOLS_ENABLED=true).
 * El resto de la configuracion de Inertia sale del paquete.
 */
return [
    'devtools' => [
        'enabled' => (bool) env('INERTIA_DEVTOOLS_ENABLED', false),
        'except' => ['telescope*', 'horizon*', '_inertia/devtools*'],
        'storage' => [
            'path' => storage_path('inertia-devtools'),
            'ttl' => (int) env('INERTIA_DEVTOOLS_TTL_HOURS', 24),
            'prune_interval' => (int) env('INERTIA_DEVTOOLS_PRUNE_INTERVAL_SECONDS', 300),
            'limit' => (int) env('INERTIA_DEVTOOLS_LIMIT', 100),
        ],
        'middleware' => ['web'],
        'gate' => env('INERTIA_DEVTOOLS_GATE'),
    ],
];
