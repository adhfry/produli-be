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

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // WAJIB origin spesifik (bukan '*') -- browser menolak kombinasi wildcard origin dengan
    // supports_credentials=true (dibutuhkan untuk cookie refresh token, docs/planning/02 §6).
    // FRONTEND_URL sudah ada di .env, tinggal dipastikan isinya benar per environment.
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Wajib true -- refresh token dikirim lewat httpOnly cookie (docs/planning/02 §6), bukan body.
    'supports_credentials' => true,

];
