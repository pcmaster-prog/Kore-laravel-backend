<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'firebase' => [
        'project_id'   => env('FIREBASE_PROJECT_ID'),
        'client_email' => env('FIREBASE_CLIENT_EMAIL'),
        // Railway almacena \n literales; los convertimos a saltos de línea reales
        'private_key'  => str_replace('\\n', "\n", env('FIREBASE_PRIVATE_KEY', '')),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // OAuth redirect_uri: Google debe volver al BACKEND, no al portal.
        // La ruta real incluye el prefijo /api que Laravel añade a api.php.
        'redirect' => env('GOOGLE_REDIRECT_URL', rtrim(env('APP_URL', 'https://api.decorartereposteria.mx'), '/') . '/api/v1/auth/google/callback'),
        // URL final del portal a la que redirigimos con el token en fragmento (#).
        'frontend_portal_url' => env('FRONTEND_PORTAL_URL', env('APP_FRONTEND_PORTAL_URL', 'https://vacantes.decorartereposteria.mx')),
    ],

    'whatsapp' => [
        'api_key' => env('WHATSAPP_API_KEY'),
        'phone' => env('WHATSAPP_PHONE'),
    ],

];
