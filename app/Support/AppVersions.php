<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Http\Request;

/**
 * The versions a phone is held to: its own app's (config mobile.apps.<slug>), else the shared mobile.min_version /
 * latest_version. The app is found the way StoreLink finds it (X-App header, `app` field, else the account's own-books app).
 */
final class AppVersions
{
    /** @return array{min_version: string, latest_version: string} */
    public static function for(?Request $request = null, ?Company $company = null): array
    {
        return self::forSlug(StoreLink::appOf($request, $company));
    }

    /** @return array{min_version: string, latest_version: string} */
    public static function forSlug(?string $slug): array
    {
        $own = $slug !== null ? (array) config('mobile.apps.'.$slug, []) : [];

        return [
            'min_version' => (string) (($own['min_version'] ?? '') ?: config('mobile.min_version')),
            'latest_version' => (string) (($own['latest_version'] ?? '') ?: config('mobile.latest_version')),
        ];
    }
}
