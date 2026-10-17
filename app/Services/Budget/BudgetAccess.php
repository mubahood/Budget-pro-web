<?php

namespace App\Services\Budget;

use App\Models\User;
use App\Services\Team\Permissions;
use Illuminate\Support\Facades\DB;

/**
 * Who may do what on one event (roadmap 02 §6). The account's own roles come first: the owner and anyone
 * whose role has `manage_budget` (manager, accountant) may do everything on every event. Everyone else
 * gets what their committee role on that event allows:
 *
 *   chair      pledges, payments, the committee
 *   treasurer  pledges, payments
 *   secretary  pledges
 *   member     pledges
 *   viewer     reads only
 *
 * One table for the API (controllers), sync (SyncApplier) and the phone (it reads the same roles from the
 * synced budget_program_members rows). Reads stay open to every team member, as in ApiPermissionMap.
 */
final class BudgetAccess
{
    public const ROLES = ['chair', 'treasurer', 'secretary', 'member', 'viewer'];

    public const PLEDGE = 'record_pledges';

    public const PAYMENT = 'record_payments';

    public const COMMITTEE = 'manage_committee';

    /** ability => committee roles that have it */
    public const ABILITIES = [
        self::PLEDGE => ['chair', 'treasurer', 'secretary', 'member'],
        self::PAYMENT => ['chair', 'treasurer'],
        self::COMMITTEE => ['chair'],
    ];

    public static function fullAccess(?User $user): bool
    {
        return Permissions::can($user, 'manage_budget');
    }

    /** The person's committee role on this event, or null. */
    public static function roleOn(User $user, int $programId): ?string
    {
        $role = DB::table('budget_program_members')->where('budget_program_id', $programId)->where('user_id', $user->id)
            ->where('company_id', $user->company_id)->where('is_deleted', 0)->where('status', 'active')->value('role');

        return $role === null ? null : (string) $role;
    }

    public static function can(?User $user, int $programId, string $ability): bool
    {
        if ($user === null) {
            return false;
        }
        if (Permissions::roleOf($user) === 'none') {
            return false; // switched off in this company
        }
        if (self::fullAccess($user)) {
            return true;
        }
        $role = self::roleOn($user, $programId);

        return $role !== null && in_array($role, self::ABILITIES[$ability] ?? [], true);
    }

    /** @return list<string> what the person may do on this event (for the API answer and the phone) */
    public static function abilities(User $user, int $programId): array
    {
        return array_values(array_filter(array_keys(self::ABILITIES), fn ($a) => self::can($user, $programId, $a)));
    }

    /**
     * A pushed op on a budget table (SyncApplier): null when allowed, else the ability that is missing.
     * Event, category and item edits stay with `manage_budget` (SyncApplier::permissionFor).
     */
    public static function refuseSyncOp(?User $actor, int $companyId, string $table, string $action, array $data, string $uuid): ?string
    {
        if (! in_array($table, ['contribution_records', 'contribution_payments', 'budget_program_members'], true)) {
            return null;
        }
        if ($actor === null) {
            return self::PLEDGE;
        }
        if (self::fullAccess($actor)) {
            return null;
        }

        if ($table === 'contribution_payments') {
            $programId = self::programOfPledge($companyId, $data['contribution_uuid'] ?? null);

            return $programId !== null && self::can($actor, $programId, self::PAYMENT) ? null : self::PAYMENT;
        }

        if ($table === 'budget_program_members') {
            $programId = self::programByUuid($companyId, $data['program_uuid'] ?? null)
                ?? DB::table('budget_program_members')->where('company_id', $companyId)->where('uuid', $uuid)->value('budget_program_id');

            return $programId !== null && self::can($actor, (int) $programId, self::COMMITTEE) ? null : self::COMMITTEE;
        }

        // contribution_records: a pledge; a changed paid figure from an older app is a payment.
        $existing = DB::table('contribution_records')->where('company_id', $companyId)->where('uuid', $uuid)->first(['budget_program_id', 'paid_amount']);
        $programId = self::programByUuid($companyId, $data['program_uuid'] ?? null) ?? ($existing ? (int) $existing->budget_program_id : null);
        if ($programId === null || ! self::can($actor, $programId, self::PLEDGE)) {
            return self::PLEDGE;
        }
        if ($action === 'delete') {
            return null; // refused by the model either way (pledges are never deleted)
        }
        $paid = $data['custom_paid_amount'] ?? $data['paid_amount'] ?? null;
        if ($paid !== null && $paid !== '' && \App\Support\Decimal::valid($paid)
            && \App\Support\Decimal::cmp((string) $paid, $existing ? (string) $existing->paid_amount : '0') !== 0
            && ! self::can($actor, $programId, self::PAYMENT)) {
            return self::PAYMENT;
        }

        return null;
    }

    private static function programByUuid(int $companyId, mixed $uuid): ?int
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }
        $id = DB::table('budget_programs')->where('company_id', $companyId)->where('uuid', $uuid)->value('id');

        return $id === null ? null : (int) $id;
    }

    private static function programOfPledge(int $companyId, mixed $uuid): ?int
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }
        $id = DB::table('contribution_records')->where('company_id', $companyId)->where('uuid', $uuid)->value('budget_program_id');

        return $id === null ? null : (int) $id;
    }
}
