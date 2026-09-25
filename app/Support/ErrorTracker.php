<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Error tracking (plan Part D "Sentry"): unexpected exceptions are grouped by
 * fingerprint with a count and the last request context, shown on the platform
 * System health page. If the Sentry SDK is installed and SENTRY_LARAVEL_DSN is
 * set, events are forwarded to Sentry too.
 */
class ErrorTracker
{
    private static bool $busy = false;

    public static function capture(Throwable $e): void
    {
        if (self::$busy || $e instanceof \App\Exceptions\BusinessRuleException || (app()->runningUnitTests() && ! config('app.track_errors_in_tests', false))) {
            return; // business-rule refusals are expected answers, not errors
        }
        self::$busy = true;
        try {
            if (function_exists('\Sentry\captureException') && config('services.sentry_dsn')) {
                \Sentry\captureException($e);
            }
            if (! Schema::hasTable('error_events')) {
                return;
            }
            $file = str_replace(base_path().'/', '', $e->getFile());
            $fingerprint = hash('sha256', get_class($e).'|'.$file.'|'.$e->getLine());
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
            $context = $request === null ? ['url' => 'console'] : ['request_id' => $request->attributes->get('request_id'), 'company_id' => $request->user()?->company_id, 'user_id' => $request->user()?->id,
                'device_id' => $request->header('X-Device-Id'), 'url' => $request->method().' '.$request->path()];
            $updated = DB::table('error_events')->where('fingerprint', $fingerprint)->update([
                'count' => DB::raw('count + 1'), 'last_seen_at' => now(), 'last_context' => json_encode($context), 'resolved_at' => null, 'message' => mb_substr($e->getMessage(), 0, 500),
            ]);
            if ($updated === 0) {
                DB::table('error_events')->insertOrIgnore(['fingerprint' => $fingerprint, 'class' => mb_substr(get_class($e), 0, 191), 'message' => mb_substr($e->getMessage(), 0, 500),
                    'file' => mb_substr($file, 0, 255), 'line' => $e->getLine(), 'count' => 1, 'last_context' => json_encode($context), 'first_seen_at' => now(), 'last_seen_at' => now()]);
            }
        } catch (Throwable) {
            // Never let error tracking cause an error.
        } finally {
            self::$busy = false;
        }
    }
}
