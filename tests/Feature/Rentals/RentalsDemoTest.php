<?php

namespace Tests\Feature\Rentals;

use App\Models\Company;
use App\Models\RentalCharge;
use App\Models\RentalLease;
use App\Models\RentalLogin;
use App\Models\RentalProperty;
use App\Models\RentalTenant;
use App\Models\User;
use App\Services\Onboarding\DemoShopService;
use App\Services\Onboarding\PublicDemo;
use App\Services\Ops\TenantDataService;
use App\Services\Rentals\Collections\Arrears;
use App\Services\Rentals\Demo\RentalsDemo;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Figures;
use App\Services\Rentals\Import\RentalImport;
use App\Services\Rentals\Jobs\RentalCheck;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\RentalReports;
use App\Services\Team\TeamService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * Estate Ledger's public demo (KR-21): the agency and the landlord on his own, built through the services for a year, with
 * every case the owner asked for; books that agree; figures that read true; a sign-in per role with the right scope;
 * locked for visitors; healed; rebuilt. One build and one rebuild (each runs a year of books), so these are one test, on a
 * fixed mid-month clock so the figures do not depend on the day it runs.
 */
class RentalsDemoTest extends ApiTestCase
{
    use MakesRentals;

    private string $disk;

    /** What may never appear in the demo's text: other apps, and any real place the data could have come from. */
    private const FORBIDDEN = ['Budget Pro', 'BudgetPro', 'budget-pro', 'Shelfie', 'Kuku Tally', 'kuku-tally', 'Akiba', 'Pledge', 'Fresh Corner', 'Wakiso Teachers', 'family', 'schooldynamics', 'public-demo', 'Mogadishu'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['rentals.demo.enabled' => true, 'edition.name' => 'saas']);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class); // every sign-in of every role, from one address, on a stopped clock
        // A disk of its own: Storage::fake's folder is shared with whatever other test process runs now.
        $this->disk = sys_get_temp_dir().'/rentals-demo-'.uniqid();
        Storage::set('public', Storage::createLocalDriver(['root' => $this->disk]));
        Carbon::setTestNow('2026-10-16 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->disk);
        parent::tearDown();
    }

    public function test_build_heal_and_rebuild(): void
    {
        $demo = new RentalsDemo();
        $first = $demo->run(true);
        $this->assertContains($first['action'], ['built', 'rebuilt']);
        $this->assertSame([], $first['skipped'], 'every sample entry went through the services');
        $this->assertSame([], $first['solo']['skipped']);
        $company = RentalsDemo::company();
        $this->assertNotNull($company);
        $cid = (int) $company->id;
        $this->assertSame('Ntinda Property Managers (Demo)', $company->name);
        $this->assertTrue((bool) $company->is_demo);
        $this->assertNull($company->demo_parent_id);
        $this->assertSame('agency', RentalsModule::settings($cid)->mode);

        $this->assertBooks($cid);
        $this->assertFigures($cid);
        $this->assertEveryCase($cid);
        $this->assertSolo();
        $this->assertSignIns($cid);
        $this->assertLocked($cid);
        $this->assertNothingLeaves($cid);
        $this->assertIndependent($cid);
        $this->assertReports($cid);
        $this->assertOtherAccountsCannotSee($cid);
        $this->assertHealed($demo, $cid);
        $this->assertLeftAloneByPurges($cid);

        // The rebuild: a new account, the published sign-ins moved to it, the old one gone.
        $again = $demo->run(true);
        $this->assertSame('rebuilt', $again['action']);
        $this->assertSame([], $again['skipped']);
        $new = RentalsDemo::company();
        $this->assertNotSame($cid, (int) $new->id);
        $this->assertNull(Company::withoutGlobalScopes()->find($cid));
        foreach (['rental_entries', 'rental_payments', 'rental_logins', 'admin_users'] as $table) {
            $this->assertSame(0, DB::table($table)->where('company_id', $cid)->count(), $table);
        }
        foreach (array_keys(RentalsDemo::roles()) as $key) {
            $this->assertSame((int) $new->id, (int) RentalsDemo::login($key)->company_id, $key);
        }
        $this->assertBooks((int) $new->id);
        $this->assertSame((int) $new->id, (int) (new RentalLogins())->attempt(RentalsDemo::email('tenant'), RentalsDemo::password())[0]['company_id']);
    }

    private function assertBooks(int $cid): void
    {
        $this->assertSame([], (new RentalCheck())->run($cid), 'rentals:check finds nothing');
        $this->assertSame(0, Artisan::call('rentals:check', ['--company' => $cid]));
        foreach (RentalLedger::cash($cid) as $method => $balance) {
            $this->assertGreaterThanOrEqual(0, $balance, "the {$method} account is not overdrawn");
        }
    }

    private function assertFigures(int $cid): void
    {
        $this->assertSame(5, DB::table('rental_landlords')->where('company_id', $cid)->where('is_self', 0)->count());
        $this->assertSame(8, DB::table('rental_properties')->where('company_id', $cid)->count());
        $this->assertSame(120, DB::table('rental_units')->where('company_id', $cid)->count());
        $tenants = DB::table('rental_tenants')->where('company_id', $cid)->count();
        $this->assertGreaterThanOrEqual(100, $tenants);
        $this->assertLessThanOrEqual(135, $tenants);

        $o = Figures::overview(RentalsDemo::login('owner'));
        $this->assertGreaterThanOrEqual(75.0, $o['occupancy_pct'], 'occupancy');
        $this->assertLessThanOrEqual(95.0, $o['occupancy_pct'], 'occupancy');
        $this->assertGreaterThanOrEqual(70.0, $o['month']['rate_pct'], 'collection rate this month');
        $this->assertLessThanOrEqual(95.0, $o['month']['rate_pct'], 'collection rate this month');
        // Of the bills due last month, how much is paid by now (Figures::month also counts old arrears paid that month).
        $due = DB::table('rental_charges')->where('company_id', $cid)->whereBetween('due_date', ['2026-09-01', '2026-09-30'])->where('status', '!=', 'void')->where('kind', '!=', 'refund');
        $paid = Figures::pct((int) (clone $due)->sum('paid_minor'), (int) (clone $due)->sum('amount_minor'));
        $this->assertGreaterThanOrEqual(70.0, $paid, 'collected of last month\'s bills');
        $this->assertLessThanOrEqual(97.0, $paid, 'collected of last month\'s bills: not everyone paid');
        foreach ($o['arrears']['aging'] as $bucket => $minor) {
            $this->assertGreaterThan(0, $minor, "arrears in {$bucket}");
        }
        // The buckets hold on other days of the month too: tenants fall due on different days.
        foreach (['2026-10-02', '2026-10-09', '2026-10-28'] as $day) {
            foreach (Arrears::aging($cid, null, Carbon::parse($day))['aging'] as $bucket => $minor) {
                $this->assertGreaterThan(0, $minor, "arrears in {$bucket} on {$day}");
            }
        }
        $this->assertGreaterThan(0, $o['ending_30']);
        $this->assertGreaterThan(0, $o['maintenance_open']);
        $this->assertGreaterThan(0, $o['credit_minor']);
        $this->assertGreaterThan(0, $o['deposits_minor']);
        $this->assertGreaterThan(0, $o['landlords_minor']);
        $kinds = collect($o['attention'] ?? [])->pluck('kind')->unique()->all();
        foreach (['overdue', 'promise', 'urgent', 'ending', 'vacant'] as $kind) {
            $this->assertContains($kind, $kinds, "the overview's attention list has {$kind}");
        }
    }

    private function assertEveryCase(int $cid): void
    {
        $count = fn (string $table) => DB::table($table)->where('company_id', $cid);
        $leases = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->get();
        $live = $leases->filter->isLive();

        // Tenants paid up, paid ahead, and behind.
        $positions = $live->map(fn (RentalLease $l) => Leases::position($l));
        $this->assertGreaterThanOrEqual(30, $positions->where('state', 'paid')->count(), 'paid up');
        $ahead = $positions->filter(fn ($p) => $p['credit_minor'] > 0 && $p['paid_up_to'] !== null && $p['paid_up_to'] > now()->addDays(60)->toDateString());
        $this->assertGreaterThanOrEqual(2, $ahead->count(), 'paid months ahead');
        $this->assertGreaterThanOrEqual(5, $positions->where('state', 'overdue')->count(), 'behind');

        // Part payments, promises kept and broken, reminders.
        $this->assertGreaterThan(20, DB::table('rental_allocations as a')->join('rental_charges as c', 'c.id', '=', 'a.charge_id')->where('a.company_id', $cid)->where('c.kind', 'rent')->where('a.amount_minor', '>', 0)->whereColumn('a.amount_minor', '<', 'c.amount_minor')->count(), 'part payments');
        $kept = 0;
        $broken = 0;
        foreach ($count('rental_follow_ups')->whereNotNull('promise_on')->where('promise_on', '<', now()->toDateString())->get() as $f) {
            $paid = DB::table('rental_payments')->where('company_id', $cid)->where('lease_id', $f->lease_id)->whereBetween('paid_on', [$f->promise_on, Carbon::parse($f->promise_on)->addDays(2)->toDateString()])->exists();
            $paid ? $kept++ : $broken++;
        }
        $this->assertGreaterThan(0, $kept, 'promises kept');
        $this->assertGreaterThan(0, $broken, 'promises broken');
        $channels = $count('rental_follow_ups')->distinct()->pluck('channel')->all();
        foreach (['call', 'whatsapp', 'sms'] as $channel) {
            $this->assertContains($channel, $channels, "follow-ups by {$channel}");
        }

        // Commission as a percent and as a flat amount, both earned.
        foreach (['percent', 'flat'] as $type) {
            $this->assertGreaterThan(0, (int) DB::table('rental_allocations as a')->join('rental_charges as c', 'c.id', '=', 'a.charge_id')->where('a.company_id', $cid)->where('c.commission_type', $type)->sum('a.commission_minor'), "{$type} commission");
        }

        // Bills of each kind, voids, waivers, reversals with their reasons.
        $charges = $count('rental_charges')->selectRaw('kind, COUNT(*) AS n')->groupBy('kind')->pluck('n', 'kind');
        foreach (['rent', 'item', 'utility', 'late_fee', 'one_off', 'deposit', 'opening'] as $kind) {
            $this->assertGreaterThan(0, (int) ($charges[$kind] ?? 0), "{$kind} bills");
        }
        $this->assertGreaterThanOrEqual(2, $count('rental_charges')->where('status', 'void')->whereNotNull('void_reason')->where('source', 'manual')->count(), 'bills cancelled');
        $this->assertGreaterThanOrEqual(2, $count('rental_payments')->where('method', 'waiver')->count(), 'waivers');
        $reversed = $count('rental_payments')->whereNotNull('reversed_at')->pluck('reverse_reason');
        $this->assertGreaterThanOrEqual(2, $reversed->count(), 'payments reversed');
        $this->assertSame(0, $reversed->filter(fn ($r) => trim((string) $r) === '')->count(), 'each with its reason');
        $this->assertTrue($count('rental_payments')->where('method', 'cheque')->whereNotNull('reversed_at')->exists(), 'a bounced cheque');
        $methods = $count('rental_payments')->distinct()->pluck('method')->all();
        foreach (['cash', 'bank', 'mobile_money', 'cheque', 'deposit', 'opening'] as $m) {
            $this->assertContains($m, $methods);
        }
        $this->assertGreaterThanOrEqual(3, DB::table('rental_payments')->where('company_id', $cid)->where('paid_on', now('Africa/Kampala')->toDateString())->whereIn('method', ['cash', 'bank', 'mobile_money', 'cheque'])->count(), 'payments today');

        // Rent changes, renewals, terms that ran out, notices.
        $this->assertGreaterThan($leases->count() + 3, $count('rental_rent_changes')->count(), 'rent changes');
        $this->assertTrue($leases->contains(fn ($l) => (int) $l->rent_minor === 700_000), 'a lease whose rent went up');
        $this->assertGreaterThanOrEqual(2, $count('rental_activities')->where('action', 'lease.renewed')->count(), 'renewals');
        $this->assertGreaterThanOrEqual(2, $live->filter(fn ($l) => Leases::termEnded($l))->count(), 'terms ended, tenant stayed');
        $this->assertGreaterThanOrEqual(3, $leases->where('status', 'notice')->count(), 'under notice');
        $this->assertGreaterThanOrEqual(1, $leases->where('status', 'cancelled')->count(), 'a lease cancelled');

        // Move-outs: damage and water taken off the deposit, refunds, one kept, one refunded later, former tenants who owe.
        $ended = $leases->where('status', 'ended');
        $this->assertGreaterThanOrEqual(12, $ended->count(), 'move-outs');
        $this->assertGreaterThanOrEqual(3, $count('rental_charges')->where('source', 'move_out')->where('kind', 'one_off')->count(), 'damage charged at move-out');
        $this->assertGreaterThanOrEqual(3, $count('rental_meter_readings')->where('notes', 'Move-out reading')->whereNotNull('charge_id')->count(), 'final water reading billed');
        $this->assertGreaterThan(0, $count('rental_payments')->where('method', 'deposit')->count(), 'the deposit settling what was owed');
        foreach (['deposit_refund', 'deposit_refund_landlord'] as $type) {
            $this->assertGreaterThan(0, $count('rental_entries')->where('type', $type)->count(), $type);
        }
        $this->assertTrue($ended->contains(fn ($l) => Deposits::held($l) > 0), 'a deposit kept for now');
        $later = $ended->filter(fn ($l) => DB::table('rental_entries')->where('lease_id', $l->id)->where('type', 'deposit_refund')->where('occurred_on', '>', $l->ended_on->toDateString())->exists());
        $this->assertGreaterThanOrEqual(1, $later->count(), 'a deposit refunded after the move-out');
        $owing = $ended->filter(fn ($l) => Leases::position($l)['owed_minor'] > 0);
        $this->assertGreaterThanOrEqual(2, $owing->count(), 'former tenants who owe');
        $this->assertSame('former', RentalTenant::withoutGlobalScopes()->find($owing->first()->tenant_id)->status);
        $this->assertTrue($count('rental_payments')->whereIn('lease_id', $owing->pluck('id')->all())->where('notes', 'Part of the balance left at move-out')->exists(), 'one paying part of it');

        // Units: vacant, reserved, under repair.
        $units = $count('rental_units')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $this->assertSame(2, (int) ($units['reserved'] ?? 0));
        $this->assertSame(2, (int) ($units['maintenance'] ?? 0));
        $this->assertGreaterThanOrEqual(10, Figures::units($cid, null)['vacant'] - 4, 'vacant units');

        // Meters and fees.
        foreach (['water', 'power'] as $utility) {
            $this->assertGreaterThan(50, $count('rental_meter_readings')->where('utility', $utility)->whereNotNull('charge_id')->count(), "{$utility} bills");
        }
        foreach (['Garbage', 'Security'] as $item) {
            $this->assertTrue($count('rental_charges')->where('kind', 'item')->where('description', 'like', "{$item}%")->exists(), "{$item} fees billed");
        }

        // Maintenance in every status, with costs.
        $tickets = $count('rental_maintenance')->pluck('status')->countBy()->all();
        foreach (['open', 'in_progress', 'done', 'cancelled'] as $status) {
            $this->assertArrayHasKey($status, $tickets, "a repair {$status}");
        }
        $this->assertGreaterThan(5, $count('rental_maintenance')->where('status', 'done')->whereNotNull('expense_id')->where('cost_minor', '>', 0)->count());
        $this->assertTrue($count('rental_maintenance')->where('reported_by', 'tenant')->exists());

        // Landlords' money.
        $this->assertGreaterThanOrEqual(4 * 10, $count('rental_payouts')->count(), 'monthly payouts');
        $this->assertSame(1, $count('rental_payouts')->whereNotNull('reversed_at')->count(), 'one reversed');
        $expenses = $count('rental_expenses')->selectRaw('charged_to, COUNT(*) AS n')->groupBy('charged_to')->pluck('n', 'charged_to');
        $this->assertGreaterThan(50, (int) $expenses['landlord']);
        $this->assertGreaterThan(30, (int) $expenses['agency']);
        $this->assertSame(1, $count('rental_expenses')->whereNotNull('reversed_at')->count());
        $this->assertGreaterThan(20, $count('rental_entries')->where('type', 'transfer')->count(), 'money moved between accounts');
        $landlords = DB::table('rental_landlords')->where('company_id', $cid)->pluck('id')->all();
        foreach ($landlords as $id) {
            $this->assertGreaterThan(0, $count('rental_payouts')->where('landlord_id', $id)->count(), "landlord {$id} was paid");
        }

        // The rest.
        $this->assertGreaterThan(1000, $count('rental_activities')->count(), 'an activity log');
        $this->assertGreaterThanOrEqual(6, $count('rental_documents')->count(), 'documents');
        $doc = $count('rental_documents')->first();
        $file = DB::table('files')->where('company_id', $cid)->where('uuid', $doc->file_uuid)->first();
        $this->assertNotNull(\App\Services\Rentals\PrivateFiles::path($file->path), 'a sample paper is kept as a real one is: privately');
        Storage::disk('public')->assertMissing($file->path);
        $this->assertSame(5, $count('rental_property_staff')->count(), 'who looks after which property');
        $this->assertGreaterThan(0, $count('rental_tenants')->where('kind', 'company')->count());
        $this->assertTrue(RentalTenant::withoutGlobalScopes()->where('company_id', $cid)->whereNotNull('id_number')->where('id_number', 'not like', 'DEMO-%')->exists(), 'ID numbers are encrypted');
        foreach ($count('rental_tenants')->pluck('phone_e164') as $phone) {
            $this->assertMatchesRegularExpression('/^\+2567000\d{5}$/', (string) $phone, 'a made-up number in the right format');
        }
    }

    /** The landlord on his own: owner mode, his own sign-in, a paid plan, books that agree. */
    private function assertSolo(): void
    {
        $company = RentalsDemo::company(RentalsDemo::SOLO);
        $this->assertNotNull($company);
        $cid = (int) $company->id;
        $this->assertSame('owner', RentalsModule::settings($cid)->mode);
        $this->assertSame([], (new RentalCheck())->run($cid));
        $this->assertSame(1, DB::table('rental_landlords')->where('company_id', $cid)->where('is_self', 1)->count());
        $this->assertSame(0, DB::table('rental_landlords')->where('company_id', $cid)->where('is_self', 0)->count());
        $this->assertSame(0, (int) DB::table('rental_allocations')->where('company_id', $cid)->sum('commission_minor'), 'no agency, no commission');
        $this->assertGreaterThan(0, DB::table('rental_payouts')->where('company_id', $cid)->count(), 'owner drawings');
        $this->assertGreaterThan(50, DB::table('rental_payments')->where('company_id', $cid)->count());
        $this->assertTrue(RentalLogins::portalOnPlan($company), 'a paid plan: the portal is on');
        $this->postJson('/api/v1/auth/login', ['email' => RentalsDemo::email('owner', RentalsDemo::SOLO), 'password' => RentalsDemo::password(), 'device_name' => 'test'])->assertOk();
        $status = RentalsDemo::status();
        $this->assertSame($cid, (int) $status['solo']['company']->id);
        $this->assertSame(RentalsDemo::email('owner', RentalsDemo::SOLO), $status['solo']['email']);
        $this->assertSame('demo_locked', $this->code(fn () => app(RentalsModule::class)->saveSettings($company, RentalsDemo::login('owner', RentalsDemo::SOLO), ['grace_days' => 9])));
    }

    private function assertSignIns(int $cid): void
    {
        $this->assertTrue(RentalLogins::portalOnPlan(Company::find($cid)), 'a paid plan: the portal is on');
        $this->assertGreaterThan(0, (int) \App\Models\Plan::find(DB::table('subscriptions')->where('company_id', $cid)->value('plan_id'))->price_ugx);
        $status = RentalsDemo::status();
        $this->assertTrue($status['ready']);
        $this->assertCount(5, array_filter($status['logins'], fn ($l) => $l['user_id'] !== null));
        $this->assertCount(3, array_filter($status['people'], fn ($p) => $p['subject_id'] !== null));
        $tokens = [];
        foreach (RentalsDemo::roles() as $key => $r) {
            $res = $this->postJson('/api/v1/auth/login', ['email' => RentalsDemo::email($key), 'password' => RentalsDemo::password(), 'device_name' => 'test']);
            $res->assertOk();
            $tokens[$key] = (string) $res->json('data.token');
            $user = User::withoutGlobalScopes()->where('username', RentalsDemo::email($key))->firstOrFail();
            $this->assertSame($cid, (int) $user->company_id, $key);
            $this->assertSame($r['role'], DB::table('company_members')->where('company_id', $cid)->where('user_id', $user->id)->value('role'), $key);
        }
        // What each role may do.
        $this->assertTrue(RentalAccess::can(RentalsDemo::login('manager'), 'rentals_settings'));
        $this->assertFalse(RentalAccess::can(RentalsDemo::login('accountant'), 'rentals_manage'));
        $this->assertTrue(RentalAccess::can(RentalsDemo::login('accountant'), 'rentals_reverse'));
        $this->assertFalse(RentalAccess::can(RentalsDemo::login('caretaker'), 'rentals_landlords'));
        $this->assertTrue(RentalAccess::can(RentalsDemo::login('viewer'), 'rentals_view'));
        $this->assertFalse(RentalAccess::can(RentalsDemo::login('viewer'), 'rentals_collect'));

        // The caretaker sees two buildings; the others see all eight; a lease in another building is not there.
        $caretaker = RentalAccess::propertyIds(RentalsDemo::login('caretaker'));
        $this->assertSame(['Kiwatule Flats', 'Najjera Rooms'], RentalProperty::withoutGlobalScopes()->whereIn('id', $caretaker)->orderBy('name')->pluck('name')->all());
        $this->assertNull(RentalAccess::propertyIds(RentalsDemo::login('accountant')));
        $this->assertCount(2, $this->getJson('/api/v1/rentals/properties', $this->auth($tokens['caretaker']))->assertOk()->json('data'));
        $this->assertCount(8, $this->getJson('/api/v1/rentals/properties', $this->auth($tokens['accountant']))->assertOk()->json('data'));
        $elsewhere = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereNotIn('property_id', $caretaker)->value('id');
        $this->getJson("/api/v1/rentals/leases/{$elsewhere}", $this->auth($tokens['caretaker']))->assertNotFound();

        // The people's own sign-ins: the tenant behind on rent, the one who pays on time, the landlord.
        foreach (['tenant' => 'tenant', 'tenant-paid' => 'tenant', 'landlord' => 'landlord'] as $key => $type) {
            $res = $this->postJson('/api/v1/rentals/portal-auth/login', ['login' => RentalsDemo::email($key), 'password' => RentalsDemo::password(), 'device_name' => 'test']);
            $res->assertSuccessful();
            $auth = $this->auth((string) $res->json('data.token'));
            $me = $this->getJson('/api/v1/rentals/me', $auth)->assertOk();
            $this->assertSame($type, $me->json('data.type'), $key);
            foreach ($type === 'tenant' ? ['charges', 'payments'] : ['properties', 'payouts', 'arrears'] as $what) {
                $this->assertNotEmpty($this->getJson("/api/v1/rentals/me/{$what}", $auth)->assertOk()->json('data'), "{$key} sees their {$what}");
            }
        }
        $tenant = RentalTenant::withoutGlobalScopes()->find(RentalLogin::withoutGlobalScopes()->where('company_id', $cid)->where('email', RentalsDemo::email('tenant'))->value('subject_id'));
        $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('overdue', Leases::position($lease)['state'], 'the tenant sign-in is behind on rent');
        $this->assertGreaterThan(0, DB::table('rental_follow_ups')->where('lease_id', $lease->id)->count());
        $paid = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->where('tenant_id', RentalLogin::withoutGlobalScopes()->where('company_id', $cid)->where('email', RentalsDemo::email('tenant-paid'))->value('subject_id'))->firstOrFail();
        $this->assertNotSame('overdue', Leases::position($paid)['state'], 'the other pays on time');
    }

    private function assertLocked(int $cid): void
    {
        $company = Company::find($cid);
        $owner = RentalsDemo::login('owner');
        $property = RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->first();
        $login = RentalLogin::withoutGlobalScopes()->where('company_id', $cid)->first();
        foreach ([
            'add a person' => fn () => app(TeamService::class)->createMember($company, $owner, 'Visitor', 'v@x.example', null, 'Secret-pass-123', 'rentals_accountant'),
            'change a password' => fn () => PublicDemo::guardAccount(RentalsDemo::login('manager'), 'changing the password'),
            'delete the account' => fn () => app(TenantDataService::class)->requestDeletion($company, $owner, RentalsDemo::password()),
            'pay for a plan' => fn () => PublicDemo::guard($company, 'paying for a plan'),
            'change the settings' => fn () => app(RentalsModule::class)->saveSettings($company, $owner, ['grace_days' => 9]),
            'open a sign-in' => fn () => app(RentalLogins::class)->enable($owner, 'tenant', (int) RentalTenant::withoutGlobalScopes()->where('company_id', $cid)->value('id'), 'x@x.example'),
            'close a sign-in' => fn () => app(RentalLogins::class)->disable($owner, (string) $login->subject_type, (int) $login->subject_id),
            'set a person\'s password' => fn () => app(RentalLogins::class)->setPassword($login, 'A-new-password-9'),
            'reassign staff' => fn () => app(Properties::class)->assignStaff($owner, $property, []),
            'import' => fn () => (new RentalImport())->run($cid, $owner, [], '2026-01-01'),
        ] as $what => $try) {
            $this->assertSame('demo_locked', $this->code($try), $what);
        }
    }

    private function assertNothingLeaves(int $cid): void
    {
        Mail::fake();
        $res = (new RentalLogins())->sendCode(RentalsDemo::email('tenant'), '127.0.0.1');
        $this->assertNull($res['sent_to']);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame(0, (int) DB::table('message_log')->where('company_id', $cid)->where('status', '!=', 'skipped')->count(), 'nothing is ever sent from the demo');
    }

    private function assertIndependent(int $cid): void
    {
        $cols = ['companies' => ['id', ['name', 'address', 'email']], 'admin_users' => ['company_id', ['name', 'email', 'username']], 'rental_settings' => ['company_id', ['payment_instructions', 'receipt_footer']],
            'rental_landlords' => ['company_id', ['name', 'address', 'payout_details', 'notes']], 'rental_properties' => ['company_id', ['name', 'address', 'area', 'notes']], 'rental_units' => ['company_id', ['code', 'notes']],
            'rental_tenants' => ['company_id', ['name', 'email', 'occupation', 'employer', 'next_of_kin_name', 'notes']], 'rental_leases' => ['company_id', ['end_reason', 'notes']], 'rental_charges' => ['company_id', ['description', 'void_reason']],
            'rental_payments' => ['company_id', ['reference', 'notes', 'reverse_reason']], 'rental_follow_ups' => ['company_id', ['note']], 'rental_maintenance' => ['company_id', ['title', 'details', 'assignee_name', 'resolution']],
            'rental_expenses' => ['company_id', ['category', 'description', 'vendor']], 'rental_payouts' => ['company_id', ['reference', 'notes', 'reverse_reason']], 'rental_documents' => ['company_id', ['name']],
            'rental_activities' => ['company_id', ['summary']], 'rental_entries' => ['company_id', ['description']]];
        $text = [];
        foreach ($cols as $table => [$key, $fields]) {
            foreach (DB::table($table)->where($key, $cid)->get($fields) as $row) {
                $text[] = implode(' | ', array_map('strval', (array) $row));
            }
        }
        $all = implode("\n", $text);
        $this->assertGreaterThan(1000, strlen($all));
        foreach (self::FORBIDDEN as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $all, "the demo's text never says {$word}");
        }
    }

    private function assertReports(int $cid): void
    {
        $owner = RentalsDemo::login('owner');
        $lease = (int) RentalLease::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'active')->value('id');
        $landlord = (int) DB::table('rental_landlords')->where('company_id', $cid)->value('id');
        foreach (RentalReports::keys() as $key) {
            $r = app(RentalReports::class)->run($key, $cid, $owner, ['from' => '2025-11-01', 'to' => '2026-10-16', 'lease_id' => $lease, 'landlord_id' => $landlord, 'days' => 120, 'month' => '2026-09', 'property_id' => in_array($key, ['monthly_building'], true) ? (int) DB::table('rental_properties')->where('company_id', $cid)->value('id') : null]);
            $this->assertTrue($r['rows'] !== [] || $r['sections'] !== [], "the report {$key} has something to show");
        }
    }

    private function assertOtherAccountsCannotSee(int $cid): void
    {
        [$other, $owner] = $this->rentalsAccount();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'secret123', 'device_name' => 'test'])->assertSuccessful()->json('data.token');
        $lease = (int) RentalLease::withoutGlobalScopes()->where('company_id', $cid)->value('id');
        $this->getJson("/api/v1/rentals/leases/{$lease}", $this->auth($token))->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/rentals/tenants', $this->auth($token))->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/rentals/properties', $this->auth($token))->assertOk()->json('data'));
        $this->assertFalse(RentalsDemo::isDemoCompany((int) $other->id));
    }

    /** A visitor's damage, then the hourly heal puts it back; a new day brings the books forward; running it again adds nothing. */
    private function assertHealed(RentalsDemo $demo, int $cid): void
    {
        $counts = fn () => collect(['rental_charges', 'rental_payments', 'rental_allocations', 'rental_entries', 'rental_follow_ups', 'rental_meter_readings', 'rental_expenses', 'rental_payouts', 'rental_maintenance'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('company_id', $cid)->count()])->all();
        $before = $counts();
        $this->assertSame('healed', ($h = $demo->run())['action']);
        $this->assertSame([], $h['repairs'], 'a second run on the same day has nothing to do');
        $this->assertSame($before, $counts(), 'the heal is idempotent');

        $accountant = RentalsDemo::login('accountant');
        DB::table('admin_users')->where('id', RentalsDemo::login('owner')->id)->update(['password' => Hash::make('changed-by-visitor-1')]);
        DB::table('admin_users')->where('id', $accountant->id)->update(['status' => 'Inactive']);
        DB::table('company_members')->where('company_id', $cid)->where('user_id', $accountant->id)->update(['status' => 'removed']);
        DB::table('rental_logins')->where('company_id', $cid)->update(['disabled_at' => now()]);
        DB::table('rental_settings')->where('company_id', $cid)->update(['grace_days' => 30, 'receipt_footer' => 'Changed by a visitor']);
        DB::table('rental_property_staff')->where('company_id', $cid)->delete();
        DB::table('rental_units')->where('company_id', $cid)->where('status', 'reserved')->update(['status' => 'available']);
        DB::table('subscriptions')->where('company_id', $cid)->update(['status' => 'cancelled']);
        Company::withoutGlobalScopes()->whereKey($cid)->update(['name' => 'Renamed by a visitor']);

        $healed = $demo->run();
        $this->assertSame('healed', $healed['action']);
        $this->assertSame($cid, $healed['company_id']);
        $this->assertSame('Ntinda Property Managers (Demo)', Company::find($cid)->name);
        $this->assertTrue(Hash::check(RentalsDemo::password(), (string) RentalsDemo::login('owner')->fresh()->password));
        $this->assertSame('Active', RentalsDemo::login('accountant')->fresh()->status);
        $this->assertSame('active', DB::table('company_members')->where('company_id', $cid)->where('user_id', $accountant->id)->value('status'));
        $this->assertSame(5, (int) RentalsModule::settings($cid)->grace_days);
        $this->assertSame(5, DB::table('rental_property_staff')->where('company_id', $cid)->count());
        $this->assertSame(2, DB::table('rental_units')->where('company_id', $cid)->where('status', 'reserved')->count());
        $this->assertSame('active', DB::table('subscriptions')->where('company_id', $cid)->value('status'));
        $this->assertNotEmpty((new RentalLogins())->attempt(RentalsDemo::email('tenant'), RentalsDemo::password()));

        // The next days: the books come forward, today is alive, and the same again adds nothing.
        Carbon::setTestNow('2026-10-18 09:00:00');
        $today = now('Africa/Kampala')->toDateString();
        $this->assertSame('healed', $demo->run()['action']);
        $this->assertGreaterThanOrEqual(3, DB::table('rental_payments')->where('company_id', $cid)->where('paid_on', $today)->whereIn('method', ['cash', 'bank', 'mobile_money', 'cheque'])->count(), 'payments today');
        $this->assertTrue(DB::table('rental_follow_ups')->where('company_id', $cid)->where('promise_on', $today)->exists(), 'someone promised to pay today');
        $this->assertTrue(RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('kind', 'rent')->where('due_date', '>=', '2026-10-17')->exists(), 'bills raised for the coming days');
        $this->assertSame($today, ((array) Company::find($cid)->onboarding_state)[RentalsDemo::MARK]['snapshot']['through']);
        $this->assertBooks($cid);
        $now = $counts();
        $this->assertSame('healed', $demo->run()['action']);
        $this->assertSame($now, $counts(), 'a day runs once');
        Carbon::setTestNow('2026-10-16 10:00:00');
    }

    private function assertLeftAloneByPurges(int $cid): void
    {
        app(DemoShopService::class)->purgeExpired();
        app(TenantDataService::class)->purgeDue();
        Artisan::call('onboarding:purge-demos');
        $this->assertNotNull(Company::withoutGlobalScopes()->find($cid));
        $this->assertNotNull(RentalsDemo::company(RentalsDemo::SOLO));
        $this->assertTrue(PublicDemo::isDemoCompany($cid) === false, 'never mistaken for the shop demo');
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $e->errorCode();
        }

        return 'none';
    }
}
