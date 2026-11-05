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
 * synced budget_program_members rows).
 *
 * Reading is by seat too (the review's PB-09, decision D3): someone with neither a seat nor full access does not see
 * the event at all. A viewer sees names and amounts, not phone numbers; taking the list away is for the chair, the
 * treasurer and the secretary; a list with names into a WhatsApp group for the chair and the treasurer.
 */
final class BudgetAccess
{
    public const ROLES = ['chair', 'treasurer', 'secretary', 'member', 'viewer'];

    public const PLEDGE = 'record_pledges';

    public const PAYMENT = 'record_payments';

    public const COMMITTEE = 'manage_committee';

    /** What was paid out on a budget line (PB-08): the treasurer's job, not only the chair's. */
    public const SPENDING = 'record_spending';

    /** Any pledge. A secretary or a member can also withdraw one of their own: canWithdraw. */
    public const WITHDRAW = 'withdraw_pledges';

    /** ability => committee roles that have it */
    public const ABILITIES = [
        self::PLEDGE => ['chair', 'treasurer', 'secretary', 'member'],
        self::PAYMENT => ['chair', 'treasurer'],
        self::COMMITTEE => ['chair'],
        self::SPENDING => ['chair', 'treasurer'],
        self::WITHDRAW => ['chair', 'treasurer'],
    ];

    /** Committee roles that see phone numbers and reach people. Everyone but the viewer. */
    private const SEE_PHONES = ['chair', 'treasurer', 'secretary', 'member'];

    /** Committee roles that may take a list of people away: CSV, Excel, the printable list. */
    private const EXPORT = ['chair', 'treasurer', 'secretary'];

    /** Committee roles that may share a list with names into a WhatsApp group. */
    private const SHARE_NAMES = ['chair', 'treasurer'];

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

    /** Full access, or a committee role in $roles (every role when null); false for someone switched off. */
    private static function holds(?User $user, int $programId, ?array $roles): bool
    {
        if ($user === null || Permissions::roleOf($user) === 'none') {
            return false;
        }
        if (self::fullAccess($user)) {
            return true;
        }
        $role = self::roleOn($user, $programId);

        return $role !== null && ($roles === null || in_array($role, $roles, true));
    }

    /** May open the event at all: its totals, names and amounts, and the committee report. A seat, or full access. */
    public static function canOpen(?User $user, int $programId): bool
    {
        return self::holds($user, $programId, null);
    }

    public static function canSeePhones(?User $user, int $programId): bool
    {
        return self::holds($user, $programId, self::SEE_PHONES);
    }

    /** May remind, thank or call people from the event. */
    public static function canRemind(?User $user, int $programId): bool
    {
        return self::holds($user, $programId, self::SEE_PHONES);
    }

    /** May take a list of people away: CSV, Excel, the printable list (PB-09's table). */
    public static function canExport(?User $user, int $programId): bool
    {
        return self::holds($user, $programId, self::EXPORT);
    }

    public static function canShareNames(?User $user, int $programId): bool
    {
        return self::holds($user, $programId, self::SHARE_NAMES);
    }

    /**
     * May withdraw this pledge (PB-04): the chair, the treasurer, full access; a secretary or a member only a pledge
     * they entered themselves, today, that never had a payment.
     */
    public static function canWithdraw(?User $user, \App\Models\ContributionRecord|\stdClass $pledge): bool
    {
        $programId = (int) $pledge->budget_program_id;
        if (self::can($user, $programId, self::WITHDRAW)) {
            return true;
        }
        if ($user === null || ! self::can($user, $programId, self::PLEDGE) || (int) $pledge->treasurer_id !== (int) $user->id || empty($pledge->created_at)) {
            return false;
        }
        $tz = \App\Support\LocalDate::timezone((int) $user->company_id);

        return \Illuminate\Support\Carbon::parse($pledge->created_at)->setTimezone($tz)->toDateString() === now($tz)->toDateString()
            && ! DB::table('contribution_payments')->where('contribution_record_id', (int) $pledge->id)->exists();
    }

    /** Someone who can be named as having taken money or a pledge on this event: a teammate with a seat or with full access. */
    public static function onTeam(int $companyId, int $programId, int $userId): bool
    {
        $user = User::withoutGlobalScopes()->where('company_id', $companyId)->find($userId);

        return $user !== null && self::canOpen($user, $programId);
    }

    /**
     * Narrow a query of an event's rows to the events this person may open.
     *
     * @template TQuery
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function limitToSeats(mixed $query, ?User $user, string $column = 'budget_program_id'): mixed
    {
        if ($user === null || Permissions::roleOf($user) === 'none') {
            return $query->whereRaw('1 = 0');
        }
        if (self::fullAccess($user)) {
            return $query;
        }

        return $query->whereIn($column, DB::table('budget_program_members')->where('user_id', $user->id)->where('company_id', $user->company_id)
            ->where('is_deleted', 0)->where('status', 'active')->select('budget_program_id'));
    }

    /**
     * The older read-everything lists (ApiController::my_list, MobileApiController::genericList): the same seats, the
     * same shape. Models that are not an event's rows pass through untouched.
     *
     * @template TQuery
     *
     * @param  TQuery  $query
     * @param  class-string  $model
     * @return TQuery
     */
    public static function narrowLegacyList(mixed $query, ?User $user, string $model): mixed
    {
        return match ($model) {
            \App\Models\BudgetProgram::class => self::limitToSeats($query, $user, 'id'),
            \App\Models\BudgetItem::class, \App\Models\BudgetItemCategory::class => self::limitToSeats($query, $user),
            \App\Models\ContributionRecord::class => self::limitToSeats($query, $user)->where('is_deleted', 0)
                ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', EventBook::KIND_IN_KIND)),
            default => $query,
        };
    }

    /**
     * Empties the phone numbers of rows this person may not see them on.
     *
     * @param  iterable<object>  $rows  each with budget_program_id and phone
     */
    public static function hidePhones(iterable $rows, ?User $user): void
    {
        $may = [];
        foreach ($rows as $row) {
            $event = (int) $row->budget_program_id;
            $may[$event] ??= self::canSeePhones($user, $event);
            if (! $may[$event]) {
                $row->phone = null;
            }
        }
    }

    /**
     * Narrow a query of an event's rows to the events whose phone numbers this person may see.
     *
     * @template TQuery
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function limitToPhones(mixed $query, ?User $user, string $column = 'budget_program_id'): mixed
    {
        if ($user === null || Permissions::roleOf($user) === 'none') {
            return $query->whereRaw('1 = 0');
        }
        if (self::fullAccess($user)) {
            return $query;
        }

        return $query->whereIn($column, DB::table('budget_program_members')->where('user_id', $user->id)->where('company_id', $user->company_id)
            ->where('is_deleted', 0)->where('status', 'active')->whereIn('role', self::SEE_PHONES)->select('budget_program_id'));
    }

    /**
     * May edit the event itself, close or reopen it, and its budget (the web portal's Events pages): full access,
     * or the event's chair. Not one of ABILITIES on purpose: the API and sync still keep event, category and
     * item edits with `manage_budget` (ApiPermissionMap, SyncApplier::permissionFor), so the phone's answer
     * does not offer the chair what its own screens would be refused.
     */
    public static function canManageEvent(?User $user, int $programId): bool
    {
        if ($user === null || Permissions::roleOf($user) === 'none') {
            return false;
        }

        return self::fullAccess($user) || self::roleOn($user, $programId) === 'chair';
    }

    /**
     * May change the event's settings (EventSettings::SETTINGS_FIELDS: its type, venue, pledge groups, message
     * templates, amount presets): full access or the event's chair. Through sync too, as long as the op carries
     * nothing but those fields (allowsSettingsSyncOp), so a chair without manage_budget can keep them up to date.
     */
    public static function canEditSettings(?User $user, int $programId): bool
    {
        return self::canManageEvent($user, $programId);
    }

    /** May change where pledgers pay (payment_details): the chair, the treasurer, or full access. */
    public static function canEditPaymentDetails(?User $user, int $programId): bool
    {
        if (self::canManageEvent($user, $programId)) {
            return true;
        }

        return $user !== null && Permissions::roleOf($user) !== 'none' && self::roleOn($user, $programId) === 'treasurer';
    }

    /**
     * A pushed budget_programs update that changes only the event's settings and/or payment details, by someone
     * those belong to (canEditSettings / canEditPaymentDetails): allowed even without manage_budget. Anything else
     * (a new event, a delete, its name or date) still needs manage_budget (SyncApplier::permissionFor).
     *
     * @param  array<string, mixed>  $data
     */
    public static function allowsSettingsSyncOp(?User $actor, int $companyId, string $table, string $action, array $data, string $uuid): bool
    {
        if ($actor === null || $table !== 'budget_programs' || ! in_array($action, ['update', 'upsert'], true) || $data === []) {
            return false;
        }
        $fields = array_keys($data);
        $settings = array_intersect($fields, EventSettings::SETTINGS_FIELDS);
        $payment = array_intersect($fields, [EventSettings::PAYMENT_FIELD]);
        if (count($settings) + count($payment) !== count($fields)) {
            return false;
        }
        $programId = DB::table('budget_programs')->where('company_id', $companyId)->where('uuid', $uuid)->where('is_deleted', 0)->value('id');
        if ($programId === null) {
            return false;
        }

        return ($settings === [] || self::canEditSettings($actor, (int) $programId))
            && ($payment === [] || self::canEditPaymentDetails($actor, (int) $programId));
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
