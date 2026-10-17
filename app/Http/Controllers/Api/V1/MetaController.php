<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Billing\PlanBadge;
use App\Services\Team\Permissions;
use App\Support\EditionMeta;
use App\Support\LocalTime;
use App\Support\MetaEnums;
use App\Support\Money;
use App\Support\ShopBrand;
use App\Support\ShopMenu;
use App\Support\StoreFeatures;
use App\Support\StoreScope;
use App\Support\SupportContact;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/meta (MOBILE_EXCELLENCE_PLAN.md D1): everything the phone needs to draw the shop the way
 * the web draws it, from the same objects the web reads: the business and how it writes money, the
 * edition, the plan badge (PlanBadge, SaaS only), the person, the payment methods, the enums with
 * their tones (MetaEnums), the app versions, who to ask for help (SupportContact), the web address and its
 * pages (ShopMenu), the switches.
 * Read after sign-in and on resume; send If-None-Match with the last ETag to get a 304.
 */
class MetaController extends Controller
{
    use ApiResponse;

    public const API_VERSION = 1;

    public function show(Request $request)
    {
        $user = $request->user();
        $company = $request->attributes->get('company') ?? Company::withoutGlobalScopes()->findOrFail($user->company_id);
        $webUrl = rtrim((string) config('saas.new_ui_url'), '/') ?: null;
        $plan = PlanBadge::forApp($company);

        $data = [
            'api_version' => self::API_VERSION,
            'business' => $this->business($company),
            'edition' => EditionMeta::edition(),
            'plan' => $plan === null ? null : $plan + ['can_pay' => Permissions::can($user, 'billing')],
            'user' => [
                'id' => (int) $user->id, 'name' => (string) $user->name, 'role' => $role = Permissions::roleOf($user),
                'role_label' => (string) config("permissions.roles.{$role}.label", ucfirst(str_replace('_', ' ', $role))),
                'permissions' => array_values(Permissions::of($user)), 'store_id' => StoreScope::forUser($user, $company),
            ],
            'payment_methods' => MetaEnums::paymentMethods($company),
            'enums' => MetaEnums::all(),
            'app' => ['min_version' => (string) config('mobile.min_version'), 'latest_version' => (string) config('mobile.latest_version'), 'download_url' => (string) config('mobile.store_url')],
            // Who to ask for help, with the prefilled messages (docs/roadmap/06 §4): a standalone copy's own contact first.
            'support' => SupportContact::forCompany($company)->toArray(),
            'web_url' => $webUrl,
            'web_pages' => $webUrl === null ? [] : ShopMenu::pagesFor($user, $company),
            'features' => ['store' => StoreFeatures::payload($company), 'modules' => $company->modules()],
        ];
        $etag = '"'.sha1((string) json_encode($data)).'"';
        if (in_array($etag, array_map('trim', explode(',', (string) $request->header('If-None-Match'))), true)) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => 'private, no-cache']);
        }

        return $this->success($data + ['server_time' => now()->toIso8601String()], 'Meta.')
            ->setEtag(trim($etag, '"'))->header('Cache-Control', 'private, no-cache');
    }

    /** @return array<string, mixed> */
    private function business(Company $company): array
    {
        $brand = ShopBrand::forCompany($company);
        $logo = trim((string) $company->logo);
        $id = (int) $company->id;

        return [
            'id' => $id,
            'name' => (string) $company->name,
            'logo_url' => $brand->logoUrl() ?? ($logo === '' ? null : (preg_match('#^https?://#i', $logo) ? $logo : url('storage/'.ltrim($logo, '/')))),
            // The buyer's own colour on a standalone copy (readable on white), else null: the app keeps Budget Pro's green.
            'brand_colour' => $brand->colour(),
            'currency' => Money::symbol($id),
            'decimals' => Money::decimals($id),
            'timezone' => LocalTime::timezone($company),
            // Enough to write an amount exactly as App\Support\Money::format does: "<code> <number>".
            'money' => ['code' => Money::symbol($id), 'position' => 'before', 'space' => true, 'decimals' => Money::decimals($id),
                'thousands_separator' => ',', 'decimal_separator' => '.', 'sample' => Money::format(1234567.5, null, $id)],
            // The small line under the receipt footer ("Powered by Budget Pro · WhatsApp …"), or null when the shop has it off.
            'receipt_support_line' => SupportContact::receiptLine($company),
        ];
    }
}
