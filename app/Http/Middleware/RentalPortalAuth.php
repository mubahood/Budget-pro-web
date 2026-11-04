<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\RentalLogin;
use App\Scopes\CompanyScope;
use App\Services\Rentals\Logins\RentalLogins;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * A tenant's or landlord's own token (`rentals/me/*`, KR-16, docs/estate-ledger/04-architecture.md §8): a bearer token that
 * RentalLogins issued to a RentalLogin with the `rentals-portal` ability, never a staff token (and staff routes refuse
 * this one: AuthServiceProvider). On every request the sign-in is checked again (RentalLogins::resolve); the rest of
 * the request runs narrowed to the person's account (CompanyScope::forRequest), with the sign-in on the request
 * (`rental_login`) so every query is keyed by it, never by a request parameter.
 */
class RentalPortalAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $access = $plain ? PersonalAccessToken::findToken($plain) : null;
        if ($access === null || $access->tokenable_type !== RentalLogin::class || ! in_array(RentalLogin::ABILITY, (array) $access->abilities, true)
            || ($access->expires_at !== null && $access->expires_at->isPast())) {
            return self::refuse();
        }
        $logins = app(RentalLogins::class);
        $login = $logins->resolve((int) $access->tokenable_id);
        if ($login === null) {
            $planOff = $logins->closedByPlan((int) $access->tokenable_id);
            $access->delete();

            return $planOff ? self::refuse(RentalLogins::PLAN_OFF, 'portal_not_on_plan') : self::refuse();
        }
        $access->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('rental_login', $login);
        $request->attributes->set('rental_token', $access);
        $request->attributes->set('company', Company::withoutGlobalScopes()->find($login->company_id));

        return CompanyScope::forRequest((int) $login->company_id, fn () => $next($request));
    }

    private static function refuse(string $message = 'Please sign in again.', string $code = 'portal_session_invalid'): Response
    {
        return response()->json(['code' => 0, 'message' => $message, 'data' => null, 'errors' => ['code' => $code]], 401);
    }
}
