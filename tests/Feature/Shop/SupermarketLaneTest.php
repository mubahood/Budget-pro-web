<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\FinancialPeriod;
use App\Models\ProductBarcode;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\Unit;
use App\Services\Shop\BarcodeService;
use App\Services\Shop\DepositService;
use App\Services\Shop\HeldCartService;
use App\Services\Shop\SaleService;
use App\Support\BarcodeResolver;
use App\Support\CashRounding;
use App\Support\Rules\CheckoutRules;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminTestCase;

/** Supermarket phase 1, the lane (SUPERMARKET_PLAN.md A1, A2, A4, A6, A7, A10, A11): budget-pro's rules. */
class SupermarketLaneTest extends AdminTestCase
{
    private array $t;

    private int $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->makeTenant('company');
        $cid = $this->t['company']->id;
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $cid, 'name' => 'Food']);
        $this->sub = StockSubCategory::create(['company_id' => $cid, 'stock_category_id' => $cat->id, 'name' => 'Dry', 'measurement_unit' => 'pcs'])->id;
    }

    private function product(array $attrs): StockItem
    {
        $cid = $this->t['company']->id;

        return StockItem::create($attrs + ['company_id' => $cid, 'created_by_id' => $this->t['user']->id, 'stock_sub_category_id' => $this->sub,
            'name' => 'Item '.uniqid(), 'sku' => 'S-'.uniqid(), 'buying_price' => 500, 'selling_price' => 1000, 'original_quantity' => 100])->fresh();
    }

    private function features(array $features, array $settings = []): void
    {
        StoreFeatures::update($this->t['company'], ['features' => $features, 'settings' => $settings]);
        $this->t['company'] = $this->t['company']->fresh();
    }

    private function label(string $twelve): string
    {
        return $twelve.BarcodeResolver::checkDigit($twelve);
    }

    public function test_barcodes_backfill_only_with_the_feature_and_are_idempotent(): void
    {
        $cid = $this->t['company']->id;
        $a = $this->product(['name' => 'Soda can', 'barcode' => '5000000000011']);
        $this->product(['name' => 'No code']);
        $this->assertSame(0, ProductBarcode::withoutGlobalScopes()->where('company_id', $cid)->count(), 'a shop without pack barcodes gets no new rows');

        $this->features(['pack_barcodes' => true]); // turning it on backfills
        $rows = ProductBarcode::withoutGlobalScopes()->where('company_id', $cid)->get();
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]->is_primary);
        $this->assertSame($a->id, (int) $rows[0]->stock_item_id);
        $this->assertNull($rows[0]->unit_id);
        $this->assertSame(0, BarcodeService::backfillPrimary($cid), 'running it again writes nothing');

        // The primary row follows the product's own barcode.
        $a->update(['barcode' => '5000000000028']);
        $this->assertSame(['5000000000028'], ProductBarcode::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->pluck('barcode')->all());
        $this->assertSame($a->id, BarcodeResolver::resolve($cid, '5000000000028')['stock_item_id']);
        $this->assertNull(BarcodeResolver::resolve($cid, '5000000000011'), 'the old code no longer finds it');
        // …and comes back when the old code is used again.
        $a->update(['barcode' => '5000000000011']);
        $this->assertSame(1, ProductBarcode::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->count());
    }

    public function test_pack_barcodes_resolve_to_the_unit_and_duplicates_name_the_other_product(): void
    {
        $cid = $this->t['company']->id;
        $this->features(['pack_barcodes' => true]);
        $can = $this->product(['name' => 'Soda can', 'barcode' => '111']);
        $crate = Unit::create(['company_id' => $cid, 'name' => 'Crate', 'abbreviation' => 'cr', 'factor' => 24]);
        BarcodeService::add($cid, $can->id, '222', $crate->id);

        $this->assertSame(['stock_item_id' => $can->id, 'unit_id' => $crate->id], array_intersect_key(BarcodeResolver::resolve($cid, '222'), ['stock_item_id' => 1, 'unit_id' => 1]));
        $this->assertNull(BarcodeResolver::resolve($cid, '111')['unit_id'], 'the product\'s own barcode is the base unit');
        $this->assertSame($can->id, BarcodeResolver::resolve($cid, $can->sku)['stock_item_id'], 'SKU still works');
        $this->assertNull(BarcodeResolver::resolve($cid, 'nothing'));

        $other = $this->product(['name' => 'Juice']);
        try {
            BarcodeService::add($cid, $other->id, '222', null);
            $this->fail('a duplicate must be refused');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Soda can', $e->getMessage());
        }
        $this->assertSame('Soda can', BarcodeService::owner($cid, '111', $other->id));
        $this->assertNull(BarcodeService::owner($cid, '111', $can->id));

        // A removed barcode can be given to another product (the unique index keeps tombstones).
        ProductBarcode::withoutGlobalScopes()->where('barcode', '222')->first()->delete();
        $this->assertSame($other->id, (int) BarcodeService::add($cid, $other->id, '222', null)->stock_item_id);
    }

    public function test_scale_labels_and_plu_codes_resolve_only_with_weighed_items(): void
    {
        $cid = $this->t['company']->id;
        $bananas = $this->product(['name' => 'Bananas', 'selling_price' => 4000, 'sold_by' => 'weight', 'plu_code' => '4011']);
        $price = $this->label('200401103450'); // 3,450 of item 04011
        $this->assertNull(BarcodeResolver::resolve($cid, $price), 'off: a scale label is just an unknown code');
        $this->assertNull(BarcodeResolver::resolve($cid, '4011'));

        $this->features(['weighed_items' => true]);
        $hit = BarcodeResolver::resolve($cid, $price);
        $this->assertSame($bananas->id, $hit['stock_item_id']);
        $this->assertSame(3450.0, $hit['price']);
        $this->assertSame(round(3450 / 4000, 3), $hit['quantity']);

        $plu = BarcodeResolver::resolve($cid, '4011');
        $this->assertSame($bananas->id, $plu['stock_item_id']);
        $this->assertTrue($plu['needs_quantity']);
        $this->assertNull($plu['quantity']);

        $this->features([], ['scale_format' => 'weight', 'scale_value_decimals' => 3]);
        $w = BarcodeResolver::resolve($cid, $this->label('220401101250'));
        $this->assertSame(1.25, $w['quantity']);
        $this->assertNull($w['price']);
    }

    public function test_cash_rounding_is_checked_on_the_server_and_part_of_the_total(): void
    {
        $cid = $this->t['company']->id;
        $uid = $this->t['user']->id;
        $p = $this->product(['selling_price' => 1020]);
        $sale = fn (array $extra) => (new SaleService())->checkout($cid, $uid, $extra + ['items' => [['stock_item_id' => $p->id, 'quantity' => 1]], 'payments_explicit' => true])['sale'];

        // Off: a rounding is refused, and a sale without one is exactly as before.
        try {
            $sale(['rounding' => -20, 'payments' => [['method' => 'cash', 'amount' => 1000]]]);
            $this->fail('rounding without the rule must be refused');
        } catch (BusinessRuleException $e) {
            $this->assertSame('rounding_mismatch', $e->errorCode());
        }
        $plain = $sale(['payments' => [['method' => 'cash', 'amount' => 1020]]]);
        $this->assertNull($plain->rounding_amount);
        $this->assertEquals(1020, (float) $plain->total_amount);

        $this->features(['fast_tender' => true], ['cash_rounding' => 50]);
        $company = $this->t['company'];
        $this->assertSame(-20.0, CashRounding::amount($company, 1020));
        $this->assertSame(20.0, CashRounding::amount($company, 1080));
        $this->assertSame(0.0, CashRounding::amount($company, 1050));

        // Cash only: rounded down to 1,000, fully paid, the rounding stored and in the total.
        $r = $sale(['rounding' => -20, 'payments' => [['method' => 'cash', 'amount' => 1000]]]);
        $this->assertEquals(-20, (float) $r->rounding_amount);
        $this->assertEquals(1000, (float) $r->total_amount);
        $this->assertEquals(1000, (float) $r->amount_paid);
        $this->assertEquals(0, (float) $r->balance);
        $this->assertSame('Paid', $r->payment_status);
        $this->assertEquals(1020, (float) $r->saleRecordItems->sum('line_total'), 'the lines keep their prices');

        // A wrong amount, or rounding a card payment, is refused.
        foreach ([['rounding' => -10, 'payments' => [['method' => 'cash', 'amount' => 1010]]], ['rounding' => -20, 'payments' => [['method' => 'card', 'amount' => 1000]]]] as $bad) {
            try {
                $sale($bad);
                $this->fail('a rounding that does not follow the rule must be refused');
            } catch (BusinessRuleException $e) {
                $this->assertSame('rounding_mismatch', $e->errorCode());
            }
        }
    }

    public function test_age_check_is_logged_on_the_sale(): void
    {
        $p = $this->product(['name' => 'Beer', 'min_age' => 18]);
        $sale = (new SaleService())->checkout($this->t['company']->id, $this->t['user']->id, ['items' => [['stock_item_id' => $p->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 1000]], 'age_checked' => true])['sale'];
        $this->assertSame($this->t['user']->id, (int) $sale->age_checked_by);
        $this->assertSame(18, $p->min_age);
    }

    public function test_open_price_items_are_not_a_price_change_only_with_department_keys(): void
    {
        $cid = $this->t['company']->id;
        $bakery = $this->product(['name' => 'Bakery', 'selling_price' => 0, 'open_price' => true, 'track_stock' => false]);
        $data = ['items' => [['stock_item_id' => $bakery->id, 'quantity' => 1, 'unit_price' => 1500]]];
        $this->assertTrue(CheckoutRules::changesPrices($cid, $data), 'off: typing a price is a price change');
        $this->features(['department_keys' => true]);
        $this->assertFalse(CheckoutRules::changesPrices($cid, $data));
        $plain = $this->product(['selling_price' => 1000]);
        $this->assertTrue(CheckoutRules::changesPrices($cid, ['items' => [['stock_item_id' => $plain->id, 'quantity' => 1, 'unit_price' => 900]]]), 'other products still need `discount`');
    }

    public function test_held_carts_are_shared_by_the_shop_taken_once_and_flagged_when_stale(): void
    {
        $cid = $this->t['company']->id;
        $svc = new HeldCartService();
        $held = $svc->hold($cid, $this->t['user']->id, ['lines' => [['stock_item_id' => 1, 'quantity' => 2]]], 'Amina', null, null, 1, 2000);
        $old = $svc->hold($cid, $this->t['user']->id, ['lines' => [['stock_item_id' => 1, 'quantity' => 1]]], 'Old');
        DB::table('held_carts')->where('id', $old->id)->update(['created_at' => now()->subHours(30)]);

        $list = collect($svc->list($cid))->keyBy('id');
        $this->assertCount(2, $list);
        $this->assertFalse($list[$held->id]['stale']);
        $this->assertTrue($list[$old->id]['stale']);
        $this->assertSame(2000.0, $list[$held->id]['total']);

        $this->assertSame(2, $svc->take($cid, $held->id)['lines'][0]['quantity']);
        try {
            $svc->take($cid, $held->id);
            $this->fail('a cart is resumed once');
        } catch (BusinessRuleException $e) {
            $this->assertSame('held_cart_gone', $e->errorCode());
        }
        $other = $this->makeTenant('company')['company'];
        $this->assertSame([], $svc->list($other->id), 'other shops see nothing');
        $this->expectException(BusinessRuleException::class);
        $svc->hold($cid, $this->t['user']->id, ['lines' => []], 'Empty');
    }

    public function test_returned_empties_go_back_through_returns_and_refund_the_deposit(): void
    {
        $cid = $this->t['company']->id;
        $uid = $this->t['user']->id;
        $crate = $this->product(['name' => 'Crate deposit', 'selling_price' => 5000, 'original_quantity' => 10]);
        $soda = $this->product(['name' => 'Soda crate', 'selling_price' => 20000, 'deposit_item_id' => $crate->id]);
        $sell = fn (int $crates) => (new SaleService())->checkout($cid, $uid, ['items' => [['stock_item_id' => $soda->id, 'quantity' => $crates], ['stock_item_id' => $crate->id, 'quantity' => $crates]],
            'payments' => [['method' => 'cash', 'amount' => 25000 * $crates]]])['sale'];
        $first = $sell(2);
        $second = $sell(1);
        $svc = new DepositService();
        $this->assertSame(3.0, $svc->held($cid, $crate->id));
        $this->assertEquals(7, (float) $crate->fresh()->current_quantity);

        $done = $svc->returnEmpties($cid, $uid, $crate->id, 3);
        $this->assertSame(15000.0, $done['refund']);
        $this->assertCount(2, $done['returns'], 'one return per sale, oldest first');
        $this->assertSame(0.0, $svc->held($cid, $crate->id));
        $this->assertEquals(10, (float) $crate->fresh()->current_quantity, 'the crates are back on the shelf');
        $this->assertEquals(10000, (float) $first->fresh()->refunded_amount);
        $this->assertEquals(-5000, (float) DB::table('payments')->where('sale_record_id', $second->id)->where('amount', '<', 0)->sum('amount'));

        try {
            $svc->returnEmpties($cid, $uid, $crate->id, 1);
            $this->fail('more empties than are out must be refused');
        } catch (BusinessRuleException $e) {
            $this->assertSame('too_many_empties', $e->errorCode());
        }
        $this->expectException(BusinessRuleException::class);
        $svc->returnEmpties($cid, $uid, $soda->id, 1); // not anyone's deposit
    }
}
