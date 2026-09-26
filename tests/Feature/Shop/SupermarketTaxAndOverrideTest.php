<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Approval;
use App\Models\CompanyMember;
use App\Models\FinancialPeriod;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\TaxClass;
use App\Models\User;
use App\Services\Reports\ReportService;
use App\Services\Shop\ApprovalService;
use App\Services\Shop\ReceiptService;
use App\Services\Shop\ReturnService;
use App\Services\Shop\SaleService;
use App\Services\Shop\TaxClassService;
use App\Services\Team\Permissions;
use App\Support\StoreFeatures;
use Tests\Feature\Admin\AdminTestCase;

/** Supermarket phase 1: tax classes per product (F1) and supervisor approval of price overrides at checkout (A5). */
class SupermarketTaxAndOverrideTest extends AdminTestCase
{
    private array $t;

    private int $cid;

    private int $cat;

    private int $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->makeTenant('company');
        $this->cid = (int) $this->t['company']->id;
        $this->t['company']->forceFill(['timezone' => 'Africa/Kampala', 'tax_rate' => 18])->save();
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $this->cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $this->cat = StockCategory::create(['company_id' => $this->cid, 'name' => 'Food'])->id;
        $this->sub = StockSubCategory::create(['company_id' => $this->cid, 'stock_category_id' => $this->cat, 'name' => 'Dry', 'measurement_unit' => 'pcs'])->id;
    }

    private function product(string $name, float $price, array $attrs = []): StockItem
    {
        return StockItem::create($attrs + ['company_id' => $this->cid, 'created_by_id' => $this->t['user']->id, 'stock_category_id' => $this->cat, 'stock_sub_category_id' => $this->sub,
            'name' => $name, 'sku' => 'S-'.uniqid(), 'buying_price' => 500, 'selling_price' => $price, 'original_quantity' => 100])->fresh();
    }

    private function features(array $features, array $settings = []): void
    {
        StoreFeatures::update($this->t['company'], ['features' => $features, 'settings' => $settings]);
        $this->t['company'] = $this->t['company']->fresh();
    }

    /** @param list<array<string, mixed>> $items */
    private function sell(array $items, float $paid, array $extra = [], ?int $userId = null): SaleRecord
    {
        return (new SaleService())->checkout($this->cid, $userId ?? $this->t['user']->id, $extra + [
            'items' => $items, 'payments' => $paid > 0 ? [['method' => 'cash', 'amount' => $paid]] : [], 'payments_explicit' => true,
        ])['sale'];
    }

    private function classes(): array
    {
        return (new TaxClassService())->ensureDefaults($this->cid)->keyBy('code')->all();
    }

    private function member(string $role): User
    {
        $u = new User();
        $u->forceFill(['name' => ucfirst($role).' '.uniqid(), 'email' => $role.uniqid('', true).'@example.test', 'password' => bcrypt('secret123'), 'status' => 'Active', 'company_id' => $this->cid])->save();
        CompanyMember::create(['company_id' => $this->cid, 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'joined_at' => now()]);
        Permissions::flush();

        return $u->fresh();
    }

    public function test_feature_off_stores_no_tax_and_changes_no_total(): void
    {
        $classes = $this->classes();
        $a = $this->product('Soap', 1000, ['tax_class_id' => $classes['zero']->id]);
        $sale = $this->sell([['stock_item_id' => $a->id, 'quantity' => 2]], 2000);

        $this->assertEquals(2000, (float) $sale->total_amount);
        $this->assertSame('Paid', $sale->payment_status);
        $line = $sale->saleRecordItems->first();
        $this->assertNull($line->tax_rate);
        $this->assertNull($line->tax_amount);
        $this->assertNull($line->tax_class_id);
        $this->assertEquals(1000, (float) $line->profit);
        $this->assertSame([], TaxClassService::breakdown($sale)['rows']);
        $this->assertStringNotContainsString('tax', strtolower((new ReceiptService())->text($sale)));
    }

    public function test_default_classes_and_their_rules(): void
    {
        $svc = new TaxClassService();
        $classes = $this->classes();
        $this->assertCount(3, $classes);
        $this->assertEquals(18, $classes['standard']->rate);
        $this->assertTrue($classes['standard']->is_default);
        $this->assertSame('Exempt', $classes['exempt']->label());
        $this->assertSame('Zero-rated 0%', $classes['zero']->label());
        $this->assertCount(3, $svc->ensureDefaults($this->cid), 'idempotent');

        $reduced = $svc->save($this->cid, ['name' => 'Reduced', 'code' => 'reduced', 'rate' => '7.5']);
        $this->assertSame('Reduced 7.5%', $reduced->label());
        $this->assertEquals(0, $svc->save($this->cid, ['name' => 'Other exempt', 'code' => 'exempt', 'rate' => 12])->rate, 'exempt is 0%');
        $svc->setDefault($this->cid, $reduced->id);
        $this->assertSame([$reduced->id], TaxClass::withoutGlobalScopes()->where('company_id', $this->cid)->where('is_default', 1)->pluck('id')->all());

        $this->product('Juice', 1000, ['tax_class_id' => $classes['zero']->id]);
        foreach ([[$reduced->id, 'tax_class_default'], [$classes['zero']->id, 'tax_class_in_use']] as [$id, $code]) {
            try {
                $svc->delete($this->cid, $id);
                $this->fail("{$code} expected");
            } catch (BusinessRuleException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        }
        $svc->delete($this->cid, $classes['exempt']->id);
        $this->assertFalse(TaxClass::withoutGlobalScopes()->whereKey($classes['exempt']->id)->exists());
    }

    public function test_inclusive_prices_keep_the_total_and_store_the_tax_inside(): void
    {
        $classes = $this->classes();
        $this->features(['tax_classes' => true]);
        $a = $this->product('Soda', 1180);
        $sale = $this->sell([['stock_item_id' => $a->id, 'quantity' => 1]], 1180);

        $this->assertEquals(1180, (float) $sale->total_amount);
        $this->assertSame('Paid', $sale->payment_status);
        $line = $sale->saleRecordItems->first();
        $this->assertEquals(18, $line->tax_rate);
        $this->assertEquals(180, (float) $line->tax_amount);
        $this->assertSame($classes['standard']->id, (int) $line->tax_class_id);
        $this->assertEquals(680, (float) $line->profit, 'inclusive: profit as before');
        $b = TaxClassService::breakdown($sale);
        $this->assertFalse($b['on_top']);
        $this->assertSame([['label' => 'Standard 18%', 'rate' => 18.0, 'net' => 1000.0, 'tax' => 180.0]], $b['rows']);
        $this->assertStringContainsString('Incl. tax Standard 18%', (new ReceiptService())->text($sale));
    }

    public function test_exclusive_prices_add_mixed_class_tax_on_top_after_the_discount(): void
    {
        $classes = $this->classes();
        $reduced = (new TaxClassService())->save($this->cid, ['name' => 'Reduced', 'code' => 'reduced', 'rate' => 5]);
        $this->features(['tax_classes' => true], ['tax_inclusive' => false]);
        $a = $this->product('Soda', 1000); // the default class (standard 18%)
        $d = $this->product('Bread', 1000, ['tax_class_id' => $reduced->id]);
        $b = $this->product('Milk', 1000, ['tax_class_id' => $classes['zero']->id]);
        $c = $this->product('Medicine', 2000, ['tax_class_id' => $classes['exempt']->id]);
        $items = array_map(fn ($p) => ['stock_item_id' => $p->id, 'quantity' => 1], [$a, $d, $b, $c]);

        // The till's own sum gives the same tax as the sale (header discount 500 shared pro-rata, the last line takes the rest).
        $this->assertEquals(207, TaxClassService::cart([['net' => 1000, 'rate' => 18], ['net' => 1000, 'rate' => 5], ['net' => 1000, 'rate' => 0], ['net' => 2000, 'rate' => 0]], 500, false)['tax']);

        $sale = $this->sell($items, 4707, ['discount_amount' => 500]);
        $lines = $sale->saleRecordItems->keyBy('stock_item_id');
        $this->assertEquals(162, (float) $lines[$a->id]->tax_amount); // 900 × 18%
        $this->assertEquals(1062, (float) $lines[$a->id]->line_total);
        $this->assertEquals(45, (float) $lines[$d->id]->tax_amount); // 900 × 5%
        $this->assertEquals(0, (float) $lines[$b->id]->tax_amount);
        $this->assertEquals(0, (float) $lines[$c->id]->tax_amount);
        $this->assertSame($classes['exempt']->id, (int) $lines[$c->id]->tax_class_id);
        $this->assertEquals(4707, (float) $sale->total_amount, '4,500 after discount + 207 tax');
        $this->assertSame('Paid', $sale->payment_status);
        $this->assertEquals(0, (float) $sale->balance);
        $this->assertEquals(400, (float) $lines[$a->id]->profit, 'tax added on top is not profit: 900 − 500');
        $this->assertTrue(TaxClassService::addedOnTop($sale));
        $this->assertEquals(207, TaxClassService::breakdown($sale)['total']);

        // Paid short of the tax: owed, and a walk-in cannot owe.
        try {
            $this->sell([['stock_item_id' => $a->id, 'quantity' => 1]], 1000);
            $this->fail('customer_required expected');
        } catch (BusinessRuleException $e) {
            $this->assertSame('customer_required', $e->errorCode());
        }
    }

    public function test_a_return_refunds_its_share_of_the_tax_and_the_vat_report_uses_line_tax(): void
    {
        $this->classes();
        $a = $this->product('Soda', 1000);
        $old = $this->sell([['stock_item_id' => $a->id, 'quantity' => 1]], 1000); // before tax classes: worked out at 18% inclusive
        $this->features(['tax_classes' => true], ['tax_inclusive' => false]);

        $sale = $this->sell([['stock_item_id' => $a->id, 'quantity' => 2]], 2360);
        $this->assertEquals(2360, (float) $sale->total_amount);
        $ret = (new ReturnService())->create($sale, [['sale_item_id' => $sale->saleRecordItems->first()->id, 'quantity' => 1]], $this->t['user']->id, 'Wrong flavour');
        $this->assertEquals(1180, (float) $ret->value);
        $this->assertEquals(1180, (float) $ret->refund_amount, 'the price and its tax come back');
        $sale = $sale->fresh();
        $this->assertEquals(1180, (float) $sale->refunded_amount);
        $this->assertEquals(0, (float) $sale->balance);

        $day = now('Africa/Kampala')->toDateString();
        $vat = (new ReportService())->run($this->cid, 'vat_summary', $day, $day);
        $this->assertEquals(1000 + 1180, $vat['rows'][0]['gross']);
        $this->assertEquals(round(180 + 1000 * 18 / 118, 2), $vat['rows'][0]['vat'], 'line tax net of the return + the older sale at the shop rate');
        $this->assertSame([['class' => 'Standard', 'rate' => 18.0, 'sales' => 1180.0, 'vat' => 180.0]], $vat['meta']['by_class']);
        $this->assertSame('of which sales at Standard 18%', $vat['rows'][2]['label']);
        $this->assertEquals($vat['rows'][0]['vat'] - $vat['rows'][1]['vat'], $vat['totals']['vat']);
        $this->assertNotNull($old->id);
    }

    public function test_price_overrides_beyond_the_limit_need_a_consumed_supervisor_approval(): void
    {
        $a = $this->product('Soda', 1000); // cost 500
        $cashier = $this->member('cashier');
        $manager = $this->member('manager');
        $approvals = new ApprovalService();
        $approvals->setPin($manager, '4321');
        $cut = [['stock_item_id' => $a->id, 'quantity' => 1, 'unit_price' => 850]];

        $this->assertEquals(850, (float) $this->sell($cut, 850, [], $cashier->id)->total_amount, 'approvals off: as before');

        $this->features(['approvals' => true]);
        $this->assertEquals(950, (float) $this->sell([['stock_item_id' => $a->id, 'quantity' => 1, 'unit_price' => 950]], 950, [], $cashier->id)->total_amount, 'within the 10% limit');
        $refused = function (array $items, string $code) use ($cashier) {
            try {
                $this->sell($items, 850, [], $cashier->id);
                $this->fail("{$code} expected");
            } catch (BusinessRuleException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        };
        $refused($cut, 'approval_required');
        $refused([['stock_item_id' => $a->id, 'quantity' => 2, 'unit_price' => 1000, 'discount_amount' => 300]], 'approval_required'); // 850 each after the line discount

        $low = $approvals->grant($this->cid, 'price_override', '4321', $cashier->id, ['amount' => 900]);
        $refused([['approval_id' => $low->id] + $cut[0]], 'approval_invalid'); // approved 900, selling at 850
        $this->assertNull(Approval::withoutGlobalScopes()->find($low->id)->consumed_at, 'a refused sale gives the approval back');

        $ok = $approvals->grant($this->cid, 'price_override', '4321', $cashier->id, ['amount' => 850]);
        $sale = $this->sell([['approval_id' => $ok->id] + $cut[0]], 850, [], $cashier->id);
        $this->assertEquals(850, (float) $sale->total_amount);
        $this->assertNotNull(Approval::withoutGlobalScopes()->find($ok->id)->consumed_at);
        $refused([['approval_id' => $ok->id] + $cut[0]], 'approval_invalid'); // used once

        $other = $approvals->grant($this->cid, 'price_override', '4321', $manager->id, ['amount' => 850]);
        $refused([['approval_id' => $other->id] + $cut[0]], 'approval_invalid'); // someone else's approval

        // Below cost needs a supervisor even within the limit.
        $this->features([], ['override_limit_pct' => 90]);
        $this->assertEquals(600, (float) $this->sell([['stock_item_id' => $a->id, 'quantity' => 1, 'unit_price' => 600]], 600, [], $cashier->id)->total_amount);
        try {
            $this->sell([['stock_item_id' => $a->id, 'quantity' => 1, 'unit_price' => 450]], 450, [], $cashier->id);
            $this->fail('below cost needs an approval');
        } catch (BusinessRuleException $e) {
            $this->assertSame('approval_required', $e->errorCode());
            $this->assertStringContainsString('below cost', $e->getMessage());
        }
    }
}
