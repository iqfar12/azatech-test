<?php

declare(strict_types=1);

/*
 * Only the PostgreSQL connection is redefined (Laravel merges the rest from the
 * framework defaults). The session time zone is pinned to UTC so that
 * timestamptz columns and bound date parameters never depend on the server's
 * TimeZone setting.
 */
return [
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'online_shop'),
            'username' => env('DB_USERNAME', 'shop'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'timezone' => 'UTC',
        ],
    ],
];
