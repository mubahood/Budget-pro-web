<?php

namespace App\Services\Sacco\Demo;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoMemberLogin;
use App\Models\User;
use App\Services\Ops\TenantDataService;
use App\Services\Team\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * The Akiba Ledger public demo (AK-35): "Wakiso Teachers' SACCO (Demo)", its own company with its own sign-ins, one
 * per role (database/data/demo_sacco.php) plus a member's own sign-in, and its own upkeep (`sacco:demo`). It has
 * nothing to do with the shop demo (PublicDemo): another company, another lock, another schedule, another switch.
 *
 * `run()` is called every hour:
 *  - heal: whatever visitors changed that would spoil it for the next person is put back (the sign-ins, their roles,
 *    the SACCO's settings), and the books are brought up to today (the loan book's daily run, the on-time borrowers'
 *    instalments, each ended month closed), so the arrears and PAR stay true as the days pass;
 *  - rebuild: every `demo.sacco.rebuild_hours`, when the BUILD changed, or when the books are broken, a new SACCO is
 *    built beside the old one; only when it is complete are the published sign-ins moved to it (one transaction),
 *    and then the old one is deleted.
 *
 * Visitors cannot change a demo sign-in's password or address, invite or add people, hand the company over, pay for
 * a plan or delete it (guard(), guardAccount(): PublicDemo's checks call them). Messages are never sent (Messenger
 * skips every is_demo company) and nothing is ever paid out (PayoutService refuses a demo company).
 */
final class SaccoDemo
{
    /** The onboarding_state key that marks the company as the SACCO demo. */
    public const MARK = 'sacco_demo';

    /** True while SaccoDemoBuilder fills or heals the books: the locks below are for visitors. */
    public static bool $building = false;

    /** @var array<int, bool> per request */
    private static array $isDemo = [];

    public static function enabled(): bool
    {
        return \App\Support\Edition::isSaas() && (bool) config('demo.sacco.enabled', true);
    }

    /** @return array<string, array{name: string, role: string, position: ?string, label: string}> */
    public static function roles(): array
    {
        return (array) SaccoDemoBuilder::data()['logins'];
    }

    public static function domain(): string
    {
        return (string) SaccoDemoBuilder::data()['sacco']['domain'];
    }

    /** The published address of a role's sign-in (also its username). */
    public static function email(string $role): string
    {
        return $role.'@'.self::domain();
    }

    public static function memberEmail(): string
    {
        return (string) SaccoDemoBuilder::data()['member_login']['email'];
    }

    public static function password(): string
    {
        return (string) config('demo.sacco.password');
    }

    public static function login(string $role): ?User
    {
        return User::withoutGlobalScopes()->where('username', self::email($role))->first();
    }

    /** The live demo SACCO, when there is one: the chair's company, marked as the demo. */
    public static function company(): ?Company
    {
        $chair = self::login('chair');
        $company = $chair?->company_id ? Company::withoutGlobalScopes()->find($chair->company_id) : null;

        return $company && self::isDemoCompany($company) && (int) $company->owner_id === (int) $chair->id ? $company : null;
    }

    /** Is this company the SACCO demo? (Also one being built.) */
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
     * Refuse something a visitor must not do in the shared demo SACCO.
     *
     * @throws BusinessRuleException demo_locked
     */
    public static function guard(Company|int|null $company, string $what): void
    {
        if (! self::$building && self::isDemoCompany($company)) {
            throw BusinessRuleException::make('demo_locked', ucfirst(str_replace(['the shop', 'a shop'], ['the SACCO', 'a SACCO'], $what))
                .' is switched off in the demo SACCO, which everyone shares. Register your own SACCO to do this.');
        }
    }

    /** Refuse changes to a demo sign-in itself (its password, its address). */
    public static function guardAccount(?User $user, string $what): void
    {
        if (! self::$building && self::isDemoUser($user)) {
            throw BusinessRuleException::make('demo_locked', ucfirst($what).' is switched off for the demo sign-ins, so the next visitor can still sign in. Register your own SACCO to do this.');
        }
    }

