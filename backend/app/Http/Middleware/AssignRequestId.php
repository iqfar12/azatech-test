<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for logs:
 *  - reuses a well-formed incoming X-Request-Id (e.g. from the gateway / frontend),
 *    otherwise generates a UUID;
 *  - stores it in Laravel's Context → automatically added to every log record of this
 *    request AND propagated into queued jobs dispatched by it;
 *  - echoes it back in the response header;
 *  - writes one structured access-log line per request.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $requestId = is_string($incoming) && preg_match('/^[A-Za-z0-9._\-]{8,100}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        $request->headers->set(self::HEADER, $requestId);
        Context::add('request_id', $requestId);

        $startedAt = microtime(true);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        Log::info('http.request', [
            'method' => $request->getMethod(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'ip' => $request->ip(),
        ]);

        return $response;
    }
}
