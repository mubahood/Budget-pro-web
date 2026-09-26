<?php

namespace App\Services\Onboarding;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Customer;
use App\Models\SaleRecord;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ops\TenantDataService;
use App\Services\Shop\CustomerService;
use App\Services\Shop\GoodsReceiptService;
use App\Services\Shop\ReturnService;
use App\Services\Shop\SaleService;
use App\Services\Team\Permissions;
use App\Support\LocalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A demo shop to play in (POWER_PLAN §3.1): a separate company (`is_demo = 1`, `demo_parent_id` = the
 * real shop) filled with a template pack and about two months of believable trading — sales, debts,
 * part payments, returns, a supplier and deliveries — all recorded through budget-pro's own services,
 * so every screen shows real figures.
 *
 * Why a separate account: `admin_users.company_id` is the one shop an account belongs to, and the
 * phone app reads it. Pointing the owner's account at the demo, even for a minute, would move their
 * phone into the demo too. So the demo has its own owner account (`demo+<owner id>`, no email or
 * phone, random password nobody knows) that only the owner's web session can switch into and back.
 *
 * Demo shops are left out of plan limits (Quotas) and never message anyone (Messenger), and the
 * hourly job deletes them after KEEP_DAYS.
 */
class DemoShopService
{
    public const KEEP_DAYS = 7;

    /** @var list<string> why sample entries were left out (rules that bite on sample data) */
    public array $skipped = [];

    public function __construct(private readonly OnboardingService $onboarding = new OnboardingService())
    {
    }

    /** The demo shop made from this real shop, if one is still there. */
    public function demoFor(Company $real): ?Company
    {
        return Company::withoutGlobalScopes()->where('demo_parent_id', $real->id)->where('is_demo', true)->orderByDesc('id')->first();
    }

    /** The account that signs into a demo shop. */
    public function demoUser(Company $demo): ?User
    {
        return $demo->is_demo ? User::withoutGlobalScopes()->find($demo->owner_id) : null;
    }

    /** May $user open a demo made from their shop (owners and managers who run the settings). */
    public function allowed(User $user): bool
    {
        $company = Company::withoutGlobalScopes()->find($user->company_id);

        return $company !== null && ! $company->is_demo && Permissions::can($user, 'manage_settings');
    }

