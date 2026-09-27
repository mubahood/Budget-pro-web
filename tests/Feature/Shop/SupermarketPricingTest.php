<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\CompanyMember;
use App\Models\Customer;
use App\Models\FinancialPeriod;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\Unit;
use App\Models\User;
use App\Services\Reports\ReportService;
use App\Services\Shop\ApprovalService;
use App\Services\Shop\PriceLevelService;
use App\Services\Shop\PromotionService;
use App\Services\Shop\ReceiptService;
use App\Services\Shop\ReturnService;
use App\Services\Shop\SaleService;
use App\Services\Team\Permissions;
use App\Support\Rules\CheckoutRules;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminTestCase;

/** Supermarket phase 3, pricing: price levels and quantity breaks (B2), promotions at checkout (B3), promotion results (H5). */
class SupermarketPricingTest extends AdminTestCase
{
    private array $t;

    private int $cid;

    private int $drinks;

    private int $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->makeTenant('company');
        $this->cid = (int) $this->t['company']->id;
        $this->t['company']->forceFill(['timezone' => 'Africa/Kampala', 'tax_rate' => 18])->save();
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $this->cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $this->drinks = StockCategory::create(['company_id' => $this->cid, 'name' => 'Drinks'])->id;
        $this->sub = StockSubCategory::create(['company_id' => $this->cid, 'stock_category_id' => $this->drinks, 'name' => 'Juice', 'measurement_unit' => 'pcs'])->id;
        PromotionService::forget($this->cid);
    }

    private function product(string $name, float $price, array $attrs = []): StockItem
    {
        return StockItem::create($attrs + ['company_id' => $this->cid, 'created_by_id' => $this->t['user']->id, 'stock_category_id' => $this->drinks, 'stock_sub_category_id' => $this->sub,
            'name' => $name, 'sku' => 'S-'.uniqid(), 'buying_price' => 500, 'selling_price' => $price, 'original_quantity' => 100])->fresh();
    }

    private function features(array $features, array $settings = []): void
    {
        StoreFeatures::update($this->t['company'], ['features' => $features, 'settings' => $settings]);
        $this->t['company'] = $this->t['company']->fresh();
    }

    private function sell(array $items, ?float $paid = null, array $extra = [], ?int $userId = null): SaleRecord
    {
        return (new SaleService())->checkout($this->cid, $userId ?? $this->t['user']->id, $extra + [
            'items' => $items, 'payments' => $paid ? [['method' => 'cash', 'amount' => $paid]] : [], 'payments_explicit' => true,
        ])['sale'];
    }

    private function member(string $role): User
    {
        $u = new User();
        $u->forceFill(['name' => ucfirst($role).' '.uniqid(), 'email' => $role.uniqid('', true).'@example.test', 'password' => bcrypt('secret123'), 'status' => 'Active', 'company_id' => $this->cid])->save();
        CompanyMember::create(['company_id' => $this->cid, 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'joined_at' => now()]);
        Permissions::flush();

        return $u->fresh();
    }

    private function promo(array $data): array
    {
        return (new PromotionService())->save($this->cid, $this->t['user']->id, $data);
    }

    public function test_with_both_features_off_nothing_changes(): void
    {
        $juice = $this->product('Juice', 1000);
        DB::table('product_prices')->insert(['company_id' => $this->cid, 'stock_item_id' => $juice->id, 'level' => 'retail', 'price' => 800, 'min_qty' => 1]);
        DB::table('promotions')->insert(['company_id' => $this->cid, 'name' => 'Half price', 'type' => 'percent_off', 'rules' => '{"percent":50}', 'is_active' => 1]);
        DB::table('promotion_targets')->insert(['promotion_id' => DB::getPdo()->lastInsertId(), 'target_type' => 'product', 'target_id' => $juice->id]);

        $sale = $this->sell([['stock_item_id' => $juice->id, 'quantity' => 3]], 3000, ['coupon_code' => 'ANY']);
        $this->assertEquals(3000, (float) $sale->total_amount);
        $this->assertNull($sale->saleRecordItems->first()->promo_discount);
        $this->assertSame(0, DB::table('sale_promotions')->where('sale_id', $sale->id)->count());
        $this->assertSame(['lines' => [], 'applied' => [], 'saved' => 0.0, 'coupon' => null], PromotionService::quote($this->cid, [['stock_item_id' => $juice->id, 'quantity' => 3]]));
        $this->assertStringNotContainsString('saved', (new ReceiptService())->text($sale));
        try {
            $this->promo(['name' => 'X', 'type' => 'percent_off', 'percent' => 10, 'targets' => [['type' => 'product', 'id' => $juice->id]]]);
            $this->fail('feature_off expected');
        } catch (BusinessRuleException $e) {
            $this->assertSame('feature_off', $e->errorCode());
        }
    }

    public function test_quantity_breaks_and_customer_levels_price_the_line(): void
    {
        $this->features(['price_levels' => true]);
        $juice = $this->product('Juice', 1000);
        $crate = Unit::create(['company_id' => $this->cid, 'name' => 'Crate', 'abbreviation' => 'crt', 'factor' => 24]);
        $svc = new PriceLevelService();
        $svc->save($this->cid, $juice->id, [
            ['level' => 'retail', 'price' => 900, 'min_qty' => 12],
            ['level' => 'Wholesale', 'price' => 850, 'min_qty' => 1],
            ['level' => 'wholesale', 'price' => 800, 'min_qty' => 24],
        ]);
        $this->assertCount(3, $svc->forProduct($this->cid, $juice->id));
        $this->assertArrayHasKey('wholesale', PriceLevelService::levels($this->cid));

        // Walk-in: retail, with the break from 12.
        $this->assertEquals(1000, PriceLevelService::price($this->cid, $juice->id, null, 11, null));
        $this->assertEquals(900, PriceLevelService::price($this->cid, $juice->id, null, 12, null));
        $sale = $this->sell([['stock_item_id' => $juice->id, 'quantity' => 12]], 10800);
        $this->assertEquals(10800, (float) $sale->total_amount);
        $this->assertEquals(900, (float) $sale->saleRecordItems->first()->unit_price);
        $this->assertEquals(0, (float) $sale->discount_amount, 'a level price is the price, not a discount');

        // A wholesale customer: the lower of their level and retail.
        $shop = Customer::create(['company_id' => $this->cid, 'name' => 'Corner shop', 'phone' => '0772000111', 'price_level' => 'wholesale']);
        $this->assertEquals(850, PriceLevelService::price($this->cid, $juice->id, null, 2, 'wholesale'));
        $sale = $this->sell([['stock_item_id' => $juice->id, 'quantity' => 2]], 1700, ['customer_id' => $shop->id]);
        $this->assertEquals(1700, (float) $sale->total_amount);

        // A crate has no price of its own: base prices, counted in bottles, times 24.
        $this->assertEquals(800 * 24, PriceLevelService::price($this->cid, $juice->id, $crate->id, 1, 'wholesale'));
        $this->assertEquals(900 * 24, PriceLevelService::price($this->cid, $juice->id, $crate->id, 1, null));

        // Sending the level price back is not changing the price; anything else still is.
        $items = [['stock_item_id' => $juice->id, 'quantity' => 2, 'unit_price' => 850]];
        $this->assertFalse(CheckoutRules::changesPrices($this->cid, ['items' => $items, 'customer_id' => $shop->id]));
        $this->assertTrue(CheckoutRules::changesPrices($this->cid, ['items' => $items]), 'a walk-in has no wholesale price');

        // Explicit prices win; the feature off = selling price again.
        $this->features(['price_levels' => false]);
        $this->assertEquals(12000, (float) $this->sell([['stock_item_id' => $juice->id, 'quantity' => 12]], 12000)->total_amount);

        foreach ([[['level' => '', 'price' => 1]], [['level' => 'retail', 'price' => -1]], [['level' => 'retail', 'price' => 1], ['level' => 'retail', 'price' => 2]]] as $bad) {
            $this->features(['price_levels' => true]);
            try {
                $svc->save($this->cid, $juice->id, $bad);
                $this->fail('refusal expected');
            } catch (BusinessRuleException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_promotions_are_worked_out_at_checkout_and_kept_on_the_sale(): void
    {
        $this->features(['promotions' => true]);
        $juice = $this->product('Juice', 4000);
        $soda = $this->product('Soda', 3000);
        $bakery = StockCategory::create(['company_id' => $this->cid, 'name' => 'Bakery'])->id;
        $bread = $this->product('Bread', 5000, ['stock_category_id' => $bakery,
            'stock_sub_category_id' => StockSubCategory::create(['company_id' => $this->cid, 'stock_category_id' => $bakery, 'name' => 'Loaves', 'measurement_unit' => 'pcs'])->id]);
        $p = $this->promo(['name' => 'Drinks 3 for 10,000', 'type' => 'mix_match', 'qty' => 3, 'price' => 10000, 'targets' => [['type' => 'category', 'id' => $this->drinks]]]);
        $this->assertSame('mix_match', $p['type']);

        $items = [['stock_item_id' => $juice->id, 'quantity' => 2], ['stock_item_id' => $soda->id, 'quantity' => 1], ['stock_item_id' => $bread->id, 'quantity' => 1]];
        // The till's quote and the checkout agree.
        $quote = PromotionService::quote($this->cid, $items);
        $this->assertSame(1000.0, $quote['saved']);
        $this->assertSame(['Drinks 3 for 10,000'], $quote['lines'][0]['names']);

        $this->assertFalse(CheckoutRules::changesPrices($this->cid, ['items' => $items]), 'nothing in the request changes a price');
        $sale = $this->sell($items, 15000);
        $this->assertEquals(16000, (float) $sale->subtotal);
        $this->assertEquals(15000, (float) $sale->total_amount);
        $this->assertEquals(1000, (float) $sale->discount_amount);
        $this->assertSame('Paid', $sale->payment_status);
        $lines = $sale->saleRecordItems->keyBy('stock_item_id');
        $this->assertEquals(round(1000 * 8000 / 11000, 2), (float) $lines[$juice->id]->promo_discount);
        $this->assertEquals((float) $lines[$juice->id]->promo_discount, (float) $lines[$juice->id]->discount_amount);
        $this->assertEquals(1000, (float) $lines[$juice->id]->promo_discount + (float) $lines[$soda->id]->promo_discount);
        $this->assertNull($lines[$bread->id]->promo_discount);
        $this->assertEquals(round(8000 - (float) $lines[$juice->id]->promo_discount - 2 * 500, 2), (float) $lines[$juice->id]->profit, 'profit on the promoted price');
        $this->assertEquals(-3, (float) DB::table('stock_records')->where('sale_record_id', $sale->id)->whereIn('stock_item_id', [$juice->id, $soda->id])->sum('quantity_delta'));
        $this->assertSame([['name' => 'Drinks 3 for 10,000', 'amount' => 1000.0]], PromotionService::forSale($sale)['rows']);

        $text = (new ReceiptService())->text($sale);
        $this->assertStringContainsString('Drinks 3 for 10,000: -1,000', $text);
        $this->assertStringContainsString('You saved: ', $text);
        $html = view('reports.sale-receipt', ['sale' => $sale, 'company' => $sale->company])->render();
        $this->assertStringContainsString('You saved', $html);
        $this->assertStringContainsString('Drinks 3 for 10,000', view('reports.sale-invoice', ['sale' => $sale, 'company' => $sale->company])->render());

        // A line the cashier re-priced takes no promotion.
        $sale = $this->sell([['stock_item_id' => $juice->id, 'quantity' => 3, 'unit_price' => 3900]], 11700);
        $this->assertEquals(11700, (float) $sale->total_amount);
    }

    public function test_automatic_discounts_need_no_discount_permission_and_no_supervisor(): void
    {
        $this->features(['promotions' => true, 'approvals' => true]);
        $juice = $this->product('Juice', 1000); // cost 500
        $this->promo(['name' => 'Juice at 400', 'type' => 'fixed_price', 'price' => 400, 'targets' => [['type' => 'product', 'id' => $juice->id]]]);
        $cashier = $this->member('cashier');
        (new ApprovalService())->setPin($this->member('manager'), '4321');

        $items = [['stock_item_id' => $juice->id, 'quantity' => 2]];
        $this->assertFalse(CheckoutRules::changesPrices($this->cid, ['items' => $items]));
        $sale = $this->sell($items, 800, [], $cashier->id);
        $this->assertEquals(800, (float) $sale->total_amount, '60% off and below cost, yet no approval: it is the shop\'s own promotion');

        // A cashier cut of the same size still needs one.
        try {
            $this->sell([['stock_item_id' => $juice->id, 'quantity' => 2, 'unit_price' => 400]], 800, [], $cashier->id);
            $this->fail('approval_required expected');
        } catch (BusinessRuleException $e) {
            $this->assertSame('approval_required', $e->errorCode());
        }
    }

    public function test_tax_on_top_and_cash_rounding_follow_the_promoted_total(): void
    {
        $this->features(['promotions' => true, 'tax_classes' => true, 'fast_tender' => true], ['tax_inclusive' => false, 'cash_rounding' => 50]);
        $juice = $this->product('Juice', 1010);
        $this->promo(['name' => '10% off juice', 'type' => 'percent_off', 'percent' => 10, 'targets' => [['type' => 'product', 'id' => $juice->id]]]);
        // 1,010 − 101 = 909 net; + 18% tax = 1,072.62; cash rounds to 1,050.
        $sale = $this->sell([['stock_item_id' => $juice->id, 'quantity' => 1]], 1050, ['rounding' => -22.62]);
        $line = $sale->saleRecordItems->first();
        $this->assertEquals(101, (float) $line->promo_discount);
        $this->assertEquals(round(909 * 0.18, 2), (float) $line->tax_amount);
        $this->assertEquals(1072.62, (float) $line->line_total);
        $this->assertEquals(1050, (float) $sale->total_amount);
        $this->assertSame('Paid', $sale->payment_status);
    }

    public function test_a_return_of_a_promoted_line_refunds_what_was_paid_for_it(): void
    {
        $this->features(['promotions' => true]);
        $juice = $this->product('Juice', 3000);
        $this->promo(['name' => 'Buy 2 get 1 free', 'type' => 'buy_x_get_y', 'buy' => 2, 'get' => 1, 'targets' => [['type' => 'product', 'id' => $juice->id]]]);
        $sale = $this->sell([['stock_item_id' => $juice->id, 'quantity' => 3]], 6000);
        $this->assertEquals(6000, (float) $sale->total_amount);
        $line = $sale->saleRecordItems->first();
        $this->assertEquals(3000, (float) $line->promo_discount);

        $ret = (new ReturnService())->create($sale, [['sale_item_id' => $line->id, 'quantity' => 1]], $this->t['user']->id, 'Leaking');
        $this->assertEquals(2000, (float) $ret->value, 'a third of what the three cost');
        $this->assertEquals(2000, (float) $ret->refund_amount);
        $sale = $sale->fresh();
        $this->assertEquals(4000, (float) $sale->total_amount - (float) $sale->refunded_amount);
        $this->assertEquals(0, (float) $sale->balance);
        $this->assertEquals(6000 - 1500, (float) $line->profit, 'profit on what was paid');
        $this->assertEquals((6000 - 1500) * 2 / 3, (float) $line->fresh()->profit, 'profit shrinks by the returned share');
    }

    public function test_coupons_members_and_the_promotions_screen_rules(): void
    {
        $this->features(['promotions' => true]);
        $juice = $this->product('Juice', 5000);
        $svc = new PromotionService();
        $coupon = $this->promo(['name' => 'SAVE10 coupon', 'type' => 'coupon', 'percent' => 10, 'code' => 'save10']);
        $this->assertSame('SAVE10', $coupon['code']);
        $items = [['stock_item_id' => $juice->id, 'quantity' => 2]];
        $this->assertEquals(10000, (float) $this->sell($items, 10000)->total_amount, 'no code, no coupon');
        $this->assertEquals(9000, (float) $this->sell($items, 9000, ['coupon_code' => 'Save10'])->total_amount);
        $this->assertFalse(PromotionService::quote($this->cid, $items, ['coupon' => 'NOPE'])['coupon']['ok']);

        $member = $this->promo(['name' => 'Members 1,000 off', 'type' => 'amount_off', 'amount' => 1000, 'member_only' => true, 'targets' => [['type' => 'product', 'id' => $juice->id]]]);
        $this->assertEquals(10000, (float) $this->sell($items, 10000)->total_amount, 'walk-in');
        $c = Customer::create(['company_id' => $this->cid, 'name' => 'Amina', 'phone' => '0772000222']);
        $sale = $this->sell($items, 8000, ['customer_id' => $c->id]);
        $this->assertEquals(8000, (float) $sale->total_amount);

        // Pause (the cache is forgotten at once), then a used promotion cannot be deleted.
        $svc->setActive($this->cid, $member['id'], false);
        $this->assertEquals(10000, (float) $this->sell($items, 10000, ['customer_id' => $c->id])->total_amount);
        try {
            $svc->delete($this->cid, $member['id']);
            $this->fail('promotion_used expected');
        } catch (BusinessRuleException $e) {
            $this->assertSame('promotion_used', $e->errorCode());
        }
        $unused = $this->promo(['name' => 'Spare', 'type' => 'percent_off', 'percent' => 5, 'targets' => [['type' => 'product', 'id' => $juice->id]], 'is_active' => false]);
        $svc->delete($this->cid, $unused['id']);
        $this->assertCount(2, $svc->list($this->cid));

        foreach ([
            ['name' => 'No targets', 'type' => 'percent_off', 'percent' => 10],
            ['name' => 'No percent', 'type' => 'percent_off', 'targets' => [['type' => 'product', 'id' => $juice->id]]],
            ['name' => 'Lonely bundle', 'type' => 'bundle', 'price' => 100, 'targets' => [['type' => 'product', 'id' => $juice->id]]],
            ['name' => 'Coupon without a code', 'type' => 'coupon', 'amount' => 100],
            ['name' => 'Same code', 'type' => 'coupon', 'amount' => 100, 'code' => 'SAVE10'],
            ['name' => 'Spend nothing', 'type' => 'spend_save', 'amount' => 100],
            ['name' => 'Backwards', 'type' => 'percent_off', 'percent' => 5, 'targets' => [['type' => 'product', 'id' => $juice->id]], 'starts_at' => '2026-10-10 10:00', 'ends_at' => '2026-10-01 10:00'],
            ['name' => 'Other shop', 'type' => 'percent_off', 'percent' => 5, 'targets' => [['type' => 'product', 'id' => 999999]]],
        ] as $bad) {
            try {
                $this->promo($bad);
                $this->fail($bad['name'].': refusal expected');
            } catch (BusinessRuleException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $ex = $svc->example($this->cid, ['type' => 'mix_match', 'rules' => ['qty' => 3, 'price' => 12000], 'targets' => [['type' => 'product', 'id' => $juice->id]]]);
        $this->assertSame(['qty' => 3.0, 'product' => 'Juice', 'single' => 'Juice', 'before' => 15000.0, 'after' => 12000.0], $ex);
    }

    public function test_promotion_results_report(): void
    {
        $this->features(['promotions' => true]);
        $juice = $this->product('Juice', 3000);
        $this->promo(['name' => 'Juice 10% off', 'type' => 'percent_off', 'percent' => 10, 'targets' => [['type' => 'product', 'id' => $juice->id]]]);
        $this->sell([['stock_item_id' => $juice->id, 'quantity' => 2]], 5400);
        $voided = $this->sell([['stock_item_id' => $juice->id, 'quantity' => 1]], 2700);
        (new SaleService())->void($voided, 'test', $this->t['user']->id);

        $day = now('Africa/Kampala')->toDateString();
        $r = (new ReportService())->run($this->cid, 'promotion_results', $day, $day);
        $this->assertSame('Promotion results', $r['title']);
        $row = $r['rows'][0];
        $this->assertSame('Juice 10% off', $row['name']);
        $this->assertSame(1, $row['sales_count'], 'the voided sale is left out');
        $this->assertEquals(600, $row['discount']);
        $this->assertEquals(2, $row['units']);
        $this->assertEquals(5400, $row['sales']);
        $this->assertEquals(round((5400 - 1000) * 100 / 5400, 1), $row['margin']);
        $this->assertEquals(0, $row['before']);
    }
}
