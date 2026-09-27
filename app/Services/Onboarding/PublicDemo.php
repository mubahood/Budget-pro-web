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
 * The public demo shop: one account anybody can open from the website (config/demo.php).
 *
 * It looks after itself. `run()` is called every hour:
 *  - heal: whatever visitors changed that would spoil it for the next person is put back (the
 *    sign-in, the shop's settings, deleted products and photos, deactivated staff), and today's
 *    trading is brought up to the minute, so the dashboard is never a museum;
 *  - rebuild: every `demo.rebuild_hours`, or when the shop is broken beyond repair, a new shop is
 *    built from scratch *beside* the old one. Only when it is complete is the demo account moved
 *    into it (one transaction), and then the old shop is deleted. Nobody lands in a half-built shop.
 *
 * The demo is a company with `is_demo = 1` and no `demo_parent_id` (so the owners' private demo
 * shops' clean-up never touches it), owned by the account with username USERNAME.
 */
class PublicDemo
{
    public const USERNAME = 'public-demo';

    public const PIN = '1234';

    /** @var array<int, bool> per request */
    private static array $isDemo = [];

    /** True while PublicDemoBuilder fills a shop: the locks below are for visitors. */
    public static bool $building = false;

    public static function enabled(): bool
    {
        return (bool) config('demo.enabled', true);
    }

    public static function owner(): ?User
    {
        return User::withoutGlobalScopes()->where('username', self::USERNAME)->first();
    }

    /** The live demo shop, when there is one. */
    public static function company(): ?Company
    {
        $owner = self::owner();
        $company = $owner?->company_id ? Company::withoutGlobalScopes()->find($owner->company_id) : null;

        return $company && $company->is_demo && ! $company->demo_parent_id && (int) $company->owner_id === (int) $owner->id ? $company : null;
    }

    /** Is this company the public demo? (Any shop built for it, including one being built.) */
    public static function isDemoCompany(Company|int|null $company): bool
    {
        if ($company instanceof Company) {
            // Already loaded: answered from the row, no query (the shell asks on every page).
            return (bool) $company->is_demo && ! $company->demo_parent_id && isset(((array) ($company->onboarding_state ?? []))['public_demo']);
        }
        $id = (int) $company;
        if ($id <= 0) {
            return false;
        }

        return self::$isDemo[$id] ??= DB::table('companies')->where('id', $id)->where('is_demo', 1)->whereNull('demo_parent_id')
            ->whereRaw("JSON_EXTRACT(onboarding_state, '$.public_demo') IS NOT NULL")->exists();
    }

    public static function isDemoUser(?User $user): bool
    {
        return $user !== null && ($user->username === self::USERNAME || self::isDemoCompany((int) $user->company_id));
    }

    /**
     * Refuse something a visitor must not do in the shared demo.
     *
     * @throws BusinessRuleException demo_locked
     */
    public static function guard(Company|int|null $company, string $what): void
    {
        if (! self::$building && self::isDemoCompany($company)) {
            throw BusinessRuleException::make('demo_locked', ucfirst($what).' is switched off in the demo shop, which everyone shares. Create your own shop to do this.');
        }
    }

    /** Refuse changes to the shared sign-in itself. */
    public static function guardAccount(?User $user, string $what): void
    {
        if (! self::$building && $user !== null && $user->username === self::USERNAME) {
            throw BusinessRuleException::make('demo_locked', ucfirst($what).' is switched off for the demo account, so the next visitor can still sign in. Create your own shop to do this.');
        }
    }

    /** What the website and the demo banner show. */
    public static function status(): array
    {
        $company = self::company();
        $state = $company ? (array) (($company->onboarding_state ?? [])['public_demo'] ?? []) : [];
        $built = isset($state['built_at']) ? Carbon::parse($state['built_at']) : null;

        return [
            'ready' => $company !== null,
            'email' => (string) config('demo.email'),
            'password' => (string) config('demo.password'),
            'pin' => self::PIN,
            'shop' => $company?->name,
            'built_at' => $built,
            'resets_at' => $built?->copy()->addHours((int) config('demo.rebuild_hours', 72)),
        ];
    }

    // ── Upkeep ──────────────────────────────────────────────────────────

    /**
     * The hourly job. Returns what it did: off | built | rebuilt | healed | busy.
     */
    public function run(bool $forceRebuild = false): array
    {
        if (! self::enabled()) {
            return ['action' => 'off'];
        }
        $lock = Cache::lock('public-demo-upkeep', 3600);
        if (! $lock->get()) {
            return ['action' => 'busy'];
        }
        try {
            $owner = $this->ensureOwner();
            $company = self::company();
            $state = $company ? (array) (($company->onboarding_state ?? [])['public_demo'] ?? []) : [];
            $age = isset($state['built_at']) ? Carbon::parse($state['built_at'])->diffInHours(now()) : PHP_INT_MAX;
            if ($forceRebuild || $company === null || ! isset($state['snapshot']) || $age >= (int) config('demo.rebuild_hours', 72) || ! $this->healthy($company)) {
                return ['action' => $company ? 'rebuilt' : 'built'] + $this->rebuild($owner, $company);
            }

            return ['action' => 'healed'] + $this->heal($company, $owner, $state);
        } finally {
            $lock->release();
        }
    }

    /** Build a fresh shop beside the old one, move the demo account in, delete the old shop. */
    public function rebuild(User $owner, ?Company $old): array
    {
        $started = microtime(true);
        $builder = new PublicDemoBuilder();
        try {
            ['company' => $new, 'manager' => $manager, 'snapshot' => $snapshot] = $builder->build();
        } catch (\Throwable $e) {
            if ($builder->companyId) {
                $this->purge(Company::withoutGlobalScopes()->find($builder->companyId)); // the old demo stays live
            }
            throw $e;
        }

        DB::transaction(function () use ($new, $owner, $manager) {
            $new->owner_id = $owner->id;
            $new->saveQuietly();
            $owner->company_id = $new->id;
            $owner->status = 'Active';
            $owner->save();
            DB::table('company_members')->updateOrInsert(['company_id' => $new->id, 'user_id' => $owner->id], ['role' => 'owner', 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('company_members')->updateOrInsert(['company_id' => $new->id, 'user_id' => $manager->id], ['role' => 'manager', 'status' => 'active', 'updated_at' => now()]);
            app(TeamService::class)->syncAdminRole($owner->fresh(), 'owner');
            app(TeamService::class)->syncAdminRole($manager->fresh(), 'manager');
        });
        Permissions::flush();
        app(ApprovalService::class)->setPin($owner->fresh(), self::PIN);

        $new = $new->fresh();
        $this->remember($new, ['built_at' => now()->toIso8601String(), 'healed_at' => now()->toIso8601String(), 'heals' => 0,
            'snapshot' => $snapshot + ['company' => $this->companyFields($new), 'owner_id' => (int) $owner->id], 'repairs' => [], 'demo_status' => null]);
        $this->forgetCache();

        if ($old !== null && (int) $old->id !== (int) $new->id) {
            $this->purge($old);
        }
        $this->purgeStrays((int) $new->id);

        Log::info('[public-demo] built', ['company_id' => $new->id, 'seconds' => round(microtime(true) - $started, 1), 'skipped' => count($builder->skipped)]);

        return ['company_id' => (int) $new->id, 'seconds' => round(microtime(true) - $started, 1), 'skipped' => array_values(array_unique($builder->skipped))];
    }

    /** Put back what visitors changed, and bring today's trading up to now. */
    public function heal(Company $company, User $owner, array $state): array
    {
        $repairs = [];
        $snap = (array) $state['snapshot'];

        // The sign-in everybody uses.
        $owner = $owner->fresh();
        if (! Hash::check((string) config('demo.password'), (string) $owner->password)) {
            $owner->password = Hash::make((string) config('demo.password'));
            $repairs[] = 'password';
        }
        foreach (['email' => strtolower((string) config('demo.email')), 'status' => 'Active', 'company_id' => (int) $company->id] as $field => $value) {
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

        // The team: nobody locked out or removed.
        $staff = array_values((array) ($snap['staff'] ?? []));
        $n = DB::table('admin_users')->whereIn('id', $staff)->where('company_id', $company->id)->where('status', '!=', 'Active')->update(['status' => 'Active']);
        $n += DB::table('company_members')->where('company_id', $company->id)->whereIn('user_id', $staff)->where('status', '!=', 'active')->update(['status' => 'active']);
        if ($n > 0) {
            $repairs[] = 'team';
        }

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

        // Today, up to the minute.
        $sales = (new PublicDemoBuilder())->load($company->fresh(), $snap)->tradeUntilNow();

        $this->remember($company->fresh(), ['healed_at' => now()->toIso8601String(), 'heals' => (int) ($state['heals'] ?? 0) + 1, 'repairs' => $repairs] + $state);
        $this->forgetCache();

        return ['company_id' => (int) $company->id, 'repairs' => $repairs, 'sales' => $sales];
    }

    /** Broken beyond a heal: the catalogue or the team is mostly gone. */
    private function healthy(Company $company): bool
    {
        $products = DB::table('stock_items')->where('company_id', $company->id)->where('is_deleted', 0)->count();
        $categories = DB::table('stock_categories')->where('company_id', $company->id)->count();

        return $products >= 20 && $categories >= 3 && strtolower((string) $company->status) !== 'inactive';
    }

    private function ensureOwner(): User
    {
        $owner = self::owner() ?? new User();
        $name = (string) (require dirname(__DIR__, 3).'/database/data/public_demo.php')['owner'];
        [$first, $last] = explode(' ', $name, 2) + [1 => ''];
        $owner->username = self::USERNAME;
        $owner->setAttribute('email', strtolower((string) config('demo.email')));
        if (! $owner->exists) {
            $owner->first_name = $first;
            $owner->last_name = $last;
            $owner->name = $name;
            $owner->phone_number = null;
            $owner->phone_e164 = null;
            $owner->password = Hash::make((string) config('demo.password'));
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
        self::$isDemo = [];
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
