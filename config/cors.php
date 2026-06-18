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

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter([
        'https://kore-react-frontend.vercel.app',
        'capacitor://localhost',
        // Portales ATS
        'https://phj3cqi66s6kw.kimi.page',
        'https://vacantes.decorartereposteria.mx',
        // Localhost solo en desarrollo (con puerto explícito)
        env('APP_ENV') !== 'production' ? 'http://localhost:5173' : null,
        env('APP_ENV') !== 'production' ? 'http://localhost:3000' : null,
        // Origen adicional configurable por entorno
        env('CORS_EXTRA_ORIGIN'),
    ]),

    // Permite preview deploys de Vercel (*.vercel.app)
    'allowed_origins_patterns' => [
        '/^https:\/\/kore-.*\.vercel\.app$/',
    ],

    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'Authorization', 'X-XSRF-TOKEN', 'Accept', 'Origin'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
