<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Scopes\CompanyScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API tenant guard (runs AFTER auth:sanctum).
 *
 * Guarantees that the authenticated API user belongs to an existing, active company,
 * and exposes the resolved company on the request as `company` for downstream use.
 * This is the real multi-tenant enforcement point for the API (the legacy
 * EnforceSaasIsolation middleware only worked for the web/session guard).
 *
 * The rest of the request runs inside CompanyScope::forRequest(), so every model with
 * the CompanyScope is narrowed to this company in API requests too (roadmap 02 §4), and
 * so does a streamed body written after the middleware returns.
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

        // CompanyScope narrows every scoped query to this company for the rest of the request, and
        // forgets it when the request is done (roadmap 02 §4). The hand-written company filters stay.
        $companyId = (int) $company->id;
        $response = CompanyScope::forRequest($companyId, fn () => $next($request));

        // A streamed body (the CSV exports) is written after this middleware has returned: it runs
        // narrowed to the same company, then forgets it too.
        if ($response instanceof StreamedResponse && ($body = $response->getCallback()) !== null) {
            $response->setCallback(fn () => CompanyScope::forRequest($companyId, $body));
        }

        return $response;
    }
}
