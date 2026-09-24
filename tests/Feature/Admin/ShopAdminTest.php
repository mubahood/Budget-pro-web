<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\FinancialPeriod;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\StockTake;
use App\Models\Supplier;
use App\Services\Shop\SaleService;
use Illuminate\Support\Facades\Auth;

/** P2-10: the web admin shop screens load for a tenant and drive the same services as the API. */
class ShopAdminTest extends AdminTestCase
{
    private function catalogue(array $t): StockItem
    {
        Auth::guard('admin')->login($t['user']);
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $t['company']->id, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $t['company']->id, 'name' => 'Drinks']);
        $sub = StockSubCategory::create(['company_id' => $t['company']->id, 'stock_category_id' => $cat->id, 'name' => 'Soda', 'measurement_unit' => 'pcs']);

        return StockItem::create(['company_id' => $t['company']->id, 'created_by_id' => $t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Cola', 'sku' => 'C-'.uniqid(), 'buying_price' => 600, 'selling_price' => 1000, 'original_quantity' => 20]);
    }

    public function test_new_shop_pages_load_for_a_tenant(): void
    {
        $t = $this->makeTenant('company');
        $p = $this->catalogue($t);
        foreach (['/customers', '/customers/create', '/suppliers', '/suppliers/create', '/units', '/units/create', '/shifts', '/goods-receipts', '/goods-receipts/create', '/stock-takes', "/stock-items/{$p->id}", '/companies-edit/'.$t['company']->id.'/edit'] as $url) {
            $this->assertSame(200, $this->asAdmin($t['user'])->get($url)->getStatusCode(), $url);
        }
        $this->assertStringContainsString('Movement history', $this->asAdmin($t['user'])->get("/stock-items/{$p->id}")->getContent());
    }

    public function test_customer_statement_and_payment_from_the_web(): void
    {
        $t = $this->makeTenant('company');
        $p = $this->catalogue($t);
        $c = Customer::create(['company_id' => $t['company']->id, 'name' => 'Mama Grace', 'phone' => '0772']);
        (new SaleService())->checkout($t['company']->id, $t['user']->id, ['customer_id' => $c->id, 'items' => [['stock_item_id' => $p->id, 'quantity' => 3]]]);
        $this->assertSame('3000.00', $c->fresh()->balance);

        $page = $this->asAdmin($t['user'])->get("/customers/{$c->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Statement', $page);
        $this->asAdmin($t['user'])->get("/customers/{$c->id}/pay")->assertOk();
        $this->asAdmin($t['user'])->post("/customers/{$c->id}/pay", ['amount' => 3000, 'method' => 'cash'])->assertRedirect();
        $this->assertSame('0.00', $c->fresh()->balance);
        $this->assertSame('Paid', SaleRecord::withoutGlobalScopes()->where('customer_id', $c->id)->value('payment_status'));
    }

    public function test_receive_stock_and_count_stock_from_the_web(): void
    {
        $t = $this->makeTenant('company');
        $p = $this->catalogue($t);
        $s = Supplier::create(['company_id' => $t['company']->id, 'name' => 'Crown']);
        $this->asAdmin($t['user'])->post('/goods-receipts', ['supplier_id' => $s->id, 'amount_paid' => 0, 'items' => [['stock_item_id' => $p->id, 'quantity' => 10, 'unit_cost' => 650]]])->assertRedirect();
        $this->assertSame(30.0, (float) $p->fresh()->current_quantity);
        $this->assertSame('6500.00', $s->fresh()->balance);

        $this->asAdmin($t['user'])->get('/stock-takes/create')->assertRedirect();
        $take = StockTake::withoutGlobalScopes()->where('company_id', $t['company']->id)->latest('id')->first();
        $this->asAdmin($t['user'])->get("/stock-takes/{$take->id}/count")->assertOk();
        $this->asAdmin($t['user'])->post("/stock-takes/{$take->id}/count", ['counts' => [$p->id => 28]])->assertRedirect();
        $this->asAdmin($t['user'])->post("/stock-takes/{$take->id}/post")->assertRedirect();
        $this->assertSame(28.0, (float) $p->fresh()->current_quantity);
    }

    public function test_other_tenants_customers_are_invisible(): void
    {
        $a = $this->makeTenant('company');
        $b = $this->makeTenant('company');
        $c = Customer::create(['company_id' => $b['company']->id, 'name' => 'Theirs']);
        $this->assertSame(404, $this->asAdmin($a['user'])->get("/customers/{$c->id}")->getStatusCode());
        $this->assertSame(404, $this->asAdmin($a['user'])->get("/customers/{$c->id}/pay")->getStatusCode());
    }
}
