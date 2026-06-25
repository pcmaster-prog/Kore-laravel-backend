<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'auth/*', 'register', 'email/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter([
        // Frontend principal Kore (producción). Debe coincidir con el dominio stateful de Sanctum.
        env('KORE_FRONTEND_URL'),
        // Portales ATS bajo el mismo dominio raíz
        env('APP_FRONTEND_PORTAL_URL'),
        // Capacitor / aplicación móvil
        'capacitor://localhost',
        // Orígenes de desarrollo local
        env('APP_ENV') !== 'production' ? 'http://localhost:5173' : null,
        env('APP_ENV') !== 'production' ? 'http://localhost:3000' : null,
        // Origen adicional configurable por entorno (p.ej. preview deploys específicos)
        env('CORS_EXTRA_ORIGIN'),
    ]),

    // No uses patrones tipo *.vercel.app en producción; agrega cada dominio de preview
    // explícitamente mediante CORS_EXTRA_ORIGIN.
    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'Authorization', 'X-XSRF-TOKEN', 'Accept', 'Origin'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
