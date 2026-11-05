<?php

declare(strict_types=1);

/*
 * Explicit allow-list (never "*"). Comma separated, e.g.
 * CORS_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:8080
 */
$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:8080')),
)));

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key', 'X-Request-Id'],
    'exposed_headers' => ['X-Request-Id', 'Idempotent-Replayed', 'Retry-After', 'Content-Disposition'],
    'max_age' => 600,
    // Bearer tokens only, no cookies.
    'supports_credentials' => false,
];
