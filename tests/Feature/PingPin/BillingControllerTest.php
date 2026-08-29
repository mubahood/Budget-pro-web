<?php

namespace Tests\Feature\PingPin;

use App\Models\Company;
use App\Models\PingPinPlan;
use App\Models\PingPinSubscription;
use App\Models\PingPinSubscriptionInvoice;
use App\Services\FlutterwaveService;
use Tests\Feature\Api\ApiTestCase;
use Tests\Feature\Api\FakeFlutterwaveService;

/**
 * Mirrors tests/Feature/Api/BillingTest.php's coverage exactly, against
 * Ping Pin's own tables — reuses the SAME FakeFlutterwaveService double,
 * since it's a generic fake of the shared, unmodified FlutterwaveService
 * class (PLAN.md §4/§7).
 */
class BillingControllerTest extends ApiTestCase
{
    private FakeFlutterwaveService $flw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PingPinPlanSeeder::class);

        $this->flw = new FakeFlutterwaveService();
        $this->app->instance(FlutterwaveService::class, $this->flw);
    }

    public function test_plans_are_public(): void
    {
        $this->getJson('/api/v1/pingpin/plans')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'slug', 'price_usd', 'price_ugx', 'interval', 'features', 'limits']]]);
    }

    public function test_uganda_company_is_billed_in_ugx_with_mobile_money(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = PingPinPlan::where('slug', 'family')->first();

        $res = $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/checkout", ['plan_id' => $plan->id], $this->auth($t['token']));

        $res->assertOk()->assertJsonPath('data.currency', 'UGX')->assertJsonPath('data.amount', 26000);
        $this->assertSame('UGX', $this->flw->lastPayload['currency']);
        $this->assertStringContainsString('mobilemoneyuganda', $this->flw->lastPayload['payment_options']);
        $this->assertSame('pingpin', $this->flw->lastPayload['meta']['product']);
    }

    public function test_international_company_is_billed_in_usd_by_card(): void
    {
        $t = $this->registerTenant(['currency' => 'USD']);
        $plan = PingPinPlan::where('slug', 'family')->first();

        $res = $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/checkout", ['plan_id' => $plan->id], $this->auth($t['token']));

        $res->assertOk()->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.amount', 6.99);
        $this->assertSame('USD', $this->flw->lastPayload['currency']);
        $this->assertSame('card', $this->flw->lastPayload['payment_options']);
    }

    public function test_verify_activates_subscription_without_touching_budget_pros_legacy_columns(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = PingPinPlan::where('slug', 'family')->first();
        $company = Company::find($t['company_id']);
        $originalLicenseExpire = $company->license_expire;

        $txRef = $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/checkout", ['plan_id' => $plan->id], $this->auth($t['token']))->json('data.tx_ref');
        $this->flw->willVerify($txRef, 26000, 'UGX');

        $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/verify", ['transaction_id' => 999001, 'tx_ref' => $txRef], $this->auth($t['token']))
            ->assertOk()
            ->assertJsonPath('data.subscription.status', 'active');

        $invoice = PingPinSubscriptionInvoice::where('provider_invoice_id', $txRef)->first();
        $this->assertSame('paid', $invoice->status);

        $sub = PingPinSubscription::where('company_id', $t['company_id'])->first();
        $this->assertSame($plan->id, $sub->plan_id);
        $this->assertTrue($sub->ends_at->isFuture());

        // DECISIONS.md D2: Ping Pin activation must NEVER touch budget-pro's
        // own license_expire/status columns — that's a different product's
        // billing concept entirely.
        $company->refresh();
        $this->assertEquals(optional($originalLicenseExpire)->toDateString(), optional($company->license_expire)->toDateString());
    }

    public function test_verify_rejects_amount_mismatch(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = PingPinPlan::where('slug', 'family')->first();
        $txRef = $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/checkout", ['plan_id' => $plan->id], $this->auth($t['token']))->json('data.tx_ref');

        $this->flw->willVerify($txRef, 100, 'UGX'); // attacker "pays" far less than billed

        $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/verify", ['transaction_id' => 999001, 'tx_ref' => $txRef], $this->auth($t['token']))
            ->assertStatus(422);

        $this->assertSame('pending', PingPinSubscriptionInvoice::where('provider_invoice_id', $txRef)->first()->status);
    }

    public function test_verify_is_idempotent(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = PingPinPlan::where('slug', 'family')->first();
        $txRef = $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/checkout", ['plan_id' => $plan->id], $this->auth($t['token']))->json('data.tx_ref');
        $this->flw->willVerify($txRef, 26000, 'UGX');

        $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/verify", ['transaction_id' => 999001, 'tx_ref' => $txRef], $this->auth($t['token']))->assertOk();
        $endsFirst = PingPinSubscription::where('company_id', $t['company_id'])->first()->ends_at;

        $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/verify", ['transaction_id' => 999001, 'tx_ref' => $txRef], $this->auth($t['token']))->assertOk();
        $endsSecond = PingPinSubscription::where('company_id', $t['company_id'])->first()->ends_at;

        $this->assertEquals($endsFirst->toDateTimeString(), $endsSecond->toDateTimeString());
    }

    public function test_webhook_activates_subscription(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = PingPinPlan::where('slug', 'family')->first();
        $txRef = $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/checkout", ['plan_id' => $plan->id], $this->auth($t['token']))->json('data.tx_ref');
        $this->flw->willVerify($txRef, 26000, 'UGX');

        $this->postJson('/api/v1/pingpin/webhooks/flutterwave', [
            'event' => 'charge.completed',
            'data' => ['id' => 999001, 'tx_ref' => $txRef, 'status' => 'successful', 'amount' => 26000, 'currency' => 'UGX'],
        ], ['verif-hash' => 'test-webhook-hash'])->assertOk();

        $this->assertSame('paid', PingPinSubscriptionInvoice::where('provider_invoice_id', $txRef)->first()->status);
        $this->assertTrue(PingPinSubscription::where('company_id', $t['company_id'])->first()->isActive());
    }

    public function test_webhook_rejects_bad_signature(): void
    {
        $this->postJson('/api/v1/pingpin/webhooks/flutterwave', [
            'event' => 'charge.completed',
            'data' => ['id' => 1, 'tx_ref' => 'x', 'status' => 'successful'],
        ], ['verif-hash' => 'WRONG'])->assertStatus(401);
    }

    public function test_duplicate_webhook_delivery_does_not_double_activate(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = PingPinPlan::where('slug', 'family')->first();
        $txRef = $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/checkout", ['plan_id' => $plan->id], $this->auth($t['token']))->json('data.tx_ref');
        $this->flw->willVerify($txRef, 26000, 'UGX');

        $payload = [
            'event' => 'charge.completed',
            'data' => ['id' => 999001, 'tx_ref' => $txRef, 'status' => 'successful', 'amount' => 26000, 'currency' => 'UGX'],
        ];
        $this->postJson('/api/v1/pingpin/webhooks/flutterwave', $payload, ['verif-hash' => 'test-webhook-hash'])->assertOk();
        $endsFirst = PingPinSubscription::where('company_id', $t['company_id'])->first()->ends_at;

        // Flutterwave redelivers the identical event — a real, documented occurrence.
        $this->postJson('/api/v1/pingpin/webhooks/flutterwave', $payload, ['verif-hash' => 'test-webhook-hash'])->assertOk();
        $endsSecond = PingPinSubscription::where('company_id', $t['company_id'])->first()->ends_at;

        $this->assertEquals($endsFirst->toDateTimeString(), $endsSecond->toDateTimeString());
    }

    public function test_out_of_order_webhook_before_client_verify_still_activates_exactly_once(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = PingPinPlan::where('slug', 'family')->first();
        $txRef = $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/checkout", ['plan_id' => $plan->id], $this->auth($t['token']))->json('data.tx_ref');
        $this->flw->willVerify($txRef, 26000, 'UGX');

        // Webhook arrives FIRST (server-to-server is often faster than the
        // browser redirect + client verify call).
        $this->postJson('/api/v1/pingpin/webhooks/flutterwave', [
            'event' => 'charge.completed',
            'data' => ['id' => 999001, 'tx_ref' => $txRef, 'status' => 'successful', 'amount' => 26000, 'currency' => 'UGX'],
        ], ['verif-hash' => 'test-webhook-hash'])->assertOk();

        // Client's own verify call arrives after — must short-circuit on the
        // already-paid invoice, not attempt to activate a second time.
        $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/verify", ['transaction_id' => 999001, 'tx_ref' => $txRef], $this->auth($t['token']))
            ->assertOk();

        $this->assertSame(1, PingPinSubscription::where('company_id', $t['company_id'])->count());
        $this->assertSame('paid', PingPinSubscriptionInvoice::where('provider_invoice_id', $txRef)->first()->status);
    }

    public function test_declined_card_leaves_the_invoice_pending_not_paid(): void
    {
        $t = $this->registerTenant(['currency' => 'USD']);
        $plan = PingPinPlan::where('slug', 'family')->first();
        $txRef = $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/checkout", ['plan_id' => $plan->id], $this->auth($t['token']))->json('data.tx_ref');

        // A declined card: Flutterwave's own verify call succeeds (the API
        // call itself works) but reports the transaction status as failed,
        // not "successful" — transactionSatisfies() must reject this.
        $this->flw->verifyResult = ['success' => true, 'data' => [
            'id' => 999002, 'tx_ref' => $txRef, 'status' => 'failed', 'amount' => 6.99, 'currency' => 'USD',
        ]];

        $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/verify", ['transaction_id' => 999002, 'tx_ref' => $txRef], $this->auth($t['token']))
            ->assertStatus(422);

        $this->assertSame('pending', PingPinSubscriptionInvoice::where('provider_invoice_id', $txRef)->first()->status);
        $this->assertNull(PingPinSubscription::where('company_id', $t['company_id'])->first());
    }

    public function test_abandoned_mobile_money_prompt_or_gateway_timeout_is_a_clean_rejection(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = PingPinPlan::where('slug', 'family')->first();
        $txRef = $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/checkout", ['plan_id' => $plan->id], $this->auth($t['token']))->json('data.tx_ref');

        // The customer never completed the mobile money prompt (or the
        // gateway timed out) — Flutterwave's verify call itself fails/
        // never finds a matching transaction. Default FakeFlutterwaveService
        // state (['success' => false]) models exactly this.
        $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/subscription/verify", ['transaction_id' => 999003, 'tx_ref' => $txRef], $this->auth($t['token']))
            ->assertStatus(422);

        $this->assertSame('pending', PingPinSubscriptionInvoice::where('provider_invoice_id', $txRef)->first()->status);
    }

    public function test_cross_tenant_checkout_is_rejected(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();
        $plan = PingPinPlan::where('slug', 'family')->first();

        $this->postJson("/api/v1/pingpin/organisations/{$b['company_id']}/subscription/checkout", ['plan_id' => $plan->id], $this->auth($a['token']))
            ->assertStatus(403);
    }
}
