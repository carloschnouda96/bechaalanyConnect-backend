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

    /*
     | Was ['*'], i.e. any site on the internet could call this API from a
     | visitor's browser. That was survivable only because the API is bearer-token
     | based and supports_credentials is false, so no cookie could ride along — but
     | it still let any origin scrape every public endpoint, and gave any XSS
     | anywhere a free relay for a stolen token.
     |
     | Driven by CORS_ALLOWED_ORIGINS (comma-separated). Falls back to the
     | configured frontend URL so a correctly configured deployment keeps working
     | even if the new variable is missed.
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', (string) env('APP_FRONT_URL', 'http://localhost:3000')))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
