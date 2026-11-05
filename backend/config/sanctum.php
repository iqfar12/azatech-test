<?php

declare(strict_types=1);

return [
    /*
     * The SPA authenticates with bearer tokens, so no domain is "stateful"
     * (no session cookies, no CSRF cookie flow).
     */
    'stateful' => [],

    'guard' => [],

    // Upper bound in minutes; each token also gets its own expires_at at creation time.
    'expiration' => (int) env('AUTH_TOKEN_TTL_MINUTES', 480),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'azs_'),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
