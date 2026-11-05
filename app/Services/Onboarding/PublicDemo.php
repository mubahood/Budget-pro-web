<?php

namespace App\Services\Onboarding;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\User;
use App\Services\Ops\TenantDataService;
use App\Services\Shop\ApprovalService;
use App\Services\Team\Permissions;
use App\Services\Team\TeamService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * The family's public demos: anybody can open each from its app's website with one click (config/demo.php). Each app has
 * its own account, so a visitor sees only that app's modules and words: Budget Pro keeps Fresh Corner Market with the shop,
 * the farm and the events; Shelfie, Kuku Tally and Pledge & Budget each have theirs (`demo.apps`, built by PublicDemoBuilder
 * and AppDemoBuilder). Everything here takes the account's key (config/apps.php's key), Budget Pro's when left out.
 *
 * Each looks after itself. `runAll()` is called every hour, which runs `run()` per account:
 *  - heal: whatever visitors changed that would spoil it for the next person is put back (the
 *    sign-in, the shop's settings, deleted products and photos, deactivated staff), and today's
 *    trading is brought up to the minute, so the dashboard is never a museum;
 *  - rebuild: every `demo.rebuild_hours`, or when the shop is broken beyond repair, a new shop is
 *    built from scratch *beside* the old one. Only when it is complete is the demo account moved
 *    into it (one transaction), and then the old shop is deleted. Nobody lands in a half-built shop.
 *
 * A demo is a company with `is_demo = 1`, no `demo_parent_id` (so the owners' private demo shops' clean-up never touches
 * it) and the `public_demo` mark in its onboarding_state, whose `account` names the app (no `account`: Budget Pro's, the
 * first one), owned by the account's username. Every lock below (guard, guardAccount, isDemoCompany, isDemoUser) covers all
 * of them. Akiba Ledger and Estate Ledger keep their own demos and locks (SaccoDemo, RentalsDemo).
 */
class PublicDemo
{
    public const USERNAME = 'public-demo';

    public const PIN = '1234';

    /** Budget Pro's account, the one with every module: the default everywhere (old phones, the main domain). */
    public const BUDGET_PRO = 'budget-pro';

    private const SHOP_WORDS = ['place' => 'shop', 'name' => 'demo shop', 'own' => 'Create your own shop'];

    /** @var array<int, string|false> per request: company id => its account, false when it is no public demo */
    private static array $accountOf = [];

    /** @var array<string, array<string, mixed>> per process: an account's data file */
    private static array $data = [];

    /** True while PublicDemoBuilder fills a shop: the locks below are for visitors. */
    public static bool $building = false;

    public static function enabled(): bool
    {
        // Forced off on a standalone copy, whatever DEMO_PUBLIC says: it would add a company and publish a known sign-in.
        return \App\Support\Edition::isSaas() && (bool) config('demo.enabled', true);
    }

    /** @return list<string> every account's key, Budget Pro's first */
    public static function accounts(): array
    {
        return [self::BUDGET_PRO, ...array_keys((array) config('demo.apps', []))];
    }

    public static function hasAccount(?string $account): bool
    {
        return $account !== null && in_array($account, self::accounts(), true);
    }

    /**
     * An account's sign-in, kind (all, shop, farm, events), data file, modules, company details and words.
     *
     * @return array{username: string, email: string, password: string, kind: string, data: string, modules: list<string>, shop: array<string, mixed>, words: array{place: string, name: string, own: string}}
     */
    public static function spec(string $account = self::BUDGET_PRO): array
    {
        if ($account === self::BUDGET_PRO) {
            return ['username' => self::USERNAME, 'email' => (string) config('demo.email'), 'password' => (string) config('demo.password'), 'kind' => 'all', 'data' => 'public_demo',
                'modules' => PublicDemoBuilder::MODULES, 'shop' => (array) config('demo.shop'), 'words' => self::SHOP_WORDS];
        }
        $spec = config("demo.apps.{$account}");
        if (! is_array($spec)) {
            throw new \InvalidArgumentException("No public demo called {$account}.");
        }

        return $spec + ['words' => self::SHOP_WORDS];
    }

    /** An account's people and catalogue (database/data). @return array<string, mixed> */
    public static function data(string $account = self::BUDGET_PRO): array
    {
        return self::$data[$account] ??= require dirname(__DIR__, 3).'/database/data/'.self::spec($account)['data'].'.php';
    }

    public static function owner(string $account = self::BUDGET_PRO): ?User
    {
        return User::withoutGlobalScopes()->where('username', self::spec($account)['username'])->first();
    }

    /** An account's live demo company, when there is one. */
    public static function company(string $account = self::BUDGET_PRO): ?Company
    {
        $owner = self::owner($account);
        $company = $owner?->company_id ? Company::withoutGlobalScopes()->find($owner->company_id) : null;

        return $company && $company->is_demo && ! $company->demo_parent_id && (int) $company->owner_id === (int) $owner->id
            && ($account === self::BUDGET_PRO || self::accountOf($company) === $account) ? $company : null;
    }

    /** Which account a company is, or null when it is no public demo. (Any company built for one, including one being built.) */
    public static function accountOf(Company|int|null $company): ?string
    {
        if ($company instanceof Company) {
            // Already loaded: answered from the row, no query (the shell asks on every page).
            if (! $company->is_demo || $company->demo_parent_id) {
                return null;
            }
            $state = ((array) ($company->onboarding_state ?? []))['public_demo'] ?? null;

            return $state === null ? null : (string) (((array) $state)['account'] ?? self::BUDGET_PRO);
        }
        $id = (int) $company;
        if ($id <= 0) {
            return null;
        }
        if (! array_key_exists($id, self::$accountOf)) {
            $raw = DB::table('companies')->where('id', $id)->where('is_demo', 1)->whereNull('demo_parent_id')->value('onboarding_state');
            $state = is_string($raw) ? (json_decode($raw, true)['public_demo'] ?? null) : null;
            self::$accountOf[$id] = $state === null ? false : (string) (((array) $state)['account'] ?? self::BUDGET_PRO);
        }

        return self::$accountOf[$id] === false ? null : self::$accountOf[$id];
    }

    /** Is this company a public demo? */
    public static function isDemoCompany(Company|int|null $company): bool
    {
        return self::accountOf($company) !== null;
    }

    public static function isDemoUser(?User $user): bool
    {
        return $user !== null && (self::isDemoUsername($user->username) || self::isDemoCompany((int) $user->company_id));
    }

    /** Is this one of the published sign-ins? */
    public static function isDemoUsername(?string $username): bool
    {
        foreach (self::accounts() as $account) {
            if ($username === self::spec($account)['username']) {
                return true;
            }
        }

        return false;
    }

    /** What an account's refusals call it: its place, its name, the invitation. @return array{place: string, name: string, own: string} */
    private static function words(?string $account): array
    {
        return self::hasAccount($account) ? self::spec($account)['words'] : self::SHOP_WORDS;
    }

    /**
     * Refuse something a visitor must not do in a shared demo.
     *
     * @throws BusinessRuleException demo_locked
     */
    public static function guard(Company|int|null $company, string $what): void
    {
        \App\Services\Sacco\Demo\SaccoDemo::guard($company, $what); // the Akiba Ledger demo has its own locks (AK-35)
        if (class_exists(\App\Services\Rentals\Demo\RentalsDemo::class)) {
            \App\Services\Rentals\Demo\RentalsDemo::guard($company, $what); // and so has the Estate Ledger demo agency (KR-21)
        }
        if (! self::$building && ($account = self::accountOf($company)) !== null) {
            $w = self::words($account);
            throw BusinessRuleException::make('demo_locked', ucfirst(str_replace('the shop', 'the '.$w['place'], $what)).' is switched off in the '.$w['name'].', which everyone shares. '.$w['own'].' to do this.');
        }
    }

    /**
     * Refuse changes to a shared sign-in itself: the owner's and, since each role opens in one click (roles()), the team's.
     */
    public static function guardAccount(?User $user, string $what): void
    {
        \App\Services\Sacco\Demo\SaccoDemo::guardAccount($user, $what);
        if (class_exists(\App\Services\Rentals\Demo\RentalsDemo::class)) {
            \App\Services\Rentals\Demo\RentalsDemo::guardAccount($user, $what);
        }
        if (! self::$building && self::isDemoUser($user)) {
            throw BusinessRuleException::make('demo_locked', ucfirst($what).' is switched off for the demo account, so the next visitor can still sign in. '.self::words(self::accountOf((int) $user->company_id))['own'].' to do this.');
        }
    }

    /**
     * What the website and the demo strip use. The password stays here for the phones and the API: no public page prints it.
     *
     * @return array<string, mixed>
     */
    public static function status(string $account = self::BUDGET_PRO): array
    {
        $spec = self::spec($account);
        $company = self::company($account);
        $state = $company ? (array) (($company->onboarding_state ?? [])['public_demo'] ?? []) : [];
        $built = isset($state['built_at']) ? Carbon::parse($state['built_at']) : null;

        return [
            'ready' => $company !== null,
            'account' => $account,
            'email' => $spec['email'],
            'password' => $spec['password'],
            'pin' => in_array('shop', $spec['modules'], true) ? self::PIN : null, // only a shop has a till
            'shop' => $company?->name,
            'built_at' => $built,
            'resets_at' => $built?->copy()->addHours((int) config('demo.rebuild_hours', 72)),
        ];
    }

    /**
     * The people a visitor can open an account as, in one click: the owner and one of each role on its team, with what
     * they do. The /demo page's buttons, and the only people DemoController::enter will sign in.
     *
     * @return array<string, array{label: string, what: string, name: string, user_id: int}> role => who
     */
    public static function roles(string $account = self::BUDGET_PRO): array
    {
        $company = self::company($account);
        $owner = $company ? self::owner($account) : null;
        if ($company === null || $owner === null) {
            return [];
        }
        $data = self::data($account);
        $staff = (array) (((array) ($company->onboarding_state ?? []))['public_demo']['snapshot']['staff'] ?? []);
        $out = [];
        foreach ((array) ($data['roles'] ?? []) as $role => $r) {
            $id = (int) $owner->id;
            $name = (string) $owner->name;
            if ($role !== 'owner') {
                $id = 0;
                foreach ((array) $data['team'] as [$person, $personRole]) {
                    // A button names its person where several hold the same role on the account (Pledge & Budget's committee).
                    if (($r['person'] ?? null) === $person || (! isset($r['person']) && $personRole === $role)) {
                        $id = (int) ($staff[strtolower(strtok($person, ' '))] ?? 0);
                        $name = (string) $person;
                        break;
                    }
                }
            }
            if ($id > 0) {
                $out[$role] = ['label' => (string) $r['label'], 'what' => (string) $r['what'], 'name' => $name, 'user_id' => $id];
            }
        }

        return $out;
    }

    /** Whom a role button signs in: someone on that account's own list and in its company, or nobody. Never another account's. */
    public static function roleUser(string $account, ?string $role): ?User
    {
        $company = self::company($account);
        $id = $company !== null && $role !== null ? (self::roles($account)[$role]['user_id'] ?? null) : null;

        return $id === null ? null : User::withoutGlobalScopes()->where('company_id', $company->id)->whereKey($id)->where('status', 'Active')->first();
    }

    // ── Upkeep ──────────────────────────────────────────────────────────

    /**
     * The hourly job for every account. One account failing never stops the others. At most one account that is merely due
     * is rebuilt per run (the others are healed and wait for the next hour), so the accounts drift apart and the shared host
     * never builds them all at once; one that does not exist yet is always built.
     *
     * @return array<string, array<string, mixed>> account => what run() did, or action `failed` with the error
     */
    public function runAll(bool $forceRebuild = false, ?string $only = null): array
    {
        $results = [];
        $hold = false;
        foreach (self::accounts() as $account) {
            if ($only !== null && $only !== $account) {
                continue;
            }
            try {
                $results[$account] = $this->run($forceRebuild, $account, $hold);
                $hold = $hold || $results[$account]['action'] === 'rebuilt';
            } catch (\Throwable $e) {
                Log::error('[public-demo] upkeep failed', ['account' => $account, 'error' => $e->getMessage()]);
                $results[$account] = ['action' => 'failed', 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * One account's upkeep. Returns what it did: off | built | rebuilt | healed | busy.
     *
     * @param  bool  $holdDue  heal rather than rebuild when the only reason to rebuild is that it is due (runAll)
     */
    public function run(bool $forceRebuild = false, string $account = self::BUDGET_PRO, bool $holdDue = false): array
    {
        if (! self::enabled()) {
            return ['action' => 'off'];
        }
        $lock = Cache::lock($account === self::BUDGET_PRO ? 'public-demo-upkeep' : "public-demo-upkeep:{$account}", 3600);
        if (! $lock->get()) {
            return ['action' => 'busy'];
        }
        try {
            $owner = $this->ensureOwner($account);
            $company = self::company($account);
            $state = $company ? (array) (($company->onboarding_state ?? [])['public_demo'] ?? []) : [];
            $age = isset($state['built_at']) ? Carbon::parse($state['built_at'])->diffInHours(now()) : PHP_INT_MAX;
            $outdated = (int) ($state['snapshot']['build'] ?? 1) < self::buildNumber($account); // built by an older version
            $due = $outdated || $age >= (int) config('demo.rebuild_hours', 72);
            if ($forceRebuild || $company === null || ! isset($state['snapshot']) || ($due && ! $holdDue) || ! $this->healthy($company, $account)) {
                return ['action' => $company ? 'rebuilt' : 'built'] + $this->rebuild($owner, $company, $account);
            }

            return ['action' => 'healed'] + $this->heal($company, $owner, $state, $account);
        } finally {
            $lock->release();
        }
    }

    private static function isShop(string $account): bool
    {
        return in_array(self::spec($account)['kind'], ['all', 'shop'], true);
    }

    private static function buildNumber(string $account): int
    {
        return self::isShop($account) ? PublicDemoBuilder::BUILD : AppDemoBuilder::BUILD;
    }

    private function builder(string $account): PublicDemoBuilder|AppDemoBuilder
    {
        return self::isShop($account) ? new PublicDemoBuilder($account) : new AppDemoBuilder($account);
    }

    /** Build a fresh account beside the old one, move the demo sign-in in, delete the old one. */
    public function rebuild(User $owner, ?Company $old, string $account = self::BUDGET_PRO): array
    {
        $started = microtime(true);
        $builder = $this->builder($account);
        try {
            ['company' => $new, 'manager' => $manager, 'snapshot' => $snapshot] = $builder->build();
        } catch (\Throwable $e) {
            if ($builder->companyId) {
                $this->purge(Company::withoutGlobalScopes()->find($builder->companyId)); // the old demo stays live
            }
            throw $e;
        }

        $managerRole = (string) (self::data($account)['team'][0][1] ?? 'manager');
        DB::transaction(function () use ($new, $owner, $manager, $managerRole) {
            $new->owner_id = $owner->id;
            $new->saveQuietly();
            $owner->company_id = $new->id;
            $owner->status = 'Active';
            $owner->save();
            DB::table('company_members')->updateOrInsert(['company_id' => $new->id, 'user_id' => $owner->id], ['role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('company_members')->updateOrInsert(['company_id' => $new->id, 'user_id' => $manager->id], ['role' => $managerRole, 'status' => 'active', 'updated_at' => now()]);
            app(TeamService::class)->syncAdminRole($owner->fresh(), 'owner');
            app(TeamService::class)->syncAdminRole($manager->fresh(), $managerRole);
        });
        Permissions::flush();
        if (self::isShop($account)) {
            app(ApprovalService::class)->setPin($owner->fresh(), self::PIN);
        }
        foreach (array_unique(array_filter([...array_values((array) ($snapshot['events'] ?? [])), (int) ($snapshot['wedding'] ?? 0)])) as $eventId) {
            (new DemoPledgeExtras())->apply((int) $eventId, (int) $owner->id, [(int) $owner->id => 'chair'], false); // the demo account chairs Brian and Grace's events
        }

        $new = $new->fresh();
        $this->remember($new, ['account' => $account, 'built_at' => now()->toIso8601String(), 'healed_at' => now()->toIso8601String(), 'heals' => 0,
            'snapshot' => $snapshot + ['company' => $this->companyFields($new), 'owner_id' => (int) $owner->id], 'repairs' => [], 'demo_status' => null]);
        $this->forgetCache();

        if ($old !== null && (int) $old->id !== (int) $new->id) {
            $this->purge($old);
        }
        $this->purgeStrays((int) $new->id);

        Log::info('[public-demo] built', ['account' => $account, 'company_id' => $new->id, 'seconds' => round(microtime(true) - $started, 1), 'skipped' => count($builder->skipped)]);

        return ['company_id' => (int) $new->id, 'seconds' => round(microtime(true) - $started, 1), 'skipped' => array_values(array_unique($builder->skipped))];
    }

    /** Put back what visitors changed, and bring today's trading up to now. */
    public function heal(Company $company, User $owner, array $state, string $account = self::BUDGET_PRO): array
    {
        $repairs = [];
        $spec = self::spec($account);
        $snap = (array) $state['snapshot'];
        // Its own modules and no others (Budget Pro's demo built when it was a shop only gets the others now).
        $snap['company'] = ['enabled_modules' => $spec['modules']] + (array) ($snap['company'] ?? []);

        // The sign-in everybody uses.
        $owner = $owner->fresh();
        if (! Hash::check($spec['password'], (string) $owner->password)) {
            $owner->password = Hash::make($spec['password']);
            $repairs[] = 'password';
        }
        foreach (['email' => strtolower($spec['email']), 'status' => 'Active', 'company_id' => (int) $company->id] as $field => $value) {
            if ((string) $owner->{$field} !== (string) $value) {
                $owner->{$field} = $value;
                $repairs[] = "account {$field}";
            }
        }
        if ($owner->isDirty()) {
            $owner->save();
        }
        DB::table('company_members')->updateOrInsert(['company_id' => $company->id, 'user_id' => $owner->id], ['role' => 'owner', 'status' => 'active', 'updated_at' => now()]);

        // The shop's settings.
        $changed = [];
        foreach ($snap['company'] as $field => $value) {
            if (json_encode($company->{$field}) !== json_encode($value)) {
                $company->{$field} = $value;
                $changed[] = $field;
            }
        }
        if ($changed !== []) {
            $company->saveQuietly();
            $repairs[] = 'settings: '.implode(', ', $changed);
        }

        // The team: nobody locked out or removed.
        $staff = array_values((array) ($snap['staff'] ?? []));
        $n = DB::table('admin_users')->whereIn('id', $staff)->where('company_id', $company->id)->where('status', '!=', 'Active')->update(['status' => 'Active']);
        $n += DB::table('company_members')->where('company_id', $company->id)->whereIn('user_id', $staff)->where('status', '!=', 'active')->update(['status' => 'active']);
        // Each keeps the role its button promises (a visitor may be the owner, who can change them).
        foreach ((array) self::data($account)['team'] as [$person, $role]) {
            $id = (int) (($snap['staff'] ?? [])[strtolower(strtok($person, ' '))] ?? 0);
            if ($id > 0 && DB::table('company_members')->where('company_id', $company->id)->where('user_id', $id)->where('role', '!=', $role)->update(['role' => $role, 'updated_at' => now()]) > 0) {
                app(TeamService::class)->syncAdminRole(User::withoutGlobalScopes()->findOrFail($id), $role);
                $n++;
            }
        }
        if ($n > 0) {
            Permissions::flush();
            $repairs[] = 'team';
        }

        if (self::isShop($account)) {
            $repairs = array_merge($repairs, $this->restoreShop($company, $snap));
        }

        // Today, up to the minute (and for Budget Pro the farm and the wedding: there, current, and never twice).
        $forward = $this->builder($account)->load($company->fresh(), $snap)->bringForward();
        $snap = (array) $forward['snapshot'] + $snap;
        $repairs = array_merge($repairs, $forward['repairs']);

        $state['snapshot'] = $snap;
        $this->remember($company->fresh(), ['healed_at' => now()->toIso8601String(), 'heals' => (int) ($state['heals'] ?? 0) + 1, 'repairs' => $repairs] + $state);
        $this->forgetCache();

        return ['company_id' => (int) $company->id, 'repairs' => $repairs, 'sales' => $forward['sales']];
    }

    /** @return list<string> what it put back in the shop's catalogue and customers */
    private function restoreShop(Company $company, array $snap): array
    {
        $repairs = [];

        // Products deleted or stripped of their photo come back.
        $restored = 0;
        foreach ((array) ($snap['items'] ?? []) as $it) {
            $restored += DB::table('stock_items')->where('company_id', $company->id)->where('id', $it['id'])
                ->where(fn ($q) => $q->where('is_deleted', 1)->orWhere(fn ($w) => $w->whereRaw('COALESCE(image, "") <> ?', [(string) ($it['photo'] ?? '')])))
                ->update(['is_deleted' => 0, 'image' => $it['photo'] ?? null, 'updated_at' => now()]);
        }
        if ($restored > 0) {
            $repairs[] = "{$restored} product(s)";
        }
        $customers = DB::table('customers')->where('company_id', $company->id)->whereIn('id', array_keys((array) ($snap['customers'] ?? [])))
            ->where(fn ($q) => $q->where('is_deleted', 1)->orWhere('is_active', 0))->update(['is_deleted' => 0, 'is_active' => 1, 'updated_at' => now()]);
        if ($customers > 0) {
            $repairs[] = "{$customers} customer(s)";
        }

        return $repairs;
    }

    /** Broken beyond a heal: what the account is about is mostly gone. */
    private function healthy(Company $company, string $account = self::BUDGET_PRO): bool
    {
        if (strtolower((string) $company->status) === 'inactive') {
            return false;
        }
        $kind = self::spec($account)['kind'];
        if ($kind === 'farm') {
            return DB::table('poultry_batches')->where('company_id', $company->id)->exists();
        }
        if ($kind === 'events') {
            return DB::table('budget_programs')->where('company_id', $company->id)->count() >= count(PublicDemoWedding::names())
                && DB::table('contribution_records')->where('company_id', $company->id)->where('is_deleted', 0)->count() >= 10
                && ! DemoPledgeExtras::inHandBelowZero($company); // a visitor paid out more than came in: In hand is never negative on the demo
        }
        $products = DB::table('stock_items')->where('company_id', $company->id)->where('is_deleted', 0)->count();
        $categories = DB::table('stock_categories')->where('company_id', $company->id)->count();

        return $products >= 20 && $categories >= 3;
    }

    private function ensureOwner(string $account = self::BUDGET_PRO): User
    {
        $spec = self::spec($account);
        $owner = self::owner($account) ?? new User();
        $name = (string) self::data($account)['owner'];
        [$first, $last] = explode(' ', $name, 2) + [1 => ''];
        $owner->username = $spec['username'];
        $owner->setAttribute('email', strtolower($spec['email']));
        if (! $owner->exists) {
            $owner->first_name = $first;
            $owner->last_name = $last;
            $owner->name = $name;
            $owner->phone_number = null;
            $owner->phone_e164 = null;
            $owner->password = Hash::make($spec['password']);
            $owner->status = 'Active';
        }
        $owner->save();

        return $owner->fresh();
    }

    /** The shop fields a visitor might change and the heal puts back. */
    private function companyFields(Company $c): array
    {
        return collect(['name', 'status', 'currency', 'country', 'timezone', 'business_type', 'address', 'phone_number', 'email', 'slogan', 'logo', 'receipt_header',
            'receipt_footer', 'enabled_modules', 'negative_stock_policy', 'payment_methods', 'receipt_channels', 'tax_rate', 'store_settings', 'is_demo', 'require_shift'])
            ->mapWithKeys(fn ($f) => [$f => $c->{$f}])->all();
    }

    private function remember(Company $company, array $state): void
    {
        $all = (array) ($company->onboarding_state ?? []);
        $all['public_demo'] = $state;
        $all['demo_status'] = 'ready';
        $company->onboarding_state = $all;
        $company->saveQuietly();
    }

    private function forgetCache(): void
    {
        self::$accountOf = [];
    }

    /** Delete an old demo shop and the rows that hang off it without a company_id. */
    private function purge(?Company $old): void
    {
        if ($old === null) {
            return;
        }
        $id = (int) $old->id;
        if (! self::isDemoCompany($id)) {
            return; // never anything but a demo
        }
        try {
            $users = DB::table('admin_users')->where('company_id', $id)->pluck('id');
            $rooms = DB::table('chat_conversations')->where('company_id', $id)->pluck('id');
            DB::table('chat_participants')->whereIn('conversation_id', $rooms)->delete();
            DB::table('promotion_targets')->whereIn('promotion_id', DB::table('promotions')->where('company_id', $id)->pluck('id'))->delete();
            DB::table('admin_user_permissions')->whereIn('user_id', $users)->delete();
            app(TenantDataService::class)->purge($id);
        } catch (\Throwable $e) {
            Log::warning('[public-demo] old shop not deleted', ['company_id' => $id, 'error' => $e->getMessage()]);
        }
    }

    /** Half-built shops left by a build that died (the server restarted mid-way). */
    private function purgeStrays(int $keep): void
    {
        $strays = DB::table('companies')->where('is_demo', 1)->whereNull('demo_parent_id')->where('id', '!=', $keep)
            ->whereRaw("JSON_EXTRACT(onboarding_state, '$.demo_status') = 'building'")->where('created_at', '<', now()->subHours(3))->pluck('id');
        foreach ($strays as $id) {
            $this->purge(Company::withoutGlobalScopes()->find($id));
        }
    }
}
