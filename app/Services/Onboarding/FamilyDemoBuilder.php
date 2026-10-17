<?php

namespace App\Services\Onboarding;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetItem;
use App\Models\BudgetItemCategory;
use App\Models\BudgetProgram;
use App\Models\Company;
use App\Models\ContributionRecord;
use App\Models\Customer;
use App\Models\FinancialCategory;
use App\Models\FinancialRecord;
use App\Models\Plan;
use App\Models\PoultryBatch;
use App\Models\PoultryCustomer;
use App\Models\PoultryDailyRecord;
use App\Models\PoultryEggTransaction;
use App\Models\PoultryExpense;
use App\Models\PoultryFeedStock;
use App\Models\PoultryFeedType;
use App\Models\PoultryHealthEvent;
use App\Models\PoultryMortalityEvent;
use App\Models\PoultrySale;
use App\Models\PoultryVaccinationEvent;
use App\Models\SaleRecord;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ops\TenantDataService;
use App\Services\Shop\CustomerService;
use App\Services\Shop\GoodsReceiptService;
use App\Services\Shop\SaleService;
use App\Services\Team\TeamService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The Budget Pro family's demo accounts for the web portal and the website's pictures
 * (budget-pro-new docs/FAMILY_PLAN.md Part 1.5), built like PublicDemoBuilder: through the models and
 * services, with the clock moved to each moment, so every screen, total and report agrees.
 *
 * - farm:    Nsubuga Poultry Farm, Mukono (poultry + finance, business type poultry). Layers House 1:
 *            320 brown layers placed 38 weeks ago, 305 alive, laying 78% (238 eggs today), feed for
 *            about five days, a Newcastle booster due today; Broilers Batch 2 grown and sold earlier.
 * - wedding: Brian & Grace Wedding (budget + finance, business type fundraising), eleven weeks before
 *            the day: 60 pledges (UGX 18,400,000 pledged, 11,250,000 paid) and a UGX 20,500,000 budget
 *            in ten categories (8,510,000 paid out).
 * - all:     Rose's Corner Shop (shop + poultry + budget + finance): three weeks of shop trading, and
 *            the same farm and wedding, for the portal's dashboard with every module on.
 *
 * The farm and the wedding are the plans of the phone pictures (budget-pro-mobo
 * test/screenshots/family_fixture.dart: FarmPlan, WeddingPlan), repeated here line for line (same
 * constants, same integer arithmetic, same order), so the website's phone and web pictures show the
 * same figures. Change one, change the other.
 *
 * Local accounts only (`php artisan demo:family`): *.demo@local.test, password secret123.
 *
 * The farm and the wedding are also reusable on their own (seedFarm, seedWedding, topUpFarm): the public
 * demo (PublicDemoBuilder) puts both into Fresh Corner Market on every build and keeps them current on
 * every heal. There, amounts are converted from UGX to the shop's currency (RATES) and no entry is dated
 * after the real "now".
 */
class FamilyDemoBuilder
{
    public const PASSWORD = 'secret123';

    public const TZ = 'Africa/Kampala';

    /** key => the account (its owner signs in with the email). */
    public const ACCOUNTS = [
        'farm' => ['email' => 'nsubuga.demo@local.test', 'first' => 'Joseph', 'last' => 'Nsubuga', 'company' => 'Nsubuga Poultry Farm, Mukono',
            'phone' => '0752604318', 'address' => 'Kyetume, Mukono', 'type' => 'poultry', 'modules' => ['poultry', 'finance']],
        'wedding' => ['email' => 'brian.demo@local.test', 'first' => 'Brian', 'last' => 'Tumusiime', 'company' => 'Brian & Grace Wedding',
            'phone' => '0774215640', 'address' => 'Mukono', 'type' => 'fundraising', 'modules' => ['budget', 'finance']],
        'all' => ['email' => 'rose.demo@local.test', 'first' => 'Rose', 'last' => 'Nakato', 'company' => "Rose's Corner Shop",
            'phone' => '0772123456', 'address' => 'Ntinda, Kampala', 'type' => 'retail', 'modules' => ['shop', 'poultry', 'budget', 'finance']],
    ];

    /** The wedding's treasurer (a member of the wedding account who takes the money). */
    public const TREASURER = ['email' => 'doreen.demo@local.test', 'name' => 'Doreen Natukunda'];

    /** UGX per unit of a shop's currency, for the farm and the wedding in a shop that does not count in shillings (round figures on purpose). */
    public const RATES = ['UGX' => 1, 'USD' => 4000, 'EUR' => 4000, 'GBP' => 5000, 'KES' => 30, 'TZS' => 1, 'RWF' => 3];

    /** @var list<string> sample entries a rule refused (logged, never fatal) */
    public array $skipped = [];

    /** @var array{farm?: array<string, int>, wedding?: int} what the last seed made: batch ids by key, the event's id */
    public array $ids = [];

    private Company $co;

    private User $owner;

    private Carbon $today;

    private string $tz = self::TZ;

    /** UGX per unit of the account's currency (RATES). */
    private int $rate = 1;

    /** @var array<int, string>|null the event's committee (user id => role); null: the owner chairs, the treasurer keeps the money */
    private ?array $committee = null;

    /** When set, nothing is dated after it (the public demo is read while it is being looked at; the local accounts keep the evening's figures). */
    private ?Carbon $realNow = null;

    /**
     * (Re)build the named accounts (default: all three). An existing account with the same email is
     * deleted first, with everything in it, so a rebuild always starts clean.
     *
     * @param  list<string>|null  $only
     * @return array<string, array{company_id: int, email: string, password: string, summary: array}>
     */
    public function build(?array $only = null): array
    {
        $keys = $only ?: array_keys(self::ACCOUNTS);
        foreach ($keys as $k) {
            if (! isset(self::ACCOUNTS[$k])) {
                throw BusinessRuleException::make('unknown_account', "No demo account called {$k}: use farm, wedding or all.");
            }
        }
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->muted(fn () => $this->account($key));
        }

