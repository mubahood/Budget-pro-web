<?php

namespace App\Services\Onboarding;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Customer;
use App\Models\FinancialCategory;
use App\Models\FinancialPeriod;
use App\Models\FinancialRecord;
use App\Models\SaleRecord;
use App\Models\Shift;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Services\Shop\ApprovalService;
use App\Services\Shop\CustomerService;
use App\Services\Shop\GiftCardService;
use App\Services\Shop\GoodsReceiptService;
use App\Services\Shop\MarkdownService;
use App\Services\Shop\PriceBookService;
use App\Services\Shop\PromotionService;
use App\Services\Shop\PurchaseOrderService;
use App\Services\Shop\ReturnService;
use App\Services\Shop\SaleService;
use App\Services\Shop\ShiftService;
use App\Services\Shop\ShrinkService;
use App\Services\Shop\StockTakeService;
use App\Services\Shop\SupplierService;
use App\Services\Shop\TaxClassService;
use App\Services\Team\TeamService;
use App\Support\StoreFeatures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fills the public demo shop (PublicDemo) with a believable neighbourhood market: a team, suppliers,
 * 45 products with photos, and `demo.days` of trading, all through the app's own services so every
 * screen, report and ledger agrees. The clock is moved to each moment, so sales, shifts, deliveries
 * and payments carry real times.
 *
 * The same class keeps an existing demo trading (tradeUntilNow): the hourly heal calls it so today's
 * figures move while people are looking.
 */
class PublicDemoBuilder
{
    public const OPEN = 7;

    public const CLOSE = 21;

    /** @var list<string> sample entries a rule refused (logged, never fatal) */
    public array $skipped = [];

    private array $data;

    private Company $co;

    /** The shop being built (so a build that fails can be cleaned up). */
    public ?int $companyId = null;

    private int $cid;

    private User $manager;

    /** @var array<string, User> first name (lower case) => member */
    private array $staff = [];

    /** @var array<string, Supplier> */
    private array $suppliers = [];

    /** @var list<array{id:int, name:string, sell:float, cost:float, target:float, reorder:float, weighed:bool, life:?int, pop:int, supplier:string, category:string, photo:?string}> */
    private array $items = [];

    /** @var list<array{customer: Customer, x: array}> */
    private array $customers = [];

    /** @var array<int, int> stock item id => index in $items */
    private array $byId = [];

    private string $tz = 'UTC';

    private Carbon $realNow;

    /** @var list<array{due: string, supplier: string, amount: float}> supplier bills to pay on their terms */
    private array $bills = [];

    /** @var list<array{code: string, customer_id: ?int}> gift cards sold (only a hash of the code is stored) */
    private array $giftCards = [];

    /** The heal carries on after the last sale already made today. */
    private ?Carbon $after = null;

    public function __construct()
    {
        $this->data = require dirname(__DIR__, 3).'/database/data/public_demo.php';
    }

    /**
     * A new, complete demo shop owned (for now) by its manager. Nobody signs into it until
     * PublicDemo hands it to the demo account.
     *
     * @return array{company: Company, manager: User, snapshot: array}
     */
    public function build(): array
    {
        $clock = Carbon::getTestNow();
        $this->realNow = now()->copy();
        $days = max(2, (int) config('demo.days', 60));
        $this->tz = (string) config('demo.shop.timezone', 'UTC');
        $today = $this->realNow->copy()->setTimezone($this->tz)->startOfDay();
        $start = $today->copy()->subDays($days);
        mt_srand((int) $today->format('Ymd'));

        Carbon::setTestNow($this->at($start->copy()->subDays(4), 9));
        $this->createShop($start, $today);
        $guards = $this->actAs($this->manager);
        PublicDemo::$building = true; // the visitor locks are for visitors, not for the builder
        try {
            OnboardingEvents::muted(function () use ($start, $today, $days) {
                $this->setUp($start);
                for ($d = 0; $d <= $days; $d++) {
                    $this->day($start->copy()->addDays($d), $d, $days);
                }
                $this->finishingTouches($today);
            });
        } finally {
            PublicDemo::$building = false;
            Carbon::setTestNow($clock);
            $this->restore($guards);
        }

        return ['company' => $this->co->fresh(), 'manager' => $this->manager->fresh(), 'snapshot' => $this->snapshot()];
    }

    /** Pick up an existing demo (for the heal) from its snapshot. */
    public function load(Company $company, array $snapshot): self
    {
        $this->co = $company;
        $this->cid = (int) $company->id;
        $this->tz = (string) ($company->timezone ?: 'UTC');
        $this->realNow = now()->copy();
        foreach ((array) ($snapshot['staff'] ?? []) as $key => $id) {
            if ($u = User::withoutGlobalScopes()->where('company_id', $this->cid)->find($id)) {
                $this->staff[$key] = $u;
            }
        }
        $this->manager = $this->staff['daniel'] ?? User::withoutGlobalScopes()->findOrFail($company->owner_id);
        foreach ((array) ($snapshot['suppliers'] ?? []) as $key => $id) {
            if ($s = Supplier::withoutGlobalScopes()->where('company_id', $this->cid)->find($id)) {
                $this->suppliers[$key] = $s;
            }
        }
        foreach ((array) ($snapshot['items'] ?? []) as $it) {
            $this->byId[(int) $it['id']] = count($this->items);
            $this->items[] = $it;
        }
        foreach ((array) ($snapshot['customers'] ?? []) as $id => $x) {
            if ($c = Customer::withoutGlobalScopes()->where('company_id', $this->cid)->find($id)) {
                $this->customers[] = ['customer' => $c, 'x' => (array) $x];
            }
        }

        return $this;
    }

    /**
     * Today's trading so far: shifts opened and closed on time and sales up to this minute, carrying
     * on from the last sale. Also brings in a delivery for anything the day's selling ran low.
     *
     * @return int sales added
     */
    public function tradeUntilNow(): int
    {
        $clock = Carbon::getTestNow();
        $guards = $this->actAs($this->manager);
        $before = (int) SaleRecord::withoutGlobalScopes()->where('company_id', $this->cid)->count();
        try {
            OnboardingEvents::muted(function () {
                $today = $this->realNow->copy()->setTimezone($this->tz)->startOfDay();
                // A day the heal missed entirely (the server was down) is left as it is; only close its tills.
                $this->closeStaleShifts($today);
                mt_srand((int) $this->realNow->format('YmdH'));
                $last = SaleRecord::withoutGlobalScopes()->where('company_id', $this->cid)->max('created_at');
                $this->after = $last ? Carbon::parse($last) : null;
                $this->restockLow($today);
                $this->trade($today, 60, true);
            });
        } finally {
            Carbon::setTestNow($clock);
            $this->restore($guards);
        }

        return (int) SaleRecord::withoutGlobalScopes()->where('company_id', $this->cid)->count() - $before;
    }

