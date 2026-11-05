<?php

declare(strict_types=1);

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: null,
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Request id must run first so every log line (including auth failures) carries it.
        $middleware->prepend(AssignRequestId::class);
        $middleware->api(prepend: [ForceJsonResponse::class]);

        // We are a pure token API: never redirect unauthenticated users to a "login" route.
        $middleware->redirectGuestsTo(fn () => null);

        // Behind the nginx container / load balancer.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return app(ApiExceptionRenderer::class)->render($e, $request);
        });

        // Business rule violations are expected outcomes, not errors worth a stack trace in the logs.
        $exceptions->dontReport([
            App\Exceptions\BusinessRuleException::class,
        ]);
    })
    ->create();
