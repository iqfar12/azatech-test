<?php

declare(strict_types=1);

return [
    /*
     * Timestamps are stored in UTC; reports are bucketed by the business timezone.
     */
    'business_timezone' => env('SHOP_BUSINESS_TIMEZONE', 'Asia/Jakarta'),

    'auth' => [
        // Sanctum personal access token lifetime (minutes).
        'token_ttl_minutes' => (int) env('AUTH_TOKEN_TTL_MINUTES', 480),
    ],

    'idempotency' => [
        // How long a completed response is replayable for the same key.
        'ttl_minutes' => (int) env('IDEMPOTENCY_TTL_MINUTES', 1440),
        // A "processing" record older than this is considered abandoned (crashed worker) and may be taken over.
        'processing_timeout_seconds' => (int) env('IDEMPOTENCY_PROCESSING_TIMEOUT', 60),
    ],

    'orders' => [
        'max_items' => 50,
        'max_quantity_per_item' => 1000,
        'number_prefix' => env('ORDER_NUMBER_PREFIX', 'INV'),
    ],

    'dashboard' => [
        'cache_ttl_seconds' => (int) env('DASHBOARD_CACHE_TTL', 300),
    ],

    'rate_limits' => [
        'login_per_minute' => (int) env('RATE_LIMIT_LOGIN', 5),
        'orders_per_minute' => (int) env('RATE_LIMIT_ORDERS', 30),
    ],

    'api_docs_public' => (bool) env('API_DOCS_PUBLIC', true),
];
