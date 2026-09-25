<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Subscription / licence enforcement for the web admin (P0-3, plan C8).
 * Mirrors the API's EnsureActiveSubscription with the grace policy:
 *
 *   active   -> everything
 *   grace    -> read-only (GET) with a warning banner; writes are refused
 *   expired  -> only the "subscription expired" page, settings and logout
 *   platform admins are never restricted.
 */
class EnsureWebAccess
{
    /** Paths (no leading slash) that stay reachable whatever the state. */
    private const ALWAYS_ALLOWED = ['subscription-expired', 'billing', 'billing/*', 'auth/logout', 'auth/setting', 'auth/login', '_handle_action_', '_handle_form_', '_handle_selectable_', '_handle_renderable_'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Admin::user();
        if ($user === null || PlatformAdminOnly::isPlatformAdmin($user) || $request->is(...self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        $company = Company::find($user->company_id);
        if ($company === null) {
            return $next($request); // HomeController renders the "company not found" notice
        }

        $state = $company->accessState();

        if ($state === 'active') {
            return $next($request);
        }

        if ($state === 'grace') {
            $daysLeft = max(0, (int) now()->diffInDays($company->accessEndedAt()->copy()->addDays((int) config('saas.grace_days', 7)), false));
            if (in_array($request->method(), ['GET', 'HEAD'], true)) {
                admin_warning('Subscription expired', "You have {$daysLeft} day(s) of read-only access left. Renew to keep recording sales on the web.");

                return $next($request);
            }

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['status' => false, 'message' => 'Your subscription has expired: the web app is read-only until you renew.'], 402);
            }
            admin_error('Subscription expired', 'The web app is read-only until you renew.');

            return back();
        }

        // expired or inactive
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['status' => false, 'message' => 'Your subscription has expired. Please renew to continue.'], 402);
        }

        return redirect(admin_base_path('subscription-expired'));
    }
}
