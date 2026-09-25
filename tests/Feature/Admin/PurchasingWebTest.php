<?php

namespace Tests\Feature\Admin;

use App\Models\FinancialPeriod;
use App\Models\PurchaseOrder;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\Supplier;
use Illuminate\Support\Facades\Auth;

/** P4-1/P4-3 on the web: purchase orders, receiving, returns and the reorder list use the same services as the API. */
class PurchasingWebTest extends AdminTestCase
{
    public function test_order_send_receive_return_and_reorder_from_the_web(): void
    {
        $t = $this->makeTenant('company');
        $t['company']->forceFill(['onboarding_state' => ['step' => 'done', 'completed_at' => now()->toIso8601String()]])->saveQuietly();
        Auth::guard('admin')->login($t['user']);
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $t['company']->id, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $t['company']->id, 'name' => 'Drinks']);
        $sub = StockSubCategory::create(['company_id' => $t['company']->id, 'stock_category_id' => $cat->id, 'name' => 'Soda', 'measurement_unit' => 'pcs']);
        $p = StockItem::create(['company_id' => $t['company']->id, 'created_by_id' => $t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Cola', 'sku' => 'C-'.uniqid(), 'buying_price' => 600, 'selling_price' => 1000, 'original_quantity' => 2, 'min_stock' => 10]);
        $s = Supplier::create(['company_id' => $t['company']->id, 'name' => 'Coca-Cola depot', 'phone' => '0772333444']);
        $u = $t['user'];

        foreach (['/purchase-orders', '/purchase-orders/create', '/purchase-returns', '/purchase-returns/create', '/reorder-suggestions'] as $url) {
            $this->assertSame(200, $this->asAdmin($u)->get($url)->getStatusCode(), $url);
        }
        $this->asAdmin($u)->get('/reorder-suggestions')->assertSee('Cola')->assertSee('twice the minimum');

        $this->asAdmin($u)->post('/purchase-orders', ['supplier_id' => $s->id, 'items' => [['stock_item_id' => $p->id, 'quantity' => 24, 'unit_cost' => '']]])->assertRedirect();
        $po = PurchaseOrder::withoutGlobalScopes()->where('company_id', $t['company']->id)->with('items')->firstOrFail();
        $this->asAdmin($u)->get("/purchase-orders/{$po->id}")->assertOk()->assertSee('24 × Cola')->assertSee('Open in WhatsApp');
        $this->asAdmin($u)->post("/purchase-orders/{$po->id}/send")->assertRedirect()->assertRedirectContains('wa.me/256772333444');

        $item = $po->items->first();
        $this->asAdmin($u)->post("/purchase-orders/{$po->id}/receive", ['received' => [$item->id => ['quantity' => 24, 'unit_cost' => 600]], 'amount_paid' => 0])->assertRedirect();
        $this->assertSame('received', $po->fresh()->status);
        $this->assertEquals(26, (float) $p->fresh()->current_quantity);

        $this->asAdmin($u)->post('/purchase-returns', ['supplier_id' => $s->id, 'reason' => 'Dented', 'items' => [['stock_item_id' => $p->id, 'quantity' => 2, 'unit_cost' => '']]])->assertRedirect();
        $this->assertEquals(24, (float) $p->fresh()->current_quantity);
        $this->assertEquals(24 * 600 - 2 * 600, (float) $s->fresh()->balance);

        $this->asAdmin($u)->post('/reorder-suggestions/orders', ['items' => [$p->id => ['pick' => 1, 'quantity' => 12, 'supplier_id' => $s->id]]])->assertRedirect(admin_url('purchase-orders'));
        $this->assertSame(2, PurchaseOrder::withoutGlobalScopes()->where('company_id', $t['company']->id)->count());

        // Reports page renders the same numbers and downloads PDF/Excel (P4-2).
        $this->asAdmin($u)->get('/reports?report=stock_valuation')->assertOk()->assertSee('Stock on hand &amp; value', false)->assertSee('Cola');
        $this->assertStringStartsWith('%PDF', $this->asAdmin($u)->get('/reports?report=supplier_balances&format=pdf')->getContent());
        $this->assertStringStartsWith('PK', $this->asAdmin($u)->get('/reports?report=stock_valuation&format=xlsx')->getContent());
    }
}
