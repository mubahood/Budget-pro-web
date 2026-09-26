<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\Lifecycle;
use App\Services\FlutterwaveService;
use Illuminate\Support\Facades\DB;

/**
 * POWER_PLAN §4.2 — annual billing, mobile-money prompts with polling, the caller's own return URL,
 * downgrades at the end of the period, renewal reminders and card auto-renew. Flutterwave is faked.
 */
class BillingCapabilityTest extends ApiTestCase
{
    private FakeFlutterwaveService $flw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->flw = new FakeFlutterwaveService();
        $this->app->instance(FlutterwaveService::class, $this->flw);
    }

    private function sub(int $companyId): Subscription
    {
        return Subscription::where('company_id', $companyId)->orderByDesc('id')->first();
    }

    private function pay(array $t, Plan $plan, string $interval = 'month', array $extra = []): array
    {
        $c = $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id, 'interval' => $interval], $this->auth($t['token']))->assertOk()->json('data');
        $this->flw->willVerify($c['tx_ref'], $c['amount'], $c['currency']);
        $this->flw->verifyResult['data'] += $extra;
        $this->postJson('/api/v1/subscription/verify', ['transaction_id' => 999001, 'tx_ref' => $c['tx_ref']], $this->auth($t['token']))->assertOk();

        return $c;
    }

    private function notices(int $cid, string $key): int
    {
        return DB::table('scheduled_notices')->where('company_id', $cid)->where('key', $key)->count();
    }

    public function test_annual_billing_is_quoted_with_two_months_free_and_buys_a_year(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $business = Plan::where('slug', 'business')->first();
        $q = $this->getJson("/api/v1/subscription/quote?plan_id={$business->id}&interval=year", $this->auth($t['token']))->assertOk()->json('data');
        $this->assertSame('year', $q['interval']);
        $this->assertEquals($business->chargeIn('UGX', 'year')['amount'], $q['amount']);
        $this->assertEqualsWithDelta(185000 * 10, $q['amount'], 0.01);
        $this->assertEqualsWithDelta(185000 * 2, $q['saving'], 0.01);
        $this->assertEqualsWithDelta(185000 * 10 / 12, $q['per_month'], 0.01);
        $this->assertEqualsWithDelta(185000 * 10 / 365, $q['per_day'], 0.01);
        $this->getJson('/api/v1/plans')->assertOk()->assertJsonFragment(['slug' => 'business', 'price_ugx_annual' => 1850000]);

        $trialEnd = $this->sub($t['company_id'])->ends_at;
        $this->pay($t, $business, 'year');
        $sub = $this->sub($t['company_id']);
        $this->assertSame('year', $sub->billing_interval);
        $base = $trialEnd && $trialEnd->isFuture() ? $trialEnd : now();
        $this->assertEqualsWithDelta($base->copy()->addYear()->timestamp, $sub->ends_at->timestamp, 60);
        $inv = SubscriptionInvoice::where('company_id', $t['company_id'])->where('status', 'paid')->first();
        $this->assertSame('year', $inv->interval());
        $this->assertEqualsWithDelta(365, $inv->period_start->diffInDays($inv->period_end), 1);
    }

    public function test_mobile_money_prompt_is_polled_until_paid_and_a_double_tap_sends_one_prompt(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $h = $this->auth($t['token']);
        $plan = Plan::where('slug', 'starter')->first();
        $r = $this->postJson('/api/v1/subscription/momo', ['plan_id' => $plan->id, 'phone' => '0772 123456', 'network' => 'mtn'], $h)->assertOk()->json('data');
        $again = $this->postJson('/api/v1/subscription/momo', ['plan_id' => $plan->id, 'phone' => '+256772123456', 'network' => 'MTN'], $h)->assertOk()->json('data');
        $this->assertSame($r['invoice_id'], $again['invoice_id'], 'the invoice reference is the idempotency key');
        $this->assertCount(1, $this->flw->charges);
        $charge = $this->flw->charges[0];
        $this->assertSame('mobile_money_uganda', $charge['type']);
        $this->assertSame($r['tx_ref'], $charge['payload']['tx_ref']);
        $this->assertSame('256772123456', $charge['payload']['phone_number']);
        $this->assertSame('MTN', $charge['payload']['network']);
        $this->assertEquals(70000, $charge['payload']['amount']);

        $this->getJson("/api/v1/subscription/payments/{$r['invoice_id']}", $h)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->flw->customerAnswers($r['tx_ref'], 70000, 'UGX');
        $this->getJson("/api/v1/subscription/payments/{$r['invoice_id']}", $h)->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.subscription.plan.slug', 'starter');
        $this->assertSame('momo', data_get(SubscriptionInvoice::find($r['invoice_id'])->meta, 'method'));

        // A declined prompt fails the invoice and nothing changes.
        $d = $this->postJson('/api/v1/subscription/momo', ['plan_id' => Plan::where('slug', 'business')->value('id'), 'phone' => '0752000111', 'network' => 'AIRTEL'], $h)->assertOk()->json('data');
        $this->flw->customerAnswers($d['tx_ref'], $d['quote']['amount'], 'UGX', 'failed');
        $this->getJson("/api/v1/subscription/payments/{$d['invoice_id']}", $h)->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertSame('starter', $this->sub($t['company_id'])->plan->slug);

        $this->postJson('/api/v1/subscription/momo', ['plan_id' => $plan->id, 'phone' => '0772123456', 'network' => 'VODA'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'invalid_network');
    }

    public function test_checkout_takes_the_callers_return_url(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $r = app(BillingService::class)->checkout(Company::find($t['company_id']), User::find($t['user_id']), Plan::where('slug', 'starter')->first(), 'month', 'https://shop.example.test/plan/return', 'card');
        $this->assertSame('https://shop.example.test/plan/return', $this->flw->lastPayload['redirect_url']);
        $this->assertSame('card', $this->flw->lastPayload['payment_options']);
        $this->assertStringContainsString($r['tx_ref'], $r['payment_link']);
    }

    public function test_a_downgrade_scheduled_for_the_end_of_the_period_is_applied_then(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $business = Plan::where('slug', 'business')->first();
        $starter = Plan::where('slug', 'starter')->first();
        $this->pay($t, $business);
        $q = $this->getJson("/api/v1/subscription/quote?plan_id={$starter->id}", $this->auth($t['token']))->assertOk()->json('data');
        $this->assertTrue($q['downgrade']);
        $this->assertTrue($q['can_schedule']);

        $this->postJson('/api/v1/subscription/schedule-change', ['plan_id' => $starter->id], $this->auth($t['token']))->assertOk()
            ->assertJsonPath('data.subscription.pending_plan.slug', 'starter');
        $sub = $this->sub($t['company_id']);
        $this->assertSame($business->id, $sub->plan_id, 'the shop keeps Business until the end');
        $this->assertEquals($sub->ends_at, $sub->pending_change_at);

        app(Lifecycle::class)->run();
        $this->assertSame($business->id, $sub->fresh()->plan_id);
        $this->travelTo($sub->ends_at->copy()->addHour());
        $counts = app(Lifecycle::class)->run();
        $sub->refresh();
        $this->assertSame(1, $counts['changes_applied']);
        $this->assertSame($starter->id, $sub->plan_id);
        $this->assertNull($sub->pending_plan_id);
        $this->assertSame('past_due', $sub->status, 'Starter is then renewed at its own price');
        $this->assertSame(1, DB::table('billing_events')->where('company_id', $t['company_id'])->where('action', 'change_applied')->count());

        // Cancelling a scheduled change keeps the plan.
        $t2 = $this->registerTenant();
        $this->pay($t2, $business);
        $this->postJson('/api/v1/subscription/schedule-change', ['plan_id' => $starter->id], $this->auth($t2['token']))->assertOk();
        $this->postJson('/api/v1/subscription/cancel-change', [], $this->auth($t2['token']))->assertOk()->assertJsonPath('data.subscription.pending_plan', null);
    }

    public function test_renewal_reminders_go_out_seven_three_and_one_day_before_once_each(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $this->pay($t, Plan::where('slug', 'starter')->first());
        $end = $this->sub($t['company_id'])->ends_at->copy();
        foreach ([6.5 * 24 => 1, 2.5 * 24 => 2, 12 => 3] as $hoursBefore => $expected) {
            $this->travelTo($end->copy()->subHours((int) $hoursBefore));
            app(Lifecycle::class)->run();
            app(Lifecycle::class)->run();
            $this->assertSame($expected, $this->notices($t['company_id'], 'renewal'));
        }
        $titles = DB::table('app_notifications')->where('company_id', $t['company_id'])->where('title', 'like', 'Starter renews in%')->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['Starter renews in 7 days', 'Starter renews in 3 days', 'Starter renews in 1 day'], $titles);
        $this->assertStringContainsString(BillingService::billingUrl(), (string) DB::table('app_notifications')->where('company_id', $t['company_id'])->where('title', 'Starter renews in 1 day')->value('body'));
    }

    public function test_card_auto_renew_charges_the_saved_card_once_before_the_end(): void
    {
        config(['saas.auto_renew' => true]);
        $t = $this->registerTenant(['currency' => 'UGX']);
        $this->pay($t, Plan::where('slug', 'starter')->first(), 'month', ['payment_type' => 'card', 'card' => ['token' => 'flw-t1nf-abc', 'last_4digits' => '4081', 'type' => 'VISA', 'expiry' => '09/31']]);
        $sub = $this->sub($t['company_id']);
        $this->assertTrue($sub->auto_renew);
        $this->assertSame('4081', $sub->savedCard()['last4']);
        $end = $sub->ends_at->copy();

        $this->travelTo($end->copy()->subHours(36));
        $counts = app(Lifecycle::class)->run();
        app(Lifecycle::class)->run();
        $this->assertSame(1, $counts['auto_renew']);
        $this->assertCount(1, $this->flw->tokenCharges);
        $this->assertSame('flw-t1nf-abc', $this->flw->tokenCharges[0]['token']);
        $this->assertEquals(70000, $this->flw->tokenCharges[0]['amount']);
        $this->assertEqualsWithDelta($end->copy()->addMonth()->timestamp, $sub->fresh()->ends_at->timestamp, 5);
        $this->assertSame(2, SubscriptionInvoice::where('company_id', $t['company_id'])->where('status', 'paid')->count());
    }

    public function test_a_failed_auto_renew_falls_back_to_reminders(): void
    {
        config(['saas.auto_renew' => true]);
        $t = $this->registerTenant(['currency' => 'UGX']);
        $this->pay($t, Plan::where('slug', 'starter')->first(), 'month', ['payment_type' => 'card', 'card' => ['token' => 'flw-t1nf-xyz', 'last_4digits' => '1111']]);
        $end = $this->sub($t['company_id'])->ends_at->copy();
        $this->flw->tokenResult = ['success' => false, 'message' => 'Insufficient funds'];

        $this->travelTo($end->copy()->subHours(30));
        $counts = app(Lifecycle::class)->run();
        app(Lifecycle::class)->run();
        $this->assertSame(1, $counts['auto_renew_failed']);
        $this->assertCount(1, $this->flw->tokenCharges, 'one attempt per period');
        $this->assertSame('failed', SubscriptionInvoice::where('company_id', $t['company_id'])->orderByDesc('id')->value('status'));
        $this->assertSame(1, DB::table('app_notifications')->where('company_id', $t['company_id'])->where('title', 'We could not renew with your card')->count());
        $this->assertSame(1, $this->notices($t['company_id'], 'renewal'), 'the reminder still goes out');
        $this->assertTrue($this->sub($t['company_id'])->ends_at->equalTo($end));
    }

    public function test_auto_renew_stays_off_while_the_platform_switch_is_off(): void
    {
        config(['saas.auto_renew' => false]);
        $t = $this->registerTenant(['currency' => 'UGX']);
        $this->pay($t, Plan::where('slug', 'starter')->first(), 'month', ['payment_type' => 'card', 'card' => ['token' => 'flw-t1nf-off']]);
        $sub = $this->sub($t['company_id']);
        $this->assertFalse($sub->auto_renew);
        $this->travelTo($sub->ends_at->copy()->subHours(20));
        app(Lifecycle::class)->run();
        $this->assertSame([], $this->flw->tokenCharges);
        $this->getJson('/api/v1/subscription', $this->auth($t['token']))->assertOk()->assertJsonPath('data.subscription.auto_renew', false);
    }
}
