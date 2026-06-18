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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URL'),
    ],

    /*
    | Yassen-Card supplier integration. The API token is a secret and must only
    | live in .env (never committed). `enabled` is a master switch so the sync
    | commands and order auto-fulfillment are no-ops until a token is configured.
    */
    'yassen' => [
        'base_url' => env('YASSEN_BASE_URL', 'https://api.yassen-card.com'),
        'token'    => env('YASSEN_API_TOKEN'),
        'enabled'  => env('YASSEN_SYNC_ENABLED', false),
        // Multiplier applied to the supplier's amount/quantity when calling
        // newOrder. Leave at 1 unless the live API expects a different unit.
        'qty_multiplier' => env('YASSEN_QTY_MULTIPLIER', 1),
    ],

];
