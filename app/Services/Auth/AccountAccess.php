<?php

namespace App\Services\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Whether a shop account may still be used, for the API (EnsureApiTenant, the pre-v1 sign-in). The same
 * rule as the web portal's sign-in refusal and TeamService::setActive:
 *
 *  - switched off: admin_users.status is set and is not "Active" (TeamService writes Active / Inactive;
 *    an unset status is the column's default, active);
 *  - removed from the shop: a company_members row that is not active (TeamService writes active /
 *    inactive), unless the person owns the shop. Staff from before membership rows have none and keep access.
 *
 * Refused API requests answer 403 with errors.code = account_inactive, so a token a switched-off person
 * still holds stops working at once.
 */
final class AccountAccess
{
    public const CODE = 'account_inactive';

    public const SWITCHED_OFF = 'This account has been switched off. Ask the shop owner to switch it back on.';

    public const REMOVED = 'Your access to this shop has been removed. Ask the shop owner.';

    public static function switchedOff(User $user): bool
    {
        $status = strtolower(trim((string) $user->status));

        return $status !== '' && $status !== 'active';
    }

    public static function removedFrom(User $user, Company $company): bool
    {
        if ((int) $company->owner_id === (int) $user->id) {
            return false;
        }
        $status = DB::table('company_members')->where('company_id', $company->id)->where('user_id', $user->id)->value('status');

        return $status !== null && strtolower((string) $status) !== 'active';
    }

    /** Why this account may not use the shop (a message for the person), or null when it may. */
    public static function refusal(User $user, ?Company $company): ?string
    {
        if (self::switchedOff($user)) {
            return self::SWITCHED_OFF;
        }

        return $company !== null && self::removedFrom($user, $company) ? self::REMOVED : null;
    }
}
