<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// No server-rendered pages: the UI is the separate `web` service.
// OpenAPI UI is served by Scramble at /docs/api (JSON at /docs/api.json).
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'docs' => url('/docs/api'),
    'health' => url('/api/health'),
]));
