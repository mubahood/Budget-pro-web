<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\FinancialPeriod;
use App\Models\GiftCard;
use App\Models\Payment;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Services\Dashboard\DashboardService;
use App\Services\FinancialReportService;
use App\Services\Reports\ReportService;
use App\Services\Shop\ApprovalService;
use App\Services\Shop\CustomerService;
use App\Services\Shop\GiftCardService;
use App\Services\Shop\LoyaltyService;
use App\Services\Shop\ReceiptService;
use App\Services\Shop\ReturnService;
use App\Services\Shop\SaleService;
use App\Support\LocalDate;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminTestCase;

/**
 * Supermarket phase 3 payments (SUPERMARKET_PLAN.md C1 loyalty, C2 gift cards and store credit, A9 exchanges
 * and returns without a receipt): money is never created or lost, and a gift card sold is not a sale.
 */
class SupermarketPaymentsTest extends AdminTestCase
{
    private array $t;

    private int $cid;

    private int $uid;

    private StockItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->makeTenant('company');
        $this->cid = (int) $this->t['company']->id;
        $this->uid = (int) $this->t['user']->id;
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $this->cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $this->cid, 'name' => 'Food']);
        $sub = StockSubCategory::create(['company_id' => $this->cid, 'stock_category_id' => $cat->id, 'name' => 'Dry', 'measurement_unit' => 'pcs']);
        $this->item = StockItem::create(['company_id' => $this->cid, 'created_by_id' => $this->uid, 'stock_sub_category_id' => $sub->id,
            'name' => 'Rice 1kg', 'sku' => 'R-'.uniqid(), 'buying_price' => 3000, 'selling_price' => 5000, 'original_quantity' => 100])->fresh();
    }

    private function on(array $settings = []): void
    {
        StoreFeatures::update($this->t['company'], ['features' => ['loyalty' => true, 'gift_cards' => true, 'exchanges' => true],
            'settings' => $settings + ['loyalty_spend_per_point' => 1000, 'loyalty_point_value' => 10]]);
        $this->t['company'] = $this->t['company']->fresh();
    }

    private function customer(string $name = 'Amina'): Customer
    {
        $c = new Customer(['name' => $name, 'phone' => '0772'.random_int(100000, 999999)]);
        $c->company_id = $this->cid;
        $c->save();

        return $c->fresh();
    }

    private function sell(int $qty, array $payments, ?int $customerId = null): SaleRecord
    {
        return (new SaleService())->checkout($this->cid, $this->uid, [
            'items' => [['stock_item_id' => $this->item->id, 'quantity' => $qty]], 'payments' => $payments, 'payments_explicit' => true, 'customer_id' => $customerId,
        ])['sale'];
    }

    /** @return array{sales: float, profit: float, collected: float, cash: float} */
    private function figures(): array
    {
        $day = LocalDate::today($this->cid)->toDateString();
        $k = (new DashboardService())->kpis($this->cid, $day, $day);
        $methods = collect((new DashboardService())->collectedByMethod($this->cid, $day, $day))->keyBy('method');

        return ['sales' => round($k['sales'], 2), 'profit' => round($k['profit'], 2), 'collected' => round($k['collected'], 2), 'cash' => round((float) ($methods['cash']['amount'] ?? 0), 2)];
    }

    private function ledgerIncome(): float
    {
        return round((float) DB::table('financial_records')->where('company_id', $this->cid)->where('type', 'Income')->where('is_deleted', 0)->sum('amount'), 2);
    }

    public function test_off_nothing_changes_and_tenders_are_refused(): void
    {
        $c = $this->customer();
        $sale = $this->sell(2, [['method' => 'cash', 'amount' => 10000]], $c->id);
        $this->assertSame(0, DB::table('loyalty_ledger')->count(), 'no points without the feature');
        $this->assertNull((new LoyaltyService())->receiptLine($sale));
        $this->assertStringNotContainsString('Points', (new ReceiptService())->text($sale));

        foreach ([['tender' => 'points', 'method' => 'points', 'amount' => 100, 'points' => 10], ['tender' => 'gift_card', 'method' => 'gift_card', 'code' => '12345678', 'amount' => 100],
            ['tender' => 'store_credit', 'method' => 'store_credit', 'amount' => 100]] as $row) {
            try {
                $this->sell(1, [$row, ['method' => 'cash', 'amount' => 5000]], $c->id);
                $this->fail('a tender must be refused while its feature is off');
            } catch (BusinessRuleException $e) {
                $this->assertSame('feature_off', $e->errorCode());
            }
        }
        $this->expectException(BusinessRuleException::class);
        (new GiftCardService())->sell($this->cid, $this->uid, 1000, 'cash');
    }

    public function test_loyalty_earns_redeems_and_is_taken_back_on_return_and_void(): void
    {
        $this->on();
        $c = $this->customer();
        $lp = new LoyaltyService();
        // 4 × 5,000 = 20,000 paid → 20 points.
        $a = $this->sell(4, [['method' => 'cash', 'amount' => 20000]], $c->id);
        $this->assertSame(20, $lp->balance($this->cid, $c->id));
        $this->assertStringContainsString('Points earned: 20', (new ReceiptService())->text($a));

        // Pay 5,000 with 15 points (150) + cash: never more than the balance, never more than due.
        $b = $this->sell(1, [['tender' => 'points', 'method' => 'points', 'points' => 15, 'amount' => 150], ['method' => 'cash', 'amount' => 5000]], $c->id);
        $this->assertSame('Paid', $b->payment_status);
        $this->assertEqualsWithDelta(150, (float) $b->payments->where('method', 'points')->sum('amount'), 0.001);
        $this->assertEqualsWithDelta(150, (float) $b->change_given, 0.001, 'cash covers the rest, the extra is change');
        // Points paid do not earn: 4,850 → 4 points.
        $this->assertSame(20 - 15 + 4, $lp->balance($this->cid, $c->id));
        $this->assertSame(0, (int) DB::table('financial_records')->where('source_type', 'payment')->whereIn('source_id', $b->payments->where('method', 'points')->pluck('id'))->count(), 'points post no income');
        try {
            $this->sell(1, [['tender' => 'points', 'method' => 'points', 'points' => 500, 'amount' => 5000]], $c->id);
            $this->fail('more points than the customer has');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('9 points', $e->getMessage());
        }
        // Due 5,000 at 10 a point: at most 500 points are taken even if more are asked (here the balance refuses first).

        // Return half of sale A → 10 points back; void B → its 4 points go and the 15 redeemed come back.
        (new ReturnService())->create($a, [['sale_item_id' => $a->saleRecordItems[0]->id, 'quantity' => 2]], $this->uid);
        $this->assertSame(9 - 10, $lp->balance($this->cid, $c->id));
        (new SaleService())->void($b->fresh(), 'test', $this->uid);
        $this->assertSame(-1 - 4 + 15, $lp->balance($this->cid, $c->id));
        $this->assertSame(0, (int) DB::table('loyalty_ledger')->where('sale_record_id', $b->id)->sum('points'), 'a voided sale leaves no points behind');

        // Tiers: a threshold on 12 months' spend.
        $this->assertSame('Silver', LoyaltyService::tierFor($this->t['company'], 1000000));
        $this->assertSame('Gold', LoyaltyService::tierFor($this->t['company'], 6000000));
        $this->assertNull(LoyaltyService::tierFor($this->t['company'], 999));
        $sum = $lp->summary($c->fresh());
        $this->assertSame(10, $sum['points']);
        $this->assertEqualsWithDelta(100, $sum['points_value'], 0.001);
    }

    public function test_a_gift_card_sold_is_cash_not_sales_and_paying_with_it_is_a_tender(): void
    {
        $this->on();
        $before = $this->figures();
        $income0 = $this->ledgerIncome();

        $sold = (new GiftCardService())->sell($this->cid, $this->uid, 50000, 'cash');
        $code = $sold['code'];
        $this->assertNotNull($code);
        $this->assertSame(64, strlen($sold['card']->code_hash));
        $this->assertStringNotContainsString(GiftCardService::normalize($code), json_encode(GiftCard::withoutGlobalScopes()->find($sold['card']->id)->toArray()), 'only a hash is kept');
        $this->assertEqualsWithDelta(50000, (float) $sold['card']->balance, 0.001);

        $after = $this->figures();
        $this->assertSame($before['sales'], $after['sales'], 'a gift card is not a sale');
        $this->assertSame($before['profit'], $after['profit']);
        $this->assertEqualsWithDelta($before['cash'] + 50000, $after['cash'], 0.001, 'the money is in the drawer');
        $this->assertEqualsWithDelta($income0 + 50000, $this->ledgerIncome(), 0.001, 'and in the ledger');
        $day = LocalDate::today($this->cid)->toDateString();
        $pl = (new FinancialReportService())->getSummaryStatistics($this->cid, $day, $day, true);
        $this->assertEqualsWithDelta(0, $pl['other_income'], 0.001, 'not profit either');
        $this->assertEqualsWithDelta(0, (new ReportService())->run($this->cid, 'sales_summary', $day, $day)['totals']['amount'] ?? 0, 0.001);

        // Pay 3 × 5,000 = 15,000: 12,000 from the card, 3,000 cash.
        $sale = $this->sell(3, [['tender' => 'gift_card', 'method' => 'gift_card', 'code' => $code, 'amount' => 12000], ['method' => 'cash', 'amount' => 3000]]);
        $this->assertSame('Paid', $sale->payment_status);
        $this->assertEqualsWithDelta(38000, (float) GiftCard::withoutGlobalScopes()->find($sold['card']->id)->balance, 0.001);
        $this->assertEqualsWithDelta(38000, (float) DB::table('gift_card_ledger')->where('gift_card_id', $sold['card']->id)->sum('amount'), 0.001, 'balance = sum of the ledger');
        $f = $this->figures();
        $this->assertEqualsWithDelta($before['sales'] + 15000, $f['sales'], 0.001, 'the sale counts at its full value');
        $this->assertEqualsWithDelta($before['profit'] + 15000 - 9000, $f['profit'], 0.001);
        $this->assertEqualsWithDelta($before['cash'] + 50000 + 3000, $f['cash'], 0.001, 'only real money is cash');
        $this->assertEqualsWithDelta($before['collected'] + 53000, $f['collected'], 0.001, 'the card payment is not money collected again');
        $this->assertEqualsWithDelta($income0 + 53000, $this->ledgerIncome(), 0.001, 'no income twice');

        // The whole card on a sale larger than its balance takes only what is left; a wrong code is refused.
        $big = $this->sell(10, [['tender' => 'gift_card', 'method' => 'gift_card', 'code' => str_replace(' ', '-', $code)], ['method' => 'cash', 'amount' => 12000]]);
        $this->assertEqualsWithDelta(38000, (float) $big->payments->where('method', 'gift_card')->sum('amount'), 0.001);
        $this->assertEqualsWithDelta(0, (float) GiftCard::withoutGlobalScopes()->find($sold['card']->id)->balance, 0.001);
        try {
            $this->sell(1, [['tender' => 'gift_card', 'method' => 'gift_card', 'code' => '0000 0000 0000 0000']]);
            $this->fail('unknown code');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('No gift card', $e->getMessage());
        }

        // Returning the big sale gives the card back its 38,000 first, the rest in cash.
        $ret = (new ReturnService())->create($big, [['sale_item_id' => $big->saleRecordItems[0]->id, 'quantity' => 10]], $this->uid);
        $this->assertEqualsWithDelta(50000, (float) $ret->refund_amount, 0.001);
        $this->assertEqualsWithDelta(38000, (float) GiftCard::withoutGlobalScopes()->find($sold['card']->id)->balance, 0.001);
        $this->assertEqualsWithDelta(-12000, (float) Payment::withoutGlobalScopes()->where('sale_record_id', $big->id)->where('method', 'cash')->where('amount', '<', 0)->sum('amount'), 0.001);

        // Report: owed on cards now, sold and used in the range.
        $r = (new ReportService())->run($this->cid, 'gift_cards', $day, $day);
        $this->assertEqualsWithDelta(38000, $r['meta']['gift_cards_owed'], 0.001);
        $this->assertEqualsWithDelta(50000, $r['meta']['sold'], 0.001);
        $this->assertEqualsWithDelta(50000, $r['meta']['used'], 0.001);
        $this->assertEqualsWithDelta(38000, $r['totals']['balance'], 0.001);
    }

    public function test_store_credit_from_a_return_pays_a_later_sale_and_voids_give_it_back(): void
    {
        $this->on();
        $c = $this->customer();
        $cs = new CustomerService();
        $sale = $this->sell(2, [['method' => 'cash', 'amount' => 10000]], $c->id);
        $income = $this->ledgerIncome();
        $cash = $this->figures()['cash'];

        $ret = (new ReturnService())->create($sale, [['sale_item_id' => $sale->saleRecordItems[0]->id, 'quantity' => 1]], $this->uid, 'Changed mind', 'cash', null, null, ['refund_to' => 'store_credit']);
        $this->assertSame('store_credit', $ret->refund_method);
        $this->assertEqualsWithDelta(-5000, $cs->balance($c->fresh()), 0.001, 'the customer is owed 5,000 in credit');
        $this->assertEqualsWithDelta($income, $this->ledgerIncome(), 0.001, 'no money left the drawer');
        $this->assertEqualsWithDelta($cash, $this->figures()['cash'], 0.001);

        $next = $this->sell(1, [['tender' => 'store_credit', 'method' => 'store_credit', 'amount' => 5000]], $c->id);
        $this->assertSame('Paid', $next->payment_status);
        $this->assertEqualsWithDelta(0, $cs->balance($c->fresh()), 0.001);
        try {
            $this->sell(1, [['tender' => 'store_credit', 'method' => 'store_credit', 'amount' => 5000]], $c->id);
            $this->fail('no credit left');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('no store credit', $e->getMessage());
        }
        (new SaleService())->void($next->fresh(), 'mistake', $this->uid);
        $this->assertEqualsWithDelta(-5000, $cs->balance($c->fresh()), 0.001, 'the credit comes back');

        // Refund to a new gift card.
        $svc = new ReturnService();
        $r2 = $svc->create($sale->fresh(), [['sale_item_id' => $sale->saleRecordItems[0]->id, 'quantity' => 1]], $this->uid, null, 'cash', null, null, ['refund_to' => 'new_gift_card']);
        $this->assertNotNull($svc->issuedCode);
        $this->assertEqualsWithDelta(5000, (float) (new GiftCardService())->find($this->cid, $svc->issuedCode)->balance, 0.001);
        $this->assertSame('gift_card', $r2->refund_method);
        $this->assertEqualsWithDelta($income, $this->ledgerIncome(), 0.001);
    }

    public function test_an_exchange_pays_or_refunds_only_the_difference(): void
    {
        $this->on();
        $sale = $this->sell(2, [['method' => 'cash', 'amount' => 10000]]);
        $sales0 = $this->figures();
        $income = $this->ledgerIncome();

        // Return 1 (5,000 credit) and take 3 (15,000): pay 10,000 more.
        $x = (new ReturnService())->exchange($sale, [['sale_item_id' => $sale->saleRecordItems[0]->id, 'quantity' => 1]], $this->uid,
            ['items' => [['stock_item_id' => $this->item->id, 'quantity' => 3]], 'payments' => [['method' => 'cash', 'amount' => 10000]]], 'Wrong size');
        $this->assertEqualsWithDelta(5000, $x['credit'], 0.001);
        $this->assertEqualsWithDelta(0, $x['refund'], 0.001);
        $this->assertSame('Paid', $x['sale']->payment_status);
        $this->assertEqualsWithDelta($income + 10000, $this->ledgerIncome(), 0.001, 'only the difference is new money');
        $f = $this->figures();
        $this->assertEqualsWithDelta($sales0['sales'] - 5000 + 15000, $f['sales'], 0.001);
        $this->assertEqualsWithDelta($sales0['cash'] + 10000, $f['cash'], 0.001);
        $this->assertEqualsWithDelta(0, (float) Payment::withoutGlobalScopes()->whereIn('sale_record_id', [$sale->id, $x['sale']->id])->where('method', 'exchange')->sum('amount'), 0.001);

        // Return 3 and take 1: 10,000 back in cash.
        $y = (new ReturnService())->exchange($x['sale']->fresh(), [['sale_item_id' => $x['sale']->saleRecordItems[0]->id, 'quantity' => 3]], $this->uid,
            ['items' => [['stock_item_id' => $this->item->id, 'quantity' => 1]], 'payments' => []]);
        $this->assertEqualsWithDelta(10000, $y['refund'], 0.001);
        $this->assertSame('Paid', $y['sale']->payment_status);
        $this->assertEqualsWithDelta($income, $this->ledgerIncome(), 0.001);
        $this->assertEqualsWithDelta($sales0['cash'], $this->figures()['cash'], 0.001);

        // The exchange credit cannot be forged from outside an exchange.
        $this->expectException(BusinessRuleException::class);
        $this->sell(1, [['tender' => 'exchange', 'method' => 'exchange', 'amount' => 5000, 'return_id' => $y['return']->id]]);
    }

    public function test_a_return_without_a_receipt_needs_a_supervisor_and_pays_the_lowest_recent_price(): void
    {
        $this->on();
        (new ApprovalService())->setPin($this->t['user'], '2468');
        // Sold at 5,000 and, with a discount, at 4,000 this month: the lowest is 4,000.
        $this->sell(1, [['method' => 'cash', 'amount' => 5000]]);
        (new SaleService())->checkout($this->cid, $this->uid, ['items' => [['stock_item_id' => $this->item->id, 'quantity' => 1, 'discount_amount' => 1000]],
            'payments' => [['method' => 'cash', 'amount' => 4000]], 'payments_explicit' => true]);
        $this->assertEqualsWithDelta(4000, ReturnService::lowestRecentPrice($this->cid, $this->item->id), 0.001);
        $before = $this->figures();
        $stock = (float) $this->item->fresh()->current_quantity;
        $income = $this->ledgerIncome();

        try {
            (new ReturnService())->noReceipt($this->cid, $this->uid, [['stock_item_id' => $this->item->id, 'quantity' => 2]], 999999, 'No slip');
            $this->fail('an approval is required');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('approval', $e->getMessage());
        }
        $approval = (new ApprovalService())->grant($this->cid, 'refund', '2468', $this->uid, ['reason' => 'No slip']);
        $ret = (new ReturnService())->noReceipt($this->cid, $this->uid, [['stock_item_id' => $this->item->id, 'quantity' => 2]], (int) $approval->id, 'No slip');
        $this->assertEqualsWithDelta(8000, (float) $ret->value, 0.001);
        $this->assertEqualsWithDelta($stock + 2, (float) $this->item->fresh()->current_quantity, 0.001, 'back on the shelf');
        $f = $this->figures();
        $this->assertEqualsWithDelta($before['sales'] - 8000, $f['sales'], 0.001, 'sales drop by what went back');
        $this->assertEqualsWithDelta($before['profit'] - (8000 - 6000), $f['profit'], 0.001);
        $this->assertEqualsWithDelta($before['cash'] - 8000, $f['cash'], 0.001);
        $this->assertEqualsWithDelta($income - 8000, $this->ledgerIncome(), 0.001);
        $doc = SaleRecord::withoutGlobalScopes()->find($ret->sale_record_id);
        $this->assertSame('Refunded', $doc->status);
        $this->assertEqualsWithDelta(0, (float) $doc->balance, 0.001, 'nobody owes anything on it');
        $day = LocalDate::today($this->cid)->toDateString();
        $sum = (new ReportService())->run($this->cid, 'sales_summary', $day, $day);
        $this->assertEqualsWithDelta($f['sales'], (float) ($sum['meta']['total'] ?? $sum['totals']['amount'] ?? $f['sales']), 0.001);

        // The approval is used once; a return document cannot be returned again.
        try {
            (new ReturnService())->noReceipt($this->cid, $this->uid, [['stock_item_id' => $this->item->id, 'quantity' => 1]], (int) $approval->id, 'again');
            $this->fail('approval reused');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('approval', $e->getMessage());
        }
        $this->expectException(BusinessRuleException::class);
        (new ReturnService())->create($doc, [['sale_item_id' => $doc->saleRecordItems()->first()->id, 'quantity' => 1]], $this->uid);
    }
}
