<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use App\Services\Shop\LocationStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store-level access and "which store was this sale" (SUPERMARKET_PLAN.md G3).
 *
 *  - A member may work at one store (company_members.location_id, set in Team). With the shop's `store_scoping`
 *    on, such a member sees only that store's figures (forUser()). Owners, and members without a store, see all.
 *    A shop with one open location is never scoped (there is nothing to hide).
 *  - A sale's store: the location its stock left from (its Sale movements); a sale without movements (services,
 *    an old sync) is the store of the phone that made it, else the main location. The same rule as
 *    ZReportService's per-location Z. An old-app sale movement is its own movement's location.
 */
class StoreScope
{
    private static ?bool $column = null;

    public static function ready(): bool
    {
        return self::$column ??= Schema::hasColumn('company_members', 'location_id');
    }

    public static function enabled(?Company $company): bool
    {
        return StoreFeatures::enabled($company, 'store_scoping') && self::ready();
    }

    /** The store a member is assigned to (null = none), whatever the feature says. */
    public static function assigned(int $companyId, int $userId): ?int
    {
        if (! self::ready()) {
            return null;
        }
        $id = DB::table('company_members')->where('company_id', $companyId)->where('user_id', $userId)->value('location_id');

        return $id ? (int) $id : null;
    }

    /**
     * The store this user is limited to, or null (sees every store): `store_scoping` on, not the owner, assigned
     * to an open store of the shop, and the shop has more than one open store.
     */
    public static function forUser(?User $user, ?Company $company = null): ?int
    {
        if ($user === null || ! $user->company_id) {
            return null;
        }
        $company ??= Company::withoutGlobalScopes()->find($user->company_id);
        if ($company === null || (int) $company->owner_id === (int) $user->id || ! self::enabled($company)) {
            return null;
        }
        $loc = self::assigned((int) $company->id, (int) $user->id);
        if ($loc === null) {
            return null;
        }
        $open = DB::table('locations')->where('company_id', $company->id)->where('is_active', 1)->pluck('id')->map(fn ($id) => (int) $id);

        return $open->count() > 1 && $open->contains($loc) ? $loc : null;
    }

    /** SQL: the store of the sale_records row aliased $alias (see the class note). */
    public static function saleLocationSql(string $alias, int $companyId): string
    {
        $default = LocationStock::defaultLocation($companyId);

        return "COALESCE((SELECT MIN(lr.location_id) FROM stock_records lr WHERE lr.sale_record_id = {$alias}.id),"
            ." (SELECT d.location_id FROM devices d WHERE d.company_id = {$alias}.company_id AND d.device_id = {$alias}.device_id LIMIT 1), {$default})";
    }
}
