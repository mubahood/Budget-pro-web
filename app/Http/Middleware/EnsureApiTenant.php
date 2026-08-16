<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API tenant guard (runs AFTER auth:sanctum).
 *
 * Guarantees that the authenticated API user belongs to an existing, active company,
 * and exposes the resolved company on the request as `company` for downstream use.
 * This is the real multi-tenant enforcement point for the API (the legacy
 * EnforceSaasIsolation middleware only worked for the web/session guard).
 */
class EnsureApiTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'code' => 0,
                'message' => 'Unauthenticated.',
                'data' => null,
            ], 401);
        }

        if (empty($user->company_id)) {
            return response()->json([
                'code' => 0,
                'message' => 'Your account is not associated with any company.',
                'data' => null,
            ], 403);
        }

        $company = Company::find($user->company_id);

        if ($company === null) {
            return response()->json([
                'code' => 0,
                'message' => 'Your company could not be found.',
                'data' => null,
            ], 403);
        }

        if (strtolower((string) $company->status) === 'inactive') {
            return response()->json([
                'code' => 0,
                'message' => 'Your company account is inactive. Please contact support.',
                'data' => null,
            ], 403);
        }

        // Make the resolved tenant available to controllers without re-querying.
        $request->attributes->set('company', $company);

        return $next($request);
    }
}