        return $out;
    }

    /**
     * The Mukono layers farm (and the broiler batch sold earlier) in an existing account, as the owner.
     *
     * @param  array{now?: Carbon, tz?: string, currency?: string, ledger?: bool}  $opts  ledger: also mirror the farm's expenses to Finance
     * @return array{summary: array, batches: array<string, int>}
     */
    public function seedFarm(Company $co, User $owner, array $opts = []): array
    {
        $summary = $this->within($co, $owner, $opts, fn () => $this->farm((bool) ($opts['ledger'] ?? false)));

        return ['summary' => $summary, 'batches' => $this->ids['farm'] ?? []];
    }

    /**
     * The Brian & Grace wedding (budget, pledges, and the pledge extras whose tables exist) in an existing
     * account, eleven weeks before the day. The treasurer defaults to the owner.
     *
     * @param  array{now?: Carbon, tz?: string, currency?: string, committee?: array<int, string>}  $opts  committee: user id => role
     * @return array{summary: array, program_id: int}
     */
    public function seedWedding(Company $co, User $owner, ?User $treasurer = null, array $opts = []): array
    {
        $this->committee = $opts['committee'] ?? null;
        $summary = $this->within($co, $owner, $opts, fn () => $this->wedding($treasurer ?? $owner));

        return ['summary' => $summary, 'program_id' => (int) ($this->ids['wedding'] ?? 0)];
    }

    /**
     * Keep a seeded farm current: the layers' daily card for each day since the last one, up to today
     * (never a day twice), and a delivery of layers mash when fewer than three days' feed is left.
     *
     * @param  array{now?: Carbon, tz?: string, currency?: string}  $opts
     * @return int daily cards added
     */
    public function topUpFarm(Company $co, User $owner, int $batchId, array $opts = []): int
    {
        return $this->within($co, $owner, $opts, function () use ($batchId) {
            $cid = (int) $this->co->id;
            $batch = DB::table('poultry_batches')->where('company_id', $cid)->where('id', $batchId)->where('is_deleted', 0)->first();
            $last = $batch ? DB::table('poultry_daily_records')->where('company_id', $cid)->where('batch_id', $batchId)->where('is_deleted', 0)->max('date') : null;
            if ($batch === null || $last === null) {
                return 0;
            }
            $from = Carbon::parse($last, $this->tz)->startOfDay()->addDay();
            if ($from->lt($this->today->copy()->subDays(14))) {
                return 0; // a farm left alone this long waits for the next rebuild
            }
            $alive = (int) $batch->start_count
                - (int) DB::table('poultry_daily_records')->where('batch_id', $batchId)->where('is_deleted', 0)->sum('mortality')
                - (int) DB::table('poultry_mortality_events')->where('batch_id', $batchId)->where('is_deleted', 0)->sum('count');
            $placed = Carbon::parse($batch->acquired_date, $this->tz)->startOfDay();
            $by = (string) $this->owner->name;
            $added = 0;
            for ($day = $from; $day->lte($this->today); $day = $day->copy()->addDay()) {
                $d = (int) $placed->diffInDays($day);
                $w = intdiv($d, 7);
                Carbon::setTestNow($this->past($day->copy()->setTime(19, 0)->utc()));
                $eggs = max(0, intdiv(self::farmLayPct($w) * $alive + 50, 100) + self::FARM_WOBBLE[$d % 7]);
                $feed = intdiv(self::farmIntake($w) * $alive + 500, 1000);
                $this->make(PoultryDailyRecord::class, ['batch_id' => $batchId, 'date' => $day->toDateString(), 'eggs_trays' => intdiv($eggs, 30), 'eggs_loose' => $eggs % 30,
                    'mortality' => 0, 'feed_kg' => $feed, 'water_l' => $feed * 2, 'notes' => '', 'egg_unit_price' => $this->money(12000),
                    'feed_price_per_kg' => $this->money(self::farmPricePerKg('layer')), 'avg_weight_kg' => 0.0], $by);
                $added++;
            }
            // Feed: a delivery before it runs out (ten bags), like the farm's fortnightly buying.
            $left = (float) DB::table('poultry_feed_stock')->where('company_id', $cid)->where('is_deleted', 0)->where('direction', 'in')->sum('qty_kg')
                - (float) DB::table('poultry_feed_stock')->where('company_id', $cid)->where('is_deleted', 0)->where('direction', 'out')->sum('qty_kg')
                - (float) DB::table('poultry_daily_records')->where('company_id', $cid)->where('is_deleted', 0)->sum('feed_kg');
            $perDay = intdiv(self::farmIntake(30) * max(1, $alive) + 500, 1000);
            $feedType = DB::table('poultry_feed_types')->where('company_id', $cid)->where('is_deleted', 0)->where('category', 'layers_mash')->value('id');
            if ($feedType && $left < 3 * $perDay) {
                [$name, , $kgPerBag, $bagPrice] = self::FARM_FEEDS['layer'];
                Carbon::setTestNow($this->past($this->today->copy()->setTime(9, 0)->utc()));
                $x = $this->make(PoultryExpense::class, ['category' => 'feed', 'feed_type_id' => (int) $feedType, 'amount' => $this->money(10 * $bagPrice), 'batch_id' => $batchId,
                    'date' => $this->today->toDateString(), 'note' => "10 bags {$name} ({$kgPerBag} kg)"], $by);
                $this->make(PoultryFeedStock::class, ['feed_type_id' => (int) $feedType, 'direction' => 'in', 'source' => 'purchase', 'qty_kg' => 10 * $kgPerBag,
                    'cost' => $this->money(10 * $bagPrice), 'batch_id' => $batchId, 'ref_uuid' => $x->uuid, 'date' => $this->today->toDateString(), 'note' => ''], $by);
            }
            Carbon::setTestNow(null);

            return $added;
        });
    }

    public static function rateFor(?string $currency): int
    {
        return self::RATES[strtoupper((string) $currency)] ?? 1;
    }

    /** Nothing leaves the machine while building (budget item updates email the account, for one), and the clock is put back. */
    private function muted(callable $fn): mixed
    {
        $mail = [config('saas.mail_enabled'), config('mail.default'), config('messaging.sms.driver'), config('messaging.whatsapp.driver')];
        $clock = Carbon::getTestNow();
        config(['saas.mail_enabled' => false, 'mail.default' => 'array', 'messaging.sms.driver' => 'log', 'messaging.whatsapp.driver' => 'log']);
        try {
            return OnboardingEvents::muted($fn);
        } finally {
            Carbon::setTestNow($clock);
            config(['saas.mail_enabled' => $mail[0], 'mail.default' => $mail[1], 'messaging.sms.driver' => $mail[2], 'messaging.whatsapp.driver' => $mail[3]]);
        }
    }

    /** Seed into an account someone else made: its currency, its time zone, today as the caller sees it. */
    private function within(Company $co, User $owner, array $opts, callable $fn): mixed
    {
        $this->co = $co;
        $this->owner = $owner;
        $this->tz = (string) ($opts['tz'] ?? ($co->timezone ?: self::TZ));
        $this->rate = self::rateFor($opts['currency'] ?? $co->currency);
        $this->realNow = ($opts['now'] ?? now())->copy();
        $this->today = $this->realNow->copy()->setTimezone($this->tz)->startOfDay();

        return $this->muted(function () use ($fn) {
            $guards = $this->actAs($this->owner);
            try {
                return $fn();
            } finally {
                $this->restore($guards);
            }
        });
    }

    /** Delete an account this builder made (only one of the *.demo@local.test owners above). */
    public function remove(string $key): bool
    {
        $email = self::ACCOUNTS[$key]['email'] ?? null;
        $user = $email ? User::withoutGlobalScopes()->where('email', $email)->first() : null;
        if ($user === null) {
            return false;
        }
        $company = Company::withoutGlobalScopes()->find($user->company_id);
        if ($company !== null && (int) $company->owner_id === (int) $user->id) {
            app(TenantDataService::class)->purge((int) $company->id);
        } else {
            DB::table('company_members')->where('user_id', $user->id)->delete();
            DB::table('admin_users')->where('id', $user->id)->delete();
        }
        if ($key === 'wedding') {
            DB::table('admin_users')->where('email', self::TREASURER['email'])->delete();
        }

        return true;
    }

    /** @return array{company_id: int, email: string, password: string, summary: array} */
    private function account(string $key): array
    {
        $a = self::ACCOUNTS[$key];
        $this->remove($key);
        $this->tz = self::TZ;
        $this->rate = 1;
        $this->realNow = null;
        $this->committee = null;
        $this->today = now(self::TZ)->startOfDay();

        // Signed up 40 weeks ago (before the layers came), through the real sign-up.
        Carbon::setTestNow($this->at(self::FARM_AGE + 14, 10));
        $r = app(RegistrationService::class)->register([
            'first_name' => $a['first'], 'last_name' => $a['last'], 'email' => $a['email'], 'password' => self::PASSWORD,
            'company_name' => $a['company'], 'company_phone' => $a['phone'], 'company_address' => $a['address'],
            'currency' => 'UGX', 'country' => 'UG', 'business_type' => $a['type'], 'modules' => $a['modules'], 'timezone' => self::TZ,
        ], RegistrationService::PRODUCT_BUDGET, 'system');
        $this->owner = $r['user']->fresh();
        $co = Company::withoutGlobalScopes()->findOrFail($r['company']->id);
        $co->enabled_modules = $a['modules'];
        $co->business_type = $a['type'];
        $co->timezone = self::TZ;
        $co->negative_stock_policy = 'allow';
        $co->receipt_channels = [];
        $co->payment_methods = ['methods' => ['cash', 'mobile_money', 'credit'], 'momo' => [], 'opening_float' => 0];
        $co->onboarding_state = ['step' => 'done', 'completed_steps' => config('onboarding.steps'), 'skipped_steps' => [], 'completed_at' => now()->toIso8601String(),
            'dismissed_checklist' => true, 'channel' => 'system', 'version' => OnboardingService::STATE_VERSION];
        $co->save();
        // On the Business plan from today, for two years (the real clock, not the sign-up's).
        Carbon::setTestNow(null);
        $co->license_expire = now()->addYears(2);
        $co->saveQuietly();
        if ($plan = Plan::where('slug', 'business')->first()) {
            $co->activateSubscription($plan, 'demo', null, true, 'year');
        }
        $this->co = $co->fresh();

        $guards = $this->actAs($this->owner);
        try {
            $summary = [];
            if ($key === 'all') {
                $summary['shop'] = $this->shop();
            }
            if (in_array('poultry', $a['modules'], true)) {
                // The all-in-one keeps the farm's money in the farm book (not mirrored to the ledger the
                // shop's dashboard reads, which would count the farm's feed but none of its egg sales).
                $summary['farm'] = $this->farm($key !== 'all');
            }
            if (in_array('budget', $a['modules'], true)) {
                $treasurer = $key === 'wedding' ? $this->treasurer() : $this->owner;
                $summary['wedding'] = $this->wedding($treasurer);
            }
        } finally {
            $this->restore($guards);
        }
        Log::info('[family-demo] built', ['account' => $key, 'company_id' => $this->co->id, 'skipped' => count($this->skipped)]);

        return ['company_id' => (int) $this->co->id, 'email' => $a['email'], 'password' => self::PASSWORD, 'summary' => $summary];
    }

    // ── Kuku Tally: the farm ─────────────────────────────────────────────────────────

    /** @return array<string, int|float> the headline figures (as Kuku Tally shows them) */
    private function farm(bool $ledger = true): array
    {
        $plan = self::farmPlan();
        $cid = (int) $this->co->id;
        $by = (string) $this->owner->name;
        $date = fn (int $d) => $this->today->copy()->subDays(self::FARM_AGE - $d)->toDateString();
        $at = function (int $d, int $hour = 19) {
            Carbon::setTestNow($this->past($this->at(self::FARM_AGE - $d, $hour)));
        };
        $new = fn (string $class, array $attrs) => $this->make($class, $attrs, $by);
        $money = fn (int $ugx) => $this->money($ugx);

        $at(0, 8);
        $feeds = [];
        foreach (self::FARM_FEEDS as $k => [$name, $category, $kgPerBag]) {
            $feeds[$k] = $new(PoultryFeedType::class, ['name' => $name, 'category' => $category, 'kg_per_bag' => $kgPerBag]);
        }
        $batches = [
            'layers' => $new(PoultryBatch::class, ['name' => 'Layers House 1', 'type' => 'layer', 'source' => 'Ugachick, Kampala', 'acquired_date' => $date(0),
                'start_count' => self::FARM_PLACED, 'cost_per_chick' => $money(self::FARM_CHICK_PRICE), 'status' => 'laying', 'is_main_farm' => true,
                'notes' => 'Brown layers, deep litter house behind the main house']),
        ];
        $customers = [];
        foreach (self::FARM_CUSTOMERS as $k => [$name, $phone]) {
            $customers[$k] = $new(PoultryCustomer::class, ['name' => $name, 'phone' => $phone, 'notes' => '']);
        }
        $at(self::FARM_BROILER_START, 8);
        $batches['broilers'] = $new(PoultryBatch::class, ['name' => 'Broilers Batch 2', 'type' => 'broiler', 'source' => 'Kakiri hatchery',
            'acquired_date' => $date(self::FARM_BROILER_START), 'start_count' => self::FARM_BROILER_PLACED, 'cost_per_chick' => $money(self::FARM_BROILER_CHICK_PRICE),
            'status' => 'closed', 'is_main_farm' => false, 'notes' => 'Sold at six weeks']);
        $bid = fn (string $k) => $k === '' ? null : (int) $batches[$k]->id;
        $this->ids['farm'] = array_map(fn ($b) => (int) $b->id, $batches);

        foreach ($plan['days'] as $r) {
            $at($r['d'], 19);
            $new(PoultryDailyRecord::class, ['batch_id' => $bid($r['batch']), 'date' => $date($r['d']), 'eggs_trays' => intdiv($r['eggs'], 30), 'eggs_loose' => $r['eggs'] % 30,
                'mortality' => $r['deaths'], 'feed_kg' => $r['feed_kg'], 'water_l' => $r['feed_kg'] * 2, 'notes' => $r['note'],
                'egg_unit_price' => $money($r['egg_price']), 'feed_price_per_kg' => $money($r['feed_price_per_kg']), 'avg_weight_kg' => $r['weight_kg']]);
        }
        foreach ($plan['sales'] as $s) {
            $at($s['d'], 9);
            $total = round($s['qty'] * $money($s['price']), 2);
            $new(PoultrySale::class, ['category' => $s['category'], 'product_label' => $s['label'], 'qty' => $s['qty'], 'unit' => $s['unit'],
                'unit_price' => $money($s['price']), 'total' => $total, 'amount_paid' => $s['paid'] === $s['qty'] * $s['price'] ? $total : $money($s['paid']),
                'customer_id' => $s['customer'] === '' ? null : (int) $customers[$s['customer']]->id, 'batch_id' => $bid($s['batch']), 'date' => $date($s['d']), 'note' => '']);
        }
        // Farm expenses also go to the Finance ledger, as the phone's PoultryFinanceBridge mirrors them.
        $category = $ledger ? FinancialCategory::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'name' => 'Poultry Farm', 'type' => 'Expense'],
            ['status' => 'Active', 'description' => 'Auto-created for Poultry module expenses.']) : null;
        foreach ($plan['expenses'] as $e) {
            $at($e['d'], 17);
            $x = $new(PoultryExpense::class, ['category' => $e['category'], 'feed_type_id' => $e['feed'] === '' ? null : (int) $feeds[$e['feed']]->id,
                'amount' => $money($e['amount']), 'batch_id' => $bid($e['batch']), 'date' => $date($e['d']), 'note' => $e['note']]);
            if ($e['feed'] !== '') {
                $new(PoultryFeedStock::class, ['feed_type_id' => (int) $feeds[$e['feed']]->id, 'direction' => 'in', 'source' => 'purchase', 'qty_kg' => $e['kg'],
                    'cost' => $money($e['amount']), 'batch_id' => $bid($e['batch']), 'ref_uuid' => $x->uuid, 'date' => $date($e['d']), 'note' => '']);
            }
            if ($category) {
                $this->quietly(fn () => FinancialRecord::create(['company_id' => $cid, 'financial_category_id' => $category->id, 'type' => 'Expense', 'amount' => $money($e['amount']),
                    'payment_method' => 'cash', 'description' => $e['note'], 'date' => $date($e['d']), 'created_by_id' => $this->owner->id, 'user_id' => $this->owner->id]));
            }
        }
        foreach ($plan['egg_tx'] as $t) {
            $at($t['d'], 18);
            $new(PoultryEggTransaction::class, ['type' => $t['type'], 'eggs' => $t['eggs'], 'date' => $date($t['d']), 'note' => $t['note']]);
        }
        foreach ($plan['vaccines'] as $v) {
            $d = ($v['batch'] === 'layers' ? 0 : self::FARM_BROILER_START) + $v['age_days'];
            $at(min($d, self::FARM_AGE), 8);
            $new(PoultryVaccinationEvent::class, ['batch_id' => $bid($v['batch']), 'vaccine' => $v['vaccine'], 'method' => $v['method'], 'age_days' => $v['age_days'],
                'withdrawal_days' => 0, 'due_date' => $date($d), 'done' => $v['done'] ? 1 : 0, 'done_date' => $v['done'] ? $date($d) : null, 'note' => '']);
        }
        foreach ($plan['health'] as $h) {
            $at($h['d'], 16);
            $new(PoultryHealthEvent::class, ['batch_id' => $bid('layers'), 'symptoms' => $h['symptoms'], 'diagnosis' => $h['diagnosis'], 'treatment' => $h['treatment'],
                'cost' => $money($h['cost']), 'withdrawal_days' => 0, 'date' => $date($h['d']), 'note' => '']);
        }
        foreach ($plan['mortality'] as $m) {
            $at($m['d'], 7);
            $new(PoultryMortalityEvent::class, ['batch_id' => $bid($m['batch']), 'count' => $m['count'], 'cause' => $m['cause'], 'photo_path' => null,
                'date' => $date($m['d']), 'note' => $m['note']]);
        }
        Carbon::setTestNow(null);

        return self::farmSummary($plan);
    }

    // ── Pledge & Budget: the wedding ─────────────────────────────────────────────────

    /** The committee's treasurer, who receives the money (an accountant on the account). */
    private function treasurer(): User
    {
        Carbon::setTestNow($this->at(self::WEDDING_DAYS_RECORDED + 16, 11));
        PublicDemo::$building = true;
        try {
            $u = app(TeamService::class)->createMember($this->co, $this->owner, self::TREASURER['name'], self::TREASURER['email'], null, self::PASSWORD, 'accountant');
        } finally {
            PublicDemo::$building = false;
        }

        return $u->fresh();
    }

    /** @return array<string, int> the headline figures (as Pledge & Budget shows them) */
    private function wedding(User $treasurer): array
    {
        $cid = (int) $this->co->id;
        Carbon::setTestNow($this->past($this->at(self::WEDDING_DAYS_RECORDED + 16, 20)));
        $program = new BudgetProgram();
        $program->company_id = $cid;
        $program->name = self::WEDDING_NAME;
        $program->title = self::WEDDING_NAME;
        $program->status = 'Active';
        $program->is_active = 'Yes';
        $program->is_default = 'Yes';
        $program->deadline = $this->today->copy()->addDays(self::WEDDING_DAYS_TO_GO)->toDateString();
        $program->save();
        $this->ids['wedding'] = (int) $program->id;

        $c = 0;
        foreach (self::WEDDING_BUDGET as $category => $items) {
            Carbon::setTestNow($this->past($this->at(self::WEDDING_DAYS_RECORDED + 14 - $c++, 20)));
            $cat = new BudgetItemCategory();
            $cat->company_id = $cid;
            $cat->budget_program_id = $program->id;
            $cat->name = $category;
            $cat->save();
            foreach ($items as [$name, $unitPrice, $qty, $paid, $approved]) {
                $item = new BudgetItem();
                $item->company_id = $cid;
                $item->budget_item_category_id = $cat->id;
                $item->budget_program_id = $program->id;
                $item->created_by_id = $this->owner->id;
                $item->name = $name;
                $item->unit_price = $this->whole($unitPrice);
                $item->quantity = $qty;
                $item->invested_amount = $this->whole($paid);
                $item->approved = $approved ? 'Yes' : 'No';
                $item->priority = 'Normal';
                $item->details = '';
                $this->quietly(fn () => $item->save()); // target, balance and the totals above it: the model's own finalizers
            }
        }

        $n = count(self::WEDDING_PLEDGES);
        $extras = new DemoPledgeExtras();
        // With the payment history, each pledge's paid figure is its payments, recorded on their days.
        $history = DemoPledgeExtras::paymentsReady();
        $phones = DemoPledgeExtras::phonesReady();
        $now = $this->realNow ?? now();
        for ($k = 0; $k < $n; $k++) {
            [$category, $name, $amount, $paid] = self::WEDDING_PLEDGES[self::pledgeOrder($k)];
            Carbon::setTestNow($this->past($this->at(self::pledgeDaysAgo($k), $k % 2 === 0 ? 19 : 20, $k % 2 === 0 ? 30 : 10)));
            [$amount, $paid] = [$this->whole($amount), $this->whole($paid)];
            $p = new ContributionRecord();
            $p->budget_program_id = $program->id;
            $p->company_id = $cid;
            $p->treasurer_id = $treasurer->id;
            $p->name = $name;
            $p->category_id = $category;
            $p->amount = $amount;
            $p->paid_amount = $history ? 0 : $paid;
            $p->fully_paid = ! $history && $paid >= $amount ? 'Yes' : 'No';
            if ($phones) {
                $p->phone = DemoPledgeExtras::phoneFor($name);
            }
            $this->quietly(fn () => $p->save()); // not paid, fully paid and the program's totals: the model's own finalizers
            if ($history && $p->exists && ! $extras->pay($cid, (int) $p->id, $paid, $k, (int) $treasurer->id, Carbon::now(), $now)) {
                $p->refresh();
                $p->paid_amount = $paid; // the older way: the model posts the difference
                $this->quietly(fn () => $p->save());
            }
        }
        Carbon::setTestNow(null);
        BudgetProgram::recalculateFromChildren((int) $program->id);
        // The committee (and phones on any pledge without one), once those tables exist.
        $committee = $this->committee ?? [(int) $this->owner->id => 'chair'] + [(int) $treasurer->id => 'treasurer'];
        $this->skipped = array_merge($this->skipped, $extras->apply((int) $program->id, (int) $this->owner->id, $committee));

        return self::weddingSummary();
    }

    // ── Budget Pro: a small shop beside the farm and the wedding ────────────────────

    /** Rose's Corner Shop (the phone pictures' shop): its products, regulars, a delivery and a month of trading. */
    private function shop(): array
    {
        $cid = (int) $this->co->id;
        $uid = (int) $this->owner->id;
        $days = 29; // a month of trading inside the dashboard's 30-day window (nothing before it to compare with)
        $realNow = now(); // sales are made up to this minute, never later
        mt_srand(20261017);
        Carbon::setTestNow($this->at($days + 1, 8));
        $rows = [];
        foreach (self::SHOP_PRODUCTS as [$name, $category, $sub, $unit, $sell, $buy, $qty]) {
            $rows[] = ['name' => $name, 'category' => $category, 'sub_category' => $sub, 'unit' => $unit, 'selling_price' => $sell, 'buying_price' => $buy,
                'opening_stock' => $qty, 'barcode' => null, 'sku' => null];
        }
        app(OnboardingService::class)->createProducts($this->co, $this->owner, $rows);
        $products = DB::table('stock_items')->where('company_id', $cid)->where('is_deleted', false)->orderBy('id')->get(['id', 'name', 'selling_price'])->all();
        $customers = [];
        foreach ([['Amina Nakato', '0772456789', 200000], ['John Okello', '0701234567', 0], ['Sarah Namubiru', '0752987654', 100000],
            ['Grace Atim', '0783111222', 0], ['Peter Ssemakula', '0706555444', 0], ['Mama Joy Restaurant', '0774909090', 500000]] as [$name, $phone, $limit]) {
            $customers[] = Customer::create(['company_id' => $cid, 'name' => $name, 'phone' => $phone, 'credit_limit' => $limit, 'is_active' => true,
                'reminders_enabled' => false, 'created_by_id' => $uid]);
        }
        $supplier = Supplier::create(['company_id' => $cid, 'name' => 'Kikuubo Wholesalers', 'phone' => '0414 344 120', 'payment_terms_days' => 14, 'is_active' => true, 'created_by_id' => $uid]);

        $sales = new SaleService();
        $count = 0;
        $credit = [];
        for ($d = $days; $d >= 0; $d--) {
            if ($d === 14) { // a delivery from the wholesaler, part paid (the rest on its 14 days' terms)
                Carbon::setTestNow($this->at($d, 9, 15));
                $lines = [];
                foreach (array_slice($products, 0, 8) as $p) {
                    $buy = collect(self::SHOP_PRODUCTS)->firstWhere(0, $p->name)[5];
                    $lines[] = ['stock_item_id' => (int) $p->id, 'quantity' => $buy <= 2000 ? 48 : 12, 'unit_cost' => $buy];
                }
                $value = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_cost'], $lines));
                $this->quietly(fn () => app(GoodsReceiptService::class)->receive($cid, $uid, $lines, (int) $supplier->id, 'KW-20431', round($value * 0.6, -3), 'cash',
                    $this->today->copy()->subDays($d)->toDateString()));
            }
            $per = $d === 0 ? 9 : mt_rand(10, 15);
            for ($n = 0; $n < $per; $n++) {
                $when = $this->at($d, 8 + intdiv($n * 12, $per), mt_rand(0, 59));
                if ($when->greaterThan($realNow)) {
                    continue;
                }
                Carbon::setTestNow($when);
                $items = [];
                $total = 0.0;
                foreach ((array) array_rand($products, mt_rand(1, 4)) as $i) {
                    $p = $products[$i];
                    $q = (float) $p->selling_price <= 2500 ? mt_rand(1, 4) : 1;
                    $items[] = ['stock_item_id' => (int) $p->id, 'quantity' => $q];
                    $total += $q * (float) $p->selling_price;
                }
                $roll = mt_rand(1, 100);
                $data = ['items' => $items, 'payments_explicit' => true, 'sale_date' => $when->copy()->setTimezone(self::TZ)->toDateString(), 'allow_negative_stock' => true,
                    'client_uuid' => (string) Str::uuid()];
                if ($roll <= 7 && $d > 1) { // on credit: the regulars with a credit limit
                    $data['customer_id'] = (int) $customers[[0, 2, 5][mt_rand(0, 2)]]->id;
                    $data['payments'] = mt_rand(0, 1) ? [] : [['method' => 'cash', 'amount' => round($total / 2, -2)]];
                } else {
                    $data['payments'] = [['method' => $roll <= 35 ? 'mobile_money' : 'cash', 'amount' => $total]];
                }
                $sale = $this->quietly(fn () => $sales->checkout($cid, $uid, $data)['sale']);
                if ($sale instanceof SaleRecord) {
                    $count++;
                    if ((float) $sale->balance > 0) {
                        $credit[] = [$sale, $d];
                    }
                }
            }
        }
        foreach (array_slice($credit, 0, (int) ceil(count($credit) / 2)) as [$sale, $d]) {
            Carbon::setTestNow($this->at(max(0, $d - 3), 16, 30));
            $customer = Customer::withoutGlobalScopes()->find($sale->customer_id);
            if ($customer) {
                $this->quietly(fn () => app(CustomerService::class)->receivePayment($customer, max(500, round((float) $sale->fresh()->balance / 2, -2)), 'cash', $uid));
            }
        }
        foreach ([['Rent', 'Shop rent for the month', 300000, 16, 'bank'], ['Transport', 'Boda to Owino market for stock', 15000, 0, 'cash'],
            ['Electricity (Yaka)', 'Yaka token for the shop', 50000, 3, 'mobile_money'], ['Wages', 'Weekly pay: shop attendant', 80000, 1, 'mobile_money'],
            ['Wages', 'Weekly pay: shop attendant', 80000, 8, 'mobile_money'], ['Wages', 'Weekly pay: shop attendant', 80000, 15, 'mobile_money'],
            ['Wages', 'Weekly pay: shop attendant', 80000, 22, 'mobile_money'], ['Transport', 'Delivery to Mama Joy Restaurant', 8000, 5, 'cash'],
            ['Transport', 'Boda to Kikuubo for the delivery', 12000, 14, 'cash']] as [$cat, $what, $amount, $ago, $method]) {
            Carbon::setTestNow($this->at($ago, 17, 30));
            $category = FinancialCategory::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'name' => $cat, 'type' => 'Expense'], ['status' => 'Active']);
            $this->quietly(fn () => FinancialRecord::create(['company_id' => $cid, 'financial_category_id' => $category->id, 'type' => 'Expense', 'amount' => $amount,
                'payment_method' => $method, 'description' => $what, 'date' => $this->today->copy()->subDays($ago)->toDateString(), 'created_by_id' => $uid, 'user_id' => $uid]));
        }
        Carbon::setTestNow(null);

        return ['products' => count($products), 'sales' => $count];
    }

    /** name, category, sub-category, unit, selling price, buying price, opening stock (shop_fixture.dart's shop). */
    public const SHOP_PRODUCTS = [
        ['Coca-Cola 500ml', 'Drinks', 'Sodas', 'bottle', 2000, 1500, 260],
        ['Fanta Orange 500ml', 'Drinks', 'Sodas', 'bottle', 2000, 1500, 260],
        ['Stoney Tangawizi 500ml', 'Drinks', 'Sodas', 'bottle', 2000, 1500, 260],
        ['Rwenzori Water 1L', 'Drinks', 'Water & juice', 'bottle', 1500, 1000, 260],
        ['Minute Maid Mango 400ml', 'Drinks', 'Water & juice', 'bottle', 2500, 1800, 260],
        ['Kakira Sugar 1kg', 'Groceries', 'Flour, sugar & rice', 'packet', 5500, 4800, 110],
        ['Maize Flour 2kg', 'Groceries', 'Flour, sugar & rice', 'packet', 6000, 5000, 110],
        ['Super Rice 1kg', 'Groceries', 'Flour, sugar & rice', 'packet', 5000, 4200, 110],
        ['Mukwano Cooking Oil 1L', 'Groceries', 'Cooking oil', 'bottle', 8500, 7500, 110],
        ['Fresh Dairy Milk 500ml', 'Groceries', 'Dairy & eggs', 'piece', 2000, 1600, 260],
        ['Eggs (tray of 30)', 'Groceries', 'Dairy & eggs', 'piece', 12000, 10500, 110],
        ['Blue Band 250g', 'Groceries', 'Dairy & eggs', 'piece', 4500, 3800, 110],
        ['Omo Washing Powder 500g', 'Household', 'Soap & detergent', 'piece', 6500, 5400, 110],
        ['Geisha Bathing Soap', 'Household', 'Soap & detergent', 'piece', 2500, 2000, 260],
        ['Hot Loaf Bread 500g', 'Snacks & bakery', 'Bread & biscuits', 'piece', 4500, 3800, 110],
        ['Nice Biscuits 100g', 'Snacks & bakery', 'Bread & biscuits', 'piece', 1000, 700, 260],
    ];

    // ── Helpers ─────────────────────────────────────────────────────────────────────

    /** $daysAgo days before today at $hour:$minute in Kampala, as UTC (the app's clock). */
    private function at(int $daysAgo, int $hour, int $minute = 0): Carbon
    {
        return $this->today->copy()->subDays($daysAgo)->setTime($hour, $minute)->utc();
    }

    /** Never after the real "now" when seeding a live account (the family accounts keep the evening's figures). */
    private function past(Carbon $t): Carbon
    {
        return $this->realNow !== null && $t->greaterThan($this->realNow) ? $this->realNow->copy() : $t;
    }

    /** A UGX amount in the account's currency, to the cent. */
    private function money(int|float $ugx): float
    {
        return $this->rate === 1 ? (float) $ugx : round($ugx / $this->rate, 2);
    }

    /** A UGX amount in the account's currency, in whole units (the budget tables keep whole numbers). */
    private function whole(int $ugx): int
    {
        return $this->rate === 1 ? $ugx : (int) round($ugx / $this->rate);
    }

    /** One farm row through its model (so it carries the uuid and sync fields the phone needs). */
    private function make(string $class, array $attrs, string $by): mixed
    {
        $m = new $class();
        foreach ($attrs + ['company_id' => (int) $this->co->id, 'entered_by' => $by, 'is_deleted' => 0, 'version' => 1] as $k => $v) {
            $m->{$k} = $v;
        }
        $m->save();

        return $m;
    }

    /** One refused entry (a rule that bites on sample data) must not stop the rest. */
    private function quietly(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (BusinessRuleException|\DomainException|\InvalidArgumentException $e) {
            $this->skipped[] = $e->getMessage();
            Log::info('[family-demo] sample entry skipped: '.$e->getMessage());

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

    // ── The farm plan: FarmPlan in budget-pro-mobo test/screenshots/family_fixture.dart, line for line ──
    // Day d counts from the day the layers came (d = 0) to today (d = FARM_AGE).

    public const FARM_AGE = 266; // the layers are 38 weeks old today

    public const FARM_PLACED = 320;

    public const FARM_CHICK_PRICE = 3600;

    public const FARM_BROILER_START = 150;

    public const FARM_BROILER_DAYS = 42;

    public const FARM_BROILER_PLACED = 150;

    public const FARM_BROILER_CHICK_PRICE = 2800;

    public const FARM_RESERVE_EGGS = 240; // keep about 8 trays in the store, sell the rest

    public const FARM_LAY_FEED_LEFT_KG = 170; // about five days of layers mash left today

    /** key => [name, category, kg per bag, bag price] */
    public const FARM_FEEDS = [
        'chick' => ['Chick Mash', 'chick_mash', 50, 82500],
        'grower' => ['Growers Mash', 'growers_mash', 70, 94500],
        'layer' => ['Layers Mash', 'layers_mash', 70, 101500],
        'bstarter' => ['Broiler Starter', 'broiler_starter', 50, 100000],
        'bfinisher' => ['Broiler Finisher', 'broiler_finisher', 50, 95000],
    ];

    public const FARM_CUSTOMERS = [
        'brenda' => ['Mama Brenda (Mukono market)', '0772 418 236'],
        'nakato' => ['Nakato Grocery, Seeta', '0701 562 904'],
        'bakery' => ['Ssebunya Bakery', '0758 330 412'],
        'hillside' => ['Hillside Guest House', '0783 904 115'],
        'mukasa' => ['Mukasa Tomato Farm, Nakifuma', '0704 221 873'],
        'seeta' => ['Seeta Poultry Traders', '0776 640 512'],
    ];

    /** Grams of feed per bird per day by week of age (layers); 112 g from week 26. */
    public const FARM_LAYER_INTAKE = [10, 16, 22, 28, 34, 39, 44, 48, 52, 56, 59, 62, 65, 68, 71, 74, 77, 80, 86, 92, 98, 103, 108, 108, 108, 108];

    /** Hen-day lay rate (%) from week 18 to 38. */
    public const FARM_LAY = [4, 15, 36, 58, 74, 84, 88, 90, 90, 90, 90, 89, 88, 87, 86, 85, 84, 82, 81, 79, 78];

    public const FARM_WOBBLE = [0, 2, -3, 1, -1, 3, -2];

    /** d % 7 => who buys eggs that morning, and how many trays they want ('' = walk-in). */
    public const FARM_SCHEDULE = [
        0 => [['brenda', 8], ['hillside', 4]],
        1 => [['nakato', 6]],
        2 => [['brenda', 8]],
        3 => [['bakery', 10]],
        4 => [['nakato', 6], ['brenda', 8]],
        5 => [['brenda', 8]],
        6 => [['', 3]],
    ];

    public const FARM_DEATHS = [3 => 4, 24 => 3, 97 => 2, 181 => 2, 238 => 1];

    public const FARM_ROUTINE_DEATHS = [58 => 1, 143 => 1, 209 => 1];

    public const FARM_BROILER_INTAKE = [22, 50, 85, 120, 150, 172];

    public const FARM_BROILER_DEATHS = [2 => 2, 5 => 1, 16 => 1, 30 => 2];

    public const FARM_BROILER_WEIGHTS = [7 => 0.18, 14 => 0.45, 21 => 0.85, 28 => 1.32, 35 => 1.80, 41 => 2.20];

    /** The phone's layer and broiler vaccination programmes (lib/poultry/data/entities/vaccination.dart): [vaccine, method, age in days]. */
    public const FARM_LAYER_PROGRAM = [['Marek (at hatchery)', 'injection', 1], ['Newcastle + IB (NDV/IB)', 'eye drop', 7], ['Gumboro (IBD)', 'drinking water', 14],
        ['Gumboro (IBD) booster', 'drinking water', 21], ['Newcastle (Lasota)', 'drinking water', 28], ['Fowl Typhoid', 'injection', 42], ['Fowl Pox', 'wing stab', 56],
        ['Deworming', 'drinking water', 63], ['Newcastle (Lasota) booster', 'drinking water', 84], ['Fowl Typhoid booster', 'injection', 112]];

    public const FARM_BROILER_PROGRAM = [['Marek (at hatchery)', 'injection', 1], ['Newcastle + IB (NDV/IB)', 'eye drop', 7], ['Gumboro (IBD)', 'drinking water', 14],
        ['Gumboro (IBD) booster', 'drinking water', 21], ['Newcastle (Lasota)', 'drinking water', 28]];

    public static function farmIntake(int $w): int
    {
        return $w < count(self::FARM_LAYER_INTAKE) ? self::FARM_LAYER_INTAKE[$w] : 112;
    }

    public static function farmLayPct(int $w): int
    {
        return $w < 18 ? 0 : self::FARM_LAY[max(0, min($w - 18, count(self::FARM_LAY) - 1))];
    }

    public static function farmPricePerKg(string $feed): int
    {
        return intdiv(self::FARM_FEEDS[$feed][3], self::FARM_FEEDS[$feed][2]);
    }

    /**
     * @return array{days: list<array>, sales: list<array>, expenses: list<array>, vaccines: list<array>, health: list<array>, mortality: list<array>, egg_tx: list<array>}
     */
    public static function farmPlan(): array
    {
        $days = $sales = $expenses = $vaccines = $eggTx = [];
        $day = fn (string $batch, int $d) => ['batch' => $batch, 'd' => $d, 'alive' => 0, 'eggs' => 0, 'deaths' => 0, 'feed_kg' => 0, 'egg_price' => 0,
            'feed_price_per_kg' => 0, 'weight_kg' => 0.0, 'note' => ''];
        $sale = fn (int $d, string $customer, string $category, int $qty, string $unit, int $price, string $batch, string $label = '') => [
            'd' => $d, 'customer' => $customer, 'category' => $category, 'qty' => $qty, 'unit' => $unit, 'price' => $price, 'batch' => $batch, 'label' => $label, 'paid' => $qty * $price];
        $expense = fn (int $d, string $category, int $amount, string $batch, string $note, string $feed = '', int $kg = 0) => [
            'd' => $d, 'category' => $category, 'amount' => $amount, 'batch' => $batch, 'note' => $note, 'feed' => $feed, 'kg' => $kg];
        // Spreads a phase's rounding (to whole bags) over its last days, 1 kg a day.
        $toBags = function (array &$rows, int $from, int $to, int $kgPerBag): int {
            $total = 0;
            for ($i = $from; $i <= $to; $i++) {
                $total += $rows[$i]['feed_kg'];
            }
            $bags = intdiv($total + intdiv($kgPerBag, 2), $kgPerBag);
            $diff = $bags * $kgPerBag - $total;
            for ($i = $to; $diff !== 0 && $i >= $from; $i--) {
                $rows[$i]['feed_kg'] += $diff > 0 ? 1 : -1;
                $diff += $diff > 0 ? -1 : 1;
            }

            return $bags;
        };
        $buy = function (string $batch, string $feed, int $bags, array $onDays) use (&$expenses, $expense) {
            [$name, , $kgPerBag, $bagPrice] = self::FARM_FEEDS[$feed];
            $n = count($onDays);
            foreach ($onDays as $i => $d) {
                $b = intdiv($bags, $n) + ($i < $bags % $n ? 1 : 0);
                if ($b > 0) {
                    $expenses[] = $expense($d, 'feed', $b * $bagPrice, $batch, "{$b} bags {$name} ({$kgPerBag} kg)", $feed, $b * $kgPerBag);
                }
            }
        };

        // ── Layers House 1 ──
        $alive = self::FARM_PLACED;
        $store = 0;
        for ($d = 0; $d <= self::FARM_AGE; $d++) {
            $w = intdiv($d, 7);
            if ($w >= 18) {
                foreach (self::FARM_SCHEDULE[$d % 7] as [$c, $want]) {
                    $q = min($want, intdiv($store - self::FARM_RESERVE_EGGS, 30));
                    if ($q <= 0) {
                        continue;
                    }
                    $price = $w < 21 ? 10000 : ($c === 'bakery' ? 11500 : 12000);
                    $sales[] = $sale($d, $c, 'eggs', $q, 'tray', $price, 'layers', $w < 21 ? 'Small (pullet) eggs' : ($c === 'bakery' ? 'Bulk price' : ''));
                    $store -= $q * 30;
                }
            }
            $r = $day('layers', $d);
            $r['deaths'] = self::FARM_ROUTINE_DEATHS[$d] ?? 0;
            $alive -= (self::FARM_DEATHS[$d] ?? 0) + $r['deaths'];
            $r['alive'] = $alive;
            if ($w >= 18) {
                $r['eggs'] = max(0, intdiv(self::farmLayPct($w) * $alive + 50, 100) + ($w >= 20 ? self::FARM_WOBBLE[$d % 7] : 0));
                $r['egg_price'] = $w < 21 ? 10000 : 12000;
            }
            $store += $r['eggs'];
            if ($w >= 20 && $d % 7 === 3) {
                $b = 5 + $d % 4;
                $store -= $b;
                $eggTx[] = ['d' => $d, 'type' => 'broken', 'eggs' => -$b, 'note' => 'Cracked at collection'];
            }
            if ($w >= 21 && $d % 7 === 6) {
                $store -= 30;
                $eggTx[] = ['d' => $d, 'type' => 'consumption', 'eggs' => -30, 'note' => 'A tray for the house'];
            }
            $r['feed_kg'] = intdiv(self::farmIntake($w) * $alive + 500, 1000);
            $r['feed_price_per_kg'] = self::farmPricePerKg($w < 8 ? 'chick' : ($w < 18 ? 'grower' : 'layer'));
            $days[] = $r;
        }
        $chickBags = $toBags($days, 0, 55, 50);
        $growerBags = $toBags($days, 56, 125, 70);
        $layUsed = 0;
        for ($i = 126; $i <= self::FARM_AGE; $i++) {
            $layUsed += $days[$i]['feed_kg'];
        }
        $layerBags = intdiv($layUsed + self::FARM_LAY_FEED_LEFT_KG + 69, 70);
        $buy('layers', 'chick', $chickBags, [0, 21, 42]);
        $buy('layers', 'grower', $growerBags, [56, 77, 98, 119]);
        $buy('layers', 'layer', $layerBags, array_map(fn ($k) => 126 + 14 * $k, range(0, 9)));
        $days[3]['note'] = 'Power cut at night, brooder lamp went off';
        $days[126]['note'] = 'Moved to layers mash; first eggs this week';

        foreach ([120, 160, 200, 240] as $d) {
            $sales[] = $sale($d, 'mukasa', 'manure', 12, 'bag', 5000, 'layers');
        }
        // Credit: the bakery's last two deliveries and the grocery's last one are not fully paid.
        $bakery = array_keys(array_filter($sales, fn ($s) => $s['customer'] === 'bakery'));
        $sales[end($bakery)]['paid'] = 0;
        $sales[$bakery[count($bakery) - 2]]['paid'] = 60000;
        $grocery = array_keys(array_filter($sales, fn ($s) => $s['customer'] === 'nakato'));
        $g = end($grocery);
        $sales[$g]['paid'] = intdiv($sales[$g]['qty'] * $sales[$g]['price'], 2000) * 1000; // half now, half next week

        // Vaccines: the layer programme, all given on time, and the 3-monthly Newcastle boosters.
        $layerCosts = [0, 18000, 15000, 15000, 15000, 40000, 30000, 20000, 15000, 40000];
        foreach (self::FARM_LAYER_PROGRAM as $i => [$vaccine, $method, $age]) {
            $vaccines[] = ['batch' => 'layers', 'vaccine' => $vaccine, 'method' => $method, 'age_days' => $age, 'cost' => $layerCosts[$i], 'done' => true];
        }
        $vaccines[] = ['batch' => 'layers', 'vaccine' => 'Newcastle booster', 'method' => 'drinking water', 'age_days' => 196, 'cost' => 15000, 'done' => true];
        $vaccines[] = ['batch' => 'layers', 'vaccine' => 'Newcastle booster', 'method' => 'drinking water', 'age_days' => self::FARM_AGE, 'cost' => 15000, 'done' => false];

        $health = [
            ['d' => 24, 'symptoms' => 'Bloody droppings, chicks huddling', 'diagnosis' => 'Coccidiosis', 'treatment' => 'Amprolium in drinking water for 5 days', 'cost' => 45000],
            ['d' => 150, 'symptoms' => 'Panting and eating less on hot afternoons', 'diagnosis' => 'Heat stress', 'treatment' => 'Vitamins and electrolytes in water for 3 days', 'cost' => 25000],
            ['d' => 236, 'symptoms' => 'Pale combs, birds restless at night', 'diagnosis' => 'Red mites', 'treatment' => 'Sprayed the house and nests, changed the litter', 'cost' => 35000],
        ];
        $mortality = [
            ['batch' => 'layers', 'd' => 3, 'count' => 4, 'cause' => 'Cold stress', 'note' => 'Power went off at night and the brooder got cold'],
            ['batch' => 'layers', 'd' => 24, 'count' => 3, 'cause' => 'Coccidiosis', 'note' => 'Bloody droppings; treated with Amprolium'],
            ['batch' => 'layers', 'd' => 97, 'count' => 2, 'cause' => 'Predator (wild cat)', 'note' => 'Got in through a gap in the wire mesh'],
            ['batch' => 'layers', 'd' => 181, 'count' => 2, 'cause' => 'Prolapse', 'note' => 'Two hens with vent prolapse'],
            ['batch' => 'layers', 'd' => 238, 'count' => 1, 'cause' => 'Heat stress', 'note' => 'Very hot afternoon'],
        ];

        // ── Broilers Batch 2: 150 chicks on day 150, sold at six weeks ──
        $balive = self::FARM_BROILER_PLACED;
        $bdays = [];
        for ($b = 0; $b < self::FARM_BROILER_DAYS; $b++) {
            $r = $day('broilers', self::FARM_BROILER_START + $b);
            $r['deaths'] = self::FARM_BROILER_DEATHS[$b] ?? 0;
            $balive -= $r['deaths'];
            $r['alive'] = $balive;
            $r['feed_kg'] = intdiv(self::FARM_BROILER_INTAKE[intdiv($b, 7)] * $balive + 500, 1000);
            $r['feed_price_per_kg'] = self::farmPricePerKg($b < 21 ? 'bstarter' : 'bfinisher');
            $r['weight_kg'] = self::FARM_BROILER_WEIGHTS[$b] ?? 0.0;
            $bdays[] = $r;
        }
        $starterBags = $toBags($bdays, 0, 20, 50);
        $finisherBags = $toBags($bdays, 21, self::FARM_BROILER_DAYS - 1, 50);
        array_push($days, ...$bdays);
        $buy('broilers', 'bstarter', $starterBags, [self::FARM_BROILER_START]);
        $buy('broilers', 'bfinisher', $finisherBags, [self::FARM_BROILER_START + 21]);
        $sales[] = $sale(self::FARM_BROILER_START + self::FARM_BROILER_DAYS, 'seeta', 'broilers', 120, 'bird', 15500, 'broilers');
        $sales[] = $sale(self::FARM_BROILER_START + self::FARM_BROILER_DAYS + 1, '', 'broilers', $balive - 120, 'bird', 17000, 'broilers', 'Sold at the farm gate');
        $broilerCosts = [0, 10000, 8000, 8000, 8000];
        foreach (self::FARM_BROILER_PROGRAM as $i => [$vaccine, $method, $age]) {
            $vaccines[] = ['batch' => 'broilers', 'vaccine' => $vaccine, 'method' => $method, 'age_days' => $age, 'cost' => $broilerCosts[$i], 'done' => true];
        }

        // ── Other money out ──
        $expenses[] = $expense(0, 'brooding', 60000, 'layers', 'Charcoal and wood shavings for brooding');
        $expenses[] = $expense(self::FARM_BROILER_START, 'brooding', 45000, 'broilers', 'Charcoal and shavings for the broiler brooder');
        foreach ($vaccines as $v) {
            if ($v['done'] && $v['cost'] > 0) {
                $expenses[] = $expense(($v['batch'] === 'layers' ? 0 : self::FARM_BROILER_START) + $v['age_days'], 'drugs_vaccines', $v['cost'], $v['batch'], $v['vaccine']);
            }
        }
        foreach ($health as $h) {
            $expenses[] = $expense($h['d'], 'drugs_vaccines', $h['cost'], 'layers', "{$h['diagnosis']} treatment");
        }
        // Farm-wide costs (no batch): they add up to the chicks' price (UGX 1,572,000), so Kuku Tally's
        // Reports screen's farm net profit (which leaves the chicks out) equals the sum of the batch
        // profits (which leave these out).
        $expenses[] = $expense(0, 'equipment', 132000, '', 'Feeders, drinkers and a brooder lamp');
        $expenses[] = $expense(118, 'equipment', 80000, '', 'Laying nests and egg trays');
        for ($d = 30; $d <= self::FARM_AGE; $d += 30) {
            $expenses[] = $expense($d, 'labor', 150000, '', 'Wages: Musoke (farm hand)');
        }
        for ($d = 22 * 7; $d <= self::FARM_AGE; $d++) {
            if ($d % 7 === 1) {
                $expenses[] = $expense($d, 'transport', 10000, '', 'Boda: eggs to Mukono town');
            }
        }

        return ['days' => $days, 'sales' => $sales, 'expenses' => $expenses, 'vaccines' => $vaccines, 'health' => $health, 'mortality' => $mortality, 'egg_tx' => $eggTx];
    }

    /** The figures Kuku Tally shows for the plan (the phone harness writes the same keys to family-figures.json). */
    public static function farmSummary(?array $plan = null): array
    {
        $plan ??= self::farmPlan();
        $today = null;
        foreach ($plan['days'] as $r) {
            if ($r['batch'] === 'layers' && $r['d'] === self::FARM_AGE) {
                $today = $r;
            }
        }
        $sum = fn (array $rows, callable $f) => array_sum(array_map($f, $rows));
        $income = fn (?string $b) => $sum(array_filter($plan['sales'], fn ($s) => $b === null || $s['batch'] === $b), fn ($s) => $s['qty'] * $s['price']);
        $spent = fn (?string $b) => $sum(array_filter($plan['expenses'], fn ($e) => $b === null || $e['batch'] === $b), fn ($e) => $e['amount']);
        $produced = $sum($plan['days'], fn ($r) => $r['eggs']);
        $sold = $sum(array_filter($plan['sales'], fn ($s) => $s['category'] === 'eggs'), fn ($s) => $s['qty'] * 30);
        $out = $sum($plan['egg_tx'], fn ($t) => $t['eggs']);
        $bought = $sum($plan['expenses'], fn ($e) => $e['kg']);
        $eaten = $sum($plan['days'], fn ($r) => $r['feed_kg']);
        $layersCost = $spent('layers') + self::FARM_PLACED * self::FARM_CHICK_PRICE;
        $broilersCost = $spent('broilers') + self::FARM_BROILER_PLACED * self::FARM_BROILER_CHICK_PRICE;

        return [
            'hens_alive' => $today['alive'],
            'eggs_today' => $today['eggs'],
            'lay_rate_pct' => intdiv($today['eggs'] * 1000, $today['alive']) / 10,
            'tray_price' => 12000,
            'egg_store_eggs' => $produced - $sold + $out,
            'eggs_produced' => $produced,
            'feed_left_kg' => $bought - $eaten,
            'income_layers' => $income('layers'),
            'costs_layers' => $layersCost,
            'profit_layers' => $income('layers') - $layersCost,
            'income_broilers' => $income('broilers'),
            'costs_broilers' => $broilersCost,
            'profit_broilers' => $income('broilers') - $broilersCost,
            'farm_income' => $income(null),
            'farm_expenses_recorded' => $spent(null),
            'customers_owe' => $sum($plan['sales'], fn ($s) => $s['qty'] * $s['price'] - $s['paid']),
        ];
    }

    // ── The wedding plan: WeddingPlan in family_fixture.dart, line for line ──

    public const WEDDING_NAME = 'Brian & Grace Wedding';

    public const WEDDING_DAYS_TO_GO = 77; // 11 weeks today

    public const WEDDING_DAYS_RECORDED = 70; // pledges came in over the last ten weeks

    /** [category, name, pledged, paid]: 60 pledges, UGX 18,400,000 pledged, 11,250,000 paid. */
    public const WEDDING_PLEDGES = [
        ['Family', 'Mr. & Mrs. Tumusiime Fred', 2000000, 2000000],
        ['Family', 'Mr. & Mrs. Ssemwanga Joseph', 2000000, 1000000],
        ['Family', 'Aunt Kyomuhendo Annet', 500000, 500000],
        ['Family', 'Uncle Mugisha Patrick', 800000, 500000],
        ['Family', 'Hajji Ssali Abdul', 1000000, 0],
        ['Family', 'Nalongo Nakimuli Sarah', 300000, 300000],
        ['Family', 'Kakuru Edgar', 1000000, 500000],
        ['Family', 'Natukunda Doreen', 300000, 300000],
        ['Family', 'Ssempala Ivan', 300000, 300000],
        ['Family', 'Nabukenya Juliet', 200000, 0],
        ['Family', 'Aunt Nabirye Florence', 300000, 300000],
        ['Family', 'Uncle Byaruhanga Simon', 1000000, 0],
        ['Family-2', 'Ainembabazi Brenda', 150000, 150000],
        ['Family-2', 'Lubega Moses', 200000, 100000],
        ['Family-2', 'Namuli Esther', 100000, 100000],
        ['Family-2', 'Atuhaire Collins', 150000, 0],
        ['Friend', 'Okello James', 300000, 300000],
        ['Friend', 'Nansubuga Prossy', 200000, 200000],
        ['Friend', 'Wasswa Derrick', 200000, 100000],
        ['Friend', 'Achieng Mercy', 150000, 150000],
        ['Friend', 'Kiggundu Ronald', 500000, 0],
        ['Friend', 'Nakayima Rebecca', 100000, 100000],
        ['Friend', 'Mutebi Allan', 200000, 200000],
        ['Friend', 'Akello Joyce', 100000, 50000],
        ['Friend', 'Opio Samuel', 150000, 0],
        ['Friend', 'Nalwoga Sharon', 100000, 100000],
        ['Friend', 'Twinomujuni Paul', 200000, 200000],
        ['Friend', 'Nakitende Agnes', 100000, 0],
        ['Friend-2', 'Ochieng Dennis', 150000, 150000],
        ['Friend-2', 'Kasozi Henry', 200000, 100000],
        ['Friend-2', 'Asiimwe Gloria', 100000, 100000],
        ['Friend-2', 'Muwonge Isaac', 150000, 0],
        ['Friend-2', 'Nambi Christine', 100000, 100000],
        ['Friend-2', 'Tugume Arnold', 200000, 200000],
        ['Workmates', 'Kiwanuka Peter', 200000, 200000],
        ['Workmates', 'Nakalema Faith', 100000, 100000],
        ['Workmates', 'Ssekandi Martin', 150000, 50000],
        ['Workmates', 'Auma Patricia', 100000, 0],
        ['Workmates', 'Bbosa Julius', 200000, 200000],
        ['Workmates', 'Nantongo Winnie', 100000, 100000],
        ['Workmates', 'Mwesigwa Ronald', 150000, 0],
        ['Workmates', 'Kobusingye Ruth', 100000, 100000],
        ['Workmates', "Grace's office (finance team)", 800000, 500000],
        ['OBs_and_OGs', 'Lwanga Emmanuel', 100000, 100000],
        ['OBs_and_OGs', 'Nakato Diana', 100000, 0],
        ['OBs_and_OGs', 'Ssenyonga Tonny', 150000, 150000],
        ['OBs_and_OGs', 'Kabuye Andrew', 100000, 50000],
        ['OBs_and_OGs', 'Namirembe Lillian', 100000, 100000],
        ['OBs_and_OGs', 'Class of 2012 (old students)', 600000, 600000],
        ['Others', 'Church choir', 300000, 300000],
        ['Others', 'Rev. Canon Mukiibi Stephen', 200000, 200000],
        ['Others', "Mothers' Union", 500000, 0],
        ['Others', 'Mzee Kizza Yowasi', 100000, 100000],
        ['Others', 'Mama Shamim', 50000, 50000],
        ['Others', 'Nsubuga Joseph', 200000, 0],
        ['Others', 'Kawooya Godfrey', 100000, 0],
        ['Others', 'Bukenya Hassan', 150000, 150000],
        ['Others', 'Nanteza Rose', 100000, 50000],
        ['Others', 'Mugerwa Fred', 50000, 50000],
        ['Others', 'Kyambadde Moses', 100000, 0],
    ];

    /** category => [[item, unit price, quantity, paid so far, approved]]: UGX 20,500,000 budgeted, 8,510,000 paid. */
    public const WEDDING_BUDGET = [
        'Venue' => [['Garden hire, Mukono', 2500000, 1, 1500000, true], ['Tents, chairs and tables (300 guests)', 1800000, 1, 0, true]],
        'Food' => [['Catering, buffet for 300 guests', 15000, 300, 2000000, true]],
        'Drinks' => [['Sodas and water (crates)', 22000, 60, 0, false], ['Juice for the high table (crates)', 28000, 10, 0, false]],
        'Decor' => [['Decor package: flowers, drapes and lights', 1600000, 1, 800000, true]],
        'Attire' => [['Wedding gown', 1200000, 1, 1200000, true], ["Groom's suit", 650000, 1, 650000, true],
            ["Bridesmaids' dresses", 120000, 6, 360000, false], ["Groomsmen's suits (hire)", 80000, 6, 0, false]],
        'Transport' => [['Bridal car hire', 500000, 1, 0, false], ['Coaster bus for the families (2 days)', 300000, 2, 0, false]],
        'Photography' => [['Photo and video package', 1800000, 1, 900000, true]],
        'Music' => [['DJ and sound system', 800000, 1, 400000, false], ['MC', 600000, 1, 300000, false]],
        'Cake' => [['Wedding cake, three tiers', 750000, 1, 0, false]],
        'Church' => [['Church fees and choir', 400000, 1, 400000, true]],
    ];

    /** The k-th pledge written down is PLEDGES[pledgeOrder(k)], pledgeDaysAgo(k) days ago (the newest last). */
    public static function pledgeOrder(int $k): int
    {
        return ($k * 37) % count(self::WEDDING_PLEDGES);
    }

    public static function pledgeDaysAgo(int $k): int
    {
        return self::WEDDING_DAYS_RECORDED - intdiv($k * 68, count(self::WEDDING_PLEDGES) - 1);
    }

    /** The figures Pledge & Budget and the portal show for the plan. */
    public static function weddingSummary(): array
    {
        $pledged = array_sum(array_column(self::WEDDING_PLEDGES, 2));
        $paid = array_sum(array_column(self::WEDDING_PLEDGES, 3));
        $items = array_merge(...array_values(self::WEDDING_BUDGET));
        $budget = array_sum(array_map(fn ($i) => $i[1] * $i[2], $items));
        $spent = array_sum(array_map(fn ($i) => $i[3], $items));

        return [
            'pledges' => count(self::WEDDING_PLEDGES),
            'pledged' => $pledged,
            'paid' => $paid,
            'owed' => $pledged - $paid,
            'fully_paid' => count(array_filter(self::WEDDING_PLEDGES, fn ($p) => $p[3] >= $p[2])),
            'part_paid' => count(array_filter(self::WEDDING_PLEDGES, fn ($p) => $p[3] > 0 && $p[3] < $p[2])),
            'not_paid' => count(array_filter(self::WEDDING_PLEDGES, fn ($p) => $p[3] === 0)),
            'budget_total' => $budget,
            'budget_spent' => $spent,
            'budget_balance' => $budget - $spent,
            'weeks_left' => intdiv(self::WEDDING_DAYS_TO_GO, 7),
        ];
    }
}
