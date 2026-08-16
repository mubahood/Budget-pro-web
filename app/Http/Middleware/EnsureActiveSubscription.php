<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API subscription/licence guard (runs AFTER auth:sanctum + api.tenant).
 *
 * Blocks tenants whose access has lapsed. Access is granted when the company's
 * subscription is active/trialing, OR (fallback for pre-billing tenants) the
 * legacy `license_expire` date is still in the future.
 *
 * This is the enforcement point that makes `companies.license_expire` and the
 * subscription plan actually mean something (previously it was decorative).
 */
class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Company|null $company */
        $company = $request->attributes->get('company') ?? Company::find(optional($request->user())->company_id);

        if ($company === null) {
            return response()->json([
                'code' => 0,
                'message' => 'Your company could not be found.',
                'data' => null,
            ], 403);
        }

        if (! $company->hasActiveAccess()) {
            return response()->json([
                'code' => 0,
                'message' => 'Your subscription has expired. Please renew to continue.',
                'data' => [
                    'reason' => 'subscription_expired',
                    'license_expire' => optional($company->license_expire)->toDateString(),
                ],
            ], 402); // 402 Payment Required
        }

        return $next($request);
    }
}
