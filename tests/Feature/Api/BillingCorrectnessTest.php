<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\Lifecycle;
use App\Services\Billing\Quotas;
use App\Services\Billing\Reconciler;
use App\Services\FlutterwaveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * POWER_PLAN §4.1 — billing correctness, all against a faked Flutterwave: a double payment extends,
 * a suspended shop can't buy or reactivate itself, lost webhooks are reconciled, the webhook asks
 * Flutterwave to retry when it can't verify, messy currencies are normalised, limits are enforced.
 */
class BillingCorrectnessTest extends ApiTestCase
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

    private function checkout(array $t, Plan $plan, string $interval = 'month'): array
    {
        return $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id, 'interval' => $interval], $this->auth($t['token']))->assertOk()->json('data');
    }

    private function verify(array $t, array $c, int $id = 999001): void
    {
        $this->flw->willVerify($c['tx_ref'], $c['amount'], $c['currency'], $id);
        $this->postJson('/api/v1/subscription/verify', ['transaction_id' => $id, 'tx_ref' => $c['tx_ref']], $this->auth($t['token']))->assertOk();
    }

    private function member(array $t, string $role): string
    {
        $u = new User();
        $u->forceFill(['name' => ucfirst($role), 'first_name' => ucfirst($role), 'email' => $role.'_'.uniqid('', true).'@example.test', 'username' => $role.'_'.uniqid('', true),
            'password' => bcrypt('secret123'), 'status' => 'Active', 'company_id' => $t['company_id']])->save();
        CompanyMember::create(['company_id' => $t['company_id'], 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'joined_at' => now()]);

        \App\Services\Team\Permissions::flush();

        return $u->createToken('test')->plainTextToken;
    }

    public function test_a_second_payment_for_the_same_change_extends_instead_of_resetting(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $starter = Plan::where('slug', 'starter')->first();
        $business = Plan::where('slug', 'business')->first();
        $this->verify($t, $this->checkout($t, $starter));
        $this->travelTo($this->sub($t['company_id'])->ends_at->copy()->subDays(15));

        // The owner starts two payments for the same upgrade (two tabs), then both go through.
        $a = $this->checkout($t, $business);
        $b = $this->checkout($t, $business);
        $this->assertTrue($a['quote']['change']);
        $this->travel(1)->minutes();
        $this->verify($t, $a, 999001);
        $afterFirst = $this->sub($t['company_id'])->ends_at->copy();
        $this->assertEqualsWithDelta(30, now()->diffInDays($afterFirst), 1, 'the first paid change starts a fresh period');

        $this->travel(1)->minutes();
        $this->verify($t, $b, 999002);
        $sub = $this->sub($t['company_id']);
        $this->assertSame($business->id, $sub->plan_id);
        $this->assertEqualsWithDelta($afterFirst->copy()->addMonth()->timestamp, $sub->ends_at->timestamp, 5, 'the second payment extends by a month, nothing is lost');
        $second = SubscriptionInvoice::where('provider_invoice_id', $b['tx_ref'])->first();
        $this->assertTrue((bool) data_get($second->meta, 'extended'));
        $this->assertSame(2, DB::table('billing_events')->where('company_id', $t['company_id'])->where('action', 'paid')->where('created_at', '>=', now()->subMinutes(5))->count());
    }

    public function test_a_suspended_shop_cannot_check_out_and_a_late_payment_does_not_reactivate_it(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = Plan::where('slug', 'business')->first();
        $pending = $this->checkout($t, $plan); // started before the platform suspended the shop

        Company::where('id', $t['company_id'])->update(['status' => 'Inactive']);
        $this->postJson('/api/v1/subscription/checkout', ['plan_id' => $plan->id], $this->auth($t['token']))->assertStatus(403);
        try {
            app(BillingService::class)->quote(Company::find($t['company_id']), $plan);
            $this->fail('a suspended shop gets no quote');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('suspended', $e->errorCode());
        }
        $this->getJson('/api/v1/subscription/quote?plan_id='.$plan->id, $this->auth($t['token']))->assertStatus(403);
        $this->postJson('/api/v1/subscription/momo', ['plan_id' => $plan->id, 'phone' => '0772123456', 'network' => 'MTN'], $this->auth($t['token']))->assertStatus(403);
        $this->assertSame([], $this->flw->charges);

        // The money that was already sent is honoured (the period is recorded) but the shop stays suspended.
        $this->flw->willVerify($pending['tx_ref'], $pending['amount'], 'UGX');
        $this->postJson('/api/v1/webhooks/flutterwave', ['event' => 'charge.completed', 'data' => ['id' => 999001, 'tx_ref' => $pending['tx_ref'], 'status' => 'successful']],
            ['verif-hash' => 'test-webhook-hash'])->assertOk();
        $this->assertSame('paid', SubscriptionInvoice::where('provider_invoice_id', $pending['tx_ref'])->value('status'));
        $company = Company::find($t['company_id']);
        $this->assertSame('Inactive', $company->status);
        $this->assertSame('inactive', $company->accessState());
    }

    public function test_hourly_reconciliation_confirms_lost_webhooks_fails_declines_and_abandons_stale_invoices(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = Plan::where('slug', 'starter')->first();
        $old = $this->checkout($t, $plan);
        $this->travel(80)->hours();
        $paid = $this->checkout($t, $plan);
        $declined = $this->checkout($t, $plan);
        $unknown = $this->checkout($t, $plan);
        $this->travel(10)->minutes();
        $this->flw->customerAnswers($paid['tx_ref'], $paid['amount'], 'UGX');
        $this->flw->customerAnswers($declined['tx_ref'], $declined['amount'], 'UGX', 'failed');

        $counts = app(Reconciler::class)->run();
        $this->assertSame(1, $counts['paid']);
        $this->assertSame(1, $counts['failed']);
        $this->assertSame(1, $counts['abandoned']);
        $status = fn ($c) => SubscriptionInvoice::where('provider_invoice_id', $c['tx_ref'])->first();
        $this->assertSame('paid', $status($paid)->status);
        $this->assertSame('failed', $status($declined)->status);
        $this->assertSame('pending', $status($unknown)->status, 'no answer yet: still pending, checked again next hour');
        $this->assertSame('abandoned', $status($old)->status);
        $this->assertNotNull($status($old)->abandoned_at);
        $this->assertTrue(Company::find($t['company_id'])->hasActiveAccess());

        // Idempotent; and when Flutterwave is down nothing changes.
        $this->flw->down = true;
        $again = app(Reconciler::class)->run();
        $this->assertSame(0, $again['paid']);
        $this->assertSame(1, $again['errors']);
        $this->assertSame('pending', $status($unknown)->status);
        $this->artisan('billing:reconcile')->assertSuccessful();
    }

    public function test_the_webhook_asks_flutterwave_to_retry_when_verification_fails_transiently_and_is_not_throttled(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $c = $this->checkout($t, Plan::where('slug', 'business')->first());
        $this->flw->down = true;
        $body = ['event' => 'charge.completed', 'data' => ['id' => 999001, 'tx_ref' => $c['tx_ref'], 'status' => 'successful']];
        $this->postJson('/api/v1/webhooks/flutterwave', $body, ['verif-hash' => 'test-webhook-hash'])->assertStatus(503);
        $this->assertSame('pending', SubscriptionInvoice::where('provider_invoice_id', $c['tx_ref'])->value('status'));

        $this->flw->down = false;
        $this->flw->willVerify($c['tx_ref'], $c['amount'], 'UGX');
        $this->postJson('/api/v1/webhooks/flutterwave', $body, ['verif-hash' => 'test-webhook-hash'])->assertOk()->assertJsonPath('status', 'ok');
        $this->assertSame('paid', SubscriptionInvoice::where('provider_invoice_id', $c['tx_ref'])->value('status'));

        $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/webhooks/flutterwave');
        $this->assertNotContains('Illuminate\Routing\Middleware\ThrottleRequests:api', app('router')->gatherRouteMiddleware($route));
        for ($i = 0; $i < 65; $i++) { // more than the 60/min api limit: never 429
            $this->postJson('/api/v1/webhooks/flutterwave', ['data' => []], ['verif-hash' => 'nope'])->assertStatus(401);
        }
    }

    public function test_free_text_ugandan_currencies_are_normalised_and_real_codes_are_kept(): void
    {
        $migration = require base_path('database/migrations/2026_10_01_300001_billing_capability.php');
        $allowed = config('saas.currencies');
        foreach (['ugshs' => 'UGX', 'Uganda shillings' => 'UGX', 'UGX ' => 'UGX', 'ugx' => 'UGX', 'Ushs.' => 'UGX', 'kes' => 'KES', 'Ksh' => 'KES', 'usd' => 'USD', 'fgg' => 'fgg', 'LKR' => 'LKR', '' => 'UGX'] as $raw => $want) {
            $this->assertSame($want, $migration::normalise($raw, $allowed, 'UGX'), "'{$raw}'");
        }
        $t = $this->registerTenant(['currency' => 'UGX']);
        DB::table('companies')->where('id', $t['company_id'])->update(['currency' => 'Uganda shillings']);
        $this->assertSame('UGX', app(BillingService::class)->currency(Company::find($t['company_id'])), 'billing reads a stray value as UGX, never USD');
        $migration->up();
        $this->assertSame('UGX', DB::table('companies')->where('id', $t['company_id'])->value('currency'));

        $this->checkout($t, Plan::where('slug', 'starter')->first());
        $this->assertSame('UGX', $this->flw->lastPayload['currency']);
    }

    public function test_sales_per_month_is_soft_the_owner_is_told_once_and_usage_shows_it(): void
    {
        $t = $this->registerTenant(['currency' => 'UGX']);
        $h = $this->auth($t['token']);
        $plan = Plan::create(['name' => 'Tiny', 'slug' => 'tiny-'.uniqid(), 'price' => 5, 'price_ugx' => 10000, 'currency' => 'USD', 'interval' => 'month', 'is_active' => true, 'is_public' => false,
            'limits' => ['max_sales_per_month' => 1, 'max_products' => 50]]);
        Subscription::where('company_id', $t['company_id'])->update(['plan_id' => $plan->id, 'status' => 'active', 'ends_at' => now()->addDays(20), 'trial_ends_at' => null]);

        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $h)->json('data.id');
        $p = $this->postJson('/api/v1/stock-items', ['name' => 'Soap', 'stock_sub_category_id' => $sub, 'selling_price' => 1000, 'buying_price' => 700, 'original_quantity' => 10], $h)->json('data');
        foreach ([1, 2, 3] as $n) { // past the limit: every sale still goes through
            $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'cash', 'amount' => 1000]], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 1]]], $h)->assertStatus(201);
        }
        $company = Company::find($t['company_id']);
        (new Quotas())->assertCanAdd($company, 'sales'); // soft: never throws
        $this->assertTrue((new Quotas())->usage($company)['sales']['over']);
        $this->getJson('/api/v1/subscription', $h)->assertOk()->assertJsonPath('data.usage.sales.over', true);

        $counts = app(Lifecycle::class)->run();
        app(Lifecycle::class)->run();
        $this->assertGreaterThanOrEqual(1, $counts['limit_notices']);
        $this->assertSame(1, DB::table('app_notifications')->where('company_id', $t['company_id'])->where('title', 'like', 'You have passed your plan%1 sales this month')->count());
    }

    public function test_storage_is_checked_on_upload_and_locations_are_a_quota_with_the_billing_link(): void
    {
        config(['saas.billing_url' => 'https://shop.example.test/plan']);
        Storage::fake('public');
        $t = $this->registerTenant(['currency' => 'UGX']);
        $plan = Plan::create(['name' => 'Small', 'slug' => 'small-'.uniqid(), 'price' => 5, 'price_ugx' => 10000, 'currency' => 'USD', 'interval' => 'month', 'is_active' => true, 'is_public' => false,
            'limits' => ['storage_mb' => 1, 'max_locations' => 1]]);
        Subscription::where('company_id', $t['company_id'])->update(['plan_id' => $plan->id, 'status' => 'active', 'ends_at' => now()->addDays(20), 'trial_ends_at' => null]);
        DB::table('files')->insert(['company_id' => $t['company_id'], 'uuid' => (string) Str::uuid(), 'purpose' => 'other', 'path' => 'x', 'mime' => 'image/png', 'size_bytes' => 1048000, 'created_at' => now(), 'updated_at' => now()]);

        $r = $this->post('/api/v1/files', ['uuid' => (string) Str::uuid(), 'purpose' => 'product_image', 'file' => UploadedFile::fake()->image('p.png', 200, 200)->size(40)], $this->auth($t['token']) + ['Accept' => 'application/json']);
        $r->assertStatus(422)->assertJsonPath('errors.code', 'plan_limit_reached')->assertJsonPath('errors.limit', 'storage_mb')->assertJsonPath('errors.upgrade_url', 'https://shop.example.test/plan');
        $this->assertSame(1, DB::table('files')->where('company_id', $t['company_id'])->count(), 'nothing was stored');

        $company = Company::find($t['company_id']);
        $this->assertArrayHasKey('locations', (new Quotas())->usage($company));
        $this->assertSame('max_locations', Quotas::KEYS['locations']);
        try {
            (new Quotas())->assertCanAdd($company, 'locations');
            $this->fail('the second location is over the plan');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('plan_limit_reached', $e->errorCode());
            $this->assertSame('https://shop.example.test/plan', $e->toErrors()['upgrade_url']);
            $this->assertNotNull($e->toErrors()['next_plan'], 'the next plan that fits is named');
        }
    }

    public function test_invoice_pdfs_need_the_billing_permission_and_show_the_tax_and_seller(): void
    {
        config(['saas.invoice.tax_rate' => 18, 'saas.invoice.seller_name' => 'Budget Pro Ltd', 'saas.invoice.seller_tin' => '1000123456']);
        $t = $this->registerTenant(['currency' => 'UGX']);
        $c = $this->checkout($t, Plan::where('slug', 'business')->first());
        $this->verify($t, $c);
        $invoice = SubscriptionInvoice::where('provider_invoice_id', $c['tx_ref'])->first();
        $this->assertEqualsWithDelta(185000 * 18 / 118, (float) $invoice->tax_amount, 0.01);

        $cashier = $this->member($t, 'cashier');
        $this->app['auth']->forgetGuards(); // a new request, a new bearer token
        $this->get("/api/v1/subscription/invoices/{$invoice->id}.pdf", $this->auth($cashier))->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->assertStringStartsWith('%PDF', $this->get("/api/v1/subscription/invoices/{$invoice->id}.pdf", $this->auth($t['token']))->assertOk()->getContent());

        $html = app(\App\Services\Billing\InvoicePdf::class)->html($invoice);
        $this->assertStringContainsString('Budget Pro Ltd', $html);
        $this->assertStringContainsString('TIN 1000123456', $html);
        $this->assertStringContainsString('Includes VAT at 18%', $html);
        $this->assertStringContainsString('Tax invoice', $html);

        $other = $this->registerTenant();
        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/subscription/invoices/{$invoice->id}.pdf", $this->auth($other['token']))->assertStatus(404);
    }
}
