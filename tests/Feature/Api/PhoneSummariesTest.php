<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;

/** D2 "worth a look", D3 customer summary (+ statement PDF), D4 product summary. */
class PhoneSummariesTest extends ApiTestCase
{
    private array $t;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->registerTenant();
        $this->h = $this->auth($this->t['token']);
    }

    private function product(float $qty = 50, array $extra = []): array
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $this->h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $this->h)->json('data.id');

        return $this->postJson('/api/v1/stock-items', $extra + ['name' => 'Item '.uniqid(), 'stock_sub_category_id' => $sub, 'selling_price' => 1000, 'buying_price' => 600, 'original_quantity' => $qty], $this->h)
            ->assertStatus(201)->json('data');
    }

    private function sale(array $p, int $qty, float $paid, array $extra = []): array
    {
        return $this->postJson('/api/v1/sales/checkout', $extra + ['payments' => $paid > 0 ? [['method' => 'cash', 'amount' => $paid]] : [], 'items' => [['stock_item_id' => $p['id'], 'quantity' => $qty]]], $this->h)
            ->assertStatus(201)->json('data');
    }

    private function cashier(): array
    {
        $u = new User();
        $u->forceFill(['name' => 'Cashier', 'email' => 'c'.uniqid('', true).'@example.test', 'password' => bcrypt('secret123'), 'status' => 'Active', 'company_id' => $this->t['company_id']])->save();
        CompanyMember::create(['company_id' => $this->t['company_id'], 'user_id' => $u->id, 'role' => 'cashier', 'status' => 'active', 'joined_at' => now()]);
        Permissions::flush();

        return $this->auth($u->createToken('t')->plainTextToken);
    }

    public function test_customer_summary_ages_the_debt_and_brings_the_statement_forward(): void
    {
        $p = $this->product();
        $c = $this->postJson('/api/v1/customers', ['name' => 'Nakato', 'credit_limit' => 100000, 'payment_terms_days' => 7], $this->h)->assertStatus(201)->json('data');
        $this->sale($p, 3, 0, ['customer_id' => $c['id'], 'sale_date' => now()->subDays(70)->toDateString()]);
        $this->sale($p, 2, 0, ['customer_id' => $c['id'], 'sale_date' => now()->subDays(40)->toDateString()]);
        $this->sale($p, 1, 400, ['customer_id' => $c['id']]);
        $this->postJson("/api/v1/customers/{$c['id']}/payments", ['amount' => 500, 'method' => 'cash'], $this->h)->assertStatus(201);

        $from = now()->subDays(30)->toDateString();
        $d = $this->getJson("/api/v1/customers/{$c['uuid']}/summary?from={$from}", $this->h)->assertOk()->json('data');
        $this->assertEquals([5100, 100000, 94900, 0], [$d['figures']['owes'], $d['figures']['credit_limit'], $d['figures']['available'], $d['figures']['store_credit']]);
        $this->assertNull($d['figures']['points'], 'loyalty is off');
        $this->assertEquals(4500, $d['figures']['overdue']);
        $this->assertEquals([600, 2000, 2500], array_column($d['aging']['buckets'], 'amount'));
        $this->assertSame(['0_30', '31_60', '60_plus'], array_column($d['aging']['buckets'], 'key'));
        $this->assertCount(3, $d['recent_sales']);
        $this->assertGreaterThanOrEqual(2, count($d['payments']));
        $this->assertEquals(5000, $d['statement']['brought_forward']);
        $this->assertEquals(5100, $d['statement']['closing_balance']);
        $this->assertSame($from, $d['statement']['from']);
        $this->assertStringEndsWith("/api/v1/customers/{$c['uuid']}/statement.pdf?from={$from}", $d['statement_pdf_url']);

        $pdf = $this->get(parse_url($d['statement_pdf_url'], PHP_URL_PATH).'?from='.$from, $this->h)->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        // Default window: 90 days back. By id too; another shop's customer is a 404; a guest is a 401.
        $this->assertSame(now()->subDays(90)->toDateString(), $this->getJson("/api/v1/customers/{$c['id']}/summary", $this->h)->assertOk()->json('data.statement.from'));
        $this->getJson("/api/v1/customers/{$c['uuid']}/summary", $this->auth($this->registerTenant()['token']))->assertStatus(404);
        $this->getJson("/api/v1/customers/{$c['uuid']}/summary")->assertStatus(401);
    }

    public function test_product_summary_shows_stock_movements_sales_and_prices(): void
    {
        $p = $this->product(20);
        $this->sale($p, 3, 3000);
        $d = $this->getJson("/api/v1/stock-items/{$p['uuid']}/summary", $this->h)->assertOk()->json('data');
        $this->assertEquals(17, $d['product']['current_quantity']);
        $this->assertSame('in_stock', $d['product']['stock_state']);
        $this->assertEquals(600, $d['product']['buying_price']);
        $this->assertEquals(17, array_sum(array_column($d['locations'], 'quantity')));
        $this->assertSame('Sale', $d['movements'][0]['type']);
        $this->assertEquals([-3, 17], [$d['movements'][0]['delta'], $d['movements'][0]['balance']]);
        $this->assertSame('sale', $d['movements'][0]['document']['type']);
        $this->assertEquals([3, 3000, 1], [$d['sales_30d']['quantity'], $d['sales_30d']['value'], $d['sales_30d']['sales']]);
        $this->assertNull($d['supplier_prices'], 'feature off');
        $this->assertNull($d['price_history'], 'feature off');
        $this->assertSame([], $d['batches']);

        StoreFeatures::update(Company::find($this->t['company_id']), ['features' => ['price_book' => true, 'supplier_prices' => true]]);
        $this->putJson("/api/v1/stock-items/{$p['id']}", ['selling_price' => 1200], $this->h)->assertOk();
        DB::table('stock_items')->where('id', $p['id'])->update(['track_batches' => 1]);
        DB::table('stock_batches')->insert(['company_id' => $this->t['company_id'], 'stock_item_id' => $p['id'], 'batch_number' => 'LOT-1', 'expiry_date' => now()->addDays(10)->toDateString(), 'quantity' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $d = $this->getJson("/api/v1/stock-items/{$p['id']}/summary", $this->h)->assertOk()->json('data');
        $this->assertSame([], $d['supplier_prices']);
        $this->assertEquals(1200, $d['price_history']['applied'][0]['new']);
        $this->assertSame(['LOT-1', 'soon'], [$d['batches'][0]['batch_number'], $d['batches'][0]['state']]);

        $cashier = $this->getJson("/api/v1/stock-items/{$p['uuid']}/summary", $this->cashier())->assertOk()->json('data');
        $this->assertNull($cashier['product']['buying_price']);
        $this->assertNull($cashier['supplier_prices'], 'needs restock or view_reports');
        $this->getJson("/api/v1/stock-items/{$p['uuid']}/summary", $this->auth($this->registerTenant()['token']))->assertStatus(404);
    }

    public function test_worth_a_look_picks_what_this_person_can_act_on(): void
    {
        $this->assertSame(['all_clear'], array_column($this->getJson('/api/v1/dashboard/suggestions', $this->h)->assertOk()->assertJsonPath('data.all_clear', true)->json('data.items'), 'key'));

        $this->product(0);
        $this->product(2, ['min_stock' => 5]);
        $p = $this->product(50);
        $c = $this->postJson('/api/v1/customers', ['name' => 'Late payer'], $this->h)->json('data');
        $this->sale($p, 1, 0, ['customer_id' => $c['id'], 'sale_date' => now()->subDays(40)->toDateString()]);
        $sale = $this->sale($p, 1, 1000);
        DB::table('sale_records')->where('id', $sale['id'])->update(['customer_phone' => '0772123456']);
        $shift = $this->postJson('/api/v1/shifts/open', ['opening_float' => 0], $this->h)->assertStatus(201)->json('data');
        DB::table('shifts')->where('id', $shift['id'])->update(['opened_at' => now()->subDays(2)]);
        DB::table('stock_batches')->insert(['company_id' => $this->t['company_id'], 'stock_item_id' => $p['id'], 'batch_number' => 'B1', 'expiry_date' => now()->addDays(5)->toDateString(), 'quantity' => 4, 'created_at' => now(), 'updated_at' => now()]);
        $device = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device, 'name' => 'Old till'], $this->h)->assertOk();
        DB::table('devices')->where('device_id', $device)->update(['last_seen_at' => now()->subDays(5)]);
        $supplier = DB::table('suppliers')->insertGetId(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->t['company_id'], 'name' => 'Mukwano', 'payment_terms_days' => 7, 'balance' => 6000, 'is_active' => 1, 'is_deleted' => 0, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[20, 5000], [1, 1000]] as [$ago, $cost]) {
            DB::table('goods_receipts')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->t['company_id'], 'supplier_id' => $supplier, 'number' => 'GRN-'.uniqid(), 'received_on' => now()->subDays($ago)->toDateString(), 'total_cost' => $cost, 'amount_paid' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        $items = $this->getJson('/api/v1/dashboard/suggestions?limit=20', $this->h)->assertOk()->assertJsonPath('data.all_clear', false)->json('data.items');
        $keys = array_column($items, 'key');
        foreach (['out_of_stock', 'low_stock', 'credit_overdue', 'shift_open', 'expiring', 'devices_unsynced', 'supplier_bills_due'] as $key) {
            $this->assertContains($key, $keys);
        }
        if (app(\App\Services\Engage\ReceiptDelivery::class)->allowed(Company::find($this->t['company_id']))) {
            $this->assertContains('receipts_unsent', $keys);
        }
        $this->assertSame('out_of_stock', $keys[0], 'most urgent first');
        $bills = collect($items)->firstWhere('key', 'supplier_bills_due');
        $this->assertStringContainsString('5,000', $bills['detail'], 'only the bill past its terms');
        foreach ($items as $i) {
            $this->assertNotEmpty($i['title']);
            $this->assertNotEmpty($i['route']);
        }
        $this->assertCount(2, $this->getJson('/api/v1/dashboard/suggestions?limit=2', $this->h)->json('data.items'));

        // A cashier: the debts, but not stock, phones or suppliers; and only their own open till.
        $cashier = array_column($this->getJson('/api/v1/dashboard/suggestions?limit=20', $this->cashier())->assertOk()->json('data.items'), 'key');
        $this->assertContains('credit_overdue', $cashier);
        $this->assertEmpty(array_intersect(['low_stock', 'out_of_stock', 'devices_unsynced', 'supplier_bills_due', 'shift_open', 'expiring'], $cashier));
    }
}
