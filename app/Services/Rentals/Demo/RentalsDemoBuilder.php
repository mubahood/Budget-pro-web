<?php

namespace App\Services\Rentals\Demo;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Plan;
use App\Models\RentalCharge;
use App\Models\RentalDocument;
use App\Models\RentalFollowUp;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalLeaseItem;
use App\Models\RentalMaintenance;
use App\Models\RentalPayment;
use App\Models\RentalPayout;
use App\Models\RentalProperty;
use App\Models\RentalTenant;
use App\Models\RentalUnit;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Onboarding\OnboardingEvents;
use App\Services\Onboarding\OnboardingService;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Collections\Arrears;
use App\Services\Rentals\Collections\FollowUps;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Directory\Tenants;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Import\RentalImport;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Maintenance;
use App\Services\Rentals\Meters;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\PrivateFiles;
use App\Services\Rentals\Portal\TenantRequests;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalsModule;
use App\Services\Team\TeamService;
use App\Support\Rentals\Periods;
use Database\Seeders\EstateLedgerPlanSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Fills an Estate Ledger demo account (RentalsDemo, KR-21) from database/data/demo_rentals.php: the agency, or the landlord on
 * his own. Every shilling goes through Estate Ledger's own services (no row is written into a money table by hand), so the
 * journal balances and `rentals:check` agrees.
 *
 * The clock runs forward day by day from the first of the month `rentals.demo.months` ago: the books come in through
 * the import on the first day, then each day has its work at its own time: bills and late fees first, then what the data
 * file dates to that day (move-ins, move-outs, repairs, reversals…), what recurs (payouts on the 5th, meters on the 27th,
 * the month's expenses, cash banked on Fridays) and each tenant's way of paying.
 *
 * What recurs and how tenants pay is worked out from the date alone, so the hourly heal runs the very same days through
 * bringForward(): the books are always up to today. Each day runs in one transaction with its progress mark, and
 * payments carry ids made from their lease, day and purpose, so a day never happens twice.
 */
final class RentalsDemoBuilder
{
    /** Bumped when a build changes what the demo holds. A live demo of an older build is rebuilt on the next run, not healed. */
    public const BUILD = 1;

    /** Grouping for ids made from names (uuid v5). */
    private const UUID_SPACE = '6f1d3a52-6d6e-5a54-9a2f-0d7a4e6b2c11';

    private const METHOD = ['momo' => 'mobile_money', 'cash' => 'cash', 'bank' => 'bank', 'cheque' => 'cheque'];

    /** @var list<string> sample entries a rule refused (logged, never fatal) */
    public array $skipped = [];

    /** The company being built (so a build that fails can be cleaned up). */
    public ?int $companyId = null;

    private array $data;

    private array $all;

    private Company $co;

    private int $cid;

    private string $tz;

    private Carbon $realNow;

    private Carbon $today;

    private Carbon $start;

    private string $token;

    /** The day being worked. */
    private string $current;

    /** Events outside these days are not planned. */
    private string $rangeFrom;

    private string $rangeTo;

    private bool $healing = false;

    /** @var array<string, User> sign-in key => user */
    private array $users = [];

    /** @var array<string, int> key => id */
    private array $landlords = [];

    private array $properties = [];

    private array $units = [];

    /** @var array<string, int> "property:unit" => its current lease */
    private array $leases = [];

    /** @var array<int, array{key: string, pay: string, m: string, early?: bool}> lease id => how the tenant pays */
    private array $behaviour = [];

    /** @var array<string, list<array{0: int, 1: callable}>> Y-m-d => [hour, the work] */
    private array $calendar = [];

    /** Next person's and company's index in the names (each new tenant takes the next). */
    private int $nextPerson = 0;

    private int $nextCompany = 0;

    /** Tenants made so far: their phone numbers count from the block's start. */
    private int $tenantSeq = 0;

    /** @var array<int, string> unit id => status held (reserved, under repair) */
    private array $unitStates = [];

    /** @var array<string, int> people's sign-in key => rental login id */
    private array $logins = [];

    public function __construct(private string $account = RentalsDemo::AGENCY)
    {
        $this->all = require dirname(__DIR__, 4).'/database/data/demo_rentals.php';
        $this->data = self::specFrom($this->all, $account);
    }

    /** The data of one demo account, with every section there (a section left out is empty). @return array<string, mixed> */
    public static function spec(string $account = RentalsDemo::AGENCY): array
    {
        return self::specFrom(require dirname(__DIR__, 4).'/database/data/demo_rentals.php', $account);
    }

    /** @param array<string, mixed> $all @return array<string, mixed> */
    private static function specFrom(array $all, string $account): array
    {
        $shared = ['names' => $all['names'], 'occupations' => $all['occupations'], 'kin' => $all['kin'], 'notes' => $all['notes']];
        $empty = array_fill_keys(['logins', 'staff', 'people', 'landlords', 'properties', 'empty_on_day_one', 'tenancies', 'opening_cash', 'move_ins', 'move_outs', 'cancelled', 'notices',
            'rent_changes', 'items', 'one_offs', 'waivers', 'former_pays', 'unit_states', 'maintenance', 'vendors', 'monthly_expenses', 'one_expenses', 'documents'], []);
        if ($account === RentalsDemo::SOLO) {
            return (array) $all['solo'] + $shared + $empty;
        }
        unset($all['solo']);

        return $all + $empty;
    }

    /**
     * A new, complete demo account. Its sign-ins carry temporary addresses until RentalsDemo hands it the published ones.
     *
     * @return array{company: Company, users: array<string, User>, snapshot: array<string, mixed>}
     */
    public function build(): array
    {
        $clock = Carbon::getTestNow();
        $this->prepareClock();
        $this->start = $this->today->copy()->startOfMonth()->subMonthsNoOverflow(max(3, (int) config('rentals.demo.months', 12)));
        $this->rangeFrom = $this->start->toDateString();
        $this->rangeTo = $this->today->toDateString();
        $this->token = strtolower(Str::random(6));

        $guards = [];
        RentalsDemo::$building = true;
        try {
            OnboardingEvents::muted(function () use (&$guards) {
                $this->at($this->start, 7, 30);
                $this->createCompany();
                $guards = $this->actAs($this->users['owner']);
                $this->setUpAccount();
                $this->importBooks();
                $this->planScenes();
                foreach ($this->behaviour as $leaseId => $b) {
                    $this->planLease($leaseId, $b);
                }
                $this->simulate($this->start, $this->today);
                $this->finishingTouches();
                $this->ensureToday();
            });
        } finally {
            RentalsDemo::$building = false;
            Carbon::setTestNow($clock);
            $this->restore($guards);
        }

        return ['company' => $this->co->fresh(), 'users' => array_map(fn (User $u) => $u->fresh(), $this->users), 'snapshot' => $this->snapshot()];
    }

    /** Pick up a live demo (for the heal) from its snapshot. @param array<string, mixed> $snap */
    public function load(Company $company, array $snap): self
    {
        $this->co = $company;
        $this->cid = (int) $company->id;
        $this->companyId = $this->cid;
        $this->prepareClock();
        foreach ((array) ($snap['users'] ?? []) as $key => $id) {
            if ($u = User::withoutGlobalScopes()->find((int) $id)) {
                $this->users[$key] = $u;
            }
        }
        $this->landlords = array_map('intval', (array) ($snap['landlords'] ?? []));
        $this->properties = array_map('intval', (array) ($snap['properties'] ?? []));
        $this->units = array_map('intval', (array) ($snap['units'] ?? []));
        $this->behaviour = [];
        foreach ((array) ($snap['behaviour'] ?? []) as $id => $b) {
            $this->behaviour[(int) $id] = (array) $b;
        }
        $this->rangeFrom = (string) ($snap['through'] ?? $this->today->toDateString());

        return $this;
    }

    /**
     * The heal's half: run every day not yet run, up to today (bills, payments, what recurs), then make sure today looks
     * like a working day. Running it again the same day adds nothing.
     *
     * @return array{repairs: list<string>}
     */
    public function bringForward(): array
    {
        $clock = Carbon::getTestNow();
        $repairs = [];
        $guards = isset($this->users['owner']) ? $this->actAs($this->users['owner']) : [];
        RentalsDemo::$building = true;
        $this->healing = true;
        try {
            OnboardingEvents::muted(function () use (&$repairs) {
                $last = Carbon::parse($this->rangeFrom);
                $from = $last->copy()->addDay();
                if ($from->lte($this->today)) {
                    $this->rangeFrom = $from->toDateString();
                    $this->rangeTo = $this->today->toDateString();
                    foreach ($this->behaviour as $leaseId => $b) {
                        $this->planLease($leaseId, $b);
                    }
                    $this->simulate($from, $this->today);
                    $repairs[] = 'books brought up to '.$this->today->toDateString();
                }
                $this->current = $this->today->toDateString();
                $added = $this->ensureToday();
                if ($added > 0) {
                    $repairs[] = "{$added} payment(s) today";
                }
            });
        } finally {
            RentalsDemo::$building = false;
            $this->healing = false;
            Carbon::setTestNow($clock);
            $this->restore($guards);
        }

        return ['repairs' => $repairs];
    }

    private function prepareClock(): void
    {
        $this->realNow = now()->copy();
        $this->tz = (string) config('rentals.demo.timezone', 'Africa/Kampala');
        $this->today = Carbon::parse($this->realNow->copy()->setTimezone($this->tz)->toDateString());
        $this->current = $this->today->toDateString();
    }

    // ── The account ────────────────────────────────────────────────────────

    private function createCompany(): void
    {
        $a = $this->data['agency'];
        $owner = $this->newLogin('owner', $this->data['logins']['owner']['name']);

        $co = new Company();
        $co->owner_id = $owner->id; // created hook: the owner's company_id
        $co->name = (string) $a['name'];
        $co->status = 'Active';
        $co->is_demo = true;
        $co->demo_parent_id = null;
        $co->currency = 'UGX';
        $co->country = 'UG';
        $co->timezone = $this->tz;
        $co->business_type = 'rentals';
        $co->address = (string) $a['address'];
        $co->phone_number = (string) $a['phone'];
        $co->email = $a['email'].'@'.RentalsDemo::domain($this->account);
        $co->license_expire = now()->addYears(5);
        $co->enabled_modules = ['rentals'];
        $co->receipt_channels = [];
        $co->onboarding_state = ['step' => 'done', 'completed_steps' => config('onboarding.steps'), 'skipped_steps' => [], 'completed_at' => now()->toIso8601String(),
            'dismissed_checklist' => true, 'demo' => true, 'version' => OnboardingService::STATE_VERSION,
            RentalsDemo::MARK => ['building' => true, 'account' => $this->account]]; // marks it from the start (a dead build is cleaned up)
        $co->save();
        $this->co = $co->fresh();
        $this->cid = (int) $co->id;
        $this->companyId = $this->cid;

        CompanyMember::create(['company_id' => $co->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
        $owner->refresh();
        User::ensureCompanyOwnerRole($owner);
        $this->users['owner'] = $owner->fresh();

        // A paid plan: the people's own portal is on (KR-16).
        if (! Plan::where('slug', (string) $a['plan'])->exists()) {
            (new EstateLedgerPlanSeeder())->run();
        }
        Subscription::create(['company_id' => $co->id, 'plan_id' => Plan::where('slug', (string) $a['plan'])->value('id'), 'status' => 'active', 'provider' => 'demo',
            'starts_at' => now(), 'ends_at' => now()->addYears(5)]);
        $this->co = $this->co->fresh();
    }

    /** A sign-in with a temporary address (the published one is given at hand-over) and a random password for now. */
    private function newLogin(string $key, string $name): User
    {
        [$first, $last] = explode(' ', $name, 2) + [1 => ''];
        $u = new User();
        $u->first_name = $first;
        $u->last_name = $last;
        $u->name = $name;
        $u->email = $this->tempEmail($key);
        $u->username = $u->email;
        $u->phone_number = null;
        $u->phone_e164 = null;
        $u->password = Hash::make(Str::random(40));
        $u->status = 'Active';
        $u->save();

        return $u;
    }

    private function tempEmail(string $key): string
    {
        return "{$key}+build-{$this->token}@".RentalsDemo::domain($this->account);
    }

    private function setUpAccount(): void
    {
        $owner = $this->users['owner'];
        app(RentalsModule::class)->setup($this->co, $owner, (array) $this->data['agency']['settings']);
        $this->co = $this->co->fresh();
        foreach (array_merge($this->data['logins'], $this->data['staff']) as $key => $login) {
            if ($key !== 'owner') {
                $this->users[$key] = app(TeamService::class)->createMember($this->co, $owner, $login['name'], $this->tempEmail($key), null, Str::password(24, symbols: false), $login['role']);
            }
        }
    }

    // ── The first day: everything comes in through the import ─────────────

    private function importBooks(): void
    {
        $sheets = ['landlords' => [], 'properties' => [], 'units' => [], 'tenancies' => [], 'opening_cash' => []];
        foreach ($this->data['landlords'] as $l) {
            $sheets['landlords'][] = ['name' => $l['name'], 'phone' => $l['phone'], 'address' => $l['address'], 'payout_method' => $l['payout_method'], 'payout_details' => $l['payout_details'],
                'commission_percent' => $l['commission_percent'] ?? '', 'opening_balance' => $l['opening'] ?? 0, 'commission_flat' => $l['commission_flat'] ?? ''];
        }
        $tenancies = [];
        foreach ($this->data['properties'] as $pk => $p) {
            $meters = (array) ($p['meters'] ?? []);
            $sheets['properties'][] = ['name' => $p['name'], 'landlord_name' => isset($p['landlord']) ? $this->data['landlords'][$p['landlord']]['name'] : '', 'kind' => $p['kind'], 'area' => $p['area'],
                'district' => $p['district'], 'address' => $p['address'], 'commission_percent' => $p['commission_percent'] ?? '', 'deposit_held_by' => $p['deposit_held_by'] ?? '',
                'water_rate' => $p['water_rate'] ?? '', 'power_rate' => $p['power_rate'] ?? '', 'commission_flat' => ''];
            foreach ($p['units'] as $n => [$code, $kind, $rent, $cycle]) {
                $sheets['units'][] = ['property_name' => $p['name'], 'code' => $code, 'kind' => $kind, 'floor' => ['Ground', 'First', 'Second'][$n % 3], 'rent' => $rent, 'deposit' => '', 'cycle_months' => $cycle,
                    'water_meter' => in_array('water', $meters, true) ? 'W-'.strtoupper(substr($pk, 0, 3)).'-'.$code : '',
                    'power_meter' => in_array('power', $meters, true) ? 'P-'.strtoupper(substr($pk, 0, 3)).'-'.$code : ''];
                $key = "{$pk}:{$code}";
                if (! in_array($key, $this->data['empty_on_day_one'], true)) {
                    $tenancies[$key] = [$p, $code, $rent, $cycle];
                }
            }
        }
        $people = [];
        $asOf = $this->start->copy();
        foreach ($tenancies as $key => [$p, $code, $rent, $cycle]) {
            $t = $this->tenancySpec($key, $p);
            $person = isset($t['same_tenant_as']) ? $people[$t['same_tenant_as']] : $this->nextTenant($p['kind'] === 'commercial' ? 'company' : 'person');
            $people[$key] = $person;
            $sheets['tenancies'][] = ['property_name' => $p['name'], 'unit_code' => $code, 'tenant_name' => $person['name'], 'phone' => $person['phone'], 'email' => $person['email'] ?? '',
                'id_number' => $person['id_number'], 'start_date' => $this->startDate($t, $asOf)->toDateString(), 'rent' => $rent, 'cycle_months' => $cycle,
                'deposit_held' => $t['deposit'] ?? $rent * (int) ($this->data['agency']['settings']['deposit_months'] ?? 1), 'owed_now' => $t['owed'] ?? 0, 'credit_now' => $t['credit'] ?? 0,
                'items' => $p['items'] ?? '', 'end_date' => $this->endDate($t)?->toDateString() ?? ''];
        }
        foreach ($this->data['opening_cash'] as $method => $amount) {
            $sheets['opening_cash'][] = ['method' => $method, 'amount' => $amount];
        }
        $this->current = $asOf->toDateString();
        (new RentalImport())->run($this->cid, $this->users['owner'], $sheets, $asOf->toDateString());

        // Who is who, now that they exist.
        foreach ($this->data['landlords'] as $k => $l) {
            $this->landlords[$k] = (int) RentalLandlord::withoutGlobalScopes()->where('company_id', $this->cid)->where('name', $l['name'])->value('id');
        }
        foreach ($this->data['properties'] as $pk => $p) {
            $this->properties[$pk] = (int) RentalProperty::withoutGlobalScopes()->where('company_id', $this->cid)->where('name', $p['name'])->value('id');
            foreach ($p['units'] as [$code]) {
                $this->units["{$pk}:{$code}"] = (int) RentalUnit::withoutGlobalScopes()->where('company_id', $this->cid)->where('property_id', $this->properties[$pk])->where('code', $code)->value('id');
            }
        }
        $owner = $this->users['owner'];
        foreach ($this->data['properties'] as $pk => $p) {
            foreach ($p['units'] as [$code, , , , $bedrooms]) {
                if ($bedrooms !== null) {
                    app(Units::class)->update($owner, $this->unitModel("{$pk}:{$code}"), ['bedrooms' => $bedrooms, 'bathrooms' => 1]);
                }
            }
        }
        foreach (RentalLease::withoutGlobalScopes()->where('company_id', $this->cid)->get() as $lease) {
            $key = array_search((int) $lease->unit_id, $this->units, true);
            $this->leases[$key] = (int) $lease->id;
            $p = $this->data['properties'][explode(':', $key)[0]];
            $t = $this->tenancySpec($key, $p);
            $this->behaviour[(int) $lease->id] = array_filter(['key' => $key, 'pay' => $t['pay'], 'm' => $t['m'], 'early' => $t['early'] ?? null], fn ($v) => $v !== null);
            $this->tenantDetails($lease, $people[$key]);
        }
        foreach ($this->data['landlords'] as $k => $l) {
            if (! empty($l['notes'])) {
                app(Landlords::class)->update($owner, RentalLandlord::withoutGlobalScopes()->find($this->landlords[$k]), ['notes' => $l['notes']]);
            }
        }
        foreach (array_merge($this->data['logins'], $this->data['staff']) as $key => $login) {
            foreach ((array) ($login['properties'] ?? []) as $pk) {
                $property = RentalProperty::withoutGlobalScopes()->find($this->properties[$pk]);
                $have = \App\Models\RentalPropertyStaff::withoutGlobalScopes()->where('company_id', $this->cid)->where('property_id', $property->id)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
                app(Properties::class)->assignStaff($owner, $property, array_merge($have, [(int) $this->users[$key]->id]));
            }
        }
    }

    /** A tenancy's data with the defaults of an ordinary tenant who pays on time. @param array<string, mixed> $p @return array<string, mixed> */
    private function tenancySpec(string $key, array $p): array
    {
        $h = crc32($key);
        $commercial = $p['kind'] === 'commercial';

        return (array) ($this->data['tenancies'][$key] ?? []) + [
            'since' => 2 + $h % 34, 'day' => [1, 1, 1, 1, 5, 10, 15, 20, 25][$h % 9], 'pay' => 'regular',
            'm' => $commercial ? ['bank', 'bank', 'momo'][$h % 3] : ['momo', 'momo', 'momo', 'cash', 'bank'][$h % 5],
        ];
    }

    private function startDate(array $t, Carbon $asOf): Carbon
    {
        if (isset($t['anniversary_ago'])) {
            return $this->today->copy()->startOfMonth()->subMonthsNoOverflow((int) $t['anniversary_ago'])->subYearsNoOverflow((int) $t['years']);
        }
        $d = $asOf->copy()->subMonthsNoOverflow((int) $t['since']);

        return $d->day(min((int) $t['day'], $d->daysInMonth));
    }

    private function endDate(array $t): ?Carbon
    {
        return match (true) {
            isset($t['ends_in']) => $this->today->copy()->addDays((int) $t['ends_in']),
            isset($t['ended_days_ago']) => $this->today->copy()->subDays((int) $t['ended_days_ago']),
            isset($t['renew']) => Carbon::parse($this->dateOf($t['renew'])),
            default => null,
        };
    }

    /** The next made-up person or company. @return array<string, mixed> */
    private function nextTenant(string $kind): array
    {
        $names = $this->data['names'];
        $n = $this->tenantSeq++;
        $base = (array) $this->data['agency']['phones'];
        $occupation = $this->data['occupations'][$n % count($this->data['occupations'])];
        $from = (int) ($this->data['agency']['names_from'] ?? 0);
        if ($kind === 'company') {
            $name = $names['companies'][$this->nextCompany++ % count($names['companies'])];
            $slug = Str::slug(str_replace(' (Demo)', '', $name));

            return ['name' => $name, 'kind' => 'company', 'phone' => '+'.($base['tenant'] + $n), 'email' => "{$slug}@tenants.example", 'id_type' => 'company_reg', 'id_number' => sprintf('DEMO-REG-%04d', $n),
                'occupation' => null, 'employer' => null, 'kin_name' => $this->data['kin'][$n % count($this->data['kin'])].' '.$names['last'][($n * 11) % count($names['last'])], 'kin_phone' => '+'.($base['kin'] + $n)];
        }
        $i = $from + $this->nextPerson++;
        $last = $names['last'][($i * 7) % count($names['last'])];
        $name = $names['first'][$i % count($names['first'])].' '.$last;

        return ['name' => $name, 'kind' => 'person', 'phone' => '+'.($base['tenant'] + $n), 'email' => $n % 2 === 0 ? Str::slug($name, '.').'@tenants.example' : null, 'id_type' => 'national_id', 'id_number' => sprintf('DEMO-NIN-%04d', $n),
            'occupation' => $occupation[0], 'employer' => $occupation[1], 'kin_name' => $this->data['kin'][$n % count($this->data['kin'])].' '.$last, 'kin_phone' => '+'.($base['kin'] + $n)];
    }

    /** What the import has no column for: added the way the Tenants screen adds it. @param array<string, mixed> $person */
    private function tenantDetails(RentalLease $lease, array $person): void
    {
        $t = RentalTenant::withoutGlobalScopes()->find($lease->tenant_id);
        app(Tenants::class)->update($this->users['owner'], $t, $this->tenantFields($person));
    }

    /** @param array<string, mixed> $person @return array<string, mixed> */
    private function tenantFields(array $person): array
    {
        return array_filter(['kind' => $person['kind'], 'id_type' => $person['id_type'], 'occupation' => $person['occupation'], 'employer' => $person['employer'],
            'next_of_kin_name' => $person['kin_name'], 'next_of_kin_phone' => $person['kin_phone']], fn ($v) => $v !== null);
    }

    // ── The dated scenes of the data file ──────────────────────────────────

    /** A scene's day: `ago` [months, day], or `days_ago`. */
    private function dateOf(array $when): string
    {
        if (isset($when['days_ago'])) {
            return $this->today->copy()->subDays((int) $when['days_ago'])->toDateString();
        }
        [$months, $day] = $when['ago'];
        $d = $this->today->copy()->startOfMonth()->subMonthsNoOverflow((int) $months);

        return $d->day(min((int) $day, $d->daysInMonth))->toDateString();
    }

    private function scene(string $date, int $hour, callable $fn): void
    {
        if ($date < $this->rangeFrom || $date > $this->rangeTo) {
            $this->skipped[] = "{$date}: a scene falls outside the history";

            return;
        }
        $this->calendar[$date][] = [$hour, $fn];
    }

    private function planScenes(): void
    {
        foreach ($this->data['move_ins'] as $key => $m) {
            $this->scene($this->dateOf($m), 9, fn () => $this->moveIn($key, $m));
        }
        foreach ($this->data['move_outs'] as $key => $m) {
            $this->scene($this->dateOf($m), 10, fn () => $this->moveOut($key, $m));
        }
        foreach ($this->data['cancelled'] as $c) {
            $date = $this->dateOf($c);
            $this->scene($date, 11, fn () => $this->cancelledLease($c));
        }
        foreach ($this->data['notices'] as $key => [$ago, $in]) {
            $date = $this->today->copy()->subDays((int) $ago)->toDateString();
            $out = $this->today->copy()->addDays((int) $in)->toDateString();
            $this->scene($date, 10, fn () => app(Leases::class)->notice($this->users['owner'], $this->lease($key), $date, $out));
        }
        foreach ($this->data['tenancies'] as $key => $t) {
            if (isset($t['rent_to'])) {
                $anniversary = $this->today->copy()->startOfMonth()->subMonthsNoOverflow((int) $t['anniversary_ago']);
                $this->scene($anniversary->copy()->subDays(9)->toDateString(), 9, fn () => $this->rentChange($key, (int) $t['rent_to'], 'Yearly review at the anniversary'));
            }
            if (isset($t['renew'])) {
                $end = Carbon::parse($this->dateOf($t['renew']));
                $to = $t['renew']['months'] !== null ? $end->copy()->addMonthsNoOverflow((int) $t['renew']['months'])->toDateString() : null;
                $this->scene($end->copy()->subDays(4)->toDateString(), 10, fn () => app(Leases::class)->renew($this->users['owner'], $this->lease($key), $to));
            }
        }
        foreach ($this->data['rent_changes'] as [$key, $rent, $when, $why]) {
            $this->scene($this->dateOf($when), 9, fn () => $this->rentChange($key, (int) $rent, $why));
        }
        foreach ($this->data['items'] as [$what, $key, $name, $amount, $when]) {
            $this->scene($this->dateOf($when), 9, fn () => $this->leaseItem($what, $key, $name, $amount));
        }
        foreach ($this->data['one_offs'] as $o) {
            $date = $this->dateOf($o[4]);
            $this->scene($date, 11, fn () => $this->oneOff($o));
        }
        foreach ($this->data['waivers'] as [$key, $kind, $amount, $when, $why]) {
            $this->scene($this->dateOf($when), 14, fn () => $this->waiver($key, $kind, (int) $amount, $why));
        }
        foreach ($this->data['former_pays'] as [$key, $amount, $method, $when]) {
            $date = $this->dateOf($when);
            $this->scene($date, 12, fn () => $this->formerPays($key, (int) $amount, $method, $date));
        }
        foreach ($this->data['maintenance'] as $m) {
            $this->planTicket($m);
        }
        foreach ($this->data['one_expenses'] as $e) {
            $date = $this->dateOf($e['when']);
            $this->scene($date, 13, fn () => $this->expense($e, $date));
        }
        if (isset($this->data['bounced_cheque'])) {
            $b = $this->data['bounced_cheque'];
            $date = $this->dateOf($b);
            $this->scene($date, 11, fn () => $this->bouncedCheque($b));
        }
        if (isset($this->data['wrong_tenant'])) {
            $b = $this->data['wrong_tenant'];
            $date = $this->dateOf($b);
            $this->scene($date, 15, fn () => $this->wrongTenant($b));
        }
        if (isset($this->data['reversed_payout'])) {
            $r = $this->data['reversed_payout'];
            $date = $this->dateOf($r);
            $this->scene($date, 11, fn () => $this->reversedPayout($r));
        }
    }

    /** @param array<string, mixed> $spec */
    private function moveIn(string $key, array $spec): void
    {
        [$pk, $code] = explode(':', $key);
        $p = $this->data['properties'][$pk];
        $unit = $this->unitModel($key);
        $person = $this->nextTenant($p['kind'] === 'commercial' ? 'company' : 'person');
        $lease = app(Leases::class)->moveIn($this->users['owner'], ['unit_id' => $unit->id, 'start_date' => $this->current, 'rent_minor' => (int) $unit->rent_minor,
            'cycle_months' => (int) $unit->cycle_months, 'items' => $this->items((string) ($p['items'] ?? '')), 'notes' => null,
            'tenant' => ['name' => $person['name'], 'phone' => $person['phone'], 'email' => $person['email'], 'id_number' => $person['id_number']] + $this->tenantFields($person)]);
        $this->leases[$key] = (int) $lease->id;
        $b = ['key' => $key, 'pay' => (string) $spec['pay'], 'm' => (string) $spec['m']];
        $this->behaviour[(int) $lease->id] = $b;
        $this->payOpen((int) $lease->id, $b, 'move-in');
        $this->planLease((int) $lease->id, $b, Carbon::parse($this->current)->addDay()->toDateString());
    }

    /** @param array<string, mixed> $m */
    private function moveOut(string $key, array $m): void
    {
        $lease = $this->lease($key);
        $b = $this->behaviour[(int) $lease->id] ?? null;
        if ($b !== null && $b['pay'] === 'regular') {
            $this->payOpen((int) $lease->id, $b, 'before-move-out'); // someone who pays on time leaves with the rent paid: the deposit is for what comes up at the end
        }
        $method = $m['refund_deposit'] ?? null;
        $data = ['date' => $this->current, 'reason' => $m['reason'] ?? null, 'unused_days' => ! empty($m['unused_days'])];
        if ($method !== null) {
            $data['refund_deposit'] = $method;
            $data['refund_credit'] = $method;
        }
        foreach ((array) ($m['damages'] ?? []) as [$what, $amount]) {
            $data['damages'][] = ['description' => $what, 'amount_minor' => (int) $amount];
        }
        if (isset($m['water'])) {
            $last = Meters::last($this->cid, (int) $lease->unit_id, 'water');
            $data['readings'][] = ['utility' => 'water', 'reading' => $this->meterText(((float) ($last?->reading ?? 100)) + (float) $m['water'])];
        }
        if ($method !== null) {
            $will = app(Leases::class)->moveOutPreview($lease, $data);
            $this->fund((string) $method, (int) $will['deposit_refunded_minor'] + (int) $will['credit_refunded_minor']);
        }
        app(Leases::class)->moveOut($this->users['owner'], $lease, $data);
        unset($this->behaviour[(int) $lease->id]);
        unset($this->leases[$key]);
        if (isset($m['relet'])) {
            $on = Carbon::parse($this->current)->addDays((int) $m['relet']['after'])->toDateString();
            $this->scene($on, 9, fn () => $this->moveIn($key, (array) $m['relet']));
        }
        if (isset($m['refund_later'])) {
            $on = Carbon::parse($this->current)->addDays((int) $m['refund_later']['after'])->toDateString();
            $id = (int) $lease->id;
            $this->scene($on, 11, function () use ($id, $m, $on) {
                $l = RentalLease::withoutGlobalScopes()->findOrFail($id);
                $this->fund((string) $m['refund_later']['method'], Deposits::held($l));
                Deposits::refundKept($this->users['owner'], $l, null, (string) $m['refund_later']['method'], $on);
            });
        }
    }

    /** @param array<string, mixed> $c */
    private function cancelledLease(array $c): void
    {
        $person = $this->nextTenant('person');
        $unit = $this->unitModel((string) $c['unit']);
        $lease = app(Leases::class)->moveIn($this->users['owner'], ['unit_id' => $unit->id, 'start_date' => $this->current, 'rent_minor' => (int) $unit->rent_minor,
            'tenant' => ['name' => $person['name'], 'phone' => $person['phone'], 'id_number' => $person['id_number']] + $this->tenantFields($person)]);
        app(Leases::class)->cancel($this->users['owner'], $lease, (string) $c['reason']);
    }

    private function rentChange(string $key, int $rent, string $why): void
    {
        $lease = $this->lease($key);
        $from = ($lease->billed_until?->copy() ?? Carbon::parse($this->current))->addDay()->toDateString();
        app(Leases::class)->changeRent($this->users['accountant'] ?? $this->users['owner'], $lease, $rent, $from, $why);
    }

    private function leaseItem(string $what, string $key, string $name, ?int $amount): void
    {
        $lease = $this->lease($key);
        $by = $this->users['accountant'] ?? $this->users['owner'];
        if ($what === 'add') {
            app(Leases::class)->addItem($by, $lease, ['name' => $name, 'amount_minor' => $amount, 'goes_to' => 'landlord']);

            return;
        }
        $item = RentalLeaseItem::withoutGlobalScopes()->where('company_id', $this->cid)->where('lease_id', $lease->id)->where('name', $name)->where('active', true)->firstOrFail();
        app(Leases::class)->endItem($by, $item, ($lease->billed_until ?? Carbon::parse($this->current))->toDateString());
    }

    /** @param array<int, mixed> $o */
    private function oneOff(array $o): void
    {
        [$key, $what, $amount, $goesTo] = $o;
        $lease = $this->lease($key);
        $by = $this->users['accountant'] ?? $this->users['owner'];
        $charge = app(Billing::class)->charge($by, $lease, ['description' => $what, 'amount_minor' => (int) $amount, 'goes_to' => $goesTo, 'due_date' => $this->current]);
        if (isset($o[5])) {
            app(Billing::class)->void($by, $charge, (string) $o[5]);

            return;
        }
        $b = $this->behaviour[(int) $lease->id] ?? null;
        if ($b !== null && $b['pay'] === 'regular') {
            $this->event(Carbon::parse($this->current)->addDays(2)->toDateString(), 13, fn () => $this->payOpen((int) $lease->id, $b, 'one-off:'.$charge->id));
        }
    }

    private function waiver(string $key, string $kind, int $most, string $why): void
    {
        $lease = $this->lease($key);
        $open = Payments::openCharges($lease, false);
        $charge = $open->firstWhere('kind', $kind) ?? $open->firstWhere('kind', 'rent') ?? $open->first();
        if ($charge === null) {
            throw BusinessRuleException::make('nothing_to_waive', "{$key} owes nothing to forgive.");
        }
        app(Payments::class)->waive($this->users['accountant'] ?? $this->users['owner'], $charge, min($most, $charge->left()), $why);
    }

    private function formerPays(string $key, int $amount, string $method, string $date): void
    {
        $lease = RentalLease::withoutGlobalScopes()->where('company_id', $this->cid)->where('unit_id', $this->units[$key])->where('status', 'ended')->orderByDesc('id')->firstOrFail();
        app(Payments::class)->receive($this->users['accountant'] ?? $this->users['owner'], $lease, ['amount_minor' => $amount, 'method' => $method, 'paid_on' => $date,
            'notes' => 'Part of the balance left at move-out', 'uuid' => $this->uuid("former|{$lease->id}|{$date}")]);
    }

    /** A cheque for the coming quarter: it bounces three days after the period starts, and the money comes by bank a week later. @param array<string, mixed> $b */
    private function bouncedCheque(array $b): void
    {
        $lease = $this->lease((string) $b['lease']);
        $amount = max($this->owed($lease), (int) $lease->rent_minor * (int) $lease->cycle_months);
        $by = $this->users['accountant'] ?? $this->users['owner'];
        $payment = app(Payments::class)->receive($by, $lease, ['amount_minor' => $amount, 'method' => 'cheque', 'paid_on' => $this->current, 'reference' => 'CHQ '.(100200 + crc32($b['lease']) % 700),
            'uuid' => $this->uuid("cheque|{$lease->id}|{$this->current}")]);
        $back = Carbon::parse($this->current)->addDays(3)->toDateString();
        $this->event($back, 10, function () use ($by, $payment, $amount, $b) {
            // The cheque may have been banked on Friday: the bank takes it back before the receipt is reversed.
            $this->fund('cheque', $amount, 'Returned cheque taken back by the bank');
            app(Payments::class)->reverse($by, $payment->fresh(), (string) $b['reason']);
        });
        $again = Carbon::parse($this->current)->addDays(10)->toDateString();
        $bh = ['key' => (string) $b['lease'], 'pay' => 'regular', 'm' => 'bank'];
        $this->event($again, 11, fn () => $this->payOpen((int) $lease->id, $bh, 'after-cheque'));
    }

    /** @param array<string, mixed> $b */
    private function wrongTenant(array $b): void
    {
        $by = $this->users['accountant'] ?? $this->users['owner'];
        $wrong = $this->lease((string) $b['wrong']);
        $right = $this->lease((string) $b['right']);
        $ref = 'MP'.(26000000 + crc32($this->current) % 999999);
        $p = app(Payments::class)->receive($by, $wrong, ['amount_minor' => (int) $b['amount'], 'method' => 'mobile_money', 'paid_on' => $this->current, 'reference' => $ref, 'uuid' => $this->uuid("wrong|{$this->current}")]);
        app(Payments::class)->reverse($by, $p, (string) $b['reason']);
        app(Payments::class)->receive($by, $right, ['amount_minor' => (int) $b['amount'], 'method' => 'mobile_money', 'paid_on' => $this->current, 'reference' => $ref, 'uuid' => $this->uuid("right|{$this->current}")]);
    }

    /** @param array<string, mixed> $r */
    private function reversedPayout(array $r): void
    {
        $by = $this->users['accountant'] ?? $this->users['owner'];
        $landlord = RentalLandlord::withoutGlobalScopes()->find($this->landlords[$r['landlord']]);
        $payout = RentalPayout::withoutGlobalScopes()->where('company_id', $this->cid)->where('landlord_id', $landlord->id)->whereNull('reversed_at')->orderByDesc('id')->firstOrFail();
        app(Payouts::class)->reverse($by, $payout, (string) $r['reason']);
        $next = Carbon::parse($this->current)->addDay()->toDateString();
        $this->event($next, 10, function () use ($by, $landlord, $payout, $next) {
            $this->fund((string) $payout->method, (int) $payout->amount_minor);
            app(Payouts::class)->record($by, $landlord, ['amount_minor' => (int) $payout->amount_minor, 'method' => $payout->method, 'paid_on' => $next, 'period_from' => $payout->period_from,
                'period_to' => $payout->period_to, 'reference' => 'Paid again to the right account']);
        });
    }

    /** A repair from the report to its end. @param array<int, mixed> $m */
    private function planTicket(array $m): void
    {
        [$where, $title, $priority, $reporter, $when, $end] = $m;
        $date = $this->dateOf($when);
        $this->scene($date, 10, function () use ($where, $title, $priority, $reporter, $end, $date) {
            $by = $this->users['owner'];
            $data = ['title' => $title, 'priority' => $priority, 'details' => null];
            $unit = null;
            if (str_contains($where, ':')) {
                $unit = $this->unitModel($where);
                $data['unit_id'] = $unit->id;
            } else {
                $data['property_id'] = $this->properties[$where];
            }
            $lease = $unit !== null ? RentalLease::withoutGlobalScopes()->where('company_id', $this->cid)->where('unit_id', $unit->id)->whereIn('status', RentalLease::LIVE)->first() : null;
            $ticket = $reporter === 'tenant' && $lease !== null
                ? app(Maintenance::class)->open(null, $this->cid, $data, ['tenant', (int) $lease->tenant_id])
                : app(Maintenance::class)->open($by, $this->cid, $data);
            $id = (int) $ticket->id;
            $next = Carbon::parse($date)->addDay()->toDateString();
            if (isset($end['cancel'])) {
                $this->event($next, 11, fn () => app(Maintenance::class)->cancel($by, $this->ticket($id), (string) $end['cancel']));
            } elseif (isset($end['progress'])) {
                $this->event($next, 11, fn () => app(Maintenance::class)->update($by, $this->ticket($id), ['status' => 'in_progress', 'assignee_name' => (string) $end['progress']]));
            } elseif (isset($end['done'])) {
                $this->event($next, 9, fn () => app(Maintenance::class)->update($by, $this->ticket($id), ['status' => 'in_progress', 'assignee_name' => (string) $end['by']]));
                $done = Carbon::parse($date)->addDays(max(1, (int) $end['done']))->toDateString();
                $this->event($done, 15, function () use ($by, $id, $end, $done) {
                    $method = (int) $end['cost'] > 100_000 ? 'mobile_money' : 'cash';
                    $this->fund($method, (int) $end['cost']);
                    app(Maintenance::class)->complete($by, $this->ticket($id), ['cost_minor' => (int) $end['cost'], 'vendor' => (string) $end['by'], 'method' => $method, 'paid_on' => $done,
                        'charged_to' => ! empty($end['agency']) ? 'agency' : 'landlord', 'resolved_on' => $done, 'resolution' => 'Done by '.$end['by'].'.']);
                });
            }
        });
    }

    private function ticket(int $id): RentalMaintenance
    {
        return RentalMaintenance::withoutGlobalScopes()->findOrFail($id);
    }

    /** @param array<string, mixed> $e */
    private function expense(array $e, string $date): void
    {
        $this->fund((string) $e['method'], (int) $e['amount']);
        $by = $this->users['accountant'] ?? $this->users['owner'];
        $x = app(Expenses::class)->record($by, array_filter(['property_id' => isset($e['property']) ? $this->properties[$e['property']] : null, 'category' => $e['category'],
            'description' => $e['what'] ?? null, 'vendor' => isset($e['vendor']) ? ($this->data['vendors'][$e['vendor']] ?? null) : null, 'amount_minor' => (int) $e['amount'], 'method' => $e['method'],
            'paid_on' => $date, 'charged_to' => $e['charged_to'] ?? 'landlord'], fn ($v) => $v !== null));
        if (isset($e['reversed'])) {
            $this->event(Carbon::parse($date)->addDay()->toDateString(), 9, fn () => app(Expenses::class)->reverse($by, $x->fresh(), (string) $e['reversed']));
        }
    }

    // ── How each tenant pays, from the dates alone ─────────────────────────

    /** Add the payments and follow-ups of one lease to the calendar, for the days being run. @param array<string, mixed> $b */
    private function planLease(int $leaseId, array $b, ?string $from = null): void
    {
        $lease = RentalLease::withoutGlobalScopes()->where('company_id', $this->cid)->find($leaseId);
        if ($lease === null || ! $lease->isLive()) {
            return;
        }
        $from ??= $this->rangeFrom;
        // Periods that began earlier can still have work in these days (the second part, a late payment).
        $periods = Periods::between($lease->start_date, (int) $lease->cycle_months, (string) $lease->anchor,
            Carbon::parse($from)->subDays(60), Carbon::parse($this->rangeTo)->addDays(6));
        foreach ($periods as $p) {
            $this->planPeriod($leaseId, $b, $p['start'], $from);
        }
    }

    /** @param array<string, mixed> $b */
    private function planPeriod(int $id, array $b, Carbon $start, string $from): void
    {
        $key = (string) $b['key'];
        $tag = $start->toDateString();
        $h = crc32("{$key}|{$tag}");
        $on = fn (int $days) => $start->copy()->addDays($days)->toDateString();
        $at = fn (string $date, int $hour, callable $fn) => $date >= $from ? $this->event($date, $hour, $fn) : null;
        [$kind, $n] = explode(':', (string) $b['pay'].':0');
        switch ($kind) {
            case 'ahead':
                $at($on(-4), 10, fn () => $this->payAhead($id, $b, $tag));
                break;
            case 'parts':
                $at($on(1 + $h % 3), 11, fn () => $this->payPart($id, $b, 0.5, "part1:{$tag}"));
                if ($h % 3 === 0) {
                    $at($on(8), 9, fn () => $this->chase($id, $this->word('reminder', $h), $h % 2 === 0 ? 'whatsapp' : 'sms'));
                }
                $at($on(11 + $h % 9), 15, fn () => $this->payOpen($id, $b, "part2:{$tag}"));
                break;
            case 'behind':
                $promise = $on(13 + $h % 3);
                $broken = $h % 4 === 0;
                $at($on(7 + $h % 3), 9, fn () => $this->chase($id, str_replace('{date}', Carbon::parse($promise)->format('j M'), $this->word('promise', $h)), ['call', 'whatsapp', 'visit', 'sms'][$h % 4], $promise));
                if ($broken) {
                    $at(Carbon::parse($promise)->addDay()->toDateString(), 9, fn () => $this->chase($id, str_replace('{date}', Carbon::parse($promise)->format('j M'), $this->word('broken', $h)), 'call'));
                } else {
                    $at($promise, 14, fn () => $this->payDown($id, $b, (int) $n, $tag));
                }
                break;
            default: // regular
                $r = $h % 100;
                $days = $r < 45 ? 0 : ($r < 70 ? 1 : ($r < 85 ? 2 : ($r < 95 ? 3 : 4)));
                if (! empty($b['early'])) {
                    $days = -4 + $h % 2;
                } elseif (crc32("late|{$key}|{$tag}") % 12 === 0) {
                    $days = 8 + $h % 6; // a late month: past the grace days, so a late fee too
                }
                $at($on($days), 10 + $h % 6, fn () => $this->payOpen($id, $b, "rent:{$tag}"));
        }
    }

    private function event(string $date, int $hour, callable $fn): void
    {
        if ($date >= $this->rangeFrom && $date <= $this->rangeTo) {
            $this->calendar[$date][] = [$hour, $fn];
        }
    }

    private function live(int $id): ?RentalLease
    {
        $lease = RentalLease::withoutGlobalScopes()->where('company_id', $this->cid)->find($id);

        return $lease !== null && $lease->isLive() ? $lease : null;
    }

    private function owed(RentalLease $lease): int
    {
        return (int) Payments::openCharges($lease, false)->sum(fn (RentalCharge $c) => $c->left());
    }

    /** @param array<string, mixed> $b */
    private function payOpen(int $id, array $b, string $tag): void
    {
        $lease = $this->live($id);
        if ($lease !== null && ($owed = $this->owed($lease)) > 0) {
            $this->receive($lease, $b, $owed, $tag);
        }
    }

    /** @param array<string, mixed> $b */
    private function payPart(int $id, array $b, float $share, string $tag): void
    {
        $lease = $this->live($id);
        if ($lease === null || ($owed = $this->owed($lease)) <= 0) {
            return;
        }
        $this->receive($lease, $b, min($owed, max(10_000, intdiv((int) ($owed * $share), 10_000) * 10_000)), $tag);
    }

    /** Pay what is owed down to N months of rent (the tenant stays that far behind). @param array<string, mixed> $b */
    private function payDown(int $id, array $b, int $months, string $tag): void
    {
        $lease = $this->live($id);
        if ($lease === null) {
            return;
        }
        $pay = intdiv(max(0, $this->owed($lease) - $months * (int) $lease->rent_minor), 10_000) * 10_000;
        if ($pay > 0) {
            $this->receive($lease, $b, $pay, "down:{$tag}");
        }
    }

    /** Six months' money when the credit is about to run out. @param array<string, mixed> $b */
    private function payAhead(int $id, array $b, string $tag): void
    {
        $lease = $this->live($id);
        if ($lease === null) {
            return;
        }
        $items = (int) RentalLeaseItem::withoutGlobalScopes()->where('company_id', $this->cid)->where('lease_id', $id)->where('active', true)->sum('amount_minor');
        $month = (int) $lease->rent_minor + $items;
        if (Payments::credit($lease) < $month) {
            $this->receive($lease, $b, 6 * $month + $this->owed($lease), "ahead:{$tag}");
        }
    }

    /** @param array<string, mixed> $b */
    private function receive(RentalLease $lease, array $b, int $amount, string $tag): RentalPayment
    {
        $h = crc32("{$lease->id}|{$tag}|{$this->current}");
        $method = self::METHOD[(string) $b['m']] ?? 'cash';
        if ($method === 'mobile_money' && $h % 7 === 0) {
            $method = 'cash'; // now and then the mobile money payer comes to the office
        }
        $reference = match ($method) {
            'mobile_money' => 'MP'.(26000000 + $h % 999999),
            'bank' => 'FT'.(2600000 + $h % 99999),
            default => null,
        };

        return app(Payments::class)->receive($this->collector($lease, $method), $lease, ['amount_minor' => $amount, 'method' => $method, 'paid_on' => $this->current, 'reference' => $reference,
            'uuid' => $this->uuid("pay|{$lease->id}|{$this->current}|{$tag}")], $method === 'cash' ? 'app' : 'web');
    }

    /** Who takes it: the caretaker of the building for cash, the accountant at the office for the rest. */
    private function collector(RentalLease $lease, string $method): User
    {
        if ($method === 'cash') {
            foreach (array_merge($this->data['logins'], $this->data['staff']) as $key => $login) {
                if (isset($this->users[$key]) && in_array((int) $lease->property_id, array_map(fn ($pk) => $this->properties[$pk] ?? 0, (array) ($login['properties'] ?? [])), true)) {
                    return $this->users[$key];
                }
            }
        }

        return $this->users['accountant'] ?? $this->users['owner'];
    }

    /** A line in the collections log, by whoever looks after the building. */
    private function chase(int $id, string $note, string $channel, ?string $promise = null): void
    {
        $lease = $this->live($id);
        if ($lease !== null && $this->owed($lease) > 0) {
            app(FollowUps::class)->log($this->collector($lease, 'cash'), $lease, $channel, $note, $promise);
        }
    }

    private function word(string $list, int $h): string
    {
        $words = (array) $this->data['notes'][$list];

        return (string) $words[$h % count($words)];
    }

    // ── A day ──────────────────────────────────────────────────────────────

    private function simulate(Carbon $from, Carbon $to): void
    {
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $this->runDay($d->toDateString());
        }
    }

    private function runDay(string $date): void
    {
        RentalLedger::locked($this->cid, function () use ($date) {
            $this->current = $date;
            $day = Carbon::parse($date);
            $this->at($day, 7, 30);
            $this->quietly(fn () => $this->billsAndFees($day));
            $this->recurring($day);
            $done = 0;
            // Work may add more work for later today, so read the list until it is empty.
            while (($list = $this->calendar[$date] ?? []) !== []) {
                unset($this->calendar[$date]);
                usort($list, fn ($a, $b) => $a[0] <=> $b[0]);
                foreach ($list as [$hour, $fn]) {
                    $this->at($day, (int) $hour, ($done++ * 7) % 60);
                    $this->quietly($fn);
                }
            }
            if ($this->healing) {
                $this->markThrough($date);
            }
        });
    }

    /** The daily job's work, on the leases whose next bill is inside the horizon: Billing::raise, then the late fees. */
    private function billsAndFees(Carbon $day): void
    {
        $horizon = $day->copy()->addDays((int) ($this->data['agency']['settings']['bill_days_before'] ?? 5))->toDateString();
        $due = RentalLease::withoutGlobalScopes()->where('company_id', $this->cid)->whereIn('status', RentalLease::LIVE)
            ->where(fn ($q) => $q->whereNull('billed_until')->orWhereRaw('DATE_ADD(billed_until, INTERVAL 1 DAY) <= ?', [$horizon]))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereColumn('end_date', '>', 'billed_until'))->get();
        $billing = app(Billing::class);
        foreach ($due as $lease) {
            $billing->raise($lease, $day->copy());
        }
        $billing->lateFees($this->cid, $day->copy());
        // A tenant under notice whose day has come moves out (the rebuild usually comes first).
        foreach (RentalLease::withoutGlobalScopes()->where('company_id', $this->cid)->where('status', 'notice')->where('move_out_on', '<=', $day->toDateString())->get() as $lease) {
            $data = ['date' => $day->toDateString(), 'refund_deposit' => 'bank', 'refund_credit' => 'bank', 'reason' => 'Gave notice'];
            $will = app(Leases::class)->moveOutPreview($lease, $data);
            $this->fund('bank', (int) $will['deposit_refunded_minor'] + (int) $will['credit_refunded_minor']);
            app(Leases::class)->moveOut($this->users['owner'], $lease, $data);
            unset($this->behaviour[(int) $lease->id]);
        }
    }

    /** What happens on a date whichever year it is: a function of the date, so a heal does it as a build does. */
    private function recurring(Carbon $day): void
    {
        $date = $day->toDateString();
        foreach ($this->data['monthly_expenses'] as $e) {
            if ((int) $e['day'] === $day->day) {
                $this->event($date, 12, fn () => $this->expense($e + ['what' => $e['what'] ?? null], $date));
            }
        }
        if ($day->day === 5) {
            $this->event($date, 16, fn () => $this->payouts($date));
        }
        if ($day->day === 12) {
            $this->event($date, 11, fn () => $this->chaseFormers($date));
        }
        if ($day->day === 27) {
            $this->event($date, 8, fn () => $this->meters($date));
        }
        // Tenants who pay on time settle the utility bills days after the meter day.
        $meterDay = $day->day >= 28 ? $day->copy()->day(27) : ($day->day <= 3 ? $day->copy()->startOfMonth()->subDay()->day(27) : null);
        if ($meterDay !== null) {
            foreach ($this->behaviour as $id => $b) {
                if ($b['pay'] === 'regular' && ! empty($this->data['properties'][explode(':', $b['key'])[0]]['meters'])
                    && $meterDay->copy()->addDays(1 + crc32($b['key']) % 4)->toDateString() === $date) {
                    $this->event($date, 13, fn () => $this->payOpen((int) $id, $b, 'utility:'.$meterDay->toDateString()));
                }
            }
        }
        if ($day->isFriday()) {
            $this->event($date, 17, fn () => $this->bankTheCash($date));
        }
    }

    /** Monthly payouts: what each landlord is owed, to the nearest 50,000 down; owner mode's drawing is the same payment. */
    private function payouts(string $date): void
    {
        $by = $this->users['accountant'] ?? $this->users['owner'];
        $from = Carbon::parse($date)->subMonthNoOverflow()->startOfMonth();
        foreach (RentalLandlord::withoutGlobalScopes()->where('company_id', $this->cid)->where('status', 'active')->orderBy('id')->get() as $l) {
            $balance = LandlordAccounts::balance($l);
            $amount = intdiv(max(0, $balance), 50_000) * 50_000;
            if ($amount < 200_000) {
                continue;
            }
            $method = (string) ($l->payout_method ?: 'bank');
            $this->fund($method, $amount);
            $have = RentalLedger::cash($this->cid)[$method] ?? 0;
            $amount = min($amount, intdiv(max(0, $have), 50_000) * 50_000);
            if ($amount > 0) {
                app(Payouts::class)->record($by, $l, ['amount_minor' => $amount, 'method' => $method, 'paid_on' => $date, 'period_from' => $from->toDateString(), 'period_to' => $from->copy()->endOfMonth()->toDateString(),
                    'reference' => $method === 'mobile_money' ? 'MP'.(27000000 + crc32($date.$l->id) % 999999) : 'FT'.(2700000 + crc32($date.$l->id) % 99999)]);
            }
        }
    }

    /** The monthly call to former tenants who still owe. */
    private function chaseFormers(string $date): void
    {
        $ended = RentalLease::withoutGlobalScopes()->where('company_id', $this->cid)->where('status', 'ended')
            ->whereExists(fn ($q) => $q->from('rental_charges as c')->whereColumn('c.lease_id', 'rental_leases.id')->where('c.status', 'open')->where('c.kind', '!=', 'refund'))->limit(6)->get();
        foreach ($ended as $lease) {
            $h = crc32("{$lease->id}|{$date}");
            app(FollowUps::class)->log($this->collector($lease, 'cash'), $lease, ['call', 'whatsapp', 'sms'][$h % 3], $this->word('former', $h));
        }
    }

    /** The month's readings, by the caretaker of each metered building. */
    private function meters(string $date): void
    {
        foreach ($this->data['properties'] as $pk => $p) {
            foreach ((array) ($p['meters'] ?? []) as $utility) {
                foreach ($p['units'] as [$code]) {
                    $key = "{$pk}:{$code}";
                    $unitId = (int) ($this->units[$key] ?? 0);
                    $lived = RentalLease::withoutGlobalScopes()->where('company_id', $this->cid)->where('unit_id', $unitId)->whereIn('status', RentalLease::LIVE)->first();
                    if ($unitId === 0 || $lived === null) {
                        continue;
                    }
                    $h = crc32("{$key}|{$utility}|{$date}");
                    $last = Meters::last($this->cid, $unitId, $utility);
                    $used = $utility === 'water' ? 2 + $h % 6 + ($h % 10) / 10 : 20 + $h % 60;
                    $reading = $last !== null ? (float) $last->reading + $used : 100 + crc32($key) % 400;
                    app(Meters::class)->record($this->collector($lived, 'cash'), ['unit_id' => $unitId, 'utility' => $utility, 'reading' => $this->meterText($reading), 'read_on' => $date,
                        'uuid' => $this->uuid("meter|{$unitId}|{$utility}|{$date}")]);
                }
            }
        }
    }

    private function meterText(float $v): string
    {
        return number_format($v, 1, '.', '');
    }

    /** Friday: cash and mobile money above a float go to the bank, cheques are banked. */
    private function bankTheCash(string $date): void
    {
        $cash = RentalLedger::cash($this->cid);
        $small = $this->account === RentalsDemo::SOLO;
        foreach (['cash' => $small ? 200_000 : 1_000_000, 'mobile_money' => $small ? 300_000 : 2_000_000, 'cheque' => 0] as $method => $float) {
            $move = intdiv(max(0, (int) ($cash[$method] ?? 0) - $float), 50_000) * 50_000;
            if ($move > 0 || ($method === 'cheque' && (int) ($cash['cheque'] ?? 0) > 0)) {
                app(CashBook::class)->transfer($this->users['accountant'] ?? $this->users['owner'], $method, 'bank', $method === 'cheque' ? (int) $cash['cheque'] : $move, $date, 'Banked on Friday');
            }
        }
    }

    /** Money going out must be in the account it goes out of (CashGuard): move it there first, as the office would. */
    private function fund(string $method, int $amount, string $note = 'To pay what is due today'): void
    {
        $cash = RentalLedger::cash($this->cid);
        $need = $amount - (int) ($cash[$method] ?? 0);
        if ($need <= 0) {
            return;
        }
        $need = (int) ceil($need / 100_000) * 100_000;
        foreach (['bank', 'mobile_money', 'cash'] as $source) {
            if ($source === $method || $need <= 0) {
                continue;
            }
            $take = min($need, max(0, (int) ($cash[$source] ?? 0)));
            if ($take > 0) {
                app(CashBook::class)->transfer($this->users['accountant'] ?? $this->users['owner'], $source, $method, $take, $this->current, $note);
                $need -= $take;
            }
        }
    }

    /** This day is done (in the day's own transaction, so a day never runs twice). */
    private function markThrough(string $date): void
    {
        $co = Company::withoutGlobalScopes()->findOrFail($this->cid);
        $state = (array) $co->onboarding_state;
        $state[RentalsDemo::MARK]['snapshot']['through'] = $date;
        $state[RentalsDemo::MARK]['snapshot']['behaviour'] = $this->behaviour;
        $co->onboarding_state = $state;
        $co->saveQuietly();
    }

    // ── Today ──────────────────────────────────────────────────────────────

    /**
     * Today always looks like a working day: a few payments in, and someone who promised to pay today. Adds nothing once
     * that is so. Returns the payments added.
     */
    private function ensureToday(): int
    {
        $added = 0;
        $today = $this->today->toDateString();
        $this->current = $today;
        $this->at($this->today, 9, 15);
        $small = $this->account === RentalsDemo::SOLO;
        $want = $small ? 1 : 3;
        RentalLedger::locked($this->cid, function () use (&$added, $today, $want) {
            $have = (int) RentalPayment::withoutGlobalScopes()->where('company_id', $this->cid)->whereIn('method', RentalPayment::CASH_METHODS)->whereNull('reversed_at')->where('paid_on', $today)->count();
            $order = array_keys($this->behaviour);
            usort($order, fn ($a, $b) => crc32("{$a}|{$today}") <=> crc32("{$b}|{$today}"));
            foreach ([true, false] as $owing) {
                foreach ($order as $id) {
                    if ($have + $added >= $want) {
                        break 2;
                    }
                    $b = $this->behaviour[$id];
                    $lease = $this->live((int) $id);
                    if ($lease === null || str_starts_with((string) $b['pay'], 'ahead') || str_starts_with((string) $b['pay'], 'behind')) {
                        continue;
                    }
                    $owed = $this->owed($lease);
                    if ($owing && $owed <= 0) {
                        continue;
                    }
                    // Nobody owes today: someone pays next month's rent early.
                    $amount = $owed > 0 ? $owed : (int) $lease->rent_minor;
                    $done = $this->quietly(fn () => $this->receive($lease, $b, $amount, 'today'));
                    if ($done !== null && (string) $done->paid_on?->toDateString() === $today && (int) $done->amount_minor === $amount) {
                        $added++;
                    }
                }
            }
            if (! RentalFollowUp::withoutGlobalScopes()->where('company_id', $this->cid)->where('promise_on', $today)->exists()) {
                foreach (Arrears::list($this->cid) as $row) {
                    $lease = $this->live((int) $row['lease_id']);
                    if ($lease !== null) {
                        $this->quietly(fn () => app(FollowUps::class)->log($this->collector($lease, 'cash'), $lease, 'call', (string) $this->data['notes']['today'], $today));
                        break;
                    }
                }
            }
        });

        return $added;
    }

    // ── Finishing: the people's sign-ins, the papers, the units held ───────

    private function finishingTouches(): void
    {
        $this->current = $this->today->toDateString();
        $this->at($this->today, 8, 20);
        $owner = $this->users['owner'];
        foreach ($this->data['unit_states'] as $key => $s) {
            $unit = $this->unitModel($key);
            $this->quietly(fn () => app(Units::class)->update($owner, $unit, ['status' => $s['status'], 'notes' => $s['notes']]));
            $this->unitStates[(int) $unit->id] = (string) $s['status'];
        }
        foreach ($this->data['people'] as $key => $p) {
            $subject = $p['type'] === 'landlord' ? $this->landlords[$p['landlord']] : (int) $this->lease((string) $p['tenancy'])->tenant_id;
            $login = app(RentalLogins::class)->enable($owner, (string) $p['type'], (int) $subject, $this->tempEmail($key));
            $this->logins[$key] = (int) $login->id;
        }
        if (isset($this->logins['tenant'])) {
            // A repair the demo tenant reported from their own page.
            $login = \App\Models\RentalLogin::withoutGlobalScopes()->find($this->logins['tenant']);
            $this->quietly(fn () => app(TenantRequests::class)->maintenance($login, ['title' => 'Kitchen tap dripping', 'details' => 'It drips all night.', 'priority' => 'normal',
                'uuid' => $this->uuid("tap|{$this->cid}")]));
        }
        foreach ($this->data['documents'] as [$key, $whose, $kind, $name]) {
            $this->quietly(fn () => $this->document((string) $key, (string) $whose, (string) $kind, (string) $name));
        }
    }

    /** A small PDF marked as a sample, kept the way an uploaded one is. */
    private function document(string $key, string $whose, string $kind, string $name): void
    {
        $lease = $this->lease($key);
        $tenant = RentalTenant::withoutGlobalScopes()->find($lease->tenant_id);
        $html = '<h2>'.e($name).'</h2><p>'.e($tenant->name).', unit '.e(explode(':', $key)[1]).'.</p><p>This is a sample page in a demo account. Every name and number on it is made up.</p>';
        $pdf = app('dompdf.wrapper')->loadHTML($html)->setPaper('a4')->output();
        $uuid = (string) Str::uuid();
        $path = "files/{$this->cid}/demo/{$uuid}.pdf";
        PrivateFiles::put($path, $pdf);
        StoredFile::withoutGlobalScopes()->create(['company_id' => $this->cid, 'uuid' => $uuid, 'purpose' => 'rental_document', 'path' => $path, 'mime' => 'application/pdf',
            'size_bytes' => strlen($pdf), 'uploaded_by_id' => $this->users['owner']->id]);
        $doc = RentalDocument::create(['company_id' => $this->cid, 'owner_type' => $whose, 'owner_id' => $whose === 'lease' ? $lease->id : $tenant->id, 'kind' => $kind, 'name' => $name,
            'file_uuid' => $uuid, 'uploaded_by_id' => $this->users['owner']->id]);
        RentalAudit::record($this->cid, 'document.added', $doc, "Added {$name} to the file of {$tenant->name}", [], 'staff', (int) $this->users['owner']->id);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function lease(string $key): RentalLease
    {
        return RentalLease::withoutGlobalScopes()->where('company_id', $this->cid)->findOrFail($this->leases[$key] ?? 0);
    }

    private function unitModel(string $key): RentalUnit
    {
        return RentalUnit::withoutGlobalScopes()->where('company_id', $this->cid)->findOrFail($this->units[$key]);
    }

    /** "Garbage:10000:agency" as the moveIn items. @return list<array<string, mixed>> */
    private function items(string $text): array
    {
        $out = [];
        foreach (array_filter(explode(';', $text)) as $one) {
            [$name, $amount, $to] = explode(':', $one) + [2 => 'landlord'];
            $out[] = ['name' => $name, 'amount_minor' => (int) $amount, 'goes_to' => $to];
        }

        return $out;
    }

    private function uuid(string $name): string
    {
        return Uuid::uuid5(self::UUID_SPACE, "{$this->cid}|{$name}")->toString();
    }

    /** @return array<string, mixed> what the heal needs */
    private function snapshot(): array
    {
        $staff = [];
        foreach (\App\Models\RentalPropertyStaff::withoutGlobalScopes()->where('company_id', $this->cid)->get() as $s) {
            $staff[(int) $s->property_id][] = (int) $s->user_id;
        }
        $settings = \App\Models\RentalSetting::withoutGlobalScopes()->where('company_id', $this->cid)->first();

        return ['build' => self::BUILD, 'users' => array_map(fn (User $u) => (int) $u->id, $this->users), 'landlords' => $this->landlords, 'properties' => $this->properties, 'units' => $this->units,
            'behaviour' => $this->behaviour, 'logins' => $this->logins, 'staff' => $staff, 'unit_states' => $this->unitStates,
            'settings' => $settings?->only(['mode', 'currency', 'bill_days_before', 'grace_days', 'anchor', 'prorate_first', 'commission_type', 'commission_bp', 'commission_minor', 'commission_on_items',
                'deposit_months', 'deposit_held_by', 'late_fee_type', 'late_fee_minor', 'late_fee_bp', 'late_fee_to', 'payment_instructions', 'receipt_footer']),
            'through' => $this->today->toDateString(), 'started_on' => $this->start->toDateString()];
    }

    /** Move the clock to a moment of a day (never past the real now). */
    private function at(Carbon $day, int $hour, int $minute = 0): Carbon
    {
        $t = Carbon::create($day->year, $day->month, $day->day, $hour, $minute, 0, $this->tz);
        if ($t->gt($this->realNow)) {
            $t = $this->realNow->copy()->subSeconds(30);
        }
        Carbon::setTestNow($t);

        return $t;
    }

    /** One refused sample entry (a rule that bites on sample data) must not stop the rest; its half-done writes roll back. */
    private function quietly(callable $fn): mixed
    {
        try {
            return DB::transaction(fn () => $fn());
        } catch (BusinessRuleException|\DomainException|\InvalidArgumentException $e) {
            $this->skipped[] = now()->toDateString().': '.$e->getMessage();
            Log::info('[rentals-demo] sample entry skipped: '.$e->getMessage());

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