    // ── The shop, its people and its catalogue ─────────────────────────

    private function createShop(Carbon $start, Carbon $today): void
    {
        $s = (array) config('demo.shop');
        $token = strtolower(Str::random(6));
        [$first, $last] = explode(' ', $this->data['team'][0][0], 2);

        $manager = new User();
        $manager->first_name = $first;
        $manager->last_name = $last;
        $manager->name = $this->data['team'][0][0];
        $manager->username = "demo-{$token}-".strtolower($first);
        $manager->setAttribute('email', null);
        $manager->phone_number = null;
        $manager->phone_e164 = null;
        $manager->password = Hash::make(Str::random(40));
        $manager->status = 'Active';
        $manager->save();

        $co = new Company();
        $co->owner_id = $manager->id; // created hook: the manager's company_id
        $co->name = (string) ($s['name'] ?? 'Fresh Corner Market');
        $co->status = 'Active';
        $co->is_demo = true;
        $co->demo_parent_id = null;
        $co->currency = (string) ($s['currency'] ?? 'USD');
        $co->country = (string) ($s['country'] ?? 'US');
        $co->timezone = $this->tz;
        $co->business_type = 'supermarket';
        $co->address = (string) ($s['address'] ?? '');
        $co->phone_number = '(207) 555-0100';
        $co->email = 'hello@freshcorner.example';
        $co->slogan = 'Fresh every morning';
        $co->receipt_header = "Fresh Corner Market\n214 Harbor Street · (207) 555-0100";
        $co->receipt_footer = 'Thank you for shopping local. Returns within 14 days with this receipt.';
        $co->license_expire = now()->addYears(5);
        $co->enabled_modules = ['shop', 'finance'];
        $co->negative_stock_policy = 'allow';
        $co->receipt_channels = [];
        $co->tax_rate = 5.5;
        $co->payment_methods = ['methods' => ['cash', 'card', 'mobile_money', 'credit'], 'momo' => [], 'opening_float' => 150];
        $co->onboarding_state = ['step' => 'done', 'completed_steps' => config('onboarding.steps'), 'skipped_steps' => [], 'completed_at' => now()->toIso8601String(),
            'dismissed_checklist' => true, 'demo' => true, 'demo_status' => 'building', 'version' => OnboardingService::STATE_VERSION,
            'public_demo' => ['building' => true]]; // marks it as the public demo's from the start (guards apply, a dead build is cleaned up)
        $co->save();

        CompanyMember::create(['company_id' => $co->id, 'user_id' => $manager->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
        $manager->refresh();
        User::ensureCompanyOwnerRole($manager);

        // One period across the whole history (the default one starts on 1 January, and a history
        // that crosses New Year would fall outside it).
        $period = new FinancialPeriod();
        $period->company_id = $co->id;
        $period->name = 'FY '.$today->year;
        $period->start_date = $start->copy()->startOfMonth()->toDateString();
        $period->end_date = $today->copy()->endOfYear()->toDateString();
        $period->status = 'Active';
        $period->description = 'Demo shop trading year';
        $period->total_investment = 0;
        $period->total_sales = 0;
        $period->total_profit = 0;
        $period->total_expenses = 0;
        $period->save();

        $this->co = $co->fresh();
        $this->cid = (int) $co->id;
        $this->companyId = $this->cid;
        $this->manager = $manager->fresh();
        $this->staff[strtolower($first)] = $this->manager;
    }

    private function setUp(Carbon $start): void
    {
        StoreFeatures::update($this->co, [
            'mode' => true,
            // Off: things a visitor cannot use without hardware or a tax authority account.
            'features' => ['fiscal' => false, 'hardware' => false, 'customer_display' => false, 'training_mode' => false, 'offline_till' => false,
                'store_scoping' => false, 'store_prices' => false, 'consignment' => false, 'deposits' => false],
            'settings' => ['loyalty_spend_per_point' => 1, 'loyalty_point_value' => 0.05, 'loyalty_silver_spend' => 300, 'loyalty_gold_spend' => 1200,
                'waste_limit' => 150, 'short_dated_days' => 5, 'age_min' => 21, 'note_buttons' => [5, 10, 20, 50, 100]],
        ]);
        $this->co = $this->co->fresh();
        $taxes = app(TaxClassService::class)->ensureDefaults($this->cid)->keyBy('code');

        // The team (the manager already exists: he is building the shop).
        foreach (array_slice($this->data['team'], 1) as [$name, $role]) {
            $slug = Str::slug($name, '.');
            $u = $this->quietly(fn () => app(TeamService::class)->createMember($this->co, $this->manager, $name, "{$slug}.".$this->cid.'@freshcorner.example', null, Str::random(24), $role));
            if ($u instanceof User) {
                DB::table('admin_users')->where('id', $u->id)->update(['email' => "{$slug}@freshcorner.example"]);
                $this->staff[strtolower(strtok($name, ' '))] = $u->fresh();
            }
        }
        DB::table('admin_users')->where('id', $this->manager->id)->update(['email' => 'daniel.brooks@freshcorner.example']);
        foreach (['daniel' => '2468', 'maria' => '1357', 'kevin' => '8642'] as $who => $pin) {
            if (isset($this->staff[$who])) {
                $this->quietly(fn () => app(ApprovalService::class)->setPin($this->staff[$who], $pin));
            }
        }

        foreach ($this->data['suppliers'] as $key => [$name, $phone, $email, $terms, $lead]) {
            $this->suppliers[$key] = Supplier::create(['company_id' => $this->cid, 'name' => $name, 'phone' => $phone, 'email' => $email, 'payment_terms_days' => $terms,
                'lead_time_days' => $lead, 'is_active' => true, 'created_by_id' => $this->manager->id]);
        }

        $shelf = ['Fresh Produce' => 'A1', 'Bakery' => 'A2', 'Dairy & Eggs' => 'A3', 'Beverages' => 'A4', 'Pantry' => 'A5', 'Household' => 'A6'];
        $cats = [];
        $subs = [];
        foreach ($this->data['products'] as $n => [$name, $cat, $sub, $unit, $sell, $cost, $target, $reorder, $photo, $supplier, $x]) {
            $cats[$cat] ??= $this->make(new StockCategory(), ['name' => $cat, 'company_id' => $this->cid, 'status' => 'Active']);
            $subs[$cat.'|'.$sub] ??= $this->make(new StockSubCategory(), ['name' => $sub, 'company_id' => $this->cid, 'stock_category_id' => $cats[$cat]->id,
                'measurement_unit' => $unit, 'status' => 'Active']);
            $weighed = isset($x['plu']);
            $item = new StockItem();
            $item->company_id = $this->cid;
            $item->created_by_id = $this->manager->id;
            $item->stock_sub_category_id = $subs[$cat.'|'.$sub]->id;
            $item->name = $name;
            $item->selling_price = $sell;
            $item->buying_price = $cost;
            $item->original_quantity = 0; // everything arrives on the first morning's deliveries
            $item->sku = sprintf('FCM-%03d', $n + 1);
            $item->barcode = $weighed ? null : self::ean13('0714'.sprintf('%08d', 31000 + ($n + 1) * 37));
            $item->image = $photo ? $this->photo($photo) : null;
            $item->min_stock = $reorder;
            $item->sold_by = $weighed ? 'weight' : 'unit';
            $item->plu_code = $x['plu'] ?? null;
            $item->min_age = $x['age'] ?? null;
            $item->tax_class_id = $taxes[$x['tax'] ?? 'zero']->id ?? null;
            $item->track_batches = isset($x['life']);
            $item->save();
            $this->quietly(fn () => app(StockTakeService::class)->setShelfLocation($this->cid, (int) $item->id, ($shelf[$cat] ?? 'A9').'-'.str_pad((string) (count($this->items) % 6 + 1), 2, '0', STR_PAD_LEFT)));
            $this->byId[(int) $item->id] = count($this->items);
            $this->items[] = ['id' => (int) $item->id, 'name' => $name, 'sell' => (float) $sell, 'cost' => (float) $cost, 'target' => (float) $target, 'reorder' => (float) $reorder,
                'weighed' => $weighed, 'life' => $x['life'] ?? null, 'pop' => (int) ($x['pop'] ?? ($target > 0 ? 2 : 1)), 'supplier' => $supplier, 'category' => $cat,
                'photo' => $item->image, 'category_id' => (int) $cats[$cat]->id];
        }

        foreach ($this->data['customers'] as $i => [$name, $phone, $email, $limit, $terms, $x]) {
            Carbon::setTestNow($this->at($start->copy()->subDays(30 + $i * 9), 11));
            $c = Customer::create(['company_id' => $this->cid, 'name' => $name, 'phone' => $phone, 'email' => $email, 'credit_limit' => $limit, 'payment_terms_days' => $terms ?: null,
                'is_active' => true, 'reminders_enabled' => false, 'created_by_id' => $this->manager->id, 'marketing_opt_in' => ! empty($x['marketing']),
                'notes' => ! empty($x['account']) ? 'Café on the corner. Orders bread and dairy twice a week, pays fortnightly by bank transfer.' : null]);
            if (! empty($x['opt_out'])) {
                DB::table('customers')->where('id', $c->id)->update(['messages_opt_out' => 1, 'messages_opt_out_at' => now()]);
            }
            $this->customers[] = ['customer' => $c->fresh(), 'x' => $x];
        }

        // Deals, set up on the first morning.
        Carbon::setTestNow($this->at($start, 6, 40));
        $p = fn (string $name) => $this->item($name)['id'] ?? 0;
        $cat = fn (string $name) => ['type' => 'category', 'id' => (int) ($cats[$name]->id ?? 0)];
        foreach ([
            ['name' => 'Croissants 3 for $5', 'type' => 'mix_match', 'qty' => 3, 'price' => 5, 'targets' => [['type' => 'product', 'id' => $p('Butter Croissant')]]],
            ['name' => 'Weekend dairy 10% off', 'type' => 'percent_off', 'percent' => 10, 'days' => [6, 7], 'targets' => [$cat('Dairy & Eggs')]],
            ['name' => 'Lemonade: buy 2, get 1 free', 'type' => 'buy_x_get_y', 'buy' => 2, 'get' => 1, 'targets' => [['type' => 'product', 'id' => $p('Sparkling Lemonade 330ml')]]],
            ['name' => 'Bread & butter for $8.49', 'type' => 'bundle', 'price' => 8.49, 'targets' => [['type' => 'product', 'id' => $p('Country White Loaf')], ['type' => 'product', 'id' => $p('Salted Butter 500g')]]],
            ['name' => 'Members: coffee beans $10.99', 'type' => 'fixed_price', 'price' => 10.99, 'member_only' => true, 'targets' => [['type' => 'product', 'id' => $p('Whole Bean Coffee 500g')]]],
            ['name' => 'Bakery happy hour 20% off', 'type' => 'percent_off', 'percent' => 20, 'from' => '18:00', 'to' => '21:00', 'targets' => [$cat('Bakery')]],
            ['name' => 'Welcome coupon', 'type' => 'coupon', 'code' => 'WELCOME5', 'min_spend' => 30, 'amount' => 5],
        ] as $promo) {
            $this->quietly(fn () => app(PromotionService::class)->save($this->cid, $this->manager->id, $promo + ['is_active' => true]));
        }
    }

    // ── One day of trading ──────────────────────────────────────────────

    private function day(Carbon $day, int $d, int $days): void
    {
        $isToday = $d === $days;
        if ($isToday) {
            $this->settleStock($day);
        } else {
            $this->deliveries($day, $d, $days);
        }
        $this->payBills($day);
        $this->trade($day, $d, $isToday);
        if (! $isToday) {
            $this->events($day, $d, $days);
            $this->expenses($day);
        }
    }

    /**
     * One day's shifts and sales. Maria opens, Kevin takes the evening; Sunday is a short day.
     * Anything after this minute (today) is left for the hourly heal.
     */
    private function trade(Carbon $day, int $d, bool $isToday): void
    {
        $dow = (int) $day->dayOfWeekIso;
        $growth = 1 + min(0.2, $d * 0.003);
        $shifts = $dow === 7
            ? [['maria', 9, 17, mt_rand(9, 12)]]
            : [['maria', self::OPEN, 14, (int) round(mt_rand(6, 9) * $growth) + ($dow === 6 ? 4 : 0)],
                ['kevin', 14, self::CLOSE, (int) round(mt_rand(7, 10) * $growth) + ($dow === 6 ? 3 : 0) + ($dow === 5 ? 3 : 0)]];

        foreach ($shifts as [$who, $from, $to, $count]) {
            $user = $this->staff[$who] ?? $this->manager;
            $openAt = $this->at($day, $from - 1, 55);
            if ($openAt->greaterThan($this->realNow)) {
                continue;
            }
            $shift = $this->openShift($user, $openAt, $day);
            if ($shift === null) {
                continue;
            }
            // The café's standing order, first thing on Mondays and Thursdays.
            if ($who === 'maria' && in_array($dow, [1, 4], true)) {
                $this->cafeOrder($day, $user, $shift);
            }
            $times = [];
            for ($i = 0; $i < $count; $i++) {
                $times[] = $this->at($day, mt_rand($from, $to - 1), mt_rand(0, 59));
            }
            sort($times);
            $half = intdiv(count($times), 2);
            foreach ($times as $i => $t) {
                if ($t->greaterThan($this->realNow)) {
                    break;
                }
                if ($this->after && $t->lessThanOrEqualTo($this->after)) {
                    continue;
                }
                Carbon::setTestNow($t);
                $this->sale($day, $user, $shift);
                if ($i === $half) {
                    $this->safeDrop($shift, $user);
                }
            }
            $closeAt = $this->at($day, $to, mt_rand(5, 20));
            if ($closeAt->lessThanOrEqualTo($this->realNow)) {
                Carbon::setTestNow($closeAt);
                $this->closeShift($shift, $user);
            }
        }
    }

    private function sale(Carbon $day, User $user, ?Shift $shift): void
    {
        $lines = $this->basket(mt_rand(1, 100));
        if ($lines === []) {
            return;
        }
        $est = array_sum(array_map(fn ($l) => $l['quantity'] * $this->items[$this->byId[$l['stock_item_id']]]['sell'], $lines));
        $data = ['items' => $lines, 'sale_date' => $day->toDateString(), 'client_uuid' => (string) Str::uuid(), 'age_checked' => true,
            'allow_negative_stock' => true, 'shift_id' => $shift?->id];

        $who = mt_rand(1, 100) <= 32 ? $this->regular() : null;
        if ($who) {
            $data['customer_id'] = (int) $who['customer']->id;
            if ($est >= 32 && mt_rand(1, 100) <= 6) {
                $data['coupon_code'] = 'WELCOME5';
            }
            if (! empty($who['x']['credit']) && mt_rand(1, 100) <= 30 && (empty($who['x']['overdue']) || $day->diffInDays($this->realNow) > 34)) {
                $data['payments_explicit'] = true;
                $data['payments'] = mt_rand(0, 1) ? [] : [['method' => 'cash', 'amount' => round($est * 0.4, 2)]];
                $this->checkout($user, $data);

                return;
            }
        }
        $roll = mt_rand(1, 100);
        if ($roll <= 36) {
            // Cash: people hand over a round note and get change.
            $data['payments_explicit'] = true;
            $data['payments'] = [['method' => 'cash', 'amount' => $est < 4 || mt_rand(0, 1) ? round($est, 2) : ceil($est / 5) * 5]];
            $this->checkout($user, $data);

            return;
        }
        // Card / mobile wallet: the till charges the exact total after the deals (the part of the
        // estimate over the total is never taken; it is not change either).
        $method = $roll <= 90 ? 'card' : 'mobile_money';
        $data['payments_explicit'] = true;
        $data['payments'] = [['method' => $method, 'amount' => round($est + 1, 2),
            'reference' => $method === 'card' ? 'VISA •••• '.mt_rand(1000, 9999) : 'WALLET-'.strtoupper(Str::random(8))]];
        if ($sale = $this->checkout($user, $data)) {
            DB::table('sale_records')->where('id', $sale->id)->update(['change_given' => 0]);
        }
    }

    private function cafeOrder(Carbon $day, User $user, Shift $shift): void
    {
        $cafe = collect($this->customers)->first(fn ($c) => ! empty($c['x']['account']));
        if ($cafe === null) {
            return;
        }
        $when = $this->at($day, self::OPEN, 5);
        if ($when->greaterThan($this->realNow) || ($this->after && $when->lessThanOrEqualTo($this->after))) {
            return;
        }
        Carbon::setTestNow($when);
        $lines = [];
        foreach (['Country White Loaf' => 6, 'Butter Croissant' => 12, 'Whole Milk 2L' => 4, 'Free-Range Eggs 12pk' => 2, 'Navel Oranges' => 3.2] as $name => $qty) {
            if (($it = $this->item($name)) && $this->stockOf($it['id']) >= $qty) {
                $lines[] = ['stock_item_id' => $it['id'], 'quantity' => $qty];
            }
        }
        if ($lines !== []) {
            $this->checkout($user, ['items' => $lines, 'customer_id' => (int) $cafe['customer']->id, 'payments_explicit' => true, 'payments' => [],
                'sale_date' => $day->toDateString(), 'client_uuid' => (string) Str::uuid(), 'shift_id' => $shift->id, 'notes' => 'Standing order, on account']);
        }
    }

    /** @return list<array{stock_item_id:int, quantity:float}> */
    private function basket(int $roll): array
    {
        $size = $roll <= 16 ? 1 : ($roll <= 36 ? 2 : ($roll <= 58 ? 3 : ($roll <= 76 ? 4 : ($roll <= 90 ? 5 : mt_rand(6, 8)))));
        $weights = [];
        foreach ($this->items as $i => $it) {
            $weights[$i] = $it['pop'];
        }
        $lines = [];
        for ($n = 0; $n < $size * 2 && count($lines) < $size; $n++) {
            $i = $this->weighted($weights);
            $it = $this->items[$i];
            if (isset($lines[$it['id']])) {
                continue;
            }
            $qty = $it['weighed'] ? round(mt_rand(300, 1800) / 1000, 3) : (float) (mt_rand(1, 100) <= 64 ? 1 : (mt_rand(1, 100) <= 75 ? 2 : 3));
            if ($this->stockOf($it['id']) < $qty) {
                continue; // nothing on the shelf: the customer takes something else
            }
            $lines[$it['id']] = ['stock_item_id' => $it['id'], 'quantity' => $qty];
        }

        return array_values($lines);
    }

    private function regular(): ?array
    {
        $weights = [];
        foreach ($this->customers as $i => $c) {
            $weights[$i] = empty($c['x']['account']) ? (int) ($c['x']['regular'] ?? 1) : 0;
        }

        return array_sum($weights) > 0 ? $this->customers[$this->weighted($weights)] : null;
    }

    private function checkout(User $user, array $data): ?SaleRecord
    {
        $sale = $this->quietly(fn () => (new SaleService())->checkout($this->cid, $user->id, $data)['sale']);

        return $sale instanceof SaleRecord ? $sale : null;
    }

    // ── Tills ───────────────────────────────────────────────────────────

    private function openShift(User $user, Carbon $at, Carbon $day): ?Shift
    {
        $svc = app(ShiftService::class);
        if ($open = $svc->current($this->cid, $user->id)) {
            return $open;
        }
        // Already worked today and cashed up: not opened a second time by a later heal.
        if (Shift::withoutGlobalScopes()->where('company_id', $this->cid)->where('opened_by_id', $user->id)->where('opened_at', '>=', $this->at($day, 0))->exists()) {
            return null;
        }
        Carbon::setTestNow($at);
        $shift = $this->quietly(fn () => $svc->open($this->cid, $user->id, 150.0, null, (string) Str::uuid()));

        return $shift instanceof Shift ? $shift : null;
    }

    private function safeDrop(Shift $shift, User $user): void
    {
        $svc = app(ShiftService::class);
        $cash = (float) $svc->totals($shift->fresh())['expected_cash'];
        if ($cash > 260) {
            $this->quietly(fn () => $svc->cashMovement($shift->fresh(), 'drop', floor(($cash - 150) / 50) * 50, 'Safe drop', $user->id, null, (string) Str::uuid()));
        }
    }

    private function closeShift(Shift $shift, User $user): void
    {
        $svc = app(ShiftService::class);
        $shift = $shift->fresh();
        if ($shift === null || $shift->closed_at !== null) {
            return;
        }
        $expected = (float) $svc->totals($shift)['expected_cash'];
        $roll = mt_rand(1, 100);
        $variance = $roll <= 82 ? 0.0 : ($roll <= 94 ? -mt_rand(10, 500) / 100 : mt_rand(5, 200) / 100);
        $note = $variance < 0 ? 'Short at cash-up, recounted twice.' : ($variance > 0 ? 'Over: probably change not taken.' : null);
        $this->quietly(fn () => $svc->close($shift, round($expected + $variance, 2), $user->id, $note));
    }

    /** Tills left open from an earlier day are counted and closed at that day's closing time. */
    private function closeStaleShifts(Carbon $today): void
    {
        $open = Shift::withoutGlobalScopes()->where('company_id', $this->cid)->whereNull('closed_at')->get();
        foreach ($open as $shift) {
            $opened = Carbon::parse($shift->opened_at ?? $shift->created_at)->setTimezone($this->tz);
            if ($opened->lt($today)) {
                $user = User::withoutGlobalScopes()->find($shift->opened_by_id ?? $shift->created_by_id) ?? $this->manager;
                Carbon::setTestNow($this->at($opened->copy()->startOfDay(), self::CLOSE, 10));
                $this->closeShift($shift, $user);
            }
        }
    }

    // ── Stock coming in ─────────────────────────────────────────────────

    /** Suppliers' delivery days; the first morning brings everything. */
    private function deliveries(Carbon $day, int $d, int $days): void
    {
        $dow = (int) $day->dayOfWeekIso;
        $due = $d === 0 ? array_keys($this->suppliers) : array_keys(array_filter([
            'farm' => in_array($dow, [1, 4], true),
            'bakery' => in_array($dow, [2, 5], true),
            'dairy' => $dow === 3,
            'drinks' => $dow === 1,
            'pantry' => $dow === 2 && $day->weekOfYear % 2 === 0,
            'home' => $dow === 2 && $day->day <= 7,
        ]));
        foreach ($due as $key) {
            $interval = ['farm' => 4, 'bakery' => 4, 'dairy' => 7, 'drinks' => 7, 'pantry' => 14, 'home' => 30][$key] ?? 7;
            $lines = [];
            foreach ($this->items as $it) {
                if ($it['supplier'] !== $key || ($it['target'] <= 0 && $d > 0)) {
                    continue;
                }
                // Enough for this week's selling, never more than the shop will sell before today.
                $par = $it['target'] + ceil($it['pop'] * 0.9 * min($interval, $days - $d));
                $have = $this->stockOf($it['id']);
                if ($d > 0 && $have > $par * 0.6) {
                    continue;
                }
                $qty = $it['weighed'] ? round($par - $have, 1) : ceil($par - $have);
                if ($qty > 0) {
                    $lines[] = $this->receiptLine($it, $qty, $day);
                }
            }
            $this->receive($key, $lines, $day, $d === 14 && $key === 'pantry');
        }
    }

    private function receiptLine(array $it, float $qty, Carbon $day): array
    {
        $line = ['stock_item_id' => $it['id'], 'quantity' => $qty, 'unit_cost' => $it['cost']];
        if ($it['life']) {
            $line['batch_number'] = 'L'.$day->format('ymd').'-'.substr((string) $it['id'], -3);
            $line['expiry_date'] = $day->copy()->addDays((int) $it['life'])->toDateString();
        }

        return $line;
    }

    /** A delivery: paid on the spot (farm, bakery) or on the supplier's terms; dairy comes on a purchase order. */
    private function receive(string $key, array $lines, Carbon $day, bool $freight = false): void
    {
        $supplier = $this->suppliers[$key] ?? null;
        if ($lines === [] || $supplier === null) {
            return;
        }
        $keeper = $this->staff['samuel'] ?? $this->manager;
        $value = round(array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_cost'], $lines)), 2);
        $cashOnDelivery = in_array($key, ['farm', 'bakery'], true);
        $ref = strtoupper(substr($key, 0, 2)).'-'.$day->format('md').mt_rand(10, 99);

        if ($key === 'dairy') {
            Carbon::setTestNow($this->at($day->copy()->subDays(2), 16, 30));
            $po = $this->quietly(fn () => app(PurchaseOrderService::class)->create($this->cid, $this->manager->id,
                array_map(fn ($l) => ['stock_item_id' => $l['stock_item_id'], 'quantity' => $l['quantity'], 'unit_cost' => $l['unit_cost']], $lines),
                (int) $supplier->id, $day->toDateString(), 'Weekly dairy order'));
            if ($po) {
                $this->quietly(fn () => app(PurchaseOrderService::class)->send($po, false));
                Carbon::setTestNow($this->at($day, 6, 45));
                $byItem = collect($lines)->keyBy('stock_item_id');
                $poLines = $po->fresh()->items->map(fn ($pl) => ['purchase_order_item_id' => (int) $pl->id, 'quantity' => (float) $pl->quantity,
                    'unit_cost' => (float) $pl->unit_cost] + array_intersect_key((array) $byItem->get((int) $pl->stock_item_id), ['batch_number' => 1, 'expiry_date' => 1]))->all();
                $this->quietly(fn () => app(PurchaseOrderService::class)->receive($po->fresh(), $keeper->id, $poLines, 0, 'bank', $ref, $day->toDateString(), (string) Str::uuid()));
            }
        } else {
            Carbon::setTestNow($this->at($day, 6, mt_rand(20, 50)));
            $options = $freight ? ['landed_costs' => [['label' => 'Freight', 'amount' => 35]], 'landed_split' => 'value'] : [];
            $this->quietly(fn () => app(GoodsReceiptService::class)->receive($this->cid, $keeper->id, $lines, (int) $supplier->id, $ref,
                $cashOnDelivery ? $value : 0, 'cash', $day->toDateString(), (string) Str::uuid(), null, null, null, null, $options));
        }
        if (! $cashOnDelivery) {
            $this->bills[] = ['due' => $day->copy()->addDays((int) $supplier->payment_terms_days)->toDateString(), 'supplier' => $key, 'amount' => $value];
        }
    }

    private function payBills(Carbon $day): void
    {
        foreach ($this->bills as $i => $bill) {
            if ($bill['due'] <= $day->toDateString()) {
                Carbon::setTestNow($this->at($day, 10, 15));
                $supplier = $this->suppliers[$bill['supplier']]->fresh();
                $owed = (float) app(SupplierService::class)->balance($supplier);
                $amount = round(min($owed, $bill['amount']), 2);
                if ($amount > 0) {
                    $this->quietly(fn () => app(SupplierService::class)->pay($supplier, $amount, 'bank', $this->manager->id, 'Bank transfer '.$day->format('d M')));
                }
                unset($this->bills[$i]);
            }
        }
    }

    /** The heal's top-up: anything the day's selling pushed well under its level comes back in. */
    private function restockLow(Carbon $today): void
    {
        $bySupplier = [];
        foreach ($this->items as $it) {
            if ($it['target'] <= 0) {
                continue;
            }
            $have = $this->stockOf($it['id']);
            if ($have < min($it['target'], $it['reorder']) * 0.5) {
                $qty = $it['weighed'] ? round($it['target'] - $have, 1) : ceil($it['target'] - $have);
                $bySupplier[$it['supplier']][] = $this->receiptLine($it, $qty, $today);
            }
        }
        foreach ($bySupplier as $key => $lines) {
            $supplier = $this->suppliers[$key] ?? null;
            if ($supplier) {
                Carbon::setTestNow(now()->greaterThan($this->realNow) ? $this->realNow : now());
                $this->quietly(fn () => app(GoodsReceiptService::class)->receive($this->cid, ($this->staff['samuel'] ?? $this->manager)->id, $lines, (int) $supplier->id,
                    'TOP-'.$today->format('md').mt_rand(10, 99), 0, 'cash', $today->toDateString(), (string) Str::uuid()));
            }
        }
    }

    /**
     * This morning's shelves match the catalogue: short items come in on an early delivery, and
     * anything over was sold yesterday afternoon. So the low-stock and sold-out examples are exact.
     */
    private function settleStock(Carbon $today): void
    {
        $yesterday = $today->copy()->subDay();
        $in = [];
        foreach ($this->items as $it) {
            $target = $it['target'] - ($it['name'] === 'Greek Yogurt 1kg' ? 5 : 0); // five more arrive as the short-dated batch
            $delta = round($target - $this->stockOf($it['id']), 3);
            if ($delta > 0) {
                $in[$it['supplier']][] = $this->receiptLine($it, $it['weighed'] ? $delta : ceil($delta), $today);
            }
            $guard = 0;
            $chunk = max(3.0, ceil(-$delta / 4));
            while ($delta < -0.0005 && $guard++ < 8) {
                $qty = $guard === 8 || -$delta <= $chunk ? round(-$delta, 3) : (float) $chunk;
                Carbon::setTestNow($this->at($yesterday, mt_rand(15, 20), mt_rand(0, 59)));
                $this->checkout($this->staff['kevin'] ?? $this->manager, ['items' => [['stock_item_id' => $it['id'], 'quantity' => $qty]], 'payments_explicit' => true,
                    'payments' => [['method' => 'cash', 'amount' => round($qty * $it['sell'], 2)]], 'sale_date' => $yesterday->toDateString(),
                    'client_uuid' => (string) Str::uuid(), 'age_checked' => true, 'allow_negative_stock' => true]);
                $delta = round($target - $this->stockOf($it['id']), 3);
            }
        }
        foreach ($in as $key => $lines) {
            Carbon::setTestNow($this->at($today, 6, 30));
            $this->quietly(fn () => app(GoodsReceiptService::class)->receive($this->cid, ($this->staff['samuel'] ?? $this->manager)->id, $lines, (int) $this->suppliers[$key]->id,
                'AM-'.$today->format('md').strtoupper(substr($key, 0, 2)), in_array($key, ['farm', 'bakery'], true) ? round(array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_cost'], $lines)), 2) : 0,
                'cash', $today->toDateString(), (string) Str::uuid()));
        }
    }

    // ── The rest of shop life ───────────────────────────────────────────

    private function events(Carbon $day, int $d, int $days): void
    {
        $manager = $this->manager;
        $keeper = $this->staff['samuel'] ?? $manager;
        $back = $days - $d;

        // Credit customers pay part of what they owe; the café settles its account every fortnight.
        foreach ($this->customers as $c) {
            $cust = $c['customer']->fresh();
            $owed = (float) $cust->balance;
            if ($owed <= 0 || ! empty($c['x']['overdue'])) {
                continue;
            }
            $pay = ! empty($c['x']['account']) ? ($d % 14 === 13 ? round($owed * 0.9, 2) : 0) : ($d % 9 === 5 && mt_rand(0, 1) ? round($owed / 2, 2) : 0);
            if ($pay > 0) {
                Carbon::setTestNow($this->at($day, 16, 40));
                $this->quietly(fn () => app(CustomerService::class)->receivePayment($cust, $pay, ! empty($c['x']['account']) ? 'bank' : 'cash', $manager->id));
            }
        }

        // Gift cards: one bought for a customer, one over the counter; later spent in part.
        if ($d === 10 || $d === 26) {
            $kenji = $this->customerNamed('Kenji Sato');
            Carbon::setTestNow($this->at($day, 12, 20));
            $sold = $this->quietly(fn () => app(GiftCardService::class)->sell($this->cid, ($this->staff['maria'] ?? $manager)->id, $d === 10 ? 50 : 25, 'card',
                $d === 10 && $kenji ? ['customer_id' => (int) $kenji->id, 'client_uuid' => (string) Str::uuid()] : ['client_uuid' => (string) Str::uuid()]));
            if (is_array($sold) && ! empty($sold['code'])) {
                $this->giftCards[] = ['code' => (string) $sold['code'], 'customer_id' => $d === 10 ? $kenji?->id : null];
            }
        }
        if ($d === 31 || $d === 47) {
            $this->spendGiftCard($day);
        }

        // Returns, one every couple of weeks.
        $reasons = [(int) ($days * 0.3) => ['Customer changed their mind', true, 'cash'], (int) ($days * 0.5) => ['Damaged packaging', false, 'card'],
            (int) ($days * 0.7) => ['Bought twice by mistake', true, 'card'], (int) ($days * 0.9) => ['Past its date on the shelf', false, 'cash']];
        if (isset($reasons[$d])) {
            [$why, $restock, $method] = $reasons[$d];
            $sale = SaleRecord::withoutGlobalScopes()->where('company_id', $this->cid)->where('sale_date', $day->toDateString())->where('balance', '<=', 0)
                ->whereNull('voided_at')->inRandomOrder()->first();
            $line = $sale?->saleRecordItems()->first();
            if ($sale && $line) {
                Carbon::setTestNow(Carbon::parse($sale->created_at)->addMinutes(mt_rand(40, 180)));
                $this->quietly(fn () => app(ReturnService::class)->create($sale, [['sale_item_id' => (int) $line->id, 'quantity' => min(1.0, (float) $line->quantity) ?: (float) $line->quantity, 'restock' => $restock]],
                    $manager->id, $why, $method, (string) Str::uuid()));
            }
        }

        // Write-offs.
        $writeOffs = [12 => ['Free-Range Eggs 12pk', 'Damage', 1, 'Tray dropped while shelving'], 6 => ['Whole Milk 2L', 'Expired', 2, 'Past use-by date'],
            4 => ['Bananas', 'Expired', 1.4, 'Overripe'], 20 => ['Iced Tea 2L', 'Internal Use', 1, 'Staff room']];
        if (isset($writeOffs[$back]) && ($it = $this->item($writeOffs[$back][0]))) {
            Carbon::setTestNow($this->at($day, 8, 10));
            $this->quietly(fn () => app(ShrinkService::class)->record($this->cid, $keeper->id, ['stock_item_id' => $it['id'], 'type' => $writeOffs[$back][1],
                'quantity' => $writeOffs[$back][2], 'reason' => $writeOffs[$back][3], 'unit_cost' => $it['cost']]));
        }

        // Last month's pantry count, posted with two small differences.
        if ($back === 18) {
            Carbon::setTestNow($this->at($day, 20, 30));
            $pantry = array_values(array_filter($this->items, fn ($it) => $it['category'] === 'Pantry'));
            $take = $this->quietly(fn () => app(StockTakeService::class)->create($this->cid, $keeper->id, 'Pantry count', $pantry[0]['category_id'] ?? null, (string) Str::uuid()));
            if ($take) {
                $counts = array_map(fn ($it) => ['stock_item_id' => $it['id'], 'counted_quantity' => max(0, $this->stockOf($it['id'])
                    - ($it['name'] === 'Spaghetti 500g' ? 2 : ($it['name'] === 'Cane Sugar 2kg' ? 1 : 0)))], $pantry);
                $this->quietly(fn () => app(StockTakeService::class)->count($take, $counts));
                $this->quietly(fn () => app(StockTakeService::class)->post($take->fresh(), $manager->id));
            }
        }
    }

    private function spendGiftCard(Carbon $day): void
    {
        $card = $this->giftCards[0] ?? null;
        $coffee = $this->item('Whole Bean Coffee 500g');
        $nuts = $this->item('Roasted Hazelnuts 250g');
        if ($card === null || ! $coffee || ! $nuts) {
            return;
        }
        Carbon::setTestNow($this->at($day, 15, 25));
        $maria = $this->staff['kevin'] ?? $this->manager;
        $est = $coffee['sell'] + $nuts['sell'];
        $sale = $this->checkout($maria, ['items' => [['stock_item_id' => $coffee['id'], 'quantity' => 1], ['stock_item_id' => $nuts['id'], 'quantity' => 1]],
            'customer_id' => $card['customer_id'], 'payments_explicit' => true, 'sale_date' => $day->toDateString(), 'client_uuid' => (string) Str::uuid(),
            'payments' => [['tender' => 'gift_card', 'code' => $card['code'], 'amount' => 15.0], ['method' => 'card', 'amount' => round($est, 2), 'reference' => 'VISA •••• 4417']]]);
        if ($sale) {
            DB::table('sale_records')->where('id', $sale->id)->update(['change_given' => 0]);
        }
    }

    private function expenses(Carbon $day): void
    {
        $rows = array_values(array_filter($this->data['expenses'], fn ($e) => (int) $e[3] === $day->day));
        if ((int) $day->dayOfWeekIso === 5) {
            $rows[] = ['Wages', 'Weekly wages (part-time staff)', 780, 0];
        }
        foreach ($rows as [$category, $description, $amount]) {
            Carbon::setTestNow($this->at($day, 17, 30));
            $cat = FinancialCategory::withoutGlobalScopes()->firstOrCreate(['company_id' => $this->cid, 'name' => $category, 'type' => 'Expense'],
                ['status' => 'Active']);
            $this->quietly(fn () => FinancialRecord::create(['company_id' => $this->cid, 'financial_category_id' => $cat->id, 'type' => 'Expense', 'amount' => $amount,
                'payment_method' => $category === 'Wages' || $category === 'Rent' ? 'bank' : 'card', 'description' => $description, 'date' => $day->toDateString(),
                'created_by_id' => $this->manager->id, 'user_id' => $this->manager->id]));
        }
    }

    /** What makes the shop feel lived in today: an order out, a markdown, a count under way, the team talking. */
    private function finishingTouches(Carbon $today): void
    {
        $manager = $this->manager;
        $keeper = $this->staff['samuel'] ?? $manager;
        $at = fn (int $h, int $m) => $this->at($today, $h, $m)->greaterThan($this->realNow) ? $this->at($today->copy()->subDay(), $h, $m) : $this->at($today, $h, $m);

        // Yogurt near its date, marked down by the chiller.
        if ($yogurt = $this->item('Greek Yogurt 1kg')) {
            Carbon::setTestNow($at(6, 35));
            $this->quietly(fn () => app(GoodsReceiptService::class)->receive($this->cid, $keeper->id, [['stock_item_id' => $yogurt['id'], 'quantity' => 5, 'unit_cost' => $yogurt['cost'],
                'batch_number' => 'L'.$today->copy()->subDays(16)->format('ymd').'-Y', 'expiry_date' => $today->copy()->addDays(2)->toDateString()]],
                (int) $this->suppliers['dairy']->id, 'RET-SHELF', 0, 'cash', $today->copy()->subDays(16)->toDateString(), (string) Str::uuid(), 'Found at the back of the chiller'));
            $batch = DB::table('stock_batches')->where('company_id', $this->cid)->where('stock_item_id', $yogurt['id'])->where('quantity', '>', 0)->orderBy('expiry_date')->first();
            if ($batch) {
                Carbon::setTestNow($at(7, 5));
                $this->quietly(fn () => app(MarkdownService::class)->create($this->cid, $manager->id, (int) $batch->id, 30));
            }
        }

        // Orders: one sent yesterday for what is running out, one still being put together.
        Carbon::setTestNow($this->at($today->copy()->subDay(), 17, 45));
        $home = array_filter([$this->item('Wireless Headphones') ? ['stock_item_id' => $this->item('Wireless Headphones')['id'], 'quantity' => 6, 'unit_cost' => 28] : null,
            $this->item('AA Batteries 8pk') ? ['stock_item_id' => $this->item('AA Batteries 8pk')['id'], 'quantity' => 24, 'unit_cost' => 4.3] : null]);
        $po = $this->quietly(fn () => app(PurchaseOrderService::class)->create($this->cid, $manager->id, array_values($home), (int) $this->suppliers['home']->id,
            $today->copy()->addDays(2)->toDateString(), 'Headphones sold out; batteries low.'));
        if ($po) {
            $this->quietly(fn () => app(PurchaseOrderService::class)->send($po, false));
        }
        Carbon::setTestNow($at(9, 40));
        $drinks = array_values(array_filter(array_map(fn ($n) => ($it = $this->item($n)) ? ['stock_item_id' => $it['id'], 'quantity' => $n === 'Spring Water 1.5L' ? 48 : 24, 'unit_cost' => $it['cost']] : null,
            ['Spring Water 1.5L', 'Sparkling Lemonade 330ml', 'Orange Juice 1.5L'])));
        $this->quietly(fn () => app(PurchaseOrderService::class)->create($this->cid, $manager->id, $drinks, (int) $this->suppliers['drinks']->id, $today->copy()->addDays(4)->toDateString(), 'Weekend drinks'));

        // A price rise coming on Monday, and today's drinks count half done.
        if ($coffee = $this->item('Whole Bean Coffee 500g')) {
            $this->quietly(fn () => app(PriceBookService::class)->schedule($this->cid, $coffee['id'], 'selling', 13.49, $today->copy()->next(Carbon::MONDAY)->setTime(6, 0), 'Supplier price rise', $manager->id));
        }
        Carbon::setTestNow($at(8, 20));
        $drinksCount = array_values(array_filter($this->items, fn ($it) => $it['category'] === 'Beverages'));
        $take = $this->quietly(fn () => app(StockTakeService::class)->create($this->cid, $keeper->id, 'Drinks aisle count', $drinksCount[0]['category_id'] ?? null, (string) Str::uuid()));
        if ($take) {
            $this->quietly(fn () => app(StockTakeService::class)->count($take, array_map(fn ($it) => ['stock_item_id' => $it['id'], 'counted_quantity' => $this->stockOf($it['id'])],
                array_slice($drinksCount, 0, 4))));
        }

        // The team chat.
        $chat = app(ChatService::class);
        $room = $this->quietly(fn () => $chat->teamConversation($this->co->fresh()));
        if ($room) {
            foreach ([
                ['daniel', -1, 18, 40, 'Dairy order is in for Wednesday. Northbrook confirmed a 6:45 drop.'],
                ['samuel', -1, 19, 5, 'Headphones are sold out. I put them on the Brightline order with the batteries.'],
                ['maria', 0, 7, 2, 'Till 1 open with the $150 float. The café order is packed for pickup.'],
                ['samuel', 0, 7, 10, 'Five yogurts found at the back of the chiller, date is in two days. Marked down 30%.'],
                ['daniel', 0, 7, 25, 'Thanks both. Coffee goes up to $13.49 on Monday, labels are in the print queue.'],
            ] as [$who, $offset, $h, $m, $text]) {
                $when = $this->at($today->copy()->addDays($offset), $h, $m);
                if ($when->greaterThan($this->realNow) || ! isset($this->staff[$who])) {
                    continue;
                }
                Carbon::setTestNow($when);
                $this->quietly(fn () => $chat->send($room, $this->staff[$who], $text));
            }
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function snapshot(): array
    {
        return [
            'staff' => array_map(fn (User $u) => (int) $u->id, $this->staff),
            'suppliers' => array_map(fn (Supplier $s) => (int) $s->id, $this->suppliers),
            'items' => $this->items,
            'customers' => collect($this->customers)->mapWithKeys(fn ($c) => [(int) $c['customer']->id => $c['x']])->all(),
        ];
    }

    private function item(string $name): ?array
    {
        foreach ($this->items as $it) {
            if ($it['name'] === $name) {
                return $it;
            }
        }

        return null;
    }

    private function customerNamed(string $name): ?Customer
    {
        return collect($this->customers)->first(fn ($c) => $c['customer']->name === $name)['customer'] ?? null;
    }

    private function stockOf(int $id): float
    {
        return (float) DB::table('stock_items')->where('id', $id)->value('current_quantity');
    }

    /** @param array<int, int> $weights */
    private function weighted(array $weights): int
    {
        $r = mt_rand(1, max(1, array_sum($weights)));
        foreach ($weights as $k => $w) {
            if (($r -= $w) <= 0) {
                return $k;
            }
        }

        return (int) array_key_first($weights);
    }

    private function at(Carbon $day, int $hour, int $minute = 0): Carbon
    {
        return Carbon::parse($day->toDateString().sprintf(' %02d:%02d:00', $hour, $minute), $this->tz)->utc();
    }

    /** The product photo, copied once into the public images folder the web and phone apps read. */
    private function photo(string $key): ?string
    {
        $src = dirname(__DIR__, 3)."/database/data/demo-photos/{$key}.jpg";
        // Where uploads are served from: the web app's configured media root, else budget-pro's admin
        // disk (public/storage, which on some hosts is a real folder rather than a link to storage/app/public).
        $root = rtrim((string) (config('budgetpro.media_root') ?: config('filesystems.disks.admin.root') ?: dirname(__DIR__, 3).'/public/storage'), '/');
        $dest = "{$root}/images/demo/{$key}.jpg";
        if (! is_file($src)) {
            return null;
        }
        if (! is_file($dest) || filesize($dest) !== filesize($src)) {
            @mkdir(dirname($dest), 0755, true);
            @copy($src, $dest);
        }

        return is_file($dest) ? "images/demo/{$key}.jpg" : null;
    }

    public static function ean13(string $twelve): string
    {
        $sum = 0;
        foreach (str_split(substr($twelve, 0, 12)) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 ? 3 : 1);
        }

        return substr($twelve, 0, 12).((10 - $sum % 10) % 10);
    }

    private function make($model, array $attrs)
    {
        foreach ($attrs as $k => $v) {
            $model->{$k} = $v;
        }
        $model->save();

        return $model;
    }

    /** One refused entry (a rule that bites on sample data) must not stop the rest. */
    private function quietly(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (BusinessRuleException|\DomainException|\InvalidArgumentException $e) {
            $this->skipped[] = $e->getMessage();
            Log::info('[public-demo] sample entry skipped: '.$e->getMessage());

            return null;
        }
    }

    /** @return array<string, mixed> */
    private function actAs(User $user): array
    {
        $prev = [];
        foreach (['web', 'admin'] as $name) {
            try {
                $guard = Auth::guard($name);
                $prev[$name] = $guard->hasUser() ? $guard->user() : null;
                $guard->setUser($user);
            } catch (\Throwable) {
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
