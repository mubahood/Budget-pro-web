<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Http\Request;

/**
 * Where a phone told to update (or to download) is sent: its own app's store listing (config mobile.store_urls), never
 * another app's. The app comes from the X-App header, else the request's `app` field (the phones send it on sign-in), else
 * the signed-in account's own-books app; a request that names no app gets MOBILE_STORE_URL as before. An app with no
 * listing answers '' (Budget Pro lives on the web once its listing is UnityDesk's). min_version is global and unchanged.
 */
final class StoreLink
{
    public static function for(?Request $request = null, ?Company $company = null): string
    {
        $slug = self::appOf($request, $company);
        $urls = (array) config('mobile.store_urls', []);

        return $slug !== null && array_key_exists($slug, $urls) ? (string) $urls[$slug] : (string) config('mobile.store_url');
    }

    public static function appOf(?Request $request = null, ?Company $company = null): ?string
    {
        if ($request !== null) {
            $named = $request->header('X-App') ?: (is_string($request->input('app')) ? $request->input('app') : null);
            if (($slug = AppFamily::slug($named)) !== null) {
                return $slug;
            }
        }
        if ($company !== null) {
            // Own books open in one app only (AppFamily::opens): such an account's phone is that app.
            foreach (AppFamily::APPS as $slug => [, $module, $own]) {
                if ($own && in_array($module, $company->modules(), true)) {
                    return $slug;
                }
            }
        }

        return null;
    }
}
