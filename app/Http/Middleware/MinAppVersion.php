<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Apps that send X-App-Version older than mobile.min_version get 426 upgrade_required (plan B10, P4-8). */
class MinAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $v = (string) $request->header('X-App-Version');
        if ($v === '') {
            // Older apps send no version: they keep working. Logged once a day per caller, to see who still runs them.
            $who = $request->bearerToken() ? 't'.substr(hash('sha256', (string) $request->bearerToken()), 0, 12) : 'ip'.$request->ip();
            if (\Illuminate\Support\Facades\Cache::add('app-version-missing:'.$who.':'.now()->toDateString(), 1, now()->addDay())) {
                \Illuminate\Support\Facades\Log::info('API call without X-App-Version', ['path' => $request->path(), 'caller' => $who, 'agent' => mb_substr((string) $request->userAgent(), 0, 120)]);
            }
        }
        if ($v !== '' && preg_match('/^\d+(\.\d+){0,3}$/', $v) && version_compare($v, (string) config('mobile.min_version'), '<')) {
            return response()->json(['code' => 0, 'message' => 'Please update the app to keep working.', 'data' => null,
                'errors' => ['code' => 'upgrade_required', 'min_version' => config('mobile.min_version'), 'store_url' => config('mobile.store_url')]], 426);
        }
        $response = $next($request);
        $response->headers->set('X-Min-App-Version', (string) config('mobile.min_version'));

        return $response;
    }
}
