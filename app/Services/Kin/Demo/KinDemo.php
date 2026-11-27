<?php

namespace App\Services\Kin\Demo;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\User;
use App\Services\Ops\TenantDataService;
use App\Services\Team\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * My Family Roots 360's demo: "The Sample Family (Demo)" (KinDemoBuilder), one sign-in per role (head, secretary,
 * treasurer, branch coordinator, member) on the demo's made-up domain with the shared demo password. Its own company,
 * lock and schedule (`kin:demo`), never another app's demo.
 *
 * `run()` each hour: rebuilds every kin.demo.rebuild_hours, when KinDemoBuilder::BUILD changed or when the account is
 * missing (a new account beside the old one, the sign-ins moved over in one transaction, then the old one deleted);
 * otherwise it puts the sign-ins back as published (password, address, role).
 *
 * Visitors cannot change a demo sign-in's password or address, add people to the team, hand the account over or delete
 * it (guard(), guardAccount(): PublicDemo's checks call them). Changes to the tree itself are allowed and undone by the
 * next rebuild.
 */
final class KinDemo
{
    public const MARK = 'kin_demo';

    /** True while the builder works: the locks are for visitors. */
    public static bool $building = false;

    /** @var array<int, bool> */
    private static array $isDemo = [];

    public static function enabled(): bool
    {
        return \App\Support\Edition::isSaas() && (bool) config('kin.demo.enabled', true);
    }

    /** @return array<string, array{name: string, role: string, label: string}> */
    public static function roles(): array
    {
        return KinDemoBuilder::LOGINS;
    }

    public static function domain(): string
    {
        return (string) config('kin.demo.domain', 'samplefamily.example');
    }

    public static function email(string $key): string
    {
        return $key.'@'.self::domain();
    }

    public static function password(): string
    {
        return (string) config('kin.demo.password');
    }

    public static function login(string $key): ?User
    {
        return User::withoutGlobalScopes()->where('username', self::email($key))->first();
    }

    public static function company(): ?Company
    {
        $head = self::login('head');
        $company = $head?->company_id ? Company::withoutGlobalScopes()->find($head->company_id) : null;

        return $company && self::isDemoCompany($company) && (int) $company->owner_id === (int) $head->id ? $company : null;
    }

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

    /** @throws BusinessRuleException demo_locked */
    public static function guard(Company|int|null $company, string $what): void
    {
        if (! self::$building && self::isDemoCompany($company)) {
            throw BusinessRuleException::make('demo_locked', ucfirst(str_replace(['the shop', 'a shop'], ['the family', 'a family'], $what))
                .' is switched off in the demo, which everyone shares. Start your own family to do this.');
        }
    }

    /** @throws BusinessRuleException demo_locked */
    public static function guardAccount(?User $user, string $what): void
    {
        if (! self::$building && self::isDemoUser($user)) {
            throw BusinessRuleException::make('demo_locked', ucfirst($what).' is switched off for the demo sign-ins, so the next visitor can still sign in. Start your own family to do this.');
        }
    }

    /** @return array{ready: bool, company: ?Company, password: string, logins: list<array{key: string, label: string, name: string, email: string}>, built_at: ?Carbon} */
    public static function status(): array
    {
        $company = self::company();
        $state = $company ? (array) (((array) $company->onboarding_state)[self::MARK] ?? []) : [];
        $logins = [];
        foreach (self::roles() as $key => $r) {
            $logins[] = ['key' => $key, 'label' => $r['label'], 'name' => $r['name'], 'email' => self::email($key)];
        }

        return ['ready' => $company !== null, 'company' => $company, 'password' => self::password(), 'logins' => $logins,
            'built_at' => isset($state['built_at']) ? Carbon::parse($state['built_at']) : null];
    }

    // ── Upkeep ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> action: off | busy | built | rebuilt | healed */
    public function run(bool $forceRebuild = false): array
    {
        if (! self::enabled()) {
            return ['action' => 'off'];
        }
        $lock = Cache::lock('kin-demo-upkeep', 3600);
        if (! $lock->get()) {
            return ['action' => 'busy'];
        }
        try {
            $company = self::company();
            $state = $company ? (array) (((array) $company->onboarding_state)[self::MARK] ?? []) : [];
            $built = isset($state['built_at']) ? Carbon::parse($state['built_at']) : null;
            $stale = $built === null || $built->copy()->addHours(max(1, (int) config('kin.demo.rebuild_hours', 72)))->lte(now());
            $outdated = (int) ($state['snapshot']['build'] ?? 0) < KinDemoBuilder::BUILD;
            if ($forceRebuild || $company === null || $stale || $outdated) {
                return ['action' => $company ? 'rebuilt' : 'built'] + $this->rebuild($company);
            }

            return ['action' => 'healed', 'company_id' => (int) $company->id, 'repairs' => $this->heal($company, $state)];
        } finally {
            $lock->release();
        }
    }

    /** Build a new account beside the old one, move the published sign-ins to it, delete the old one. @return array<string, mixed> */
    public function rebuild(?Company $old): array
    {
        $started = microtime(true);
        $builder = new KinDemoBuilder();
        try {
            ['company' => $new, 'users' => $users, 'snapshot' => $snapshot] = $builder->build();
        } catch (\Throwable $e) {
            if ($builder->companyId) {
                $this->purge($builder->companyId);
            }
            throw $e;
        }

        DB::transaction(function () use ($new, $users, $old) {
            if ($old !== null) {
                foreach (DB::table('admin_users')->where('company_id', $old->id)->pluck('id') as $id) {
                    DB::table('admin_users')->where('id', $id)->update(['email' => null, 'username' => 'kin-demo-retired-'.$id, 'status' => 'Inactive', 'updated_at' => now()]);
                }
            }
            foreach ($users as $key => $u) {
                $email = self::email($key);
                DB::table('admin_users')->where('id', $u->id)->update(['email' => $email, 'username' => $email, 'password' => Hash::make(self::password()), 'status' => 'Active', 'updated_at' => now()]);
            }
        });
        $state = (array) $new->fresh()->onboarding_state;
        $state[self::MARK] = ['built_at' => now()->toIso8601String(), 'snapshot' => $snapshot];
        $state['demo_status'] = 'ready';
        Company::withoutGlobalScopes()->whereKey($new->id)->update(['onboarding_state' => json_encode($state)]);
        Permissions::flush();
        self::$isDemo = [];
        if ($old !== null && (int) $old->id !== (int) $new->id) {
            $this->purge((int) $old->id);
        }
        Log::info('[kin-demo] built', ['company_id' => $new->id, 'seconds' => round(microtime(true) - $started, 1)]);

        return ['company_id' => (int) $new->id, 'people' => (int) ($snapshot['people'] ?? 0), 'seconds' => round(microtime(true) - $started, 1)];
    }

    /** Put the sign-ins back as published. @param array<string, mixed> $state @return list<string> */
    private function heal(Company $company, array $state): array
    {
        $repairs = [];
        foreach ((array) ($state['snapshot']['users'] ?? []) as $key => $id) {
            $u = User::withoutGlobalScopes()->find((int) $id);
            if ($u === null || ! isset(self::roles()[$key])) {
                continue;
            }
            $email = self::email($key);
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
            $role = self::roles()[$key]['role'];
            if (DB::table('company_members')->where('company_id', $company->id)->where('user_id', $u->id)
                ->where(fn ($q) => $q->where('status', '!=', 'active')->orWhere('role', '!=', $role))->update(['status' => 'active', 'role' => $role, 'updated_at' => now()]) > 0) {
                $repairs[] = "team {$key}";
            }
        }
        if ($repairs !== []) {
            Permissions::flush();
        }

        return $repairs;
    }

    /** Delete a demo family (never anything else) with its books. */
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
                DB::statement('SET @sacco_ledger_purge = 1'); // the purse's journals are append-only except when a whole demo goes
            }
            app(TenantDataService::class)->purge($id);
        } catch (\Throwable $e) {
            Log::warning('[kin-demo] old account not deleted', ['company_id' => $id, 'error' => $e->getMessage()]);
        } finally {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('SET @sacco_ledger_purge = 0');
            }
        }
        self::$isDemo = [];
    }
}
