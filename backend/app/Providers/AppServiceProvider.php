<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureModels();
        $this->configureRoutes();
        $this->configureRateLimiting();
        $this->configureAuthorization();
        $this->configureApiDocs();
    }

    private function configureModels(): void
    {
        // Outside production: lazy loading throws (catches N+1 in tests) and filling a
        // non-fillable attribute throws instead of being silently dropped.
        $strict = ! $this->app->isProduction();
        Model::preventLazyLoading($strict);
        Model::preventSilentlyDiscardingAttributes($strict);
    }

    private function configureRoutes(): void
    {
        // Malformed ids become 404 instead of a PostgreSQL "invalid input syntax" 500.
        Route::pattern('product', '[0-9]+');
        Route::pattern('order', '[0-9]+');
        Route::pattern('customer', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Brute-force protection: per e-mail+IP and a looser per-IP ceiling.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute((int) config('shop.rate_limits.login_per_minute'))
                ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('orders', fn (Request $request) => Limit::perMinute((int) config('shop.rate_limits.orders_per_minute'))
            ->by('orders:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    private function configureAuthorization(): void
    {
        // Model policies (ProductPolicy, CustomerPolicy, OrderPolicy) are auto-discovered.
        Gate::define('view-reports', fn (User $user): bool => $user->isAdmin());
        Gate::define('view-low-stock-alerts', fn (User $user): bool => $user->isAdmin());
        Gate::define('viewApiDocs', fn (?User $user = null): bool => (bool) config('shop.api_docs_public'));
    }

    private function configureApiDocs(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        $bearer = function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::http('bearer'));
        };

        // Scramble >= 0.12 exposes configure(); older releases use extendOpenApi().
        if (method_exists(Scramble::class, 'configure')) {
            Scramble::configure()->withDocumentTransformers($bearer);
        } else {
            Scramble::extendOpenApi($bearer);
        }
    }
}
