<?php

// ============================================================
// CORS — Origine unique : https://www.fedpawa.com
// ============================================================

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['https://www.fedpawa.com', 'https://app.fedpawa.com'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Requis : auth SPA Sanctum (cookies de session).
    'supports_credentials' => true,

];