    /**
     * The owner's demo shop: the existing one, or a new one filled with $days of trading.
     */
    public function create(User $owner, ?int $days = null): Company
    {
        $days ??= (int) config('onboarding.demo_days', 60);
        $real = Company::withoutGlobalScopes()->find($owner->company_id);
        if ($real === null) {
            throw BusinessRuleException::make('no_company', 'Your account has no shop.');
        }
        if ($real->is_demo) {
            throw BusinessRuleException::make('already_demo', 'You are already in the demo shop.');
        }
        if (! Permissions::can($owner, 'manage_settings')) {
            throw BusinessRuleException::make('forbidden', 'Only the shop owner can open a demo shop.');
        }
        if ($existing = $this->demoFor($real)) {
            return $existing;
        }

        $demo = DB::transaction(function () use ($real, $owner, $days) {
            $user = User::withoutGlobalScopes()->where('username', 'demo+'.$owner->id)->first() ?? new User();
            $user->first_name = $owner->first_name ?: 'Demo';
            $user->last_name = 'Demo';
            $user->name = trim(($owner->first_name ?: $owner->name).' (demo)');
            $user->username = 'demo+'.$owner->id;
            $user->email = null;
            $user->phone_number = null;
            $user->phone_e164 = null;
            $user->password = Hash::make(Str::random(40));
            $user->status = 'Active';
            $user->save();

            $type = $real->business_type && DB::table('product_templates')->where('business_type', $real->business_type)->where('is_active', true)->exists()
                ? $real->business_type : 'retail';
            $demo = new Company();
            $demo->owner_id = $user->id;
            $demo->name = mb_substr($real->name, 0, 180).' (demo)';
            $demo->status = 'Active';
            $demo->is_demo = true;
            $demo->demo_parent_id = $real->id;
            $demo->currency = $real->currency ?: 'UGX';
            $demo->country = $real->country ?: 'UG';
            $demo->timezone = $real->timezone ?: 'Africa/Kampala';
            $demo->business_type = $type;
            $demo->license_expire = now()->addDays(self::KEEP_DAYS + 1);
            $demo->enabled_modules = ['shop', 'finance'];
            $demo->negative_stock_policy = 'allow';
            $demo->receipt_channels = [];
            $demo->payment_methods = ['methods' => ['cash', 'mobile_money', 'credit'], 'momo' => [], 'opening_float' => 0];
            $demo->onboarding_state = ['step' => 'done', 'completed_steps' => config('onboarding.steps'), 'skipped_steps' => [], 'completed_at' => now()->toIso8601String(),
                'dismissed_checklist' => true, 'demo' => true, 'demo_status' => 'filling', 'version' => OnboardingService::STATE_VERSION];
            $demo->save(); // created hook: the demo account's company_id, account categories

            CompanyMember::create(['company_id' => $demo->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
            $user->refresh();
            User::ensureCompanyOwnerRole($user);
            app(RegistrationService::class)->ensureDefaultPeriod((int) $demo->id);

            return $demo;
        });

        OnboardingEvents::record((int) $real->id, 'demo_created', ['demo_id' => (int) $demo->id], null, (int) $owner->id);
        Permissions::flush();
        // Two months of trading take a while (every sale goes through SaleService): the queue does it,
        // outside the web request's time limit. With the sync queue it runs right here.
        \App\Jobs\FillDemoShop::dispatch((int) $demo->id, $days);

        return $demo->fresh();
    }

    /** filling | ready | failed */
    public function status(Company $demo): string
    {
        $s = $demo->fresh()?->onboarding_state;

        return is_array($s) ? (string) ($s['demo_status'] ?? 'ready') : 'ready';
    }

    /** Run by the FillDemoShop job. */
    public function fillById(int $demoId, int $days): void
    {
        $demo = Company::withoutGlobalScopes()->find($demoId);
        if ($demo === null || ! $demo->is_demo || $this->status($demo) === 'ready') {
            return;
        }
        $user = $this->demoUser($demo);
        try {
            $this->fill($demo, $user, $days);
            $this->onboarding->remember($demo->fresh(), ['demo_status' => 'ready']);
        } catch (\Throwable $e) {
            $this->onboarding->remember($demo->fresh(), ['demo_status' => 'failed']);
            throw $e;
        }
    }

    /**
     * Two months of trading, day by day, through the services. The clock is moved to each moment so
     * sales, payments and deliveries carry believable times (and the dashboard's charts fill).
     */
    public function fill(Company $demo, User $user, int $days = 60): void
    {
        if (! $demo->is_demo) {
            throw BusinessRuleException::make('not_demo', 'Only a demo shop can be filled with sample trading.');
        }
        $cid = (int) $demo->id;
        $uid = (int) $user->id;
        $clock = Carbon::getTestNow();
        $guards = $this->actAs($user);
        mt_srand($cid);
        try {
            $realNow = now()->copy(); // the clock is moved below; "future" means after this moment
            OnboardingEvents::muted(function () use ($demo, $user, $cid, $uid, $days, $realNow) {
                $tz = LocalDate::timezone($cid);
                $today = LocalDate::today($cid);
                $start = $today->copy()->subDays($days);
                $at = fn (Carbon $day, int $hour, int $minute = 0) => Carbon::parse($day->toDateString().sprintf(' %02d:%02d:00', $hour, $minute), $tz)->utc();

                // Opening stock and the catalogue, on the first morning.
                Carbon::setTestNow($at($start, 7, 30));
                $pack = array_values(array_filter($this->onboarding->templates($demo)['items'], fn ($i) => (float) $i['selling_price'] > 0));
                $pack = array_slice($pack, 0, 18);
                $rows = array_map(fn ($i) => ['name' => $i['name'], 'category' => $i['category'], 'sub_category' => $i['sub_category'], 'unit' => $i['unit'],
                    'selling_price' => $i['selling_price'], 'buying_price' => $i['buying_price'] ?? 0, 'opening_stock' => $this->openingStock((float) $i['selling_price'], (string) $demo->currency),
                    'barcode' => null, 'sku' => null], $pack);
                $this->onboarding->createProducts($demo, $user, $rows);
                $products = DB::table('stock_items')->where('company_id', $cid)->where('is_deleted', false)->orderBy('id')->get(['id', 'name', 'selling_price', 'buying_price'])->all();
                if ($products === []) {
                    return;
                }
                $cheap = array_values(array_filter($products, fn ($p) => $this->isCheap((float) $p->selling_price, (string) $demo->currency)));
                $cheap = $cheap !== [] ? $cheap : $products;

                $supplier = Supplier::create(['company_id' => $cid, 'name' => $this->supplierName((string) $demo->country), 'phone' => null, 'payment_terms_days' => 14, 'is_active' => true, 'created_by_id' => $uid]);
                $customers = [];
                foreach (['Amina Nansubuga', 'John Okello', 'Grace Atim', 'Musa Ssebunya', 'Sarah Namukasa'] as $name) {
                    $customers[] = Customer::create(['company_id' => $cid, 'name' => $name, 'is_active' => true, 'reminders_enabled' => false, 'created_by_id' => $uid]);
                }

                // Deliveries: one at the start, one mid-way (part paid, so the supplier is owed a little).
                foreach ([1 => 0.7, (int) ($days / 2) => 0.5] as $offset => $paidShare) {
                    $day = $start->copy()->addDays($offset);
                    Carbon::setTestNow($at($day, 9, 15));
                    $lines = [];
                    foreach (array_slice($cheap, 0, min(6, count($cheap))) as $p) {
                        $lines[] = ['stock_item_id' => (int) $p->id, 'quantity' => mt_rand(12, 30), 'unit_cost' => (float) $p->buying_price ?: round((float) $p->selling_price * 0.8)];
                    }
                    $value = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_cost'], $lines));
                    $this->quietly(fn () => app(GoodsReceiptService::class)->receive($cid, $uid, $lines, (int) $supplier->id, 'INV-'.mt_rand(1000, 9999), round($value * $paidShare), 'cash', $day->toDateString()));
                }

                $sales = new SaleService();
                $credit = [];
                $made = [];
                for ($d = 0; $d <= $days; $d++) {
                    $day = $start->copy()->addDays($d);
                    $count = match ((int) $day->dayOfWeek) { 0 => mt_rand(0, 1), 6 => mt_rand(2, 3), default => mt_rand(1, 2) };
                    for ($n = 0; $n < $count; $n++) {
                        $time = $at($day, mt_rand(8, 19), mt_rand(0, 59));
                        if ($time->greaterThan($realNow)) {
                            continue;
                        }
                        Carbon::setTestNow($time);
                        $items = [];
                        $total = 0.0;
                        foreach ((array) array_rand($cheap, min(count($cheap), mt_rand(1, 3))) as $k) {
                            $p = mt_rand(1, 10) <= 8 ? $cheap[$k] : $products[array_rand($products)];
                            $qty = $this->isCheap((float) $p->selling_price, (string) $demo->currency) ? mt_rand(1, 3) : 1;
                            if (isset($items[$p->id])) {
                                continue;
                            }
                            $items[$p->id] = ['stock_item_id' => (int) $p->id, 'quantity' => $qty];
                            $total += $qty * (float) $p->selling_price;
                        }
                        $roll = mt_rand(1, 100);
                        $data = ['items' => array_values($items), 'payments_explicit' => true, 'sale_date' => $day->toDateString(), 'allow_negative_stock' => true,
                            'client_uuid' => (string) Str::uuid()];
                        if ($roll <= 8 && $d < $days - 3) {
                            $customer = $customers[array_rand($customers)];
                            $data['customer_id'] = (int) $customer->id;
                            $data['payments'] = mt_rand(0, 1) ? [] : [['method' => 'cash', 'amount' => round($total / 2)]];
                        } else {
                            $data['payments'] = [['method' => $roll <= 30 ? 'mobile_money' : 'cash', 'amount' => $total]];
                            if ($roll <= 18) {
                                $data['customer_id'] = (int) $customers[array_rand($customers)]->id;
                            }
                        }
                        $sale = $this->quietly(fn () => $sales->checkout($cid, $uid, $data)['sale']);
                        if ($sale instanceof SaleRecord) {
                            $made[] = $sale;
                            if ((float) $sale->balance > 0) {
                                $credit[] = [$sale, $day];
                            }
                        }
                    }
                }

                // Some debts are partly paid back a few days later; the rest stay owed.
                foreach (array_slice($credit, 0, max(1, (int) floor(count($credit) * 0.6))) as [$sale, $day]) {
                    $when = $at($day->copy()->addDays(mt_rand(3, 9)), 16, 30);
                    if ($when->greaterThan($realNow) || ! $sale->customer_id) {
                        continue;
                    }
                    Carbon::setTestNow($when);
                    $customer = Customer::withoutGlobalScopes()->find($sale->customer_id);
                    $this->quietly(fn () => app(CustomerService::class)->receivePayment($customer, max(1, round((float) $sale->fresh()->balance / 2)), 'cash', $uid));
                }

                // Two returns: something brought back and put back on the shelf.
                foreach (array_slice(array_values(array_filter($made, fn ($s) => (float) $s->balance <= 0)), (int) (count($made) / 3), 2) as $i => $sale) {
                    $line = $sale->saleRecordItems->first();
                    if ($line === null) {
                        continue;
                    }
                    Carbon::setTestNow(Carbon::parse($sale->created_at)->addHours(2));
                    $this->quietly(fn () => app(ReturnService::class)->create($sale, [['sale_item_id' => (int) $line->id, 'quantity' => 1, 'restock' => true]], $uid,
                        $i === 0 ? 'Customer changed their mind' : 'Wrong size', 'cash'));
                }
            });
        } finally {
            Carbon::setTestNow($clock);
            $this->restore($guards);
        }
    }

    /** Delete demo shops older than KEEP_DAYS (and any whose real shop is gone). */
    public function purgeExpired(): int
    {
        $n = 0;
        $due = Company::withoutGlobalScopes()->where('is_demo', true)->whereNotNull('demo_parent_id')
            ->where(fn ($q) => $q->where('created_at', '<', now()->subDays(self::KEEP_DAYS))
                ->orWhereNotExists(fn ($s) => $s->select(DB::raw(1))->from('companies as parent')->whereColumn('parent.id', 'companies.demo_parent_id')))
            ->get();
        foreach ($due as $demo) {
            try {
                $this->purge($demo);
                $n++;
            } catch (\Throwable $e) {
                Log::warning('[demo] purge failed', ['company_id' => $demo->id, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }

    /** Delete a demo shop and its account. Refuses anything that is not a demo. */
    public function purge(Company $demo): void
    {
        $demo = Company::withoutGlobalScopes()->find($demo->id);
        if ($demo === null) {
            return;
        }
        if (! $demo->is_demo || ! $demo->demo_parent_id) {
            throw BusinessRuleException::make('not_demo', 'Only a demo shop can be deleted this way.');
        }
        app(TenantDataService::class)->purge((int) $demo->id);
    }

    /** Which real account may switch back from this demo account (the owner who made it). */
    public function parentUserFor(User $demoUser): ?User
    {
        if (! str_starts_with((string) $demoUser->username, 'demo+')) {
            return null;
        }
        $demo = Company::withoutGlobalScopes()->find($demoUser->company_id);
        $real = User::withoutGlobalScopes()->find((int) substr((string) $demoUser->username, 5));
        if ($demo === null || ! $demo->is_demo || $real === null || (int) $real->company_id !== (int) $demo->demo_parent_id) {
            return null;
        }

        return $real;
    }

    private function openingStock(float $price, string $currency): int
    {
        $ugx = $price / $this->rate($currency);

        return match (true) {
            $ugx < 5000 => mt_rand(60, 120),
            $ugx < 50000 => mt_rand(20, 45),
            default => mt_rand(4, 10),
        };
    }

    private function isCheap(float $price, string $currency): bool
    {
        return $price / $this->rate($currency) < 20000;
    }

    private function rate(string $currency): float
    {
        static $rates = null;
        $rates ??= (require dirname(__DIR__, 3).'/database/data/product_templates.php')['rates'] ?? [];

        return (float) ($rates[strtoupper($currency)] ?? 1) ?: 1.0;
    }

    private function supplierName(string $country): string
    {
        return match (strtoupper($country)) {
            'KE' => 'Gikomba Wholesalers',
            'TZ' => 'Kariakoo Wholesalers',
            'RW' => 'Nyabugogo Wholesalers',
            default => 'Kikuubo Wholesalers',
        };
    }

    /** One refused sale or payment (a rule that bites on sample data) must not stop the rest. */
    private function quietly(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (BusinessRuleException|\DomainException $e) {
            $this->skipped[] = $e->getMessage();
            Log::info('[demo] sample entry skipped: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Budget-pro's company scope follows the signed-in user; while filling, the demo account is
     * the one "signed in", so every scoped query lands in the demo shop.
     *
     * @return array<string, mixed>
     */
    private function actAs(User $user): array
    {
        $prev = [];
        foreach (['web', 'admin'] as $name) {
            try {
                $guard = Auth::guard($name);
                $prev[$name] = $guard->hasUser() ? $guard->user() : null;
                $guard->setUser($user);
            } catch (\Throwable $e) {
                // guard not configured in this app
            }
        }

        return $prev;
    }

    /** @param array<string, mixed> $prev */
    private function restore(array $prev): void
    {
        foreach ($prev as $name => $user) {
            $guard = Auth::guard($name);
            if ($user !== null) {
                $guard->setUser($user);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }
}
