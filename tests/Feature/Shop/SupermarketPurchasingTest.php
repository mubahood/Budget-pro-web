<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\FinancialPeriod;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\Supplier;
use App\Services\Reports\ReportService;
use App\Services\Shop\ConsignmentService;
use App\Services\Shop\GoodsReceiptService;
use App\Services\Shop\LandedCost;
use App\Services\Shop\LocationStock;
use App\Services\Shop\ProductStatsService;
use App\Services\Shop\PurchaseOrderService;
use App\Services\Shop\PurchaseReturnService;
use App\Services\Shop\SaleService;
use App\Services\Shop\SmartReorderService;
use App\Services\Shop\SupplierPriceService;
use App\Services\Shop\SupplierService;
use App\Support\LocalDate;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminTestCase;

/** Supermarket phase 4, purchasing (SUPERMARKET_PLAN.md D5, D7, D8): budget-pro's rules, on and off. */
class SupermarketPurchasingTest extends AdminTestCase
{
    private array $t;

    private int $sub;

    protected function setUp(): void
    {
        parent::setUp();
        LocationStock::flush();
        $this->t = $this->makeTenant('company');
        $cid = $this->t['company']->id;
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->subYear(), 'end_date' => now()->addYear()]);
        $cat = StockCategory::create(['company_id' => $cid, 'name' => 'Drinks']);
        $this->sub = StockSubCategory::create(['company_id' => $cid, 'stock_category_id' => $cat->id, 'name' => 'Soda', 'measurement_unit' => 'pcs'])->id;
    }

    private function cid(): int
    {
        return (int) $this->t['company']->id;
    }

    private function uid(): int
    {
        return (int) $this->t['user']->id;
    }

    private function product(array $attrs = []): StockItem
    {
        return StockItem::create($attrs + ['company_id' => $this->cid(), 'created_by_id' => $this->uid(), 'stock_sub_category_id' => $this->sub,
            'name' => 'Item '.uniqid(), 'sku' => 'S-'.uniqid(), 'buying_price' => 600, 'selling_price' => 1000, 'original_quantity' => 0])->fresh();
    }

    private function supplier(string $name, array $attrs = []): Supplier
    {
        $s = Supplier::create(['company_id' => $this->cid(), 'name' => $name]);
        $s->forceFill($attrs + ['lead_time_days' => 7])->save();

        return $s->fresh();
    }

    private function features(array $features): void
    {
        StoreFeatures::update($this->t['company'], ['features' => $features]);
        $this->t['company'] = $this->t['company']->fresh();
    }

    private function sell(StockItem $p, float $qty, ?string $on = null): array
    {
        return (new SaleService())->checkout($this->cid(), $this->uid(), ['items' => [['stock_item_id' => $p->id, 'quantity' => $qty]],
            'payments' => [['method' => 'cash', 'amount' => $qty * (float) $p->selling_price]], 'payments_explicit' => true] + ($on ? ['sale_date' => $on] : []));
    }

    private function receive(StockItem $p, float $qty, float $cost, ?int $supplierId = null, array $options = [], ?string $on = null)
    {
        return (new GoodsReceiptService())->receive($this->cid(), $this->uid(), [['stock_item_id' => $p->id, 'quantity' => $qty, 'unit_cost' => $cost]], $supplierId, null, 0, 'cash', $on,
            null, null, null, null, null, $options);
    }

    /** The last $n dates (Y-m-d) that fall on a weekday (0 = Sunday … 6 = Saturday), before today. */
    private function lastWeekdays(int $dow, int $n): array
    {
        $out = [];
        $d = LocalDate::today($this->cid())->subDay();
        while (count($out) < $n) {
            if ($d->dayOfWeek === $dow) {
                $out[] = $d->toDateString();
            }
            $d->subDay();
        }

        return $out;
    }

    // ── Off: nothing changes ─────────────────────────────────

    public function test_with_the_features_off_purchasing_works_as_before(): void
    {
        $sup = $this->supplier('Coca');
        $p = $this->product(['min_stock' => 50]);
        $p->forceFill(['consignment_supplier_id' => $sup->id])->save(); // set, but consignment is off
        $grn = $this->receive($p, 10, 700, $sup->id, ['landed_costs' => [['label' => 'Transport', 'amount' => 1000]]]);

        $this->assertEquals(7000, (float) $grn->total_cost, 'consigned products owe as usual when consignment is off');
        $this->assertEquals(7000, (float) $sup->fresh()->balance);
        $this->assertEquals(700, (float) $p->fresh()->buying_price, 'no landed cost when off');
        $this->assertEquals(700, (float) DB::table('stock_records')->where('id', $grn->items[0]->stock_record_id)->value('unit_cost'));
        $this->assertNull($grn->items[0]->fresh()->landed_unit_cost);
        $this->assertSame(0, DB::table('supplier_prices')->where('company_id', $this->cid())->count(), 'no price list when off');
        $this->assertSame(0, DB::table('financial_records')->where('company_id', $this->cid())->where('source_type', 'goods_receipt')->count());

        $rows = (new ProductStatsService())->suggestions($this->cid());
        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('demand_daily', $rows[0]);
        $this->assertSame(90, $rows[0]['suggested_quantity'], 'min × 2 − on hand, as before');

        $ret = (new PurchaseReturnService())->create($this->cid(), $this->uid(), [['stock_item_id' => $p->id, 'quantity' => 2, 'unit_cost' => 700]], $sup->id);
        $this->assertEquals(1400, (float) $ret->total_value);
        $this->assertEquals(5600, (float) $sup->fresh()->balance);
    }

    // ── D5: smarter reordering ───────────────────────────────

    public function test_demand_follows_weekdays_over_8_weeks_and_discounts_promotion_spikes(): void
    {
        $this->features(['smart_reorder' => true]);
        $p = $this->product();
        $this->receive($p, 500, 600);
        foreach ($this->lastWeekdays(6, 8) as $sat) {
            $this->sell($p, 8, $sat);
        }
        $this->sell($p, 5, LocalDate::today($this->cid())->subDays(70)->toDateString()); // older than 8 weeks: ignored
        $tue = $this->lastWeekdays(2, 1)[0];
        $promo = $this->sell($p, 40, $tue);
        DB::table('sale_record_items')->where('sale_record_id', $promo['sale']->id)->update(['promo_discount' => 4000]);

        $d = (new SmartReorderService())->demand($this->cid(), [(int) $p->id])[(int) $p->id];
        $this->assertEquals(8.0, $d['weekday'][7], 'Saturdays: 8 a week');
        $this->assertEquals(1.25, $d['weekday'][3], 'the promotion spike (40) counts a quarter: 10 over 8 Tuesdays');
        $this->assertEquals(0.0, $d['weekday'][2]);
        $this->assertEquals(40.0, $d['promo_units']);
        $this->assertEquals(9.25, (new SmartReorderService())->forecast($d, 7, LocalDate::today($this->cid())), 'one of every weekday in 7 days');
    }

    public function test_reorder_rounds_to_packs_tops_up_to_the_minimum_order_and_flags_products_running_out(): void
    {
        $this->features(['smart_reorder' => true]);
        $sup = $this->supplier('Crown', ['lead_time_days' => 7, 'min_order_value' => 20000]);
        $carton = DB::table('units')->insertGetId(['company_id' => $this->cid(), 'name' => 'Carton', 'abbreviation' => 'ctn', 'factor' => 12, 'created_at' => now(), 'updated_at' => now()]);
        $a = $this->product(['min_stock' => 100]);
        (new SmartReorderService())->setPackUnit($a, $carton);
        $this->receive($a, 160, 600, $sup->id);
        foreach ($this->lastWeekdays(6, 8) as $sat) {
            $this->sell($a, 8, $sat);
        }
        // B is above its minimum (0) but sells 10 every Saturday with 5 left: it runs out before the next delivery.
        $b = $this->product(['min_stock' => 0]);
        $this->receive($b, 85, 500, $sup->id);
        foreach ($this->lastWeekdays(6, 8) as $sat) {
            $this->sell($b, 10, $sat);
        }

        $rows = collect((new ProductStatsService())->suggestions($this->cid()))->keyBy('stock_item_id');
        $ra = $rows[$a->id];
        // 96 on hand; 8 expected in the 7 days + 100 in reserve − 96 = 12: one carton, then whole cartons to reach the minimum order.
        $this->assertEquals(8.0, $ra['forecast_lead']);
        $this->assertEquals(12.0, $ra['pack_size']);
        $this->assertTrue(fmod($ra['suggested_quantity'], 12) == 0.0, 'whole cartons');
        $this->assertTrue($rows->has($b->id), 'running out is listed although above its minimum');
        $this->assertTrue($rows[$b->id]['running_out']);
        $this->assertStringContainsString('run out before the next delivery', $rows[$b->id]['why']);
        $total = $ra['suggested_quantity'] * 600 + $rows[$b->id]['suggested_quantity'] * 500;
        $this->assertGreaterThanOrEqual(20000, $total, 'topped up to the supplier\'s minimum order');
        $this->assertFalse($ra['below_min_order']);
        $this->assertEquals(round($total, 2), $ra['supplier_total']);
        $this->assertGreaterThan(0, $ra['topped_up'] + $rows[$b->id]['topped_up']);

        // One click: a draft per supplier, whole packs, expected after the lead time.
        $orders = (new ProductStatsService())->createOrders($this->cid(), $this->uid(), [['stock_item_id' => $a->id, 'quantity' => 13, 'supplier_id' => $sup->id]]);
        $this->assertCount(1, $orders);
        $this->assertEquals(24, (float) $orders[0]->items[0]->quantity, '13 rounds up to 2 cartons');
        $this->assertSame(LocalDate::today($this->cid())->addDays(7)->toDateString(), $orders[0]->expected_date->toDateString());

        // Below the minimum when the cap stops the top-up.
        $sup->forceFill(['min_order_value' => 10000000])->save();
        $rows = collect((new ProductStatsService())->suggestions($this->cid()))->keyBy('stock_item_id');
        $this->assertTrue($rows[$a->id]['below_min_order']);
    }

    public function test_supplier_scorecard_and_report(): void
    {
        $sup = $this->supplier('Late Ltd', ['lead_time_days' => 3]);
        $p = $this->product();
        $today = LocalDate::today($this->cid());
        $this->receive($p, 10, 500, $sup->id, [], $today->copy()->subDays(20)->toDateString());
        $po = (new PurchaseOrderService())->create($this->cid(), $this->uid(), [['stock_item_id' => $p->id, 'quantity' => 10, 'unit_cost' => 600]], $sup->id);
        DB::table('purchase_orders')->where('id', $po->id)->update(['order_date' => $today->copy()->subDays(10)->toDateString().' 09:00:00']);
        $item = $po->items[0];
        (new PurchaseOrderService())->receive($po->fresh(), $this->uid(), [['purchase_order_item_id' => $item->id, 'quantity' => 8]], 0, 'cash', null, $today->copy()->subDays(5)->toDateString());

        $card = (new SmartReorderService())->scorecard($sup->fresh());
        $this->assertSame(1, $card['orders']);
        $this->assertEquals(80.0, $card['fill_rate']);
        $this->assertEquals(2.0, $card['avg_days_late'], 'promised on day 3, arrived on day 5');
        $this->assertEquals(5.0, $card['avg_lead_days']);
        $this->assertEquals(0.0, $card['on_time_pct']);
        $this->assertSame(1, $card['price_changes']);
        $this->assertEquals(20.0, $card['price_change_pct']);

        $r = (new ReportService())->run($this->cid(), 'supplier_scorecard', $today->copy()->subDays(89)->toDateString(), $today->toDateString());
        $this->assertSame('Supplier scorecard', $r['title']);
        $this->assertCount(1, $r['rows']);
        $this->assertEquals(80.0, $r['rows'][0]['fill_rate']);
    }

    // ── D7: supplier prices, landed cost ─────────────────────

    public function test_supplier_prices_follow_receiving_and_start_purchase_order_lines(): void
    {
        $this->features(['supplier_prices' => true]);
        $a = $this->supplier('Alpha');
        $b = $this->supplier('Beta');
        $p = $this->product();
        $this->receive($p, 5, 650, $a->id);
        $this->receive($p, 5, 650, $a->id); // same cost: no new history
        $this->receive($p, 5, 620, $b->id);
        $svc = new SupplierPriceService();
        $this->assertSame(1, DB::table('supplier_prices')->where('supplier_id', $a->id)->count());
        $this->assertEquals(650, $svc->current($this->cid(), $a->id, $p->id));
        (new SupplierPriceService())->record($this->cid(), $a->id, $p->id, 700, null, null, 'manual', null, $this->uid());
        $list = $svc->forProduct($this->cid(), $p->id);
        $this->assertSame(['Beta', 'Alpha'], array_column($list, 'supplier'), 'cheapest first');
        $this->assertEquals(650, $list[1]['previous']);
        $this->assertEquals(7.7, $list[1]['change_pct']);

        // The product's cost is now 620 (last delivery); an order to Alpha starts at Alpha's price.
        $po = (new PurchaseOrderService())->create($this->cid(), $this->uid(), [['stock_item_id' => $p->id, 'quantity' => 3]], $a->id);
        $this->assertEquals(700, (float) $po->items[0]->unit_cost);
        $po = (new PurchaseOrderService())->create($this->cid(), $this->uid(), [['stock_item_id' => $p->id, 'quantity' => 3]], null);
        $this->assertEquals(620, (float) $po->items[0]->unit_cost, 'no supplier: the product cost');
    }

    public function test_landed_cost_is_spread_and_becomes_the_cost_of_later_sales(): void
    {
        $this->features(['landed_cost' => true]);
        $sup = $this->supplier('Importer');
        $x = $this->product(['selling_price' => 2000]);
        $y = $this->product(['selling_price' => 1000]);
        $grn = (new GoodsReceiptService())->receive($this->cid(), $this->uid(), [
            ['stock_item_id' => $x->id, 'quantity' => 10, 'unit_cost' => 1000],
            ['stock_item_id' => $y->id, 'quantity' => 10, 'unit_cost' => 500],
        ], $sup->id, 'INV-9', 0, 'cash', null, null, null, null, null, null, ['landed_costs' => [['label' => 'Transport', 'amount' => 1000], ['label' => 'Duty', 'amount' => 500]]]);

        $this->assertEquals(15000, (float) $grn->total_cost, 'the invoice is unchanged');
        $this->assertEquals(15000, (float) $sup->fresh()->balance, 'the extras are not owed to the supplier');
        $this->assertEquals(1500, (float) $grn->landed_cost_total);
        $this->assertSame('value', $grn->landed_split);
        $items = $grn->items->keyBy('stock_item_id');
        $this->assertEquals(1100, (float) $items[$x->id]->landed_unit_cost, '2/3 of 1500 over 10');
        $this->assertEquals(550, (float) $items[$y->id]->landed_unit_cost);
        $this->assertEquals(1100, (float) DB::table('stock_records')->where('id', $items[$x->id]->stock_record_id)->value('unit_cost'), 'stock is valued landed');
        $this->assertEquals(1100, (float) $x->fresh()->buying_price, 'the product cost follows the landed cost');
        $this->assertEquals(1500, (float) DB::table('financial_records')->where('source_type', 'goods_receipt')->where('source_id', $grn->id)->sum('amount'), 'paid as a stock purchase');

        $sale = $this->sell($x->fresh(), 1);
        $this->assertEquals(900, (float) DB::table('sale_record_items')->where('sale_record_id', $sale['sale']->id)->value('profit'), 'margin after landed cost');

        // By quantity, and through a purchase order.
        $po = (new PurchaseOrderService())->create($this->cid(), $this->uid(), [['stock_item_id' => $x->id, 'quantity' => 4, 'unit_cost' => 1000], ['stock_item_id' => $y->id, 'quantity' => 6, 'unit_cost' => 500]], $sup->id);
        $lines = $po->items->map(fn ($i) => ['purchase_order_item_id' => $i->id, 'quantity' => $i->quantity])->all();
        $g2 = (new PurchaseOrderService())->receive($po, $this->uid(), $lines, 0, 'cash', null, null, null, ['landed_costs' => [['label' => 'Handling', 'amount' => 1000]], 'landed_split' => 'quantity']);
        $this->assertEquals(1100, (float) $g2->items->firstWhere('stock_item_id', $x->id)->landed_unit_cost, '1000 over 10 units = 100 each');
        $this->assertEquals(600, (float) $g2->items->firstWhere('stock_item_id', $y->id)->landed_unit_cost);

        $this->assertEqualsWithDelta([6.667, 6.667], array_values(LandedCost::spread([['quantity' => 1, 'cost' => 0], ['quantity' => 2, 'cost' => 0]], 20, 'value')), 0.001, 'free goods: by quantity');
    }

    // ── D8: consignment ──────────────────────────────────────

    public function test_consigned_stock_owes_nothing_until_sold_and_settles_by_what_sold(): void
    {
        $this->features(['consignment' => true]);
        $sup = $this->supplier('Daily News');
        $paper = $this->product(['name' => 'Newspaper', 'buying_price' => 1500, 'selling_price' => 2000]);
        $own = $this->product(['name' => 'Pen']);
        $svc = new ConsignmentService();
        $svc->setSupplier($paper, $sup->id);

        $this->receive($own, 1, 600);
        $this->sell($own->fresh(), 1); // the shop's own stock: never settled
        $grn = (new GoodsReceiptService())->receive($this->cid(), $this->uid(), [
            ['stock_item_id' => $paper->id, 'quantity' => 20, 'unit_cost' => 1500],
            ['stock_item_id' => $own->id, 'quantity' => 2, 'unit_cost' => 600],
        ], $sup->id);
        $this->assertEquals(1200, (float) $grn->total_cost, 'only the shop\'s own goods are owed');
        $this->assertEquals(30000, (float) $grn->consignment_value);
        $this->assertTrue((bool) $grn->items->firstWhere('stock_item_id', $paper->id)->is_consignment);
        $this->assertEquals(1200, (float) $sup->fresh()->balance);

        $this->sell($paper->fresh(), 5);
        $s = $this->sell($paper->fresh(), 2);
        (new SaleService())->void($s['sale']->fresh(), 'mistake', $this->uid()); // net 5 sold
        $p = $svc->pending($sup->fresh());
        $this->assertEquals(5, $p['quantity']);
        $this->assertEquals(7500, $p['amount']);
        $this->assertEquals(15, $p['on_hand']);

        $row = $svc->settle($sup->fresh(), $this->uid());
        $this->assertEquals(7500, (float) $row->amount);
        $this->assertEquals(8700, (float) $sup->fresh()->balance, 'the settlement is owed like a delivery');
        $st = (new SupplierService())->statement($sup->fresh());
        $this->assertEquals(8700, $st['closing_balance']);
        $this->assertContains('consignment', array_column($st['entries'], 'type'));
        try {
            $svc->settle($sup->fresh(), $this->uid());
            $this->fail('nothing new to settle');
        } catch (BusinessRuleException $e) {
            $this->assertSame('nothing_to_settle', $e->errorCode());
        }

        // Unsold papers go back: nothing comes off what is owed.
        $ret = (new PurchaseReturnService())->create($this->cid(), $this->uid(), [['stock_item_id' => $paper->id, 'quantity' => 15, 'unit_cost' => 1500]], $sup->id);
        $this->assertEquals(0, (float) $ret->total_value);
        $this->assertEquals(22500, (float) $ret->consignment_value);
        $this->assertEquals(8700, (float) $sup->fresh()->balance);
        $this->assertEquals(0, (float) $paper->fresh()->current_quantity);

        // Paying clears it as usual.
        (new SupplierService())->pay($sup->fresh(), 8700, 'cash', $this->uid());
        $this->assertEquals(0, (float) $sup->fresh()->balance);
    }
}
