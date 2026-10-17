<?php

namespace App\Http\Middleware;

use App\Support\MasterPassword;
use Closure;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A classic session opened with the master password ends after MASTER_PASSWORD_SESSION_MINUTES
 * (App\Support\MasterPassword). In config('admin.route.middleware'), so it runs on every classic page
 * and request. The person is signed out on this browser only: their own "remember me" devices stay
 * signed in. The header shows how long is left (app/Admin/bootstrap.php).
 */
class EndClassicMasterSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has(MasterPassword::SESSION_KEY)) {
            return $next($request);
        }
        if (Admin::user() === null) {
            $request->session()->forget([MasterPassword::SESSION_KEY, MasterPassword::SESSION_USE_KEY]);

            return $next($request);
        }
        if (now()->getTimestamp() < (int) $request->session()->get(MasterPassword::SESSION_KEY)) {
            return $next($request);
        }

        Admin::guard()->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $message = 'The master password session has ended. Sign in again to carry on.';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect(admin_url('auth/login'))->withErrors(['username' => $message]);
    }
}