    /**
     * What the website's /demo page shows: one sign-in per role and the member's, with the shared password.
     *
     * @return array{ready: bool, company: ?Company, password: string, logins: list<array{key: string, label: string, name: string, email: string, user_id: ?int}>, member: array{email: string, member_id: ?int}, built_at: ?Carbon, resets_at: ?Carbon}
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
        $memberId = $company ? SaccoMemberLogin::withoutGlobalScopes()->where('company_id', $company->id)->where('email', self::memberEmail())->whereNull('disabled_at')->value('member_id') : null;

        return ['ready' => $company !== null, 'company' => $company, 'password' => self::password(), 'logins' => $logins,
            'member' => ['email' => self::memberEmail(), 'member_id' => $memberId === null ? null : (int) $memberId],
            'built_at' => $built, 'resets_at' => $built?->copy()->addHours((int) config('demo.sacco.rebuild_hours', 72))];
    }

    // ── Upkeep ──────────────────────────────────────────────────────────

    /** The hourly job: off | busy | built | rebuilt | healed. @return array<string, mixed> */
    public function run(bool $forceRebuild = false): array
    {
        if (! self::enabled()) {
            return ['action' => 'off'];
        }
        $lock = Cache::lock('sacco-demo-upkeep', 3600);
        if (! $lock->get()) {
            return ['action' => 'busy'];
        }
        try {
            $company = self::company();
            $state = $company ? (array) (((array) $company->onboarding_state)[self::MARK] ?? []) : [];
            $age = isset($state['built_at']) ? Carbon::parse($state['built_at'])->diffInHours(now()) : PHP_INT_MAX;
            $outdated = (int) ($state['snapshot']['build'] ?? 0) < SaccoDemoBuilder::BUILD;
            if ($forceRebuild || $company === null || ! isset($state['snapshot']) || $outdated || $age >= (int) config('demo.sacco.rebuild_hours', 72) || ! $this->healthy($company)) {
                return ['action' => $company ? 'rebuilt' : 'built'] + $this->rebuild($company);
            }

            return ['action' => 'healed'] + $this->heal($company, $state);
        } finally {
            $lock->release();
        }
    }

    /** Build a new SACCO beside the old one, move the published sign-ins to it, delete the old one. @return array<string, mixed> */
    public function rebuild(?Company $old): array
    {
        $started = microtime(true);
        $builder = new SaccoDemoBuilder();
        try {
            ['company' => $new, 'users' => $users, 'snapshot' => $snapshot] = $builder->build();
        } catch (\Throwable $e) {
            if ($builder->companyId) {
                $this->purge($builder->companyId);
            }
            throw $e;
        }

        DB::transaction(function () use ($new, $users, $old) {
            // The old demo's sign-ins give their addresses up first (they are unique), then the new ones take them.
            if ($old !== null) {
                foreach (DB::table('admin_users')->where('company_id', $old->id)->get(['id']) as $u) {
                    DB::table('admin_users')->where('id', $u->id)->update(['email' => null, 'username' => 'sacco-demo-retired-'.$u->id, 'status' => 'Inactive', 'updated_at' => now()]);
                }
                SaccoMemberLogin::withoutGlobalScopes()->where('company_id', $old->id)->update(['disabled_at' => now(), 'email' => 'retired-'.$old->id.'@'.self::domain(), 'updated_at' => now()]);
            }
            foreach ($users as $key => $u) {
                $email = isset(self::roles()[$key]) ? self::email($key) : $key.'@'.self::domain();
                DB::table('admin_users')->where('id', $u->id)->update(['email' => $email, 'username' => $email, 'password' => Hash::make(self::password()), 'status' => 'Active', 'updated_at' => now()]);
            }
            SaccoMemberLogin::withoutGlobalScopes()->where('company_id', $new->id)->update(['email' => self::memberEmail(), 'password' => Hash::make(self::password()), 'disabled_at' => null, 'updated_at' => now()]);
        });
        Permissions::flush();

        $this->remember($new->fresh(), ['built_at' => now()->toIso8601String(), 'healed_at' => now()->toIso8601String(), 'heals' => 0, 'snapshot' => $snapshot + ['company' => $this->companyFields($new)],
            'repairs' => [], 'pending' => $builder->pending()]);
        self::$isDemo = [];
        if ($old !== null && (int) $old->id !== (int) $new->id) {
            $this->purge((int) $old->id);
        }
        $this->purgeStrays((int) $new->id);
        Log::info('[sacco-demo] built', ['company_id' => $new->id, 'seconds' => round(microtime(true) - $started, 1), 'skipped' => count($builder->skipped)]);

        return ['company_id' => (int) $new->id, 'seconds' => round(microtime(true) - $started, 1), 'skipped' => array_values(array_unique($builder->skipped)), 'pending' => $builder->pending()];
    }

