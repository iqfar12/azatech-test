<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthController extends Controller
{
    /**
     * Liveness + dependencies (PostgreSQL, Redis). 200 when all are up, 503 otherwise.
     *
     * @unauthenticated
     */
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(static fn () => DB::select('SELECT 1')),
            'redis' => $this->check(static fn () => Redis::connection()->command('ping')),
        ];

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return new JsonResponse([
            'success' => $healthy,
            'message' => $healthy ? 'OK' : 'Degraded',
            'data' => ['status' => $healthy ? 'up' : 'down', 'checks' => $checks],
        ], $healthy ? 200 : 503);
    }

    /**
     * @return array{ok: bool, latency_ms: int}
     */
    private function check(callable $probe): array
    {
        $start = microtime(true);

        try {
            $probe();
            $ok = true;
        } catch (Throwable $e) {
            // Details go to the log, never to the (public) response.
            Log::error('health.check_failed', ['error' => $e->getMessage()]);
            $ok = false;
        }

        return ['ok' => $ok, 'latency_ms' => (int) round((microtime(true) - $start) * 1000)];
    }
}
