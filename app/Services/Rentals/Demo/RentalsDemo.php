<?php

namespace App\Services\Rentals\Demo;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Plan;
use App\Models\RentalLogin;
use App\Models\RentalPropertyStaff;
use App\Models\RentalSetting;
use App\Models\RentalUnit;
use App\Models\RentalLease;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ops\TenantDataService;
use App\Services\Rentals\Jobs\RentalCheck;
use App\Services\Rentals\PrivateFiles;
use App\Services\Team\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Estate Ledger's public demo (KR-21, docs/estate-ledger/04-architecture.md §14): "Ntinda Property Managers (Demo)", a letting
 * agency with one sign-in per staff role, a tenant's and a landlord's own sign-ins, and beside it "Mukasa Rentals
 * (Demo)", a landlord on his own in owner mode with his own sign-in (database/data/demo_rentals.php). Its own companies,
 * its own lock and its own schedule (`rentals:demo`): never the shared demo of the other apps. Both are on paid plans,
 * so the people's portal is on.
 *
 * `run()` is called every hour, per account:
 *  - heal: what visitors changed that would spoil it for the next person is put back (the sign-ins, their roles, the
 *    settings, the plan, who looks after which property, the units held or under repair), and the books are brought up
 *    to today (bills raised, tenants' payments of the days passed, a few payments today), so nothing goes stale;
 *  - rebuild: every `rentals.demo.rebuild_hours`, when BUILD changed, or when the books are broken, a new account is
 *    built beside the old one; only when it is complete are the published sign-ins moved to it (one transaction), then
 *    the old one is deleted.
 *
 * Visitors cannot change a demo sign-in's password or address, add people, change the settings or sign-ins, hand the
 * account over, pay for a plan or delete it (guard(), guardAccount(): PublicDemo's checks call them too). Nothing is
 * ever sent: Messenger skips every is_demo company, and sign-in codes are not mailed for a demo sign-in.
 */
final class RentalsDemo
{
    /** The onboarding_state key that marks a company as Estate Ledger's demo. */
    public const MARK = 'rentals_demo';

    /** The agency, and the landlord on his own. */
    public const AGENCY = 'agency';

    public const SOLO = 'solo';

    /** True while the builder fills or heals the books: the locks below are for visitors. */
    public static bool $building = false;

    /** @var array<int, bool> per request */
    private static array $isDemo = [];

    public static function enabled(): bool
    {
        return \App\Support\Edition::isSaas() && (bool) config('rentals.demo.enabled', true);
    }

    /** The staff sign-ins of an account. @return array<string, array{name: string, role: string, label: string}> */
    public static function roles(string $account = self::AGENCY): array
    {
        return (array) RentalsDemoBuilder::spec($account)['logins'];
    }

    /** The tenants' and landlords' own sign-ins of an account. @return array<string, array{type: string, label: string}> */
    public static function people(string $account = self::AGENCY): array
    {
        return (array) RentalsDemoBuilder::spec($account)['people'];
    }

    public static function domain(string $account = self::AGENCY): string
    {
        return (string) (RentalsDemoBuilder::spec($account)['domain'] ?? config('rentals.demo.domain', 'ntindaproperty.example'));
    }

    /** The published address of a sign-in (also its username). */
    public static function email(string $key, string $account = self::AGENCY): string
    {
        return $key.'@'.self::domain($account);
    }

    public static function password(): string
    {
        return (string) config('rentals.demo.password');
    }

    public static function login(string $key, string $account = self::AGENCY): ?User
    {
        return User::withoutGlobalScopes()->where('username', self::email($key, $account))->first();
    }

    /** A live demo account, when there is one: its owner's company, marked as the demo. */
    public static function company(string $account = self::AGENCY): ?Company
    {
        $owner = self::login('owner', $account);
        $company = $owner?->company_id ? Company::withoutGlobalScopes()->find($owner->company_id) : null;

        return $company && self::isDemoCompany($company) && (int) $company->owner_id === (int) $owner->id ? $company : null;
    }

    /** Is this company one of Estate Ledger's demo accounts? (Also one being built.) */
    public static function isDemoCompany(Company|int|null $company): bool
    {
        if ($company instanceof Company) {
            return (bool) $company->is_demo && ! $company->demo_parent_id && isset(((array) ($company->onboarding_state ?? []))[self::MARK]);
        }
        $id = (int) $company;
        if ($id <= 0) {
            return false;
        }

        return self::$isDemo[$id] ??= DB::table('companies')->where('id', $id)->where('is_demo', 1)->whereNull('demo_parent_id')
            ->whereRaw("JSON_EXTRACT(onboarding_state, '$.".self::MARK."') IS NOT NULL")->exists();
    }

    public static function isDemoUser(?User $user): bool
    {
        return $user !== null && self::isDemoCompany((int) $user->company_id);
    }

    /**
     * Refuse something a visitor must not do in the shared demo.
     *
     * @throws BusinessRuleException demo_locked
     */
    public static function guard(Company|int|null $company, string $what): void
    {
        if (! self::$building && self::isDemoCompany($company)) {
            throw BusinessRuleException::make('demo_locked', ucfirst(str_replace(['the shop', 'a shop'], ['the account', 'an account'], $what))
                .' is switched off in the demo, which everyone shares. Register your own account to do this.');
        }
    }

    /** Refuse changes to a demo sign-in itself (its password, its address). */
    public static function guardAccount(?User $user, string $what): void
    {
        if (! self::$building && self::isDemoUser($user)) {
            throw BusinessRuleException::make('demo_locked', ucfirst($what).' is switched off for the demo sign-ins, so the next visitor can still sign in. Register your own account to do this.');
        }
    }

    /**
     * What the website's /demo page shows: one sign-in per staff role, the tenants' and landlords' own, and the landlord
     * on his own (`solo`), with the shared password.
     *
     * @return array{ready: bool, company: ?Company, password: string, logins: list<array{key: string, label: string, name: string, email: string, user_id: ?int}>, people: list<array{key: string, label: string, name: ?string, email: string, type: string, subject_id: ?int, login_id: ?int}>, solo: ?array{key: string, label: string, name: string, email: string, user_id: int, company: Company}, built_at: ?Carbon, resets_at: ?Carbon}
     */
    public static function status(): array
    {
        $company = self::company();
        $state = $company ? (array) (((array) $company->onboarding_state)[self::MARK] ?? []) : [];
        $built = isset($state['built_at']) ? Carbon::parse($state['built_at']) : null;
        $logins = [];
        foreach (self::roles() as $key => $r) {
            $u = $company ? self::login($key) : null;
            $logins[] = ['key' => $key, 'label' => $r['label'], 'name' => $r['name'], 'email' => self::email($key),
                'user_id' => $u !== null && (int) $u->company_id === (int) $company?->id ? (int) $u->id : null];
        }
        $people = [];
        foreach (self::people() as $key => $p) {
            $login = $company ? RentalLogin::withoutGlobalScopes()->where('company_id', $company->id)->where('email', self::email($key))->whereNull('disabled_at')->first() : null;
            $people[] = ['key' => $key, 'label' => $p['label'], 'name' => $login?->subject()?->name, 'email' => self::email($key), 'type' => $p['type'], 'subject_id' => $login !== null ? (int) $login->subject_id : null, 'login_id' => $login !== null ? (int) $login->id : null];
        }
        $soloCompany = self::company(self::SOLO);
        $solo = null;
        if ($soloCompany !== null && ($u = self::login('owner', self::SOLO)) !== null) {
            $r = self::roles(self::SOLO)['owner'];
            $solo = ['key' => 'solo', 'label' => $r['label'], 'name' => $r['name'], 'email' => self::email('owner', self::SOLO), 'user_id' => (int) $u->id, 'company' => $soloCompany];
        }

        return ['ready' => $company !== null, 'company' => $company, 'password' => self::password(), 'logins' => $logins, 'people' => $people, 'solo' => $solo,
            'built_at' => $built, 'resets_at' => $built?->copy()->addHours((int) config('rentals.demo.rebuild_hours', 72))];
    }

    // ── Upkeep ──────────────────────────────────────────────────────────

    /**
     * The hourly job, for the agency and for the landlord on his own: off | busy | built | rebuilt | healed.
     *
     * @return array<string, mixed> the agency's result, with the other account's under `solo`
     */
    public function run(bool $forceRebuild = false): array
    {
        if (! self::enabled()) {
            return ['action' => 'off'];
        }
        $lock = Cache::lock('rentals-demo-upkeep', 3600);
        if (! $lock->get()) {
            return ['action' => 'busy'];
        }
        try {
            $agency = $this->upkeep(self::AGENCY, $forceRebuild);
            $agency['solo'] = $this->upkeep(self::SOLO, $forceRebuild);

            return $agency;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function upkeep(string $account, bool $forceRebuild): array
    {
        $company = self::company($account);
        $state = $company ? (array) (((array) $company->onboarding_state)[self::MARK] ?? []) : [];
        $hours = (int) config('rentals.demo.rebuild_hours', 72);
        $built = isset($state['built_at']) ? Carbon::parse($state['built_at']) : null;
        $stale = $built === null || $built->copy()->addHours($hours)->lte(now());
        $outdated = (int) ($state['snapshot']['build'] ?? 0) < RentalsDemoBuilder::BUILD;
        if ($forceRebuild || $company === null || ! isset($state['snapshot']) || $outdated || $stale || ! $this->healthy($company, $account)) {
            return ['action' => $company ? 'rebuilt' : 'built'] + $this->rebuild($company, $account);
        }

        return ['action' => 'healed'] + $this->heal($company, $state, $account);
    }

    /** Build a new account beside the old one, move the published sign-ins to it, delete the old one. @return array<string, mixed> */
    public function rebuild(?Company $old, string $account = self::AGENCY): array
    {
        $started = microtime(true);
        $builder = new RentalsDemoBuilder($account);
        try {
            ['company' => $new, 'users' => $users, 'snapshot' => $snapshot] = $builder->build();
        } catch (\Throwable $e) {
            if ($builder->companyId) {
                $this->purge($builder->companyId);
            }
            throw $e;
        }

        DB::transaction(function () use ($new, $users, $old, $snapshot, $account) {
            // The old demo's sign-ins give their addresses up first (they are unique), then the new ones take them.
            if ($old !== null) {
                foreach (DB::table('admin_users')->where('company_id', $old->id)->get(['id']) as $u) {
                    DB::table('admin_users')->where('id', $u->id)->update(['email' => null, 'username' => 'rentals-demo-retired-'.$u->id, 'status' => 'Inactive', 'updated_at' => now()]);
                }
                RentalLogin::withoutGlobalScopes()->where('company_id', $old->id)->update(['disabled_at' => now(), 'email' => null, 'updated_at' => now()]);
            }
            foreach ($users as $key => $u) {
                $email = self::email($key, $account);
                DB::table('admin_users')->where('id', $u->id)->update(['email' => $email, 'username' => $email, 'password' => Hash::make(self::password()), 'status' => 'Active', 'updated_at' => now()]);
            }
            foreach ((array) ($snapshot['logins'] ?? []) as $key => $loginId) {
                RentalLogin::withoutGlobalScopes()->where('company_id', $new->id)->whereKey((int) $loginId)
                    ->update(['email' => self::email($key, $account), 'password' => Hash::make(self::password()), 'disabled_at' => null, 'updated_at' => now()]);
            }
        });
        Permissions::flush();

        $this->remember($new->fresh(), ['account' => $account, 'built_at' => now()->toIso8601String(), 'healed_at' => now()->toIso8601String(), 'heals' => 0,
            'snapshot' => $snapshot + ['company' => $this->companyFields($new)], 'repairs' => []]);
        self::$isDemo = [];
        if ($old !== null && (int) $old->id !== (int) $new->id) {
            $this->purge((int) $old->id);
        }
        $this->purgeStrays((int) $new->id, $account);
        Log::info('[rentals-demo] built', ['company_id' => $new->id, 'account' => $account, 'seconds' => round(microtime(true) - $started, 1), 'skipped' => count($builder->skipped)]);

        return ['company_id' => (int) $new->id, 'seconds' => round(microtime(true) - $started, 1), 'skipped' => array_values(array_unique($builder->skipped))];
    }

    /** Put back what visitors changed and bring the books up to today. @param array<string, mixed> $state @return array<string, mixed> */
    public function heal(Company $company, array $state, string $account = self::AGENCY): array
    {
        $repairs = [];
        $snap = (array) $state['snapshot'];
        $cid = (int) $company->id;
        $spec = RentalsDemoBuilder::spec($account);
        foreach ((array) ($snap['users'] ?? []) as $key => $id) {
            $u = User::withoutGlobalScopes()->find((int) $id);
            if ($u === null) {
                $repairs[] = "sign-in {$key} missing";

                continue;
            }
            $email = self::email($key, $account);
            $fix = [];
            foreach (['email' => $email, 'username' => $email, 'status' => 'Active', 'company_id' => $cid] as $field => $value) {
                if ((string) $u->{$field} !== (string) $value) {
                    $fix[$field] = $value;
                }
            }
            if (! Hash::check(self::password(), (string) $u->password)) {
                $fix['password'] = Hash::make(self::password());
            }
            if ($fix !== []) {
                DB::table('admin_users')->where('id', $u->id)->update($fix + ['updated_at' => now()]);
                $repairs[] = "sign-in {$key}: ".implode(', ', array_keys($fix));
            }
            $role = (string) (($spec['logins'][$key]['role'] ?? null) ?? ($spec['staff'][$key]['role'] ?? ''));
            if ($role !== '' && DB::table('company_members')->where('company_id', $cid)->where('user_id', $u->id)
                ->where(fn ($q) => $q->where('status', '!=', 'active')->orWhere('role', '!=', $role))->update(['status' => 'active', 'role' => $role, 'updated_at' => now()]) > 0) {
                $repairs[] = "team {$key}";
            }
        }
        foreach ((array) ($snap['logins'] ?? []) as $key => $loginId) {
            $login = RentalLogin::withoutGlobalScopes()->where('company_id', $cid)->find((int) $loginId);
            if ($login !== null && ($login->disabled_at !== null || $login->locked_until !== null || $login->email !== self::email($key, $account) || ! Hash::check(self::password(), (string) $login->password))) {
                $login->forceFill(['email' => self::email($key, $account), 'password' => Hash::make(self::password()), 'disabled_at' => null, 'failures' => 0, 'locked_until' => null])->save();
                $repairs[] = "own sign-in {$key}";
            }
        }
        $changed = [];
        foreach ((array) ($snap['company'] ?? []) as $field => $value) {
            if (json_encode($company->{$field}) !== json_encode($value)) {
                $company->{$field} = $value;
                $changed[] = $field;
            }
        }
        if ($changed !== []) {
            $company->saveQuietly();
            $repairs[] = 'account: '.implode(', ', $changed);
        }
        $settings = RentalSetting::withoutGlobalScopes()->where('company_id', $cid)->first();
        $drift = [];
        foreach ((array) ($snap['settings'] ?? []) as $field => $value) {
            if ($settings !== null && json_encode($settings->{$field}) !== json_encode($value)) {
                $settings->{$field} = $value;
                $drift[] = $field;
            }
        }
        if ($drift !== []) {
            $settings->save();
            $repairs[] = 'settings: '.implode(', ', $drift);
        }
        foreach ((array) ($snap['staff'] ?? []) as $propertyId => $userIds) {
            $have = RentalPropertyStaff::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', (int) $propertyId)->pluck('user_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
            $want = collect((array) $userIds)->map(fn ($v) => (int) $v)->sort()->values()->all();
            if ($have !== $want) {
                RentalPropertyStaff::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', (int) $propertyId)->delete();
                foreach ($want as $uid) {
                    RentalPropertyStaff::withoutGlobalScopes()->create(['company_id' => $cid, 'property_id' => (int) $propertyId, 'user_id' => $uid]);
                }
                $repairs[] = "staff of property {$propertyId}";
            }
        }
        foreach ((array) ($snap['unit_states'] ?? []) as $unitId => $status) {
            $unit = RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->find((int) $unitId);
            if ($unit !== null && $unit->status !== $status && ! RentalLease::withoutGlobalScopes()->where('company_id', $cid)->where('unit_id', $unit->id)->whereIn('status', RentalLease::LIVE)->exists()) {
                $unit->forceFill(['status' => $status, 'in_use_by' => $status === 'in_use' ? ($snap['unit_users'][$unitId] ?? $unit->in_use_by) : null])->save();
                $repairs[] = "unit {$unit->code}";
            }
        }
        $plan = Plan::where('slug', (string) $spec['agency']['plan'])->value('id');
        if ($plan !== null && Subscription::withoutGlobalScopes()->where('company_id', $cid)->where(fn ($q) => $q->where('plan_id', '!=', $plan)->orWhere('status', '!=', 'active'))
            ->update(['plan_id' => $plan, 'status' => 'active', 'ends_at' => now()->addYears(5), 'updated_at' => now()]) > 0) {
            $repairs[] = 'plan';
        }
        Permissions::flush();

        $forward = (new RentalsDemoBuilder($account))->load($company->fresh(), $snap)->bringForward();
        $repairs = array_merge($repairs, $forward['repairs']);
        // bringForward records its progress as it goes; read the state again so that is not lost.
        $current = (array) (((array) $company->fresh()->onboarding_state)[self::MARK] ?? $state);
        $this->remember($company->fresh(), ['healed_at' => now()->toIso8601String(), 'heals' => (int) ($current['heals'] ?? 0) + 1, 'repairs' => $repairs] + $current);

        return ['company_id' => $cid, 'repairs' => $repairs];
    }

    /** Broken beyond a heal: the tenants or the books are gone, the books do not agree, or the account was switched off. */
    private function healthy(Company $company, string $account): bool
    {
        $cid = (int) $company->id;
        $tenants = DB::table('rental_tenants')->where('company_id', $cid)->count();
        $entries = DB::table('rental_entries')->where('company_id', $cid)->count();
        $small = $account === self::SOLO;

        return $tenants >= ($small ? 5 : 50) && $entries >= ($small ? 50 : 500) && strtolower((string) $company->status) === 'active'
            && (new RentalCheck())->run($cid) === [];
    }

    /** @return array<string, mixed> */
    private function companyFields(Company $c): array
    {
        return collect(['name', 'status', 'currency', 'country', 'timezone', 'business_type', 'address', 'phone_number', 'email', 'logo', 'enabled_modules', 'receipt_channels', 'is_demo'])
            ->mapWithKeys(fn ($f) => [$f => $c->{$f}])->all();
    }

    /** @param array<string, mixed> $state */
    private function remember(Company $company, array $state): void
    {
        $all = (array) ($company->onboarding_state ?? []);
        $all[self::MARK] = $state;
        $all['demo_status'] = 'ready';
        $company->onboarding_state = $all;
        $company->saveQuietly();
    }

    /** Delete a demo account (never anything else) with its books, its files and the people's tokens. */
    public function purge(int $id): void
    {
        self::$isDemo = [];
        if (! self::isDemoCompany($id)) {
            return;
        }
        try {
            $users = DB::table('admin_users')->where('company_id', $id)->pluck('id');
            DB::table('admin_user_permissions')->whereIn('user_id', $users)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', RentalLogin::class)
                ->whereIn('tokenable_id', DB::table('rental_logins')->where('company_id', $id)->select('id'))->delete();
            app(TenantDataService::class)->purge($id);
        } catch (\Throwable $e) {
            Log::warning('[rentals-demo] old account not deleted', ['company_id' => $id, 'error' => $e->getMessage()]);
        }
        Storage::disk('public')->deleteDirectory('files/'.$id);
        PrivateFiles::deleteAccount($id);
        self::$isDemo = [];
    }

    /** Half-built accounts left by a build that died (the server restarted mid-way). */
    private function purgeStrays(int $keep, string $account): void
    {
        $strays = DB::table('companies')->where('is_demo', 1)->whereNull('demo_parent_id')->where('id', '!=', $keep)
            ->whereRaw("JSON_EXTRACT(onboarding_state, '$.".self::MARK.".building') = true")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(onboarding_state, '$.".self::MARK.".account')) = ?", [$account])
            ->where('created_at', '<', now()->subHours(3))->pluck('id');
        foreach ($strays as $id) {
            $this->purge((int) $id);
        }
    }
}
