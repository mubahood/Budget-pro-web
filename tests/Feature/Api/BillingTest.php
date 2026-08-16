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
            ->assertJsonPath('data.amount', 185000);

        $this->assertSame('UGX', $this->flw->lastPayload['currency']);
        $this->assertSame(185000.0, (float) $this->flw->lastPayload['amount']);
        $this->assertStringContainsString('mobilemoneyuganda', $this->flw->lastPayload['payment_options']);
    }

    public function test_international_company_is_billed_in_usd_by_card(): void
    {
        $t = $this->registerTenant(['currency' => 'USD']);
        $plan = \App\Models\Plan::where('slug', 'business')->first();

        $res = $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']));

        $res->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.amount', 49);

        $this->assertSame('USD', $this->flw->lastPayload['currency']);
        $this->assertSame('card', $this->flw->lastPayload['payment_options']);
    }

    public function test_verify_activates_subscription(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = \App\Models\Plan::where('slug', 'business')->first();

        $checkout = $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']));
        $txRef = $checkout->json('data.tx_ref');

        $this->flw->willVerify($txRef, 185000, 'UGX');

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
        $this->flw->willVerify($txRef, 185000, 'UGX');

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
        $this->flw->willVerify($txRef, 185000, 'UGX');

        $this->postJson('/api/v1/webhooks/flutterwave', [
            'event' => 'charge.completed',
            'data' => ['id' => 999001, 'tx_ref' => $txRef, 'status' => 'successful', 'amount' => 185000, 'currency' => 'UGX'],
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

        // Force the subscription to be expired.
        $sub = Subscription::where('company_id', $t['company_id'])->first();
        $sub->status = 'expired';
        $sub->trial_ends_at = now()->subDay();
        $sub->ends_at = now()->subDay();
        $sub->save();
        Company::where('id', $t['company_id'])->update(['license_expire' => now()->subDay()]);

        // Product endpoint is gated (402).
        $this->getJson('/api/v1/dashboard', $this->auth($t['token']))->assertStatus(402);

        // But billing endpoints remain reachable so they can pay.
        $this->getJson('/api/v1/subscription', $this->auth($t['token']))->assertOk();
        $plan = \App\Models\Plan::where('slug', 'business')->first();
        $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']))->assertOk();
    }
}
