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

    'allowed_methods' => ['*'],

    // The frontend fetches with credentials: 'include', which browsers refuse with a '*' origin.
    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:3000')))),

    // Regular expressions, separated by `|||` in CORS_ALLOWED_ORIGIN_PATTERNS, e.g. every deployment URL of the Vercel
    // project: #^https://motamaken-front(-[a-z0-9-]+)?\.vercel\.app$#. Outside production, localhost, LAN addresses
    // and Cloudflare quick tunnels on any port are accepted too, for testing on phones and shared demos.
    'allowed_origins_patterns' => array_values(array_filter([
        ...explode('|||', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', '')),
        ...(env('APP_ENV') === 'production' ? [] : [
            '#^http://(localhost|127\.0\.0\.1|10(\.\d{1,3}){3}|192\.168(\.\d{1,3}){2}|172\.(1[6-9]|2\d|3[01])(\.\d{1,3}){2})(:\d+)?$#',
            '#^https://[a-z0-9-]+\.trycloudflare\.com$#',
        ]),
    ])),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
