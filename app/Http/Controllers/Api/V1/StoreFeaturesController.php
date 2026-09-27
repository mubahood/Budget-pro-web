<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\StoreFeatureGate;
use App\Http\Controllers\Controller;
use App\Support\StoreFeatures;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Supermarket mode, features and settings (App\Support\StoreFeatures) — the same switches as the web
 * Business settings. GET is open to every member (the till needs them); PUT needs manage_settings.
 */
class StoreFeaturesController extends Controller
{
    use ApiResponse, StoreFeatureGate;

    /** GET store-features → {mode, features: {key: bool}, settings: {...}, labels: {key: label}} */
    public function show(Request $request)
    {
        return $this->success(StoreFeatures::payload($this->company($request)) + ['labels' => array_map(fn ($f) => $f[0], StoreFeatures::FEATURES)], 'Store features.');
    }

    /** PUT store-features {mode?, features?: {key: bool|null}, settings?: {key: value}} — null un-sets a feature (it follows the mode again). */
    public function update(Request $request)
    {
        $change = StoreFeatures::validated($request->only(['mode', 'features', 'settings']));
        $unknown = array_diff(array_keys((array) ($change['features'] ?? [])), array_keys(StoreFeatures::FEATURES));
        if ($unknown !== []) {
            return $this->error('Unknown feature: '.implode(', ', $unknown).'.', 422, ['code' => 'unknown_feature', 'features' => array_values($unknown)]);
        }
        $company = StoreFeatures::update($this->company($request), $change);

        return $this->success(StoreFeatures::payload($company->fresh()), 'Store settings saved.');
    }
}
