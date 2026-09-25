<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pre-v1 routes used by the shipped app (plan B10 step 4, P4-8): every call is
 * counted (before the handler, which may exit()); when retired, the old app gets
 * a "please update" answer in the envelope it understands instead of data.
 */
class LegacyApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = mb_substr($request->method().' '.($request->route()?->uri() ?? $request->path()), 0, 120);
        $userId = (int) $request->get('logged_in_user_id') ?: null;
        $companyId = $userId ? DB::table('admin_users')->where('id', $userId)->value('company_id') : null;
        try {
            DB::statement('INSERT INTO legacy_calls (day, route, company_id, user_id, calls, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE calls = calls + 1, updated_at = NOW()', [now()->toDateString(), $route, $companyId, $userId]);
        } catch (\Throwable) {
            // Counting must never break the old app.
        }
        if (! config('mobile.legacy_enabled')) {
            return response()->json(['code' => 0, 'message' => 'This version of the app is no longer supported. Please update Budget Pro from the Play Store: '.config('mobile.store_url'),
                'data' => null, 'errors' => ['code' => 'upgrade_required', 'store_url' => config('mobile.store_url')]], 426);
        }

        return $next($request);
    }
}
