<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\FlutterwaveService;

class BillingTest extends ApiTestCase
{
    private FakeFlutterwaveService $flw;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure purchasable plans exist in the test DB.
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->flw = new FakeFlutterwaveService();
        $this->app->instance(FlutterwaveService::class, $this->flw);
    }

    public function test_plans_are_public(): void
    {
        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'slug', 'price_usd', 'price_ugx', 'interval']]]);
    }

    public function test_uganda_company_is_billed_in_ugx_with_mobile_money(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = \App\Models\Plan::where('slug', 'business')->first();

        $res = $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']));

        $res->assertOk()
            ->assertJsonPath('data.currency', 'UGX')
            ->assertJsonPath('data.amount', 100000);

        $this->assertSame('UGX', $this->flw->lastPayload['currency']);
        $this->assertSame(100000.0, (float) $this->flw->lastPayload['amount']);
        $this->assertStringContainsString('mobilemoneyuganda', $this->flw->lastPayload['payment_options']);
    }

    public function test_international_company_is_billed_in_usd_by_card(): void
    {
        $t = $this->registerTenant(['currency' => 'USD']);
        $plan = \App\Models\Plan::where('slug', 'business')->first();

        $res = $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']));

        $res->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.amount', 27);

        $this->assertSame('USD', $this->flw->lastPayload['currency']);
        $this->assertSame('card', $this->flw->lastPayload['payment_options']);
    }

    public function test_verify_activates_subscription(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = \App\Models\Plan::where('slug', 'business')->first();

        $checkout = $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']));
        $txRef = $checkout->json('data.tx_ref');

        $this->flw->willVerify($txRef, 100000, 'UGX');

        $this->postJson('/api/v1/subscription/verify', [
            'transaction_id' => 999001, 'tx_ref' => $txRef,
        ], $this->auth($t['token']))
            ->assertOk()
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.has_active_access', true);

        $invoice = SubscriptionInvoice::where('provider_invoice_id', $txRef)->first();
        $this->assertSame('paid', $invoice->status);

        $sub = Subscription::where('company_id', $t['company_id'])->first();
        $this->assertSame($plan->id, $sub->plan_id);
        $this->assertTrue($sub->ends_at->isFuture());

        // Legacy licence column kept in sync.
        $this->assertTrue(Company::find($t['company_id'])->license_expire->isFuture());
    }

    public function test_verify_rejects_amount_mismatch(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = \App\Models\Plan::where('slug', 'business')->first();
        $checkout = $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']));
        $txRef = $checkout->json('data.tx_ref');

        // Attacker "pays" far less than billed.
        $this->flw->willVerify($txRef, 100, 'UGX');

        $this->postJson('/api/v1/subscription/verify', [
            'transaction_id' => 999001, 'tx_ref' => $txRef,
        ], $this->auth($t['token']))->assertStatus(422);

        $this->assertSame('pending', SubscriptionInvoice::where('provider_invoice_id', $txRef)->first()->status);
    }

    public function test_verify_is_idempotent(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = \App\Models\Plan::where('slug', 'business')->first();
        $txRef = $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']))->json('data.tx_ref');
        $this->flw->willVerify($txRef, 100000, 'UGX');

        $this->postJson('/api/v1/subscription/verify', ['transaction_id' => 999001, 'tx_ref' => $txRef], $this->auth($t['token']))->assertOk();
        $endsFirst = Subscription::where('company_id', $t['company_id'])->first()->ends_at;

        // Second verify must not extend the period again.
        $this->postJson('/api/v1/subscription/verify', ['transaction_id' => 999001, 'tx_ref' => $txRef], $this->auth($t['token']))->assertOk();
        $endsSecond = Subscription::where('company_id', $t['company_id'])->first()->ends_at;

        $this->assertEquals($endsFirst->toDateTimeString(), $endsSecond->toDateTimeString());
    }

    public function test_webhook_activates_subscription(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = \App\Models\Plan::where('slug', 'business')->first();
        $txRef = $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']))->json('data.tx_ref');
        $this->flw->willVerify($txRef, 100000, 'UGX');

        $this->postJson('/api/v1/webhooks/flutterwave', [
            'event' => 'charge.completed',
            'data' => ['id' => 999001, 'tx_ref' => $txRef, 'status' => 'successful', 'amount' => 100000, 'currency' => 'UGX'],
        ], ['verif-hash' => 'test-webhook-hash'])->assertOk();

        $this->assertSame('paid', SubscriptionInvoice::where('provider_invoice_id', $txRef)->first()->status);
        $this->assertTrue(Company::find($t['company_id'])->hasActiveAccess());
    }

    public function test_webhook_rejects_bad_signature(): void
    {
        $this->postJson('/api/v1/webhooks/flutterwave', [
            'event' => 'charge.completed',
            'data' => ['id' => 1, 'tx_ref' => 'x', 'status' => 'successful'],
        ], ['verif-hash' => 'WRONG'])->assertStatus(401);
    }

    public function test_lapsed_tenant_can_reach_checkout_but_not_product(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);

        // Force the subscription to be expired *beyond the grace period*. DECISIONS.md H5 gives a
        // lapsed tenant 7 days of grace (read-only web, devices keep selling), so a 1-day lapse is
        // still allowed through; this test is about the hard-locked state.
        $sub = Subscription::where('company_id', $t['company_id'])->first();
        $sub->status = 'expired';
        $sub->trial_ends_at = now()->subDays(30);
        $sub->ends_at = now()->subDays(30);
        $sub->save();
        Company::where('id', $t['company_id'])->update(['license_expire' => now()->subDays(30)]);

        // Product endpoint is gated (402).
        $this->getJson('/api/v1/dashboard', $this->auth($t['token']))->assertStatus(402);

        // But billing endpoints remain reachable so they can pay.
        $this->getJson('/api/v1/subscription', $this->auth($t['token']))->assertOk();
        $plan = \App\Models\Plan::where('slug', 'business')->first();
        $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']))->assertOk();
    }

    public function test_non_owner_cannot_checkout(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $worker = \App\Models\User::factory()->create(['company_id' => $t['company_id'], 'email' => 'worker_'.uniqid().'@example.com', 'password' => bcrypt('secret123')]);
        $token = $worker->createToken('test')->plainTextToken;
        $plan = \App\Models\Plan::where('slug', 'business')->first();

        $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($token))->assertStatus(403);
        $this->assertSame([], $this->flw->lastPayload, 'no payment was initiated');
    }

    public function test_payment_callback_verifies_and_activates(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = \App\Models\Plan::where('slug', 'business')->first();
        $txRef = $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']))->json('data.tx_ref');
        $this->flw->willVerify($txRef, 100000, 'UGX');

        $this->get('/payment/callback?status=successful&tx_ref='.$txRef.'&transaction_id=999001')
            ->assertOk()->assertSee('Payment confirmed')->assertSee('Return to the app');

        $this->assertSame('paid', SubscriptionInvoice::where('provider_invoice_id', $txRef)->value('status'));
        $this->assertSame('active', Subscription::where('company_id', $t['company_id'])->value('status'));

        // Revisiting is idempotent.
        $this->get('/payment/callback?status=successful&tx_ref='.$txRef.'&transaction_id=999001')->assertOk()->assertSee('Payment confirmed');
        $this->assertSame(1, SubscriptionInvoice::where('company_id', $t['company_id'])->where('status', 'paid')->count());
    }

    public function test_payment_callback_handles_cancelled_and_unknown(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = \App\Models\Plan::where('slug', 'business')->first();
        $txRef = $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']))->json('data.tx_ref');

        $this->get('/payment/callback?status=cancelled&tx_ref='.$txRef)->assertOk()->assertSee('Payment cancelled');
        $this->assertSame('pending', SubscriptionInvoice::where('provider_invoice_id', $txRef)->value('status'));

        // Unverifiable "successful" redirect activates nothing.
        $this->get('/payment/callback?status=successful&tx_ref='.$txRef.'&transaction_id=1')->assertOk()->assertSee('Payment received');
        $this->assertSame('pending', SubscriptionInvoice::where('provider_invoice_id', $txRef)->value('status'));

        $this->get('/payment/callback?status=successful&tx_ref=BPRO-NOPE&transaction_id=1')->assertStatus(404)->assertSee('Payment not found');
    }
}
