<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\FinancialPeriod;
use App\Models\PurchaseOrder;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\StockTakeItem;
use App\Services\Notifications\ScheduledNotifications;
use App\Services\Shop\ApprovalService;
use App\Services\Shop\GoodsReceiptService;
use App\Services\Shop\LocationStock;
use App\Services\Shop\MarkdownService;
use App\Services\Shop\PurchaseOrderService;
use App\Services\Shop\SaleService;
use App\Services\Shop\ShortDatedService;
use App\Services\Shop\ShrinkService;
use App\Services\Shop\StockService;
use App\Services\Shop\StockTakeService;
use App\Support\BarcodeResolver;
use App\Support\LocalDate;
use App\Support\Rules\StockRecordRules;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\Feature\Admin\AdminTestCase;

/** Supermarket phase 2, the shelf (SUPERMARKET_PLAN.md D1, D2, B4, D3, D4): budget-pro's rules, on and off. */
class SupermarketShelfTest extends AdminTestCase
{
    private array $t;

    private int $sub;

    protected function setUp(): void
    {
        parent::setUp();
        LocationStock::flush();
        $this->t = $this->makeTenant('company');
        $cid = $this->t['company']->id;
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $cid, 'name' => 'Dairy']);
        $this->sub = StockSubCategory::create(['company_id' => $cid, 'stock_category_id' => $cat->id, 'name' => 'Milk', 'measurement_unit' => 'pcs'])->id;
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

    private function features(array $features, array $settings = []): void
    {
        StoreFeatures::update($this->t['company'], ['features' => $features, 'settings' => $settings]);
        $this->t['company'] = $this->t['company']->fresh();
    }

    /** A batch-tracked yoghurt: 10 expiring in 5 days (SOON), 10 in 60 days (LATE). */
    private function yoghurt(): StockItem
    {
        $p = $this->product(['name' => 'Yoghurt', 'track_batches' => true]);
        $today = LocalDate::today($this->cid());
        (new GoodsReceiptService())->receive($this->cid(), $this->uid(), [
            ['stock_item_id' => $p->id, 'quantity' => 10, 'unit_cost' => 600, 'batch_number' => 'LATE', 'expiry_date' => $today->copy()->addDays(60)->toDateString()],
            ['stock_item_id' => $p->id, 'quantity' => 10, 'unit_cost' => 600, 'batch_number' => 'SOON', 'expiry_date' => $today->copy()->addDays(5)->toDateString()],
        ]);

        return $p->fresh();
    }

    private function batch(StockItem $p, string $number): object
    {
        return DB::table('stock_batches')->where('stock_item_id', $p->id)->where('batch_number', $number)->first();
    }

    private function sell(StockItem $p, float $qty, ?float $price = null): array
    {
        return (new SaleService())->checkout($this->cid(), $this->uid(), ['items' => [['stock_item_id' => $p->id, 'quantity' => $qty] + ($price !== null ? ['unit_price' => $price] : [])],
            'payments' => [['method' => 'cash', 'amount' => $qty * ($price ?? (float) $p->selling_price)]], 'payments_explicit' => true]);
    }

    // ── D2: FEFO ─────────────────────────────────────────────

    public function test_sales_and_damage_take_the_first_expiring_batch_with_fefo_on_or_off(): void
    {
        foreach ([false, true] as $on) {
            $this->features(['fefo' => $on]);
            $p = $this->yoghurt();
            $this->sell($p, 4);
            $this->assertEquals(6, (float) $this->batch($p, 'SOON')->quantity, 'the batch expiring first is sold first ('.($on ? 'on' : 'off').')');
            $this->assertEquals(10, (float) $this->batch($p, 'LATE')->quantity);
            (new StockService())->record(['stock_item_id' => $p->id, 'type' => 'Damage', 'quantity' => 8, 'created_by_id' => $this->uid()]);
            $this->assertEquals(0, (float) $this->batch($p, 'SOON')->quantity, 'damage too');
            $this->assertEquals(8, (float) $this->batch($p, 'LATE')->quantity);
        }
    }

    public function test_short_dated_list_write_off_of_one_batch_and_the_daily_alert(): void
    {
        $p = $this->yoghurt();
        $this->assertFalse(ShortDatedService::enabled($this->t['company']), 'off unless fefo or markdowns');
        $svc = new ShortDatedService();
        $rows = $svc->batches($this->cid(), 14);
        $this->assertCount(1, $rows);
        $this->assertSame('SOON', $rows[0]->batch_number);
        $this->assertSame(5, $rows[0]->days_left);
        $this->assertEquals(6000, $rows[0]->value);
        $this->assertCount(2, $svc->batches($this->cid(), 90));

        // A write-off aimed at the LATE batch takes that batch, not the first-expiring one.
        $late = $this->batch($p, 'LATE');
        $r = $svc->writeOff($this->cid(), $this->uid(), (int) $late->id, 3);
        $this->assertSame('Expired', $r->type);
        $this->assertSame('expired', $r->reason);
        $this->assertEquals(7, (float) $this->batch($p, 'LATE')->quantity);
        $this->assertEquals(10, (float) $this->batch($p, 'SOON')->quantity);
        $this->assertEquals(17, (float) $p->fresh()->current_quantity);
        try {
            $svc->writeOff($this->cid(), $this->uid(), (int) $late->id, 8);
            $this->fail('more than the batch holds');
        } catch (BusinessRuleException $e) {
            $this->assertSame('invalid_quantity', $e->errorCode());
        }

        // Return to supplier: the delivery that brought the batch (only one with a supplier counts).
        $this->assertNull($svc->sourceReceipt($this->cid(), (int) $late->id), 'no supplier on that delivery');

        // Daily alert: only with the feature on, once a day.
        $this->travelTo(now()->setTimezone('Africa/Kampala')->setTime(9, 0)->utc());
        $count = fn () => DB::table('app_notifications')->where('company_id', $this->cid())->where('title', 'like', '%expire within 14 days%')->count();
        $run = app(ScheduledNotifications::class)->run();
        $this->assertArrayNotHasKey('short_dated', $run);
        $this->assertSame(0, $count());
        $this->features(['fefo' => true]);
        app(ScheduledNotifications::class)->run();
        app(ScheduledNotifications::class)->run();
        $this->assertSame(1, $count());
    }

    // ── B4: markdowns ────────────────────────────────────────

    public function test_markdown_labels_resolve_at_the_reduced_price_and_the_report_counts_recovery(): void
    {
        $p = $this->yoghurt();
        $soon = $this->batch($p, 'SOON');
        try {
            (new MarkdownService())->create($this->cid(), $this->uid(), (int) $soon->id, 30);
            $this->fail('markdowns are off');
        } catch (BusinessRuleException $e) {
            $this->assertSame('feature_off', $e->errorCode());
        }
        $this->features(['markdowns' => true]);
        $m = (new MarkdownService())->create($this->cid(), $this->uid(), (int) $soon->id, 30);
        $this->assertEquals(700, (float) $m->price);
        $this->assertMatchesRegularExpression('/^MD\d{8}$/', $m->barcode);

        $hit = BarcodeResolver::resolve($this->cid(), $m->barcode);
        $this->assertSame($p->id, $hit['stock_item_id']);
        $this->assertSame('markdown', $hit['source']);
        $this->assertEquals(700, $hit['price']);
        $this->assertNull($hit['unit_id']);
        $this->assertSame((int) $soon->id, $hit['batch_id']);

        // A second markdown of the batch replaces the first.
        $m2 = (new MarkdownService())->create($this->cid(), $this->uid(), (int) $soon->id, 50);
        $this->assertNull(BarcodeResolver::resolve($this->cid(), $m->barcode), 'the old label no longer scans');
        $this->assertEquals(500, BarcodeResolver::resolve($this->cid(), $m2->barcode)['price']);

        // Sold at the reduced price: FEFO takes the marked-down batch; the report counts it.
        $this->sell($p, 4, 500);
        (new ShortDatedService())->writeOff($this->cid(), $this->uid(), (int) $soon->id, 2);
        $today = LocalDate::today($this->cid())->toDateString();
        $rep = (new MarkdownService())->report($this->cid(), $today, $today);
        $this->assertEquals(2000, $rep['recovered']);
        $this->assertEquals(4, $rep['recovered_qty']);
        $this->assertEquals(4000, $rep['full_price']);
        $this->assertEquals(1200, $rep['written_off']);
        $this->assertEquals(4, (float) DB::table('batch_markdowns')->where('id', $m2->id)->value('sold_qty'));

        // Sold out: the label scans as nothing at the reduced price.
        $this->sell($p, 4, 500);
        $this->assertNull(BarcodeResolver::resolve($this->cid(), $m2->barcode));

        // Markdowns off again: labels do not resolve.
        $m3 = (new MarkdownService())->create($this->cid(), $this->uid(), (int) $this->batch($p, 'LATE')->id, 10);
        $this->features(['markdowns' => false]);
        $this->assertNull(BarcodeResolver::resolve($this->cid(), $m3->barcode));
    }

    // ── D4: shrink ───────────────────────────────────────────

    public function test_write_offs_above_the_limit_need_a_supervisor_only_with_approvals_on(): void
    {
        $p = $this->product(['original_quantity' => 50]);
        $svc = new ShrinkService();
        $attrs = ['stock_item_id' => $p->id, 'type' => 'Damage', 'quantity' => 10, 'reason' => 'stolen'];
        $this->assertFalse($svc->needsApproval($this->cid(), 'Damage', $p->id, 10));
        $svc->record($this->cid(), $this->uid(), $attrs); // off: as before
        $this->assertEquals(40, (float) $p->fresh()->current_quantity);

        $this->features(['approvals' => true], ['waste_limit' => 5000]);
        $this->assertFalse($svc->needsApproval($this->cid(), 'Damage', $p->id, 8), '4,800 is within the limit');
        $this->assertTrue($svc->needsApproval($this->cid(), 'Damage', $p->id, 10), '6,000 is above it');
        $this->assertFalse($svc->needsApproval($this->cid(), 'Adjustment Out', $p->id, 100), 'a count correction is not a write-off');
        try {
            $svc->record($this->cid(), $this->uid(), $attrs);
            $this->fail('needs approval');
        } catch (BusinessRuleException $e) {
            $this->assertSame('approval_required', $e->errorCode());
        }
        $this->assertEquals(40, (float) $p->fresh()->current_quantity);
        $approvals = new ApprovalService();
        $approvals->setPin($this->t['user'], '2468');
        $row = $approvals->grant($this->cid(), 'waste', '2468', $this->uid(), ['amount' => 6000]);
        $svc->record($this->cid(), $this->uid(), $attrs, (int) $row->id);
        $this->assertEquals(30, (float) $p->fresh()->current_quantity);
        try {
            $svc->record($this->cid(), $this->uid(), $attrs, (int) $row->id);
            $this->fail('an approval is used once');
        } catch (BusinessRuleException $e) {
            $this->assertSame('approval_invalid', $e->errorCode());
        }
    }

    public function test_new_waste_reasons_are_accepted_and_offered_only_in_supermarket_mode(): void
    {
        $rules = StockRecordRules::rules($this->cid());
        foreach (['stolen', 'own_use', 'sample', 'theft'] as $r) {
            $this->assertTrue(Validator::make(['reason' => $r], ['reason' => $rules['reason']])->passes(), $r);
        }
        $this->assertSame(StockRecordRules::REASONS, StockRecordRules::reasonsFor($this->t['company']));
        StoreFeatures::update($this->t['company'], ['mode' => true]);
        $this->assertContains('own_use', StockRecordRules::reasonsFor($this->t['company']->fresh()));
        $this->assertSame('Own use', StockRecordRules::reasonLabel('own_use'));
    }

    // ── D1: receive by scanning against an order ─────────────

    public function test_short_and_over_deliveries_are_recorded_on_the_receipt_only_with_scan_receiving(): void
    {
        $a = $this->product(['name' => 'Milk 1L']);
        $b = $this->product(['name' => 'Milk 500ml']);
        $c = $this->product(['name' => 'Cream']);
        $order = fn () => (new PurchaseOrderService())->create($this->cid(), $this->uid(), [
            ['stock_item_id' => $a->id, 'quantity' => 10, 'unit_cost' => 1000], ['stock_item_id' => $b->id, 'quantity' => 5, 'unit_cost' => 600], ['stock_item_id' => $c->id, 'quantity' => 2, 'unit_cost' => 900],
        ]);
        $receive = function (PurchaseOrder $po) {
            $items = $po->items()->get()->keyBy('stock_item_id');

            return (new PurchaseOrderService())->receive($po, $this->uid(), [
                ['purchase_order_item_id' => $items[$this->product_id('Milk 1L')]->id, 'quantity' => 8],
                ['purchase_order_item_id' => $items[$this->product_id('Milk 500ml')]->id, 'quantity' => 6],
                ['purchase_order_item_id' => $items[$this->product_id('Cream')]->id, 'quantity' => 2],
            ]);
        };
        $grn = $receive($order());
        $this->assertNull(DB::table('goods_receipts')->where('id', $grn->id)->value('discrepancies'), 'off: nothing new is written');

        $this->features(['scan_receiving' => true]);
        $grn = $receive($order());
        $d = collect(json_decode(DB::table('goods_receipts')->where('id', $grn->id)->value('discrepancies'), true))->keyBy('name');
        $this->assertCount(2, $d);
        $this->assertEquals(-2, $d['Milk 1L']['difference']);
        $this->assertEquals(10, $d['Milk 1L']['expected']);
        $this->assertEquals(1, $d['Milk 500ml']['difference']);
    }

    private function product_id(string $name): int
    {
        return (int) StockItem::withoutGlobalScopes()->where('company_id', $this->cid())->where('name', $name)->value('id');
    }

    // ── D3: aisle counts ─────────────────────────────────────

    public function test_aisle_counts_scope_by_shelf_and_big_variances_need_a_recount(): void
    {
        $svc = new StockTakeService();
        $p = $this->product(['original_quantity' => 50]);
        $q = $this->product(['original_quantity' => 20]);
        $svc->setShelfLocation($this->cid(), $p->id, ' a3-b2 ');
        $this->assertSame('A3-B2', $p->fresh()->shelf_location);
        $this->assertSame(['A3-B2'], StockTakeService::shelfLocations($this->cid()));

        // Off: the shelf is ignored, a big variance posts as before.
        $take = $svc->create($this->cid(), $this->uid(), 'Count', null, null, null, 'A3');
        $this->assertNull($take->shelf_location);
        $svc->count($take, [['stock_item_id' => $p->id, 'counted_quantity' => 30]]);
        $this->assertFalse((bool) StockTakeItem::withoutGlobalScopes()->where('stock_take_id', $take->id)->value('needs_recount'));
        $svc->post($take, $this->uid());
        $this->assertEquals(30, (float) $p->fresh()->current_quantity);

        $this->features(['aisle_counts' => true]);
        $take = $svc->create($this->cid(), $this->uid(), 'Aisle A3', null, null, null, 'a3');
        $this->assertSame('A3', $take->shelf_location);
        $svc->count($take, [['stock_item_id' => $p->id, 'counted_quantity' => 20], ['stock_item_id' => $q->id, 'counted_quantity' => 19]]);
        $item = fn (int $id) => StockTakeItem::withoutGlobalScopes()->where('stock_take_id', $take->id)->where('stock_item_id', $id)->first();
        $this->assertTrue($item($p->id)->needs_recount, '20 against 30 is beyond 10%');
        $this->assertEquals(20, (float) $item($p->id)->first_count);
        $this->assertFalse($item($q->id)->needs_recount, '19 against 20 is within');
        try {
            $svc->post($take, $this->uid());
            $this->fail('recount first');
        } catch (BusinessRuleException $e) {
            $this->assertSame('recount_needed', $e->errorCode());
        }
        $svc->count($take, [['stock_item_id' => $p->id, 'counted_quantity' => 21]]); // the recount stands
        $this->assertFalse($item($p->id)->needs_recount);
        $this->assertEquals(21, (float) $item($p->id)->counted_quantity);
        $svc->post($take, $this->uid());
        $this->assertEquals(21, (float) $p->fresh()->current_quantity);
    }
}