    /** Put back what visitors changed and bring the books up to today. @param array<string, mixed> $state @return array<string, mixed> */
    public function heal(Company $company, array $state): array
    {
        $repairs = [];
        $snap = (array) $state['snapshot'];
        foreach ((array) ($snap['users'] ?? []) as $key => $id) {
            $u = User::withoutGlobalScopes()->find((int) $id);
            if ($u === null) {
                $repairs[] = "sign-in {$key} missing";

                continue;
            }
            $email = isset(self::roles()[$key]) ? self::email($key) : $key.'@'.self::domain();
            $fix = [];
            foreach (['email' => $email, 'username' => $email, 'status' => 'Active', 'company_id' => (int) $company->id] as $field => $value) {
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
            if (DB::table('company_members')->where('company_id', $company->id)->where('user_id', $u->id)->where('status', '!=', 'active')->update(['status' => 'active', 'updated_at' => now()]) > 0) {
                $repairs[] = "team {$key}";
            }
        }
        $login = SaccoMemberLogin::withoutGlobalScopes()->where('company_id', $company->id)->where('member_id', (int) ($snap['member_login'] ?? 0))->first();
        if ($login !== null && ($login->disabled_at !== null || $login->email !== self::memberEmail() || ! Hash::check(self::password(), (string) $login->password))) {
            $login->forceFill(['email' => self::memberEmail(), 'password' => Hash::make(self::password()), 'disabled_at' => null, 'failed_attempts' => 0, 'locked_until' => null])->save();
            $repairs[] = 'member sign-in';
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
            $repairs[] = 'settings: '.implode(', ', $changed);
        }
        Permissions::flush();

        $forward = (new SaccoDemoBuilder())->load($company->fresh(), $snap)->bringForward();
        $repairs = array_merge($repairs, $forward['repairs']);
        $this->remember($company->fresh(), ['healed_at' => now()->toIso8601String(), 'heals' => (int) ($state['heals'] ?? 0) + 1, 'repairs' => $repairs, 'pending' => $forward['pending']] + $state);

        return ['company_id' => (int) $company->id, 'repairs' => $repairs, 'pending' => $forward['pending']];
    }

    /** Broken beyond a heal: the members or the books are gone, or the company was switched off. */
    private function healthy(Company $company): bool
    {
        $members = DB::table('sacco_members')->where('company_id', $company->id)->where('is_deleted', 0)->count();
        $entries = DB::table('sacco_journal_entries')->where('company_id', $company->id)->count();

        return $members >= 50 && $entries >= 500 && strtolower((string) $company->status) === 'active';
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

    /** Delete a demo SACCO (never anything else) with its books: the journal's triggers let only this through. */
    public function purge(int $id): void
    {
        self::$isDemo = [];
        if (! self::isDemoCompany($id)) {
            return;
        }
        try {
            $users = DB::table('admin_users')->where('company_id', $id)->pluck('id');
            DB::table('admin_user_permissions')->whereIn('user_id', $users)->delete();
            if (DB::getDriverName() === 'mysql') {
                DB::statement('SET @sacco_ledger_purge = 1');
            }
            app(TenantDataService::class)->purge($id);
        } catch (\Throwable $e) {
            Log::warning('[sacco-demo] old SACCO not deleted', ['company_id' => $id, 'error' => $e->getMessage()]);
        } finally {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('SET @sacco_ledger_purge = 0');
            }
        }
        foreach (['sacco/loans/'.$id, 'public/sacco/demo/'.$id] as $dir) {
            \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/'.$dir));
        }
        self::$isDemo = [];
    }

    /** Half-built SACCOs left by a build that died (the server restarted mid-way). */
    private function purgeStrays(int $keep): void
    {
        $strays = DB::table('companies')->where('is_demo', 1)->whereNull('demo_parent_id')->where('id', '!=', $keep)
            ->whereRaw("JSON_EXTRACT(onboarding_state, '$.".self::MARK.".building') = true")->where('created_at', '<', now()->subHours(3))->pluck('id');
        foreach ($strays as $id) {
            $this->purge((int) $id);
        }
    }
}
