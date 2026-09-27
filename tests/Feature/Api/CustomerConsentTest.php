<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Services\Engage\CustomerConsent;
use App\Services\Engage\DebtReminders;
use App\Services\Messaging\Messenger;
use Illuminate\Support\Facades\DB;

/** Supermarket plan C4: consent and messages (receipts, reminders, offers, the "Stop messages" link). */
class CustomerConsentTest extends ApiTestCase
{
    private array $t;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->registerTenant(['currency' => 'UGX']);
        $this->h = $this->auth($this->t['token']);
    }

    private function product(): array
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $this->h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $this->h)->json('data.id');

        return $this->postJson('/api/v1/stock-items', ['name' => 'Sugar', 'stock_sub_category_id' => $sub, 'selling_price' => 5000, 'buying_price' => 4400, 'original_quantity' => 50], $this->h)->json('data');
    }

    private function customer(array $attrs = []): Customer
    {
        $id = $this->postJson('/api/v1/customers', array_merge(['name' => 'Kato', 'phone' => '0772 600 700', 'credit_limit' => 100000], $attrs), $this->h)->assertStatus(201)->json('data.id');

        return Customer::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_the_rules_transactional_needs_no_opt_out_marketing_needs_opt_in(): void
    {
        $consent = new CustomerConsent();
        $plain = new Customer(['marketing_opt_in' => false, 'messages_opt_out' => false]);
        $agreed = new Customer(['marketing_opt_in' => true, 'messages_opt_out' => false]);
        $stopped = new Customer(['marketing_opt_in' => true, 'messages_opt_out' => true]);

        foreach (['receipt', 'statement', 'debt_reminder'] as $purpose) {
            $this->assertTrue($consent->canMessage($plain, $purpose), $purpose);
            $this->assertFalse($consent->canMessage($stopped, $purpose), $purpose);
        }
        $this->assertFalse($consent->canMessage($plain, 'marketing'));
        $this->assertTrue($consent->canMessage($agreed, 'marketing'));
        $this->assertSame(CustomerConsent::STATUS_OPT_OUT, $consent->refusal($stopped, 'marketing'));
        $this->assertSame(CustomerConsent::STATUS_NO_CONSENT, $consent->refusal($plain, 'promotion'));
        $this->assertTrue($consent->canMessage(null, 'marketing'), 'a number without a customer record: as before');
    }

    public function test_the_switches_are_writable_through_the_api_and_carry_their_dates(): void
    {
        $c = $this->customer(['marketing_opt_in' => true]);
        $this->assertTrue($c->marketing_opt_in);
        $this->assertNotNull($c->marketing_opt_in_at);
        $this->assertFalse($c->messages_opt_out);
        $this->assertNull($c->messages_opt_out_at);

        $this->putJson("/api/v1/customers/{$c->id}", ['marketing_opt_in' => false, 'messages_opt_out' => true], $this->h)->assertOk();
        $c->refresh();
        $this->assertNull($c->marketing_opt_in_at);
        $this->assertNotNull($c->messages_opt_out_at);
    }

    public function test_marketing_messages_are_blocked_without_agreement_and_every_message_after_an_opt_out(): void
    {
        $c = $this->customer();
        $m = app(Messenger::class);
        $meta = ['company_id' => $this->t['company_id'], 'customer_id' => $c->id];

        $id = $m->send('+256772600700', 'Offer', ['sms'], $meta + ['purpose' => 'marketing']);
        $this->assertSame('skipped_no_consent', DB::table('message_log')->where('id', $id)->value('status'));

        $c->update(['marketing_opt_in' => true]);
        $id = $m->send('+256772600700', 'Offer', ['sms'], $meta + ['purpose' => 'marketing']);
        $this->assertSame('sent', DB::table('message_log')->where('id', $id)->value('status'));

        $c->update(['messages_opt_out' => true]);
        foreach (['marketing', 'receipt', 'statement'] as $purpose) {
            $id = $m->send('+256772600700', 'x', ['sms'], $meta + ['purpose' => $purpose]);
            $row = DB::table('message_log')->find($id);
            $this->assertSame('skipped_opt_out', $row->status, $purpose);
            $this->assertStringContainsString('asked for no messages', $row->error);
        }
    }

    public function test_receipts_go_to_customers_with_a_stop_link_and_never_to_those_who_opted_out(): void
    {
        $p = $this->product();
        $c = $this->customer();
        DB::table('companies')->where('id', $this->t['company_id'])->update(['receipt_channels' => json_encode(['whatsapp'])]);
        $sale = $this->postJson('/api/v1/sales/checkout', ['customer_id' => $c->id, 'payments' => [['method' => 'cash', 'amount' => 5000]],
            'items' => [['stock_item_id' => $p['id'], 'quantity' => 1]]], $this->h)->assertStatus(201)->json('data');
        $msg = DB::table('message_log')->where('purpose', 'receipt')->where('to', '+256772600700')->first();
        $this->assertSame('sent', $msg->status);
        $this->assertStringContainsString('Stop messages: '.CustomerConsent::unsubscribeUrl($c), $msg->body);

        // A number with no customer record: as before, no stop line.
        $this->postJson("/api/v1/sales/{$sale['id']}/send-receipt", ['phone' => '0701222333'], $this->h)->assertOk()->assertJsonPath('data.status', 'sent');
        $this->assertStringNotContainsString('Stop messages', DB::table('message_log')->where('to', '+256701222333')->value('body'));

        // Opted out: automatic and manual receipts are logged as skipped, never an error at the till.
        $c->update(['messages_opt_out' => true]);
        $sale2 = $this->postJson('/api/v1/sales/checkout', ['customer_id' => $c->id, 'payments' => [['method' => 'cash', 'amount' => 5000]],
            'items' => [['stock_item_id' => $p['id'], 'quantity' => 1]]], $this->h)->assertStatus(201)->json('data');
        $this->assertSame(['sent', 'skipped_opt_out'], DB::table('message_log')->where('purpose', 'receipt')->where('to', '+256772600700')->orderBy('id')->pluck('status')->all());
        $this->assertNull(DB::table('sale_records')->where('id', $sale2['id'])->value('receipt_sent_at'));
        $this->postJson("/api/v1/sales/{$sale2['id']}/send-receipt", [], $this->h)->assertOk()->assertJsonPath('data.status', 'skipped_opt_out')
            ->assertJsonPath('message', 'The customer asked for no messages.');
        // The same person, typed as a phone number on a walk-in sale, is still recognised.
        $this->postJson("/api/v1/sales/{$sale['id']}/send-receipt", ['phone' => '+256 772 600 700'], $this->h)->assertOk()->assertJsonPath('data.status', 'skipped_opt_out');
    }

    public function test_debt_reminders_carry_the_stop_link_and_stop_after_an_opt_out(): void
    {
        $paid = \App\Models\Plan::create(['name' => 'Starter', 'slug' => 'st-'.uniqid(), 'price' => 19, 'price_ugx' => 70000, 'currency' => 'UGX', 'interval' => 'month', 'trial_days' => 0,
            'is_active' => true, 'is_public' => true, 'sort_order' => 1, 'features' => [], 'limits' => []]);
        \App\Models\Subscription::create(['company_id' => $this->t['company_id'], 'plan_id' => $paid->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonths(3), 'provider' => 'manual']);
        $p = $this->product();
        $c = $this->customer();
        $this->postJson('/api/v1/sales/checkout', ['customer_id' => $c->id, 'payments' => [], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 3]]], $this->h)->assertStatus(201);
        DB::table('companies')->where('id', $this->t['company_id'])->update(['debt_reminders_enabled' => true, 'credit_terms_days' => 14]);
        $this->travelTo(now()->addDays(20)->setTime(12, 0));

        $this->postJson("/api/v1/customers/{$c->id}/remind", [], $this->h)->assertOk();
        $body = DB::table('message_log')->where('purpose', 'debt_reminder')->value('body');
        $this->assertStringContainsString('Stop messages: '.CustomerConsent::unsubscribeUrl($c), $body);

        $c->refresh()->update(['messages_opt_out' => true]);
        $this->travel(2)->days();
        $this->postJson("/api/v1/customers/{$c->id}/remind", [], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'messages_opt_out');
        $this->assertSame('skipped_opt_out', DB::table('message_log')->where('purpose', 'debt_reminder')->orderByDesc('id')->value('status'));

        $this->travel(8)->days();
        $this->assertSame(0, app(DebtReminders::class)->run(), 'the daily run leaves opted-out customers alone');
    }

    public function test_the_stop_link_asks_then_stops_every_message_from_that_shop(): void
    {
        $c = $this->customer();
        $url = parse_url(CustomerConsent::unsubscribeUrl($c), PHP_URL_PATH);

        $shop = (string) DB::table('companies')->where('id', $this->t['company_id'])->value('name');
        $this->get($url)->assertOk()->assertSee('Stop all messages from '.$shop, false);
        $this->assertFalse($c->fresh()->messages_opt_out, 'opening the link (or a link preview) changes nothing');
        $this->post($url)->assertOk()->assertSee("You won't get more messages from {$shop}", false);
        $c->refresh();
        $this->assertTrue($c->messages_opt_out);
        $this->assertNotNull($c->messages_opt_out_at);
        $this->get($url)->assertOk()->assertSee("You won't get more messages from", false);

        $this->get('/stop/'.$c->id.'/'.str_repeat('0', 16))->assertNotFound();
        $this->post('/stop/'.$c->id.'/'.str_repeat('0', 16))->assertNotFound();
    }
}
