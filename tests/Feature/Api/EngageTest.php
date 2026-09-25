<?php

namespace Tests\Feature\Api;

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Engage\DebtReminders;
use App\Services\FlutterwaveService;
use Illuminate\Support\Facades\DB;

/** Plan Part E1–E3 (Phase 5): WhatsApp receipts, debt-book reminders, mobile-money request-to-pay. */
class EngageTest extends ApiTestCase
{
    private FakeFlutterwaveService $flw;

    private array $t;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flw = new FakeFlutterwaveService();
        $this->app->instance(FlutterwaveService::class, $this->flw);
        $this->t = $this->registerTenant(['currency' => 'UGX']);
        $this->h = $this->auth($this->t['token']);
    }

    private function product(): array
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $this->h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $this->h)->json('data.id');

        return $this->postJson('/api/v1/stock-items', ['name' => 'Sugar', 'stock_sub_category_id' => $sub, 'selling_price' => 5000, 'buying_price' => 4400, 'original_quantity' => 50], $this->h)->json('data');
    }

    private function freePlan(): void
    {
        $free = Plan::where('slug', 'free')->first();
        Subscription::where('company_id', $this->t['company_id'])->update(['plan_id' => $free->id, 'status' => 'active', 'ends_at' => null, 'trial_ends_at' => null]);
    }

    public function test_receipts_go_out_on_whatsapp_automatically_with_a_public_link(): void
    {
        $p = $this->product();
        DB::table('companies')->where('id', $this->t['company_id'])->update(['receipt_channels' => json_encode(['whatsapp'])]);
        $sale = $this->postJson('/api/v1/sales/checkout', ['customer_name' => 'Aisha', 'customer_phone' => '0772 555 010', 'payments' => [['method' => 'cash', 'amount' => 10000]],
            'items' => [['stock_item_id' => $p['id'], 'quantity' => 2]]], $this->h)->assertStatus(201)->json('data');
        $msg = DB::table('message_log')->where('purpose', 'receipt')->where('to', '+256772555010')->first();
        $this->assertNotNull($msg, 'sent automatically after the sale');
        $this->assertSame('whatsapp', $msg->channel);
        $token = DB::table('sale_records')->where('id', $sale['id'])->value('receipt_token');
        $this->assertStringContainsString('/r/'.$token, $msg->body);
        $this->get('/r/'.$token)->assertOk()->assertSee('Sugar')->assertSee('10,000');
        $this->assertStringStartsWith('%PDF', $this->get('/r/'.$token.'/pdf')->getContent());
        $this->get('/r/'.str_repeat('x', 32))->assertNotFound();

        // Manual send to another number; the Free plan cannot send receipts.
        $this->postJson("/api/v1/sales/{$sale['id']}/send-receipt", ['phone' => '0701222333'], $this->h)->assertOk()->assertJsonPath('data.link', url('r/'.$token));
        $this->postJson("/api/v1/sales/{$sale['uuid']}/send-receipt", [], $this->h)->assertOk(); // phones address sales by uuid
        $this->freePlan();
        $this->postJson("/api/v1/sales/{$sale['id']}/send-receipt", ['phone' => '0701222333'], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'feature_not_in_plan');
    }

    public function test_debt_reminders_only_for_overdue_customers_of_shops_that_turned_them_on_at_most_weekly(): void
    {
        $paid = Plan::create(['name' => 'Starter', 'slug' => 'st-'.uniqid(), 'price' => 19, 'price_ugx' => 70000, 'currency' => 'UGX', 'interval' => 'month', 'trial_days' => 0,
            'is_active' => true, 'is_public' => true, 'sort_order' => 1, 'features' => [], 'limits' => []]);
        Subscription::create(['company_id' => $this->t['company_id'], 'plan_id' => $paid->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonths(3), 'provider' => 'manual']);
        $p = $this->product();
        $cust = $this->postJson('/api/v1/customers', ['name' => 'Kato', 'phone' => '0772 600 700', 'credit_limit' => 100000], $this->h)->json('data.id');
        $sale = $this->postJson('/api/v1/sales/checkout', ['customer_id' => $cust, 'payments' => [], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 3]]], $this->h)->assertStatus(201)->json('data');
        $this->putJson('/api/v1/company/engagement', ['credit_terms_days' => 14], $this->h)->assertOk()->assertJsonPath('data.debt_reminders_enabled', false);
        $this->travelTo(now()->addDays(20)->setTime(12, 0));

        $this->assertSame(0, app(DebtReminders::class)->run(), 'off until the shop turns it on');
        $this->putJson('/api/v1/company/engagement', ['debt_reminders_enabled' => true], $this->h)->assertOk();
        $this->assertSame(1, app(DebtReminders::class)->run());
        $this->assertSame(0, app(DebtReminders::class)->run(), 'once a week at most');
        $msg = DB::table('message_log')->where('purpose', 'debt_reminder')->where('to', '+256772600700')->value('body');
        $this->assertStringContainsString('15,000', $msg);
        $this->assertStringContainsString('Kato', $msg);

        // Manual reminder: not twice in a day; nothing to remind once paid.
        $this->postJson("/api/v1/customers/{$cust}/remind", [], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'reminded_recently');
        $this->postJson("/api/v1/sales/{$sale['id']}/payments", ['amount' => 15000, 'method' => 'cash'], $this->h)->assertStatus(201);
        $this->travel(2)->days();
        $this->postJson("/api/v1/customers/{$cust}/remind", [], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'nothing_owed');
    }

    public function test_momo_request_to_pay_settles_the_sale_once(): void
    {
        $p = $this->product();
        $cust = $this->postJson('/api/v1/customers', ['name' => 'Grace', 'phone' => '0772 800 900', 'credit_limit' => 100000], $this->h)->json('data.id');
        $sale = $this->postJson('/api/v1/sales/checkout', ['customer_id' => $cust, 'payments' => [], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 1]]], $this->h)->json('data');

        $this->postJson("/api/v1/sales/{$sale['id']}/momo-request", ['phone' => '0772800900'], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'momo_not_set_up');
        $this->putJson('/api/v1/company/momo', ['phone' => '0772 111 000', 'network' => 'VODA'], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'unsupported_network');
        $this->putJson('/api/v1/company/momo', ['phone' => '0772 111 000', 'network' => 'MTN'], $this->h)->assertOk()->assertJsonPath('data.momo.ready', true)->assertJsonPath('data.momo.phone', '+256772111000');
        $this->assertSame('MTN', $this->flw->subaccounts[0]['account_bank']);

        $this->postJson("/api/v1/sales/{$sale['id']}/momo-request", ['phone' => '0772800900', 'network' => 'MTN', 'amount' => 6000], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'invalid_amount');
        $req = $this->postJson("/api/v1/sales/{$sale['id']}/momo-request", ['phone' => '0772800900', 'network' => 'MTN'], $this->h)->assertStatus(201)->json('data');
        $this->assertSame('pending', $req['status']);
        $charge = $this->flw->charges[0];
        $this->assertSame('mobile_money_uganda', $charge['type']);
        $this->assertSame(5000, (int) $charge['payload']['amount']);
        $this->assertSame([['id' => DB::table('companies')->where('id', $this->t['company_id'])->value('momo_subaccount_id')]], $charge['payload']['subaccounts'], 'money settles to the shop');

        $this->getJson("/api/v1/momo-requests/{$req['id']}", $this->h)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->flw->customerAnswers($req['tx_ref'], 5000, 'UGX');
        $this->getJson("/api/v1/momo-requests/{$req['id']}", $this->h)->assertOk()->assertJsonPath('data.status', 'successful');
        // The webhook arriving later changes nothing.
        config(['flutterwave.secret_hash' => 'hash']);
        $this->postJson('/api/v1/webhooks/flutterwave', ['data' => ['tx_ref' => $req['tx_ref'], 'id' => 1, 'status' => 'successful']], ['verif-hash' => 'hash'])->assertOk();
        $this->assertSame(1, DB::table('payments')->where('sale_record_id', $sale['id'])->where('method', 'mobile_money')->count());
        $this->assertEquals(0, (float) DB::table('sale_records')->where('id', $sale['id'])->value('balance'));
    }
}
