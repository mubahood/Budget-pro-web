<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web tenancy guard (runs in the `web` middleware group).
 *
 *  1. A session user without a company_id is logged out (platform admins excepted).
 *  2. A `company_id` in a form submission that doesn't match the user's company is
 *     overridden and logged (platform admins may work across companies).
 *  3. Writes without a `company_id` get the user's one injected.
 *
 * P0-3: previously only consulted the default `web` guard, which the admin
 * panel never uses, and relied on a `user_type` column that doesn't exist -- so
 * it was inert. It now looks at the `admin` guard first.
 */
class EnforceSaasIsolation
{
    public function handle(Request $request, Closure $next): Response
    {
        [$guard, $user] = $this->sessionUser();

        if ($user === null) {
            return $next($request);
        }

        $isPlatformAdmin = PlatformAdminOnly::isPlatformAdmin($user);

        if (empty($user->company_id) && ! $isPlatformAdmin) {
            Log::critical('User without company_id attempted to access the web app', [
                'user_id' => $user->id, 'email' => $user->email, 'ip' => $request->ip(), 'url' => $request->fullUrl(),
            ]);

            Auth::guard($guard)->logout();

            return redirect(admin_base_path('auth/login'))
                ->with('error', 'Your account is not linked to any company. Please contact support.');
        }

        if ($request->has('company_id') && ! $isPlatformAdmin && (int) $request->input('company_id') !== (int) $user->company_id) {
            Log::warning('Company ID mismatch in web request overridden', [
                'user_id' => $user->id, 'user_company_id' => $user->company_id,
                'requested_company_id' => $request->input('company_id'), 'url' => $request->fullUrl(), 'method' => $request->method(),
            ]);
            $request->merge(['company_id' => $user->company_id]);
        }

        if (! $request->has('company_id') && ! $isPlatformAdmin && in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            $request->merge(['company_id' => $user->company_id]);
        }

        return $next($request);
    }

    /** @return array{0: string, 1: mixed} guard name and user (user may be null) */
    private function sessionUser(): array
    {
        foreach (['admin', 'web'] as $guard) {
            if (Auth::guard($guard)->check()) {
                return [$guard, Auth::guard($guard)->user()];
            }
        }

        return ['web', null];
    }
}
