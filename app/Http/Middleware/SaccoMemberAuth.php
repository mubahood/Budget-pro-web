<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoMemberLogin;
use App\Services\Sacco\Members\MemberLogin;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * A SACCO member's own API token (04 §6.4, AK-61, `sacco/me/*`): a bearer token issued by MemberAuthController to a
 * member sign-in (sacco_member_logins), never to a staff login. Staff routes refuse it (SaccoMemberLogin has no
 * HasApiTokens, so Sanctum's guard does not accept it), and this middleware refuses every staff token. On each
 * request the membership is checked again (MemberLogin::resolve); the member and the SACCO are put on the request.
 */
class SaccoMemberAuth
{
    public const ABILITY = 'sacco:member';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $access = $token ? PersonalAccessToken::findToken($token) : null;
        if ($access === null || $access->tokenable_type !== SaccoMemberLogin::class || ! in_array(self::ABILITY, (array) $access->abilities, true)
            || ($access->expires_at !== null && $access->expires_at->isPast())) {
            return response()->json(['code' => 0, 'message' => 'Please sign in again.', 'data' => null, 'errors' => ['code' => 'member_session_invalid']], 401);
        }
        $login = SaccoMemberLogin::withoutGlobalScopes()->find($access->tokenable_id);
        try {
            $member = $login === null ? throw BusinessRuleException::make('member_session_invalid', 'Please sign in again.')
                : app(MemberLogin::class)->resolve((int) $login->company_id, (int) $login->member_id);
        } catch (BusinessRuleException $e) {
            $access->delete();

            return response()->json(['code' => 0, 'message' => $e->getMessage(), 'data' => null, 'errors' => ['code' => 'member_session_invalid']], 401);
        }
        $access->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('sacco_member', $member);
        $request->attributes->set('sacco_member_token', $access);
        $request->attributes->set('company', Company::withoutGlobalScopes()->find($member->company_id));

        return $next($request);
    }
}
