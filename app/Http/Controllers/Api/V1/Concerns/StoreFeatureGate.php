<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Company;
use App\Services\Team\Permissions;
use App\Support\StoreFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Supermarket endpoints are gated by the shop's StoreFeatures switch (403 `feature_off` when it is off)
 * and, where one permission is not enough for ApiPermissionMap, by "any of" these permissions.
 */
trait StoreFeatureGate
{
    protected function company(Request $request): Company
    {
        return $request->attributes->get('company') ?? Company::withoutGlobalScopes()->findOrFail($request->user()->company_id);
    }

    /** null when every feature is on, else the 403 to return. */
    protected function featureOff(Request $request, string ...$features): ?JsonResponse
    {
        $company = $this->company($request);
        foreach ($features as $feature) {
            if (StoreFeatures::enabled($company, $feature)) {
                return null;
            }
        }
        $label = StoreFeatures::FEATURES[$features[0]][0] ?? $features[0];

        return $this->error($label.' is switched off for this shop. The owner can switch it on in Business settings.', 403,
            ['code' => 'feature_off', 'feature' => $features[0]] + (count($features) > 1 ? ['any_of' => $features] : []));
    }

    /** null when the user has one of the permissions, else the same 403 as ApiPermissionMap. */
    protected function needsAny(Request $request, string ...$permissions): ?JsonResponse
    {
        foreach ($permissions as $p) {
            if (Permissions::can($request->user(), $p)) {
                return null;
            }
        }

        return $this->error('Your role does not allow this. Ask the shop owner.', 403, ['code' => 'forbidden', 'permission' => $permissions[0]] + (count($permissions) > 1 ? ['any_of' => $permissions] : []));
    }

    protected function can(Request $request, string $permission): bool
    {
        return Permissions::can($request->user(), $permission);
    }
}
