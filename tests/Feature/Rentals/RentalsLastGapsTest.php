<?php

namespace Tests\Feature\Rentals;

use App\Models\Company;
use App\Models\Plan;
use App\Models\RentalCharge;
use App\Models\RentalLandlord;
use App\Models\RentalLogin;
use App\Models\RentalTenant;
use App\Models\Subscription;
use App\Services\Billing\Lifecycle;
use App\Services\Billing\PlanLine;
use App\Services\Billing\Quotas;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Messages\RentalMessages;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Reports\RentalExport;
use App\Services\Rentals\Reports\RentalReports;
use Database\Seeders\EstateLedgerPlanSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * The last gaps of the rent books: the own sign-in follows the plan, a caretaker never reads how a landlord is paid, the
 * API takes the cashier's split, the plans are the owner's prices, list exports carry the list's filters and cannot
 * smuggle a formula, and a prepared message is written down without leaving the machine.
 */
class RentalsLastGapsTest extends ApiTestCase
{
    use MakesRentals;

    private const PASS = 'Tenant-pass-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{company: Company, owner: \App\Models\User, lease: \App\Models\RentalLease, tenant: RentalTenant, login: RentalLogin} */
    private function world(): array
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [, $units] = $this->building($owner, 2, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0, 'tenant' => ['name' => 'Asha Nakato', 'phone' => '0701111111', 'email' => 'asha'.uniqid().'@example.com']]);
        $tenant = RentalTenant::findOrFail($lease->tenant_id);
        $login = app(RentalLogins::class)->enable($owner, 'tenant', (int) $tenant->id, null, null, self::PASS);

        return compact('company', 'owner', 'lease', 'tenant', 'login');
    }

    private function toPlan(Company $company, string $slug): void
    {
        Subscription::where('company_id', $company->id)->update(['plan_id' => Plan::where('slug', $slug)->value('id')]);
    }

    // ── 1. The own sign-in comes with a paid plan ─────────────────────────

    public function test_the_plans_are_the_owners_and_the_own_sign_in_is_off_on_free_only(): void
    {
        $this->seed(EstateLedgerPlanSeeder::class);
        $plan = fn (string $slug) => Plan::where('slug', $slug)->firstOrFail();
        foreach (['estate-ledger-starter' => 35000, 'estate-ledger-growth' => 95000, 'estate-ledger-agency' => 250000] as $slug => $month) {
            $this->assertSame((float) $month, $plan($slug)->chargeIn('UGX')['amount'], $slug);
            $this->assertSame((float) $month * 10, $plan($slug)->chargeIn('UGX', 'year')['amount'], "{$slug}: a year costs ten months");
            $this->assertTrue($plan($slug)->features['rentals_portal'], $slug);
            $this->assertSame(PlanLine::ESTATE_LEDGER, PlanLine::of($plan($slug)));
        }
        $this->assertSame(30, $plan('estate-ledger-trial')->trial_days);
        $this->assertTrue($plan('estate-ledger-trial')->features['rentals_portal'], 'the trial shows what the portal does');
        $this->assertFalse($plan('estate-ledger-free')->features['rentals_portal']);
        $this->assertSame(10, $plan('estate-ledger-free')->limits['max_units']);
        $this->assertTrue($plan('estate-ledger-free')->isFree());
    }

    public function test_a_lapsed_account_refuses_every_way_in_on_the_next_request(): void
    {
        $w = $this->world();
        $email = (string) $w['login']->email;
        $token = $this->postJson('/api/v1/rentals/portal-auth/login', ['login' => $email, 'password' => self::PASS])->assertStatus(201)->json('data.token');
        $this->getJson('/api/v1/rentals/me', $this->auth($token))->assertOk();

        $this->toPlan($w['company'], 'estate-ledger-free');

        $res = $this->getJson('/api/v1/rentals/me', $this->auth($token))->assertStatus(401);
        $this->assertSame('portal_not_on_plan', $res->json('errors.code'));
        $this->assertSame(RentalLogins::PLAN_OFF, $res->json('message'));
        $this->assertNull(app(RentalLogins::class)->resolve((int) $w['login']->id));

        // The right password hears why; a wrong one hears what anyone hears, so no sign-in is given away.
        $this->postJson('/api/v1/rentals/portal-auth/login', ['login' => $email, 'password' => self::PASS])->assertStatus(403)->assertJsonPath('errors.code', 'portal_not_on_plan');
        $wrong = $this->postJson('/api/v1/rentals/portal-auth/login', ['login' => $email, 'password' => 'not-the-one-1'])->assertStatus(401)->json();
        $nobody = $this->postJson('/api/v1/rentals/portal-auth/login', ['login' => 'nobody@example.com', 'password' => 'not-the-one-1'])->assertStatus(401)->json();
        $this->assertSame($nobody, $wrong);

        // A code proves the email; it too hears why.
        DB::table('otp_codes')->delete();
        $code = null;
        $this->postJson('/api/v1/rentals/portal-auth/code', ['login' => $email])->assertOk();
        $body = (string) DB::table('message_log')->where('to', $email)->orderByDesc('id')->value('body');
        if (preg_match('/\b(\d{6})\b/', $body, $m)) {
            $code = $m[1];
        }
        $this->assertNotNull($code, 'the code is still sent: only proving it tells the person why');
        $this->postJson('/api/v1/rentals/portal-auth/code/verify', ['login' => $email, 'code' => $code])->assertStatus(403)->assertJsonPath('errors.code', 'portal_not_on_plan');

        // Back on a paid plan the sign-in works again, with a new token.
        $this->toPlan($w['company'], 'estate-ledger-starter');
        $this->postJson('/api/v1/rentals/portal-auth/login', ['login' => $email, 'password' => self::PASS])->assertStatus(201);
    }

    public function test_the_trial_ending_closes_the_sign_ins_through_the_lifecycle(): void
    {
        $w = $this->world();
        $this->toPlan($w['company'], 'estate-ledger-trial');
        Subscription::where('company_id', $w['company']->id)->update(['status' => 'trialing', 'trial_ends_at' => now()->addDays(30), 'ends_at' => now()->addDays(30)]);
        $this->assertNotNull(app(RentalLogins::class)->resolve((int) $w['login']->id), 'on trial the sign-in works');

        Carbon::setTestNow(now()->addDays(31));
        (new Lifecycle)->run();
        $company = Company::withoutGlobalScopes()->findOrFail($w['company']->id);
        $this->assertSame('estate-ledger-free', $company->subscription->plan->slug);
        $this->assertNull(app(RentalLogins::class)->resolve((int) $w['login']->id));
        $this->assertTrue(app(RentalLogins::class)->closedByPlan((int) $w['login']->id));
    }

    public function test_a_paid_plan_past_its_grace_closes_the_sign_ins_before_the_lifecycle_runs(): void
    {
        $w = $this->world();
        Subscription::where('company_id', $w['company']->id)->update(['status' => 'expired', 'ends_at' => now()->subDays(60)]);
        $this->assertNull(app(RentalLogins::class)->resolve((int) $w['login']->id));
    }

    public function test_opening_a_sign_in_on_a_plan_without_it_is_refused_in_plain_words(): void
    {
        $w = $this->world();
        $this->toPlan($w['company'], 'estate-ledger-free');
        $landlord = RentalLandlord::withoutGlobalScopes()->where('company_id', $w['company']->id)->firstOrFail();
        $res = $this->postJson("/api/v1/rentals/landlords/{$landlord->id}/login", ['email' => 'l'.uniqid().'@example.com', 'password' => self::PASS], $this->auth($w['owner']->createToken('t')->plainTextToken))->assertStatus(422);
        $this->assertStringContainsString('comes with a paid plan', $res->json('message'));
    }

    // ── 2. A caretaker never reads how a landlord is paid ─────────────────

    public function test_a_caretaker_reads_no_payout_details_on_any_payload_it_can_reach(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$p, $units] = $this->building($owner, 1, 300000);
        $landlord = RentalLandlord::findOrFail($p->landlord_id);
        $landlord->forceFill(['payout_method' => 'bank', 'payout_details' => 'Stanbic 9030012345678', 'notes' => 'Or mobile money 0772999111'])->save();
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        $care = $this->rentalsStaff($company, 'rentals_caretaker');
        app(\App\Services\Rentals\Directory\Properties::class)->assignStaff($owner, $p, [(int) $care->id]);
        $h = $this->auth($care->createToken('t')->plainTextToken);

        $list = $this->getJson('/api/v1/rentals/landlords', $h)->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertNull($list[0]['payout_details']);
        $this->assertNull($list[0]['payout_method']);
        $this->assertNull($list[0]['notes']);
        $one = $this->getJson("/api/v1/rentals/landlords/{$landlord->id}", $h)->assertOk()->json('data');
        $this->assertNull($one['payout_details']);
        $this->assertSame($landlord->name, $one['name']);

        $all = '';
        foreach (['overview', 'properties', "properties/{$p->id}", 'units', "units/{$units[0]->id}", 'tenants', 'leases', "leases/{$lease->id}", 'payments', 'arrears', 'settings', 'maintenance'] as $path) {
            $all .= $this->getJson("/api/v1/rentals/{$path}", $h)->assertOk()->getContent();
        }
        $this->assertStringNotContainsString('9030012345678', $all);
        $this->assertStringNotContainsString('0772999111', $all);
        $this->assertStringNotContainsString('payout_details', $all);

        // No statement, no reports, no activity for a caretaker (§7).
        $this->getJson("/api/v1/rentals/landlords/{$landlord->id}/statement", $h)->assertStatus(403);
        $this->getJson('/api/v1/rentals/reports', $h)->assertStatus(403);
        $this->getJson('/api/v1/rentals/reports/rent_roll', $h)->assertStatus(403);
        $this->getJson('/api/v1/rentals/activity', $h)->assertStatus(403);

        // Everyone else keeps them, and so does the landlord's own view.
        $staff = $this->getJson("/api/v1/rentals/landlords/{$landlord->id}", $this->auth($owner->createToken('o')->plainTextToken))->assertOk()->json('data');
        $this->assertSame('Stanbic 9030012345678', $staff['payout_details']);
        $this->assertSame('bank', $staff['payout_method']);
    }

    // ── 3. The API takes the cashier's split ──────────────────────────────

    public function test_the_payments_endpoint_takes_the_cashiers_split_and_stays_idempotent(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [, $units] = $this->building($owner, 1, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 200000]);
        $bills = RentalCharge::withoutGlobalScopes()->where('company_id', $company->id)->where('lease_id', $lease->id)->orderBy('id')->get()->keyBy('kind');
        $h = $this->auth($owner->createToken('t')->plainTextToken);
        $base = ['lease_id' => $lease->id, 'method' => 'cash'];

        // The engine alone would settle the deposit first; the cashier says rent.
        $uuid = (string) Str::uuid();
        $split = [['charge_id' => $bills['rent']->id, 'amount_minor' => 150000]];
        $body = $base + ['uuid' => $uuid, 'amount_minor' => 150000, 'split' => $split];
        $first = $this->postJson('/api/v1/rentals/payments', $body, $h)->assertStatus(201)->json('data');
        $this->assertSame([[$bills['rent']->id, 150000]], array_map(fn ($a) => [$a['charge_id'], $a['amount_minor']], $first['allocations']));
        $this->assertSame(0, (int) $bills['deposit']->fresh()->paid_minor);
        $this->assertSame(150000, (int) $bills['rent']->fresh()->paid_minor);

        // A resend is the same payment; the same id with another split is a conflict.
        $again = $this->postJson('/api/v1/rentals/payments', $body, $h)->assertOk()->json('data');
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(150000, (int) $bills['rent']->fresh()->paid_minor);
        $this->postJson('/api/v1/rentals/payments', ['split' => [['charge_id' => $bills['deposit']->id, 'amount_minor' => 150000]]] + $body, $h)
            ->assertStatus(409)->assertJsonPath('errors.code', 'idempotency_conflict');

        // The Idempotency-Key path too.
        $key = ['Idempotency-Key' => (string) Str::uuid()];
        $keyed = $base + ['amount_minor' => 1000, 'split' => [['charge_id' => $bills['rent']->id, 'amount_minor' => 1000]]];
        $once = $this->postJson('/api/v1/rentals/payments', $keyed, $h + $key)->assertStatus(201)->json('data.id');
        $this->assertSame($once, $this->postJson('/api/v1/rentals/payments', $keyed, $h + $key)->json('data.id'));
        $this->assertSame(151000, (int) $bills['rent']->fresh()->paid_minor);

        // Refusals, each in its own code.
        $post = fn (array $split, int $amount = 1000) => $this->postJson('/api/v1/rentals/payments', $base + ['amount_minor' => $amount, 'split' => $split], $h)->assertStatus(422)->json('errors.code');
        $this->assertSame('split_invalid', $post([['charge_id' => $bills['rent']->id, 'amount_minor' => 10.5]]));
        $this->assertSame('split_invalid', $post([['charge_id' => $bills['rent']->id, 'amount_minor' => '1000']]));
        $this->assertSame('split_invalid', $post([['amount_minor' => 1000]]));
        $this->assertSame('split_invalid', $post(['rent']));
        $this->assertSame('split_invalid', $post(['charge_id' => $bills['rent']->id, 'amount_minor' => 1000]));
        $this->assertSame('split_too_much', $post([['charge_id' => $bills['rent']->id, 'amount_minor' => 900000]], 900000));
        $this->assertSame('split_total', $post([['charge_id' => $bills['rent']->id, 'amount_minor' => 500]]));
        $this->assertSame('split_total', $post([['charge_id' => $bills['rent']->id, 'amount_minor' => 2000]]));
        $this->postJson('/api/v1/rentals/payments', $base + ['amount_minor' => 1000, 'split' => 'rent'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'split_invalid');

        [$other, $otherOwner] = $this->rentalsAccount();
        [, $otherUnits] = $this->building($otherOwner, 1, 300000);
        $foreign = RentalCharge::withoutGlobalScopes()->where('company_id', $other->id)->where('lease_id', $this->tenantIn($otherOwner, $otherUnits[0], '2026-03-01', ['deposit_minor' => 0])->id)->firstOrFail();
        $this->assertSame('split_wrong_bill', $post([['charge_id' => $foreign->id, 'amount_minor' => 1000]]));
        $this->assertBooksAgree((int) $company->id);
    }

    // ── 6. Exports carry the list's filters, and no formula ───────────────

    public function test_list_reports_follow_the_lists_filters_and_neutralise_formulas(): void
    {
        Carbon::setTestNow('2026-03-20 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$p, $units] = $this->building($owner, 3, 300000);
        $evil = $this->tenantIn($owner, $units[0], '2026-02-01', ['deposit_minor' => 0, 'tenant' => ['name' => '=HYPERLINK("http://evil.example","pay")', 'phone' => '0701111111']]);
        $paid = $this->tenantIn($owner, $units[1], '2026-03-15', ['deposit_minor' => 0, 'tenant' => ['name' => 'Grace Paid', 'phone' => '0702222222']]);
        app(Payments::class)->receive($owner, $paid, ['amount_minor' => 300000, 'method' => 'mobile_money', 'reference' => '@SUM(1+1)']);
        $reversed = app(Payments::class)->receive($owner, $paid, ['amount_minor' => 7000, 'method' => 'cash']);
        app(Payments::class)->reverse($owner, $reversed, 'typed twice');
        $run = fn (string $key, array $params = []) => (new RentalReports)->run($key, $cid, $owner, $params);
        $cols = fn (array $r, string $c) => array_column($r['rows'], $c);

        $this->assertSame(['Grace Paid'], $cols($run('tenant_list', ['q' => 'grace']), 'name'));
        $this->assertCount(1, $run('tenant_list', ['status' => 'owing'])['rows']);
        $this->assertSame(['A3'], $cols($run('rent_roll', ['state' => 'vacant']), 'unit'));
        $this->assertSame(['A1'], $cols($run('rent_roll', ['state' => 'overdue']), 'unit'));
        $this->assertSame(['A2'], $cols($run('rent_roll', ['q' => 'grace']), 'unit'));
        $this->assertSame(1, $run('rent_roll', ['state' => 'overdue'])['totals']['units']);
        $this->assertSame(['A1'], $cols($run('lease_list', ['q' => $evil->number]), 'unit'));
        $this->assertCount(2, $run('lease_list', ['status' => 'live'])['rows']);
        $this->assertSame([], $run('lease_list', ['status' => 'ended'])['rows']);
        $this->assertCount(1, $run('arrears_aging', ['q' => 'HYPERLINK'])['rows']);
        $this->assertSame([], $run('arrears_aging', ['q' => 'grace'])['rows']);

        $period = ['from' => '2026-03-01', 'to' => '2026-03-20'];
        $this->assertSame(300000, $run('receipts', $period)['totals']['amount_minor'], 'reversed money is not counted');
        $this->assertCount(2, $run('receipts', $period)['rows']);
        $this->assertCount(1, $run('receipts', $period + ['view' => 'reversed'])['rows']);
        $this->assertCount(1, $run('receipts', $period + ['method' => 'mobile_money'])['rows']);
        $this->assertSame([], $run('receipts', $period + ['q' => 'nobody'])['rows']);

        foreach (['tenant_list', 'rent_roll', 'lease_list', 'arrears_aging'] as $key) {
            $csv = RentalExport::csv($run($key), $cid);
            $this->assertStringContainsString("'=HYPERLINK", $csv, $key);
            $this->assertStringNotContainsString(',"=HYPERLINK', $csv, $key);
        }
        $this->assertStringContainsString("'@SUM(1+1)", RentalExport::csv($run('receipts', $period), $cid));
        $this->assertSame("' =1+1", RentalExport::safe(' =1+1'));
        $this->assertSame("'-1+1", RentalExport::safe('-1+1'));
        $this->assertSame('-25000', RentalExport::safe('-25000'));
        $this->assertSame('+256700000001', RentalExport::safe('+256700000001'));
        $this->assertSame('Grace', RentalExport::safe('Grace'));
    }

    public function test_the_expenses_report_follows_the_lists_status_category_and_search(): void
    {
        Carbon::setTestNow('2026-03-20 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$p, $units] = $this->building($owner, 1, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 300000, 'method' => 'cash']);
        $expenses = app(\App\Services\Rentals\Expenses::class);
        $expenses->record($owner, ['property_id' => $p->id, 'category' => 'Repairs', 'description' => 'Kitchen tap', 'amount_minor' => 10000, 'method' => 'cash', 'charged_to' => 'landlord']);
        $expenses->record($owner, ['category' => 'Agency office', 'description' => 'Paper', 'amount_minor' => 2000, 'method' => 'cash', 'charged_to' => 'agency']);
        $gone = $expenses->record($owner, ['property_id' => $p->id, 'category' => 'Repairs', 'description' => 'Typed twice', 'amount_minor' => 5000, 'method' => 'cash', 'charged_to' => 'landlord']);
        $expenses->reverse($owner, $gone, 'typed twice');
        $total = fn (array $params = []) => (new RentalReports)->run('expenses', $cid, $owner, ['from' => '2026-03-01', 'to' => '2026-03-20'] + $params)['totals']['amount_minor'];
        $this->assertSame(12000, $total());
        $this->assertSame(12000, $total(['status' => 'live']));
        $this->assertSame(10000, $total(['status' => 'landlord']));
        $this->assertSame(2000, $total(['status' => 'agency']));
        $this->assertSame(0, $total(['status' => 'reversed']));
        $this->assertSame(10000, $total(['category' => 'Repairs', 'status' => 'live']));
        $this->assertSame(2000, $total(['q' => 'paper']));
    }

    public function test_paid_up_to_for_many_leases_says_what_it_says_for_one(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [, $owner] = $this->rentalsAccount();
        [, $units] = $this->building($owner, 4, 300000);
        $a = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        $b = $this->tenantIn($owner, $units[1], '2026-03-01', ['deposit_minor' => 0]);
        $c = $this->tenantIn($owner, $units[2], '2026-03-01', ['deposit_minor' => 0]);
        $d = $this->tenantIn($owner, $units[3], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $b, ['amount_minor' => 100000, 'method' => 'cash']);
        app(Payments::class)->receive($owner, $c, ['amount_minor' => 1000000, 'method' => 'cash']);
        app(Payments::class)->receive($owner, $d, ['amount_minor' => 300000, 'method' => 'cash']);
        app(Leases::class)->changeRent($owner, $c, 400000, '2026-04-01');
        $many = Leases::paidUpToMany([$a, $b, $c->fresh(), $d]);
        foreach ([$a, $b, $c->fresh(), $d] as $l) {
            $this->assertSame(Leases::paidUpTo($l), $many[(int) $l->id], $l->number);
        }
    }

    // ── 8. A prepared message is written down and leaves nothing ──────────

    public function test_a_prepared_message_fills_the_figures_is_logged_and_sends_nothing(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Log::spy();
        $w = $this->world();
        Carbon::setTestNow('2026-03-20 09:00:00');
        $reminder = RentalMessages::reminder($w['lease']->fresh());
        $this->assertStringContainsString('Asha Nakato', $reminder['text']);
        $this->assertStringContainsString('A1', $reminder['text']);
        $this->assertStringContainsString('UGX 300,000', $reminder['text']);

        $due = RentalMessages::forLease($w['lease']->fresh(), 'due');
        $this->assertStringContainsString('Balance: UGX 300,000', $due);

        $id = RentalMessages::recordPrepared((int) $w['company']->id, 'whatsapp', $reminder['phone'], $reminder['text'], 'rentals_reminder', (int) $w['owner']->id);
        $row = DB::table('message_log')->find($id);
        $this->assertSame(['prepared', 'whatsapp', 'rentals_reminder'], [$row->status, $row->channel, $row->purpose]);
        $this->assertSame($reminder['text'], $row->body);
        Log::shouldHaveReceived('info')->withArgs(fn ($m) => str_contains((string) $m, '[messaging:whatsapp] to '.$reminder['phone']));
        Http::assertNothingSent();

        // A live driver is never called: only the record is kept.
        config(['messaging.whatsapp.driver' => 'meta']);
        $this->assertNotNull(RentalMessages::recordPrepared((int) $w['company']->id, 'whatsapp', $reminder['phone'], 'x', 'rentals_reminder'));
        Http::assertNothingSent();
        $this->assertNull(RentalMessages::recordPrepared((int) $w['company']->id, 'whatsapp', '', 'x', 'rentals_reminder'), 'no phone, no message');
        $this->assertNull(RentalMessages::recordPrepared((int) $w['company']->id, 'mail', 'a@example.com', 'x', 'rentals_reminder'));
        $this->assertSame(0, (new Quotas)->usage(Company::withoutGlobalScopes()->find($w['company']->id))['sms']['used'] ?? 0);
    }
}
