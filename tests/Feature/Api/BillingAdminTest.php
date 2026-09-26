<?php

namespace Tests\Feature\Api;

use App\Exceptions\BusinessRuleException;
use App\Models\BillingEvent;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Billing\AdminBilling;
use App\Services\FlutterwaveService;

/** POWER_PLAN §4.2 — platform-admin billing actions, each audited in billing_events (who, what, why). */
class BillingAdminTest extends ApiTestCase
{
    private FakeFlutterwaveService $flw;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->flw = new FakeFlutterwaveService();
        $this->app->instance(FlutterwaveService::class, $this->flw);
        $this->admin = new User();
        $this->admin->forceFill(['name' => 'Platform Admin', 'email' => 'pa_'.uniqid('', true).'@example.test', 'username' => 'pa_'.uniqid('', true), 'password' => bcrypt('x')])->save();
    }

    private function company(array $t): Company
    {
        return Company::find($t['company_id']);
    }

    private function sub(int $cid): Subscription
    {
        return Subscription::where('company_id', $cid)->orderByDesc('id')->first();
    }

    private function lastEvent(int $cid): BillingEvent
    {
        return BillingEvent::where('company_id', $cid)->orderByDesc('id')->first();
    }

    public function test_comp_extend_trial_and_extend_period_are_audited_and_need_a_reason(): void
    {
        $t = $this->registerTenant();
        $billing = app(AdminBilling::class);

        try {
            $billing->extendTrial($this->company($t), 7, ' ', $this->admin);
            $this->fail('a reason is required');
        } catch (BusinessRuleException $e) {
            $this->assertSame('reason_required', $e->errorCode());
        }
        $trialEnd = $this->sub($t['company_id'])->trial_ends_at->copy();
        $billing->extendTrial($this->company($t), 7, 'Setting up their stock', $this->admin);
        $this->assertTrue($this->sub($t['company_id'])->trial_ends_at->equalTo($trialEnd->copy()->addDays(7)));
        $e = $this->lastEvent($t['company_id']);
        $this->assertSame(['trial_extended', $this->admin->id, 'Setting up their stock'], [$e->action, $e->actor_id, $e->reason]);

        $business = Plan::where('slug', 'business')->first();
        $billing->comp($this->company($t), $business, now()->addMonths(3), 'Pilot shop', $this->admin);
        $sub = $this->sub($t['company_id']);
        $this->assertSame([$business->id, 'active', 'comp'], [$sub->plan_id, $sub->status, $sub->provider]);
        $this->assertTrue($sub->ends_at->isSameDay(now()->addMonths(3)));
        $this->assertSame('comp', $this->lastEvent($t['company_id'])->action);
        $this->assertTrue($this->company($t)->license_expire->isSameDay(now()->addMonths(3)));

        $before = $sub->ends_at->copy();
        $billing->extendPeriod($this->company($t), 10, 'Outage compensation', $this->admin);
        $this->assertTrue($this->sub($t['company_id'])->ends_at->equalTo($before->addDays(10)));
        $this->assertSame(10, $this->lastEvent($t['company_id'])->meta['days']);
        $this->assertSame(3, BillingEvent::where('company_id', $t['company_id'])->whereNotNull('actor_id')->count());
    }

    public function test_a_manual_payment_is_a_paid_invoice_and_extends_the_plan(): void
    {
        $t = $this->registerTenant();
        $starter = Plan::where('slug', 'starter')->first();
        $invoice = app(AdminBilling::class)->recordManualPayment($this->company($t), $starter, 'year', 700000, 'UGX', 'bank', 'STANBIC-778', 'Paid at the office', $this->admin);
        $this->assertSame('paid', $invoice->status);
        $this->assertStringStartsWith('BP-', $invoice->number);
        $sub = $this->sub($t['company_id']);
        $this->assertSame([$starter->id, 'year', 'active'], [$sub->plan_id, $sub->billing_interval, $sub->status]);
        $this->assertTrue($invoice->period_end->equalTo($sub->ends_at));
        $e = $this->lastEvent($t['company_id']);
        $this->assertSame(['manual_payment', $invoice->id, 'STANBIC-778'], [$e->action, $e->invoice_id, $e->meta['reference']]);
        $this->assertStringStartsWith('%PDF', $this->get("/api/v1/subscription/invoices/{$invoice->id}.pdf", $this->auth($t['token']))->assertOk()->getContent());
    }

    public function test_refunds_are_recorded_or_sent_through_flutterwave_and_can_end_the_plan(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = Plan::where('slug', 'business')->first();
        $c = $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']))->json('data');
        $this->flw->willVerify($c['tx_ref'], $c['amount'], 'UGX', 424242);
        $this->postJson('/api/v1/subscription/verify', ['transaction_id' => 424242, 'tx_ref' => $c['tx_ref']], $this->auth($t['token']))->assertOk();
        $invoice = SubscriptionInvoice::where('provider_invoice_id', $c['tx_ref'])->first();
        $billing = app(AdminBilling::class);

        try {
            $billing->refund($invoice, 999999, 'Too much', $this->admin);
            $this->fail('cannot refund more than was paid');
        } catch (BusinessRuleException $e) {
            $this->assertSame('invalid_amount', $e->errorCode());
        }
        $partial = $billing->refund($invoice, 50000, 'Goodwill', $this->admin);
        $this->assertSame('paid', $partial->status);
        $this->assertEquals(50000, $partial->refund_amount);
        $this->assertSame([], $this->flw->refunds);

        $manual = app(AdminBilling::class)->recordManualPayment($this->company($t), $plan, 'month', 185000, 'UGX', 'cash', null, 'Cash at office', $this->admin);
        try {
            $billing->refund($manual, null, 'Wrong shop', $this->admin, true);
            $this->fail('a manual payment has no gateway transaction');
        } catch (BusinessRuleException $e) {
            $this->assertSame('no_transaction', $e->errorCode());
        }

        $second = SubscriptionInvoice::create(['company_id' => $t['company_id'], 'amount' => 185000, 'currency' => 'UGX', 'status' => 'paid', 'provider' => 'flutterwave', 'paid_at' => now(),
            'provider_invoice_id' => 'BPRO-X-'.uniqid(), 'meta' => ['plan_id' => $plan->id, 'flw_transaction_id' => 515151]]);
        $full = $billing->refund($second, null, 'Charged twice', $this->admin, true, true);
        $this->assertSame('refunded', $full->status);
        $this->assertSame([['id' => 515151, 'amount' => null]], $this->flw->refunds);
        $this->assertFalse($this->sub($t['company_id'])->ends_at->isFuture(), 'the plan ended now');
        $e = $this->lastEvent($t['company_id']);
        $this->assertSame(['refund', true, true, 'Charged twice'], [$e->action, $e->meta['via_gateway'], $e->meta['end_access'], $e->reason]);
    }
}
