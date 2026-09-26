<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\Lifecycle;
use App\Services\FlutterwaveService;
use App\Services\Notifications\ScheduledNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Plan C7/C8 (P3-5, P3-6): lifecycle to Free (H2), dunning, proration, cancel, invoices, scheduled notices. */
class BillingLifecycleTest extends ApiTestCase
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

    private function pay(array $t, Plan $plan): array
    {
        $c = $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']))->assertOk()->json('data');
        if ($c['payment_link'] ?? null) {
            $this->flw->willVerify($c['tx_ref'], $c['amount'], $c['currency']);
            $this->postJson('/api/v1/subscription/verify', ['transaction_id' => 999001, 'tx_ref' => $c['tx_ref']], $this->auth($t['token']))->assertOk();
        }

        return $c;
    }

    public function test_trial_reminders_then_free_plan_instead_of_lockout(): void
    {
        $t = $this->registerTenant(['phone_number' => '0772606001']);
        $sub = $this->sub($t['company_id']);
        $this->assertSame('trialing', $sub->status);

        $this->travelTo($sub->trial_ends_at->copy()->subHours(60));
        app(Lifecycle::class)->run();
        app(Lifecycle::class)->run(); // idempotent
        $this->assertSame(1, DB::table('app_notifications')->where('company_id', $t['company_id'])->where('type', 'billing')->where('title', 'like', 'Your free trial ends in 3%')->count());
        $this->travelTo($sub->trial_ends_at->copy()->subHours(20));
        app(Lifecycle::class)->run();
        $this->assertSame(1, DB::table('app_notifications')->where('company_id', $t['company_id'])->where('title', 'like', 'Your free trial ends in 1 day')->count());

        $this->travelTo($sub->trial_ends_at->copy()->addHour());
        $counts = app(Lifecycle::class)->run();
        $this->assertSame(1, $counts['to_free']);
        $sub->refresh();
        $this->assertSame('free', $sub->plan->slug);
        $this->assertSame('active', $sub->status);
        $this->assertNull($sub->ends_at);
        $company = Company::find($t['company_id']);
        $this->assertSame('active', $company->accessState());

        // Free plan limits are live in the entitlement snapshot; the second phone is refused.
        $me = $this->getJson('/api/v1/auth/me', $this->auth($t['token']))->assertOk();
        $me->assertJsonPath('data.entitlements.limits.max_devices', 1)->assertJsonPath('data.entitlements.features.whatsapp_automation', false);
        $this->postJson('/api/v1/devices/register', ['device_id' => (string) Str::uuid()], $this->auth($t['token']))->assertOk();
        $this->postJson('/api/v1/devices/register', ['device_id' => (string) Str::uuid()], $this->auth($t['token']))->assertStatus(422)->assertJsonPath('errors.code', 'plan_limit_reached');
    }

    public function test_paid_plan_lapses_to_past_due_with_dunning_then_free(): void
    {
        $t = $this->registerTenant();
        $this->pay($t, Plan::where('slug', 'starter')->first());
        $sub = $this->sub($t['company_id']);
        $this->assertSame('starter', $sub->plan->slug);
        $this->assertSame(1, DB::table('app_notifications')->where('company_id', $t['company_id'])->where('title', 'Payment received')->count());

        $this->travelTo($sub->ends_at->copy()->addHour());
        app(Lifecycle::class)->run();
        $this->assertSame('past_due', $sub->fresh()->status);
        $this->assertSame('grace', Company::find($t['company_id'])->accessState());
        $this->travelTo($sub->ends_at->copy()->addDays(3)->addHour());
        app(Lifecycle::class)->run();
        $this->assertSame(2, DB::table('scheduled_notices')->where('company_id', $t['company_id'])->where('key', 'dunning')->count());

        $this->travelTo($sub->ends_at->copy()->addDays(8));
        app(Lifecycle::class)->run();
        $this->assertSame('free', $sub->fresh()->plan->slug);
        $this->assertSame(2, DB::table('scheduled_notices')->where('company_id', $t['company_id'])->where('key', 'dunning')->count(), 'no catch-up reminder on the way to Free');
        $this->assertSame('active', Company::find($t['company_id'])->accessState());
    }

    public function test_a_plan_that_lapsed_long_ago_moves_to_free_without_a_pile_of_reminders(): void
    {
        $t = $this->registerTenant();
        $this->pay($t, Plan::where('slug', 'starter')->first());
        $sub = $this->sub($t['company_id']);
        $this->travelTo($sub->ends_at->copy()->addDays(40));
        $counts = app(Lifecycle::class)->run();
        $this->assertSame(0, DB::table('app_notifications')->where('company_id', $t['company_id'])->where('title', 'like', 'Payment due%')->count());
        $this->assertSame('free', $sub->fresh()->plan->slug);
        $this->assertGreaterThanOrEqual(1, $counts['to_free']);
    }

    public function test_upgrade_mid_period_is_prorated_and_downgrade_paid_by_credit(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $starter = Plan::where('slug', 'starter')->first();   // 70,000 UGX
        $business = Plan::where('slug', 'business')->first(); // 185,000 UGX
        $this->pay($t, $starter);
        // Paying during the trial stacks the month after the trial; move to 15 days before the end.
        $this->travelTo($this->sub($t['company_id'])->ends_at->copy()->subDays(15));

        $q = $this->getJson("/api/v1/subscription/quote?plan_id={$business->id}", $this->auth($t['token']))->assertOk()->json('data');
        $this->assertTrue($q['change']);
        $this->assertEqualsWithDelta(25000, $q['credit'], 100);  // half a month of Starter
        $this->assertEqualsWithDelta(75000, $q['amount'], 100);
        $this->pay($t, $business);
        $sub = $this->sub($t['company_id']);
        $this->assertSame($business->id, $sub->plan_id);
        $this->assertEqualsWithDelta(30, now()->diffInDays($sub->ends_at), 1); // fresh period from today
        $invoice = SubscriptionInvoice::where('company_id', $t['company_id'])->where('status', 'paid')->orderByDesc('id')->first();
        $this->assertStringStartsWith('BP-', $invoice->number);
        $pdf = $this->get("/api/v1/subscription/invoices/{$invoice->id}.pdf", $this->auth($t['token']))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Straight back down to Starter: 30 days of Business covers it, no payment, extra days instead.
        $r = $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $starter->id], $this->auth($t['token']))->assertOk();
        $this->assertNull($r->json('data.payment_link'));
        $sub->refresh();
        $this->assertSame($starter->id, $sub->plan_id);
        $this->assertGreaterThanOrEqual(59, now()->diffInDays($sub->ends_at));
    }

    public function test_cancel_keeps_the_plan_until_period_end_then_free_and_resume(): void
    {
        $t = $this->registerTenant();
        $this->pay($t, Plan::where('slug', 'starter')->first());
        $this->postJson('/api/v1/subscription/cancel', [], $this->auth($t['token']))->assertOk()->assertJsonPath('data.subscription.status', 'canceled');
        $this->postJson('/api/v1/subscription/resume', [], $this->auth($t['token']))->assertOk()->assertJsonPath('data.subscription.status', 'active');
        $this->postJson('/api/v1/subscription/cancel', [], $this->auth($t['token']))->assertOk();
        $this->assertSame('active', Company::find($t['company_id'])->accessState());

        $this->travelTo($this->sub($t['company_id'])->ends_at->copy()->addMinute());
        app(Lifecycle::class)->run();
        $this->assertSame('free', $this->sub($t['company_id'])->plan->slug);
        $this->postJson('/api/v1/subscription/cancel', [], $this->auth($t['token']))->assertStatus(422)->assertJsonPath('errors.code', 'nothing_to_cancel');
    }

    public function test_kenyan_shop_pays_in_kes_by_mpesa_when_the_plan_has_a_kes_price(): void
    {
        $plan = Plan::where('slug', 'starter')->first();
        $plan->prices = ['KES' => 2500];
        $plan->save();
        $t = $this->registerTenant(['currency' => 'KES']);
        $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']))->assertOk()->assertJsonPath('data.currency', 'KES');
        $this->assertSame(2500.0, (float) $this->flw->lastPayload['amount']);
        $this->assertStringContainsString('mpesa', $this->flw->lastPayload['payment_options']);
    }

    public function test_daily_summary_low_stock_unsynced_device_and_cash_variance(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        DB::table('companies')->where('id', $t['company_id'])->update(['timezone' => 'Africa/Nairobi']);
        // Daily summary is opt-in.
        $this->putJson('/api/v1/notifications/preferences', ['preferences' => [['key' => 'daily_summary', 'enabled' => true, 'channels' => ['in_app']]]], $h)->assertOk();

        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $h)->json('data.id');
        $p = $this->postJson('/api/v1/stock-items', ['name' => 'Sugar 1kg', 'stock_sub_category_id' => $sub, 'selling_price' => 5000, 'buying_price' => 4000, 'original_quantity' => 4, 'min_stock' => 5], $h)->json('data');
        $shift = $this->postJson('/api/v1/shifts/open', ['opening_float' => 10000], $h)->assertStatus(201)->json('data');
        $this->postJson('/api/v1/sales/checkout', ['shift_id' => $shift['id'], 'payments' => [['method' => 'cash', 'amount' => 10000]], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 2]]], $h)->assertStatus(201);
        $closed = $this->postJson("/api/v1/shifts/{$shift['id']}/close", ['counted_cash' => 19000], $h)->assertOk()->json('data');
        $this->assertSame('-1000.00', $closed['variance']);
        $this->assertSame(1, DB::table('app_notifications')->where('company_id', $t['company_id'])->where('type', 'cash_variance')->where('title', 'like', 'Cash short by 1,000%')->count());

        DB::table('devices')->insert(['company_id' => $t['company_id'], 'user_id' => $t['user_id'], 'device_id' => 'old-phone-1', 'name' => 'Counter 2', 'number_prefix' => 'Z',
            'prefix_index' => 9, 'status' => 'active', 'last_seen_at' => now()->subDays(4), 'created_at' => now(), 'updated_at' => now()]);

        $this->travelTo(now()->setTimezone('Africa/Nairobi')->setTime(20, 5)->utc());
        $run = app(ScheduledNotifications::class)->run();
        app(ScheduledNotifications::class)->run(); // once per day
        $this->assertGreaterThanOrEqual(1, $run['daily_summary']);
        $n = fn (string $type) => DB::table('app_notifications')->where('company_id', $t['company_id'])->where('type', $type)->get();
        $this->assertCount(1, $n('daily_summary'));
        $this->assertStringContainsString('10,000', $n('daily_summary')[0]->body);
        $this->assertStringContainsString('Sugar 1kg', $n('daily_summary')[0]->body);
        $this->assertCount(1, $n('low_stock'));
        $this->assertStringContainsString('Sugar 1kg (2)', $n('low_stock')[0]->body);
        $this->assertCount(1, $n('unsynced_device'));
        $this->assertStringContainsString("Counter 2' hasn't synced for 4 days", $n('unsynced_device')[0]->title);
        $this->artisan('saas:hourly')->assertSuccessful();
    }
}
