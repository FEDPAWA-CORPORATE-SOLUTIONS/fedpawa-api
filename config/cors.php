<?php

// ============================================================
// CORS (audit FIX-SECURITY) — origines restreintes, JAMAIS '*'.
// APP_URL (prod) + front dev localhost:3000. Utilisé par le
// middleware global HandleCors (bootstrap/app.php, Laravel 13).
// ============================================================

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter([
        env('APP_URL'),
        'https://www.fedpawa.com',
        'http://localhost:3000',
        'http://localhost',
    ]))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Requis : auth SPA Sanctum (cookies de session).
    'supports_credentials' => true,

];
