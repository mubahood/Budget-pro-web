<?php

namespace App\PingPin\Http\Middleware;

use App\Models\Company;
use App\Models\CompanyMember;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fails closed by design (PLAN.md §2, DECISIONS.md D4) — unlike CompanyScope,
 * which the audit found silently degrades to a no-op whenever Auth::check()
 * is false. This middleware only ever lets a request through when it has
 * positively resolved BOTH an authenticated user AND an active membership
 * row for the organisation being acted on; every other outcome (no user, no
 * organisation, no membership, a revoked/invited-not-accepted membership)
 * is an explicit denial, never a silent pass-through.
 */
class EnsurePingPinMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $this->deny('Unauthenticated.', 401);
        }

        $company = $this->resolveCompany($request);
        if ($company === null) {
            return $this->deny('Organisation not found.', 404);
        }

        $membership = CompanyMember::where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($membership === null) {
            return $this->deny('You are not an active member of this organisation.', 403);
        }

        // Available to controllers without re-querying — same pattern as
        // EnsureApiTenant's 'company' attribute.
        $request->attributes->set('company', $company);
        $request->attributes->set('membership', $membership);

        return $next($request);
    }

    /**
     * A route-bound {company} parameter takes precedence (the common case —
     * .../organisations/{company}/devices/...); the header is the escape
     * hatch for endpoints that aren't scoped to one org in the URL at all
     * (e.g. "list every organisation I belong to").
     */
    private function resolveCompany(Request $request): ?Company
    {
        $routeCompany = $request->route('company');
        if ($routeCompany instanceof Company) {
            return $routeCompany;
        }
        if (is_numeric($routeCompany)) {
            return Company::find($routeCompany);
        }

        $headerId = $request->header('X-Organisation-Id');
        if ($headerId !== null && is_numeric($headerId)) {
            return Company::find($headerId);
        }

        return null;
    }

    private function deny(string $message, int $status): Response
    {
        return response()->json(['code' => 0, 'message' => $message, 'data' => null], $status);
    }
}
