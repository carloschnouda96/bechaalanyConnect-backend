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

    /*
    | SwiftServices supplier integration (Perfect Panel v2). Auth is the `key`
    | query parameter — a secret that must only live in .env (never committed).
    | `enabled` is a master switch so the sync commands and order
    | auto-fulfillment are no-ops until a key is configured.
    */
    'swift' => [
        'base_url' => env('SWIFT_BASE_URL', 'https://swiftservices.store'),
        'key'      => env('SWIFT_API_KEY'),
        'enabled'  => env('SWIFT_SYNC_ENABLED', false),
    ],

    /*
    | 1xpanel supplier integration (Perfect Panel v2, like Swift). Auth is the
    | `key` query parameter — a secret that must only live in .env (never
    | committed). `enabled` is a master switch so the sync commands and order
    | auto-fulfillment are no-ops until a key is configured. The env prefix is
    | ONEXPANEL_ (not 1XPANEL_) because env-var names starting with a digit can't
    | be exported by POSIX shells / Docker / Forge.
    */
    '1xpanel' => [
        'base_url' => env('ONEXPANEL_BASE_URL', 'https://1xpanel.com'),
        'key'      => env('ONEXPANEL_API_KEY'),
        'enabled'  => env('ONEXPANEL_SYNC_ENABLED', false),
    ],

    /*
    | usharez supplier integration (https://bechaalanyconnect.usharez.com). Auth is
    | an `Authorization: Bearer <token>` header — a secret that must only live in
    | .env (never committed). Two independent product families sit behind one
    | account, each with its own switch so one can roll out without the other:
    |   quota  GET /api/quota/admin/stock  →  POST /api/quota/admin/allocate
    |   gift   GET /api/alfa-gifts         →  POST /api/alfa-gifts/purchase
    */
    'usharez' => [
        'base_url' => env('USHAREZ_BASE_URL', 'https://bechaalanyconnect.usharez.com'),
        'token'    => env('USHAREZ_API_KEY'),
        'enabled'  => env('USHAREZ_SYNC_ENABLED', false),

        // Quota stock prices arrive in LBP (660000 ≈ $7.4) but the storefront is
        // USD-only, so the connector divides by this. Fixed Settings
        // (usharez_lbp_per_usd) overrides it when set, so admins can update the
        // rate from the CMS without a deploy. A wrong value mis-prices the whole
        // catalog — review derived costs before enabling a category's import.
        'lbp_per_usd' => env('USHAREZ_LBP_PER_USD', 89500),

        'quota_enabled' => env('USHAREZ_QUOTA_ENABLED', true),
        // The live token currently gets HTTP 403 on /api/alfa-gifts. The sync
        // tolerates that (logs a warning, keeps quota intact), so this stays on:
        // gifts start importing the moment usharez grants the permission.
        'gifts_enabled'  => env('USHAREZ_GIFTS_ENABLED', true),
        // Unverified along with the rest of the gifts payload — flip to 'lbp' if a
        // real response turns out to price gifts in lira.
        'gifts_currency' => env('USHAREZ_GIFTS_CURRENCY', 'usd'),
        'gift_max_qty'   => env('USHAREZ_GIFT_MAX_QTY', 10),

        // 'national8' keeps the operator's leading zero (03123456);
        // 'strip_leading_zero' sends 3123456. Confirm with the first live order.
        'phone_format' => env('USHAREZ_PHONE_FORMAT', 'national8'),
    ],

    /*
    | U-Manage supplier integration (https://api.umanageapp.uk/api/v1/external).
    | Auth is a PAIR of headers, X-API-Key + X-API-Secret — both secrets that must
    | only live in .env. Every endpoint is scoped by a store id: pin it with
    | UMANAGE_STORE_ID, otherwise the first store from GET /stores is used.
    | Five families across three order endpoints, grouped by the three switches
    | below (one HTTP call feeds both SMS families, and both telecom families).
    | Rate limit is 1000 requests/hour per key — see CheckUmanageOrders.
    */
    'umanage' => [
        'base_url' => env('UMANAGE_BASE_URL', 'https://api.umanageapp.uk/api/v1/external'),
        'key'      => env('UMANAGE_API_KEY'),
        'secret'   => env('UMANAGE_API_SECRET'),
        'store_id' => env('UMANAGE_STORE_ID'),
        'enabled'  => env('UMANAGE_SYNC_ENABLED', false),

        'bundles_enabled' => env('UMANAGE_BUNDLES_ENABLED', true),
        'sms_enabled'     => env('UMANAGE_SMS_ENABLED', true),
        'telecom_enabled' => env('UMANAGE_TELECOM_ENABLED', true),

        // Per-supplier LBP→USD override; normally left empty so the shared
        // platform rate below (or Fixed Settings) applies.
        'lbp_per_usd' => env('UMANAGE_LBP_PER_USD'),
    ],

    /*
    | Shared platform LBP→USD divisor, used by every supplier that quotes in
    | Lebanese Pounds (usharez, umanage). Fixed Settings `lbp_per_usd` overrides
    | it so admins can update the rate from the CMS without a deploy; a
    | supplier-specific Fixed Settings column (e.g. usharez_lbp_per_usd) still
    | wins over both. See App\Services\Suppliers\ExchangeRate.
    */
    'lbp_per_usd' => env('LBP_PER_USD', 89500),

    /*
    | Shared secret for the HTTP cron entrypoints (CronController). When set, the
    | /api/cron/* routes require a matching `?token=` so only the host's cron can
    | trigger them. Leave empty to keep the endpoints open (like the host's other
    | tokenless wget jobs).
    */
    'cron' => [
        'token' => env('CRON_TOKEN'),
    ],

];
