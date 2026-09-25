<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use ZipArchive;

/** Plan A6 (P4-2): reports agree with the documents (no double counting), render as PDF/XLSX, respect roles. */
class ReportsTest extends ApiTestCase
{
    private array $t;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->registerTenant();
        $this->h = $this->auth($this->t['token']);
    }

    private function product(string $name, float $price, float $cost, float $qty = 100, ?float $min = null): array
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => $name.' cat'], $this->h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $this->h)->json('data.id');

        return $this->postJson('/api/v1/stock-items', array_filter(['name' => $name, 'stock_sub_category_id' => $sub, 'selling_price' => $price, 'buying_price' => $cost, 'original_quantity' => $qty, 'min_stock' => $min], fn ($v) => $v !== null), $this->h)
            ->assertStatus(201)->json('data');
    }

    private function report(string $name, array $q = []): array
    {
        return $this->getJson("/api/v1/reports/{$name}?".http_build_query($q), $this->h)->assertOk()->json('data');
    }

    public function test_sales_profit_and_payment_mix_match_the_sales(): void
    {
        $soda = $this->product('Soda', 1000, 600);
        $bread = $this->product('Bread', 4000, 3000);
        $cust = $this->postJson('/api/v1/customers', ['name' => 'Aunt Sarah', 'phone' => '0772100200', 'credit_limit' => 50000], $this->h)->json('data.id');
        $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'cash', 'amount' => 5000]], 'items' => [['stock_item_id' => $soda['id'], 'quantity' => 5]]], $this->h)->assertStatus(201);
        $mm = $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'mobile_money', 'amount' => 8000, 'reference' => 'X1']], 'items' => [['stock_item_id' => $bread['id'], 'quantity' => 2]]], $this->h)->json('data');
        $this->postJson('/api/v1/sales/checkout', ['customer_id' => $cust, 'payments' => [['method' => 'cash', 'amount' => 1000]], 'items' => [['stock_item_id' => $bread['id'], 'quantity' => 1]]], $this->h)->assertStatus(201);
        $void = $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'cash', 'amount' => 3000]], 'items' => [['stock_item_id' => $soda['id'], 'quantity' => 3]]], $this->h)->json('data');
        $this->postJson("/api/v1/sales/{$void['id']}/void", ['reason' => 'Mistake'], $this->h)->assertOk();
        // Return one bread from the mobile-money sale.
        $this->postJson("/api/v1/sales/{$mm['id']}/returns", ['items' => [['sale_item_id' => $mm['sale_record_items'][0]['id'], 'quantity' => 1]]], $this->h)->assertStatus(201);

        $byDay = $this->report('sales_summary', ['group_by' => 'day']);
        $this->assertSame(3, $byDay['totals']['sales'], 'the voided sale is not counted');
        $this->assertEquals(5000 + 4000 + 4000, $byDay['totals']['amount'], 'net of the returned bread');
        $this->assertEquals(3000, $byDay['totals']['owed']);

        $byMethod = collect($this->report('sales_summary', ['group_by' => 'method'])['rows'])->keyBy('label');
        // 6,000 cash taken − 4,000 refunded in cash for the returned bread; money in + still owed = net sales.
        $this->assertEquals(2000, $byMethod['Cash']['amount']);
        $this->assertEquals(8000, $byMethod['Mobile Money']['amount']);
        $this->assertEquals(3000, $byMethod['On credit (still owed)']['amount']);
        $this->assertEquals($byDay['totals']['amount'], $byMethod->sum('amount'));

        $byProduct = collect($this->report('sales_summary', ['group_by' => 'product'])['rows'])->keyBy('label');
        $this->assertEquals(2, $byProduct['Bread']['quantity']);
        $this->assertEquals(5, $byProduct['Soda']['quantity']);
        $this->assertCount(1, $this->report('sales_summary', ['group_by' => 'cashier'])['rows']);
        $this->assertContains('Aunt Sarah', array_column($this->report('sales_summary', ['group_by' => 'customer'])['rows'], 'label'));

        $profit = $this->report('profit', ['group_by' => 'product']);
        $this->assertEquals(5000 + 8000, $profit['totals']['revenue']);
        $this->assertEquals(5 * 600 + 2 * 3000, $profit['totals']['cost']);
        $this->assertEquals(13000 - 9000, $profit['totals']['profit']);
        $this->assertEquals(30.8, $profit['totals']['margin']);

        $top = $this->report('fast_movers');
        $this->assertSame('Soda', $top['rows'][0]['name']);
    }

    public function test_stock_aging_vat_purchases_and_exports(): void
    {
        $sugar = $this->product('Sugar', 5000, 4400, 3, 10);
        $this->product('Candles', 3000, 2000, 20);
        $cust = $this->postJson('/api/v1/customers', ['name' => 'Kato', 'credit_limit' => 100000], $this->h)->json('data.id');
        $old = $this->postJson('/api/v1/sales/checkout', ['customer_id' => $cust, 'payments' => [], 'items' => [['stock_item_id' => $sugar['id'], 'quantity' => 1]]], $this->h)->json('data');
        DB::table('sale_records')->where('id', $old['id'])->update(['sale_date' => now()->subDays(45)->toDateString()]);
        $this->postJson('/api/v1/sales/checkout', ['customer_id' => $cust, 'payments' => [], 'items' => [['stock_item_id' => $sugar['id'], 'quantity' => 1]]], $this->h)->assertStatus(201);
        $sup = $this->postJson('/api/v1/suppliers', ['name' => 'Mukwano'], $this->h)->json('data.id');
        $this->postJson('/api/v1/goods-receipts', ['supplier_id' => $sup, 'items' => [['stock_item_id' => $sugar['id'], 'quantity' => 10, 'unit_cost' => 4400]]], $this->h)->assertStatus(201);
        DB::table('companies')->where('id', $this->t['company_id'])->update(['tax_rate' => 18]);

        $aging = $this->report('customer_aging', ['from' => now()->subDays(90)->toDateString()]);
        $this->assertEquals(5000, $aging['rows'][0]['d0_30']);
        $this->assertEquals(5000, $aging['rows'][0]['d31_60']);
        $this->assertEquals(10000, $aging['totals']['total']);

        $val = collect($this->report('stock_valuation')['rows'])->keyBy('name');
        $this->assertEquals(11 * 4400, $val['Sugar']['value_at_cost']);
        $this->assertTrue(collect($this->report('dead_stock')['rows'])->contains('name', 'Candles'));
        $this->assertSame([], $this->report('low_stock')['rows'], 'sugar is back above its minimum after the delivery');

        $vat = $this->report('vat_summary');
        $this->assertEquals(round(5000 * 18 / 118, 2), $vat['rows'][0]['vat']);
        $this->assertEquals(round(44000 * 18 / 118, 2), $vat['rows'][1]['vat']);
        $this->assertEquals(44000, $this->report('purchase_summary')['totals']['total']);
        $this->assertEquals(44000, $this->report('supplier_balances')['totals']['balance']);
        $this->assertNotEmpty($this->report('movement_audit')['rows']);

        $pdf = $this->get('/api/v1/reports/customer_aging?format=pdf&from='.now()->subDays(90)->toDateString(), $this->h)->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $xlsx = $this->get('/api/v1/reports/stock_valuation?format=xlsx', $this->h)->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $xlsx->getContent());
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($file) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('Sugar', $sheet);
        $this->assertStringContainsString('<v>48400</v>', $sheet);
        $zip->close();
        @unlink($file);

        $this->getJson('/api/v1/reports/nope', $this->h)->assertStatus(404);
        $this->getJson('/api/v1/reports/profit?from=2026-02-01&to=2026-01-01', $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'invalid_range');
        $list = $this->getJson('/api/v1/reports', $this->h)->assertOk()->json('data.reports');
        $this->assertCount(13, $list);
    }

    public function test_roles_limit_which_reports_open(): void
    {
        $link = $this->postJson('/api/v1/team/invites', ['role' => 'stock_keeper', 'phone' => '0772929001'], $this->h)->json('data.link');
        $token = $this->postJson('/api/v1/invites/'.basename($link).'/accept', ['first_name' => 'S', 'last_name' => 'K', 'password' => 'secret123'])->json('data.token');
        $sk = $this->auth($token);
        $names = array_column($this->getJson('/api/v1/reports', $sk)->json('data.reports'), 'name');
        $this->assertSame(['stock_valuation', 'purchase_summary'], $names);
        $this->getJson('/api/v1/reports/profit', $sk)->assertStatus(403)->assertJsonPath('errors.permission', 'view_profit');
        $this->getJson('/api/v1/reports/stock_valuation', $sk)->assertOk();
    }
}
