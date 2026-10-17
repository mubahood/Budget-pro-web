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

        // Sign-in, sign-in codes and password reset (routes/api.php, on top of the 10-a-minute limit per address):
        // 6 tries a minute per account and address, per endpoint, so one account's guesses cannot use up another's.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(self::LOGIN_TRIES)->by(self::loginKey($request)));

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /** Tries a minute per account and address on the API's sign-in, code and reset endpoints (the web portal's number). */
    public const LOGIN_TRIES = 6;

    /**
     * The `login` limiter's key: the endpoint, the account (an email in lower case, a phone in E.164 so
     * every way of writing it is one account; a reset token by its hash) and the address.
     */
    public static function loginKey(Request $request): string
    {
        $raw = '';
        foreach (['identifier', 'email', 'login'] as $field) {
            $value = $request->input($field);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $raw = trim((string) $value);
                break;
            }
        }
        if ($raw !== '') {
            $country = is_string($request->input('country')) ? strtoupper((string) $request->input('country')) : 'UG';
            $account = str_contains($raw, '@') ? mb_strtolower($raw) : (\App\Support\Phone::e164($raw, $country) ?? mb_strtolower($raw));
        } else {
            $token = $request->input('reset_token');
            $account = is_scalar($token) && (string) $token !== '' ? 'reset:'.hash('sha256', (string) $token) : '';
        }

        return $request->path().'|'.$account.'|'.$request->ip();
    }

    /** The sync routes with the larger limit (conflicts and the rest keep the normal api limit). */
    public static function isSync(Request $request): bool
    {
        return $request->is('api/v1/sync/push', 'api/v1/sync/pull', 'api/v1/sync/pull-many', 'api/v1/sync/bootstrap');
    }
}
