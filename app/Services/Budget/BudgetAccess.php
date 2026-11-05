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
     * May edit the event itself, close or reopen it, and its budget: full access, or the event's chair. On the web's Events pages and, by allowsChairSyncOp,
     * from a phone. Not one of ABILITIES, which older phones read as a list of what a committee role may do. The older REST routes for event, category and item edits
     * (ApiPermissionMap) stay with `manage_budget`; capabilities() says this as `change_lines`.
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
     * Everything this person may do on this event, in one map, for the phone (GET budget/home): the same answers as the web's `access`.
     * Whether the event is open is a separate fact the phone already holds, so these say who may, not whether it is closed.
     *
     * @return array<string, bool>
     */
    public static function capabilities(?User $user, int $programId): array
    {
        return [
            'open' => self::canOpen($user, $programId),
            'see_phones' => self::canSeePhones($user, $programId),
            'remind' => self::canRemind($user, $programId),
            'export' => self::canExport($user, $programId),
            'share_names' => self::canShareNames($user, $programId),
            'record_pledges' => self::can($user, $programId, self::PLEDGE),
            'record_payments' => self::can($user, $programId, self::PAYMENT),
            'record_spending' => self::can($user, $programId, self::SPENDING),
            'withdraw' => self::can($user, $programId, self::WITHDRAW),
            'manage_committee' => self::can($user, $programId, self::COMMITTEE),
            'change_lines' => self::canManageEvent($user, $programId),
            'settings' => self::canEditSettings($user, $programId),
            'payment_details' => self::canEditPaymentDetails($user, $programId),
            'close' => self::canManageEvent($user, $programId),
        ];
    }

    /**
     * The events this person's phone may hold (the review's PB-32): null means every event of the account (full access), a list means those
     * where they have an active seat, and someone switched off gets none.
     *
     * @return list<int>|null
     */
    public static function seats(?User $user): ?array
    {
        if ($user === null || Permissions::roleOf($user) === 'none') {
            return [];
        }
        if (self::fullAccess($user)) {
            return null;
        }

        return DB::table('budget_program_members')->where('user_id', $user->id)->where('company_id', $user->company_id)->where('is_deleted', 0)->where('status', 'active')
            ->orderBy('budget_program_id')->pluck('budget_program_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * The events whose phone numbers this person's phone may hold: null means all of them. It changes with a role, while the seat list does not, so a phone refetches when
     * it moves (a viewer made treasurer gets the numbers, a member made viewer loses them).
     *
     * @return list<int>|null
     */
    public static function phoneSeats(?User $user): ?array
    {
        if ($user === null || Permissions::roleOf($user) === 'none') {
            return [];
        }
        if (self::fullAccess($user)) {
            return null;
        }

        return DB::table('budget_program_members')->where('user_id', $user->id)->where('company_id', $user->company_id)->where('is_deleted', 0)->where('status', 'active')->whereIn('role', self::SEE_PHONES)
            ->orderBy('budget_program_id')->pluck('budget_program_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** What a person's record says that a teammate has no need of. The keys stay, empty, for older phones. */
    private const PERSON_PRIVATE = ['phone_number', 'phone_e164', 'phone_verified_at', 'phone_number_2', 'address', 'sex', 'dob', 'email', 'email_verified_at', 'last_login_at', 'last_active_at', 'password_pending_at', 'sessions_valid_from'];

    /** The same for the account's own contact and payout details. */
    private const COMPANY_PRIVATE = ['phone_number', 'phone_number_2', 'email', 'address', 'pobox', 'momo_subaccount_id', 'momo_payout_phone', 'momo_payout_network'];

    /** Empties the private details of the people and the account inside an API row (treasurer, changed_by, budget_program.company), unless this person has full access. */
    public static function hideNested(\Illuminate\Database\Eloquent\Model $model, ?User $user): void
    {
        if (self::fullAccess($user)) {
            return;
        }
        self::hideIn($model, 0);
    }

    private static function hideIn(\Illuminate\Database\Eloquent\Model $model, int $depth): void
    {
        if ($depth >= 4) {
            return;
        }
        foreach ($model->getRelations() as $related) {
            foreach ($related instanceof \Illuminate\Support\Collection ? $related : [$related] as $row) {
                if (! $row instanceof \Illuminate\Database\Eloquent\Model) {
                    continue;
                }
                $private = $row instanceof User ? self::PERSON_PRIVATE : ($row instanceof \App\Models\Company ? self::COMPANY_PRIVATE : []);
                if ($private !== []) {
                    $row->setRawAttributes(array_replace($row->getAttributes(), array_intersect_key(array_fill_keys($private, null), $row->getAttributes())));
                }
                self::hideIn($row, $depth + 1);
            }
        }
    }

    /**
     * A pushed change the web already lets an event's chair make: its budget lines and categories, and its own details. Only on an event that exists, and never making or
     * deleting one (SyncApplier::permissionFor keeps that with manage_budget).
     *
     * @param  array<string, mixed>  $data
     */
    public static function allowsChairSyncOp(?User $actor, int $companyId, string $table, string $action, array $data, string $uuid): bool
    {
        if ($actor === null) {
            return false;
        }
        if ($table === 'budget_programs') {
            if (! in_array($action, ['update', 'upsert'], true)) {
                return false;
            }
            $programId = DB::table('budget_programs')->where('company_id', $companyId)->where('uuid', $uuid)->where('is_deleted', 0)->value('id');

            return $programId !== null && self::canManageEvent($actor, (int) $programId);
        }
        if (! in_array($table, ['budget_item_categories', 'budget_items'], true)) {
            return false;
        }
        // The event it names, the event its category is on (a line follows its category) and the event it is on now: all of them are theirs, or none.
        $events = array_unique(array_filter([
            self::programByUuid($companyId, $data['program_uuid'] ?? null),
            $table === 'budget_items' ? self::programOfRow($companyId, 'budget_item_categories', $data['category_uuid'] ?? null) : null,
            self::programOfRow($companyId, $table, $uuid),
        ]));
        foreach ($events as $programId) {
            if (! self::canManageEvent($actor, (int) $programId)) {
                return false;
            }
        }

        return $events !== [];
    }

    /**
     * A pushed op on a budget table (SyncApplier): null when allowed, else the ability that is missing.
     * Event, category and item edits stay with `manage_budget` (SyncApplier::permissionFor).
     */
    public static function refuseSyncOp(?User $actor, int $companyId, string $table, string $action, array $data, string $uuid, int $clientUpdatedAt = 0): ?string
    {
        if (! in_array($table, ['contribution_records', 'contribution_payments', 'budget_program_members', 'pledge_follow_ups', 'event_invitation_ops'], true)) {
            return null;
        }
        if ($actor === null) {
            return self::PLEDGE;
        }
        if (self::fullAccess($actor)) {
            return null;
        }

        if ($table === 'pledge_follow_ups') {
            $programId = self::programOfPledge($companyId, $data['contribution_uuid'] ?? null);

            return $programId !== null && self::canRemind($actor, $programId) ? null : self::PLEDGE;
        }

        if ($table === 'event_invitation_ops') {
            $programId = self::programByUuid($companyId, $data['program_uuid'] ?? null);

            return $programId !== null && self::can($actor, $programId, self::PLEDGE) ? null : self::PLEDGE;
        }

        if ($table === 'contribution_payments') {
            // A reversal acts on the payment it names, whatever pledge it says, so the right is checked on the payment's event too.
            return self::mayOnAll($actor, [self::programOfRow($companyId, 'contribution_payments', $data['reverses_uuid'] ?? null), self::programOfPledge($companyId, $data['contribution_uuid'] ?? null)], self::PAYMENT)
                ? null : self::PAYMENT;
        }

        if ($table === 'budget_program_members') {
            return self::mayOnAll($actor, [self::programByUuid($companyId, $data['program_uuid'] ?? null), self::programOfRow($companyId, 'budget_program_members', $uuid)], self::COMMITTEE)
                ? null : self::COMMITTEE;
        }

        // contribution_records: a pledge; a changed paid figure from an older app is a payment.
        $existing = DB::table('contribution_records')->where('company_id', $companyId)->where('uuid', $uuid)
            ->first(['id', 'budget_program_id', 'paid_amount', 'fully_paid', 'is_deleted', 'client_updated_at', 'treasurer_id', 'created_at']);
        $named = self::programByUuid($companyId, $data['program_uuid'] ?? null);
        $events = [$named, $existing ? (int) $existing->budget_program_id : null];
        if (! self::mayOnAll($actor, $events, self::PLEDGE)) {
            return self::PLEDGE;
        }
        if ($action === 'delete') {
            return null; // refused by the model either way (pledges are never deleted)
        }
        if (self::changesPaidFigure($data, $existing) && ! self::mayOnAll($actor, $events, self::PAYMENT)) {
            return self::PAYMENT;
        }
        // An edit newer than a withdrawal puts the pledge back (MasterDataService), and putting back is the withdrawer's to do.
        if ($existing !== null && $existing->is_deleted && $clientUpdatedAt >= (int) $existing->client_updated_at && ! self::canWithdraw($actor, $existing)) {
            return self::WITHDRAW;
        }

        return null;
    }

    /**
     * Would this save post money (ContributionRecord::prepare)? Each way in is read on its own: the model takes custom_paid_amount only above zero, then
     * paid_amount, then the flag.
     *
     * @param  array<string, mixed>  $data
     */
    public static function changesPaidFigure(array $data, ?object $existing): bool
    {
        $was = $existing ? (string) $existing->paid_amount : '0';
        foreach (['custom_paid_amount', 'paid_amount'] as $field) {
            $value = $data[$field] ?? null;
            if ($value === null || $value === '' || ! \App\Support\Decimal::valid($value)) {
                continue;
            }
            if ($field === 'custom_paid_amount' && \App\Support\Decimal::cmp((string) $value, '0') <= 0) {
                continue;
            }
            if (\App\Support\Decimal::cmp((string) $value, $was) !== 0) {
                return true;
            }
        }

        return ($data['fully_paid'] ?? null) === 'Yes' && ($existing === null || ($existing->fully_paid ?? null) !== 'Yes');
    }

    /** @param  array<int, int|string|null>  $programIds  true when at least one is known and they may do this on every one */
    private static function mayOnAll(?User $actor, array $programIds, string $ability): bool
    {
        $events = array_unique(array_filter($programIds));
        foreach ($events as $programId) {
            if (! self::can($actor, (int) $programId, $ability)) {
                return false;
            }
        }

        return $events !== [];
    }

    private static function programOfRow(int $companyId, string $table, mixed $uuid): ?int
    {
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }
        $id = DB::table($table)->where('company_id', $companyId)->where('uuid', $uuid)->value('budget_program_id');

        return $id === null ? null : (int) $id;
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
