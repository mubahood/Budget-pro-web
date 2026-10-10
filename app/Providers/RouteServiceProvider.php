<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            // S5: an offline phone catching up sends many sync calls in a burst; they get their own, larger budget.
            if (self::isSync($request)) {
                return RateLimiter::limiter('sync')($request);
            }

            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Sync push/pull/pull-many/bootstrap: 300 a minute per user and device (X-Device-Id). A 429 carries Retry-After.
        RateLimiter::for('sync', function (Request $request) {
            $user = $request->user() ?? $request->user('sanctum');
            $device = (string) ($request->header('X-Device-Id') ?: $request->input('device_id', ''));

            return Limit::perMinute((int) config('sync.rate_per_minute', 300))
                ->by('sync|'.($user?->id ?: $request->ip()).'|'.mb_substr($device, 0, 64));
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /** The sync routes with the larger limit (conflicts and the rest keep the normal api limit). */
    public static function isSync(Request $request): bool
    {
        return $request->is('api/v1/sync/push', 'api/v1/sync/pull', 'api/v1/sync/pull-many', 'api/v1/sync/bootstrap');
    }
}
