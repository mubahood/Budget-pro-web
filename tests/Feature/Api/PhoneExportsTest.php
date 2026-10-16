<?php

namespace Tests\Feature\Api;

use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Team\Permissions;

/** D6: the web's list exports (sales, products, customers) as CSV or XLSX for the phone. */
class PhoneExportsTest extends ApiTestCase
{
    private array $t;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->registerTenant();
        $this->h = $this->auth($this->t['token']);
    }

    private function member(string $role): array
    {
        $u = new User();
        $u->forceFill(['name' => ucfirst($role), 'email' => $role.uniqid('', true).'@example.test', 'password' => bcrypt('secret123'), 'status' => 'Active', 'company_id' => $this->t['company_id']])->save();
        CompanyMember::create(['company_id' => $this->t['company_id'], 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'joined_at' => now()]);
        Permissions::flush();

        return $this->auth($u->createToken('t')->plainTextToken);
    }

    private function fetch(string $uri, array $headers = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->get($uri, $headers);
    }

    /** @return list<list<string>> */
    private function csv(string $body): array
    {
        $lines = array_values(array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $body)), fn ($l) => trim($l) !== ''));

        return array_map('str_getcsv', $lines);
    }

    public function test_sales_products_and_customers_export_with_the_web_columns(): void
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'Drinks'], $this->h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'Soda', 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $this->h)->json('data.id');
        $p = $this->postJson('/api/v1/stock-items', ['name' => '=Fanta', 'stock_sub_category_id' => $sub, 'selling_price' => 1500, 'buying_price' => 900, 'original_quantity' => 10], $this->h)->assertStatus(201)->json('data');
        $c = $this->postJson('/api/v1/customers', ['name' => 'Opio', 'phone' => '0701000001'], $this->h)->json('data');
        $this->postJson('/api/v1/sales/checkout', ['customer_id' => $c['id'], 'payments' => [], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 2]]], $this->h)->assertStatus(201);
        $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'cash', 'amount' => 1500]], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 1]], 'sale_date' => now()->subDays(60)->toDateString()], $this->h)->assertStatus(201);

        $res = $this->fetch('/api/v1/exports/sales?format=csv', $this->h)->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=sales-', (string) $res->headers->get('Content-Disposition'));
        $rows = $this->csv($res->streamedContent());
        $this->assertSame(['Date', 'Time', 'Receipt', 'Customer', 'Phone', 'Total', 'Paid', 'Still owed', 'Status', 'Paid by', 'Sold by'], $rows[0]);
        $this->assertCount(2, $rows, 'the last 30 days by default: the old sale is left out');
        $this->assertSame(['Opio', '3000', '0', '3000', 'Completed'], [$rows[1][3], $rows[1][5], $rows[1][6], $rows[1][7], $rows[1][8]]);
        $all = $this->csv($this->fetch('/api/v1/exports/sales?from='.now()->subDays(90)->toDateString(), $this->h)->assertOk()->streamedContent());
        $this->assertCount(3, $all);
        $this->assertCount(2, $this->csv($this->fetch('/api/v1/exports/sales?scope=unpaid&from='.now()->subDays(90)->toDateString(), $this->h)->streamedContent()));

        $products = $this->csv($this->fetch('/api/v1/exports/products', $this->h)->assertOk()->streamedContent());
        $this->assertSame('Buying price', $products[0][9], 'the owner sees costs');
        $this->assertSame("'=Fanta", $products[1][0], 'a formula-looking cell is quoted');
        $this->assertSame(['7', '1500'], [$products[1][6], $products[1][8]]);

        $xlsx = $this->fetch('/api/v1/exports/customers?format=xlsx&scope=owes', $this->h)->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsx->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', (string) $xlsx->getContent());
        $zip = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($zip, $xlsx->getContent());
        $sheet = (string) file_get_contents('zip://'.$zip.'#xl/worksheets/sheet1.xml');
        @unlink($zip);
        $this->assertStringContainsString('Opio', $sheet);
        $this->assertStringContainsString('Credit limit', $sheet);

        // Permissions: a cashier may export customers but not sales or products; a stock keeper products without costs.
        $cashier = $this->member('cashier');
        $this->fetch('/api/v1/exports/sales', $cashier)->assertStatus(403)->assertJsonPath('errors.code', 'forbidden');
        $this->fetch('/api/v1/exports/products', $cashier)->assertStatus(403);
        $this->fetch('/api/v1/exports/customers', $cashier)->assertOk();
        $keeper = $this->csv($this->fetch('/api/v1/exports/products', $this->member('stock_keeper'))->assertOk()->streamedContent());
        $this->assertContains('Buying price', $keeper[0], 'stock keepers have view_cost');
        $this->fetch('/api/v1/exports/orders', $this->h)->assertStatus(404);
        $this->fetch('/api/v1/exports/sales?format=pdf', $this->h)->assertStatus(422);
        $this->fetch('/api/v1/exports/sales?from=2026-05-02&to=2026-05-01', $this->h)->assertStatus(422);
        $this->fetch('/api/v1/exports/sales')->assertStatus(401);
    }
}
