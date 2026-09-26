<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\FinancialPeriod;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Services\Shop\DebtService;
use App\Services\Shop\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminTestCase;

/** Debts from customer accounts AND from credit sales that only carry a typed name. */
class DebtServiceTest extends AdminTestCase
{
    private array $t;

    private StockItem $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->makeTenant('company');
        $cid = $this->t['company']->id;
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $cid, 'name' => 'Food']);
        $sub = StockSubCategory::create(['company_id' => $cid, 'stock_category_id' => $cat->id, 'name' => 'Dry', 'measurement_unit' => 'kg']);
        $this->p = StockItem::create(['company_id' => $cid, 'created_by_id' => $this->t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Rice', 'sku' => 'R-'.uniqid(), 'buying_price' => 4000, 'selling_price' => 5000, 'original_quantity' => 100]);
    }

    private function sell(array $extra, int $qty = 2): SaleRecord
    {
        return (new SaleService())->checkout($this->t['company']->id, $this->t['user']->id, $extra + ['payments' => [], 'payments_explicit' => true, 'from_sync' => true,
            'items' => [['stock_item_id' => $this->p->id, 'quantity' => $qty]]])['sale'];
    }

    public function test_named_credit_sales_are_debtors_and_can_be_paid_and_adopted(): void
    {
        $cid = $this->t['company']->id;
        $uid = $this->t['user']->id;
        $a = $this->sell(['customer_name' => 'Member x'], 2);   // 10,000
        $b = $this->sell(['customer_name' => ' member X '], 1); // 5,000, same person typed differently
        $this->sell(['customer_name' => 'Thembo 1'], 3);        // 15,000
        $acc = new Customer();
        $acc->forceFill(['company_id' => $cid, 'name' => 'Nakato', 'phone' => '0752300400'])->save();
        $this->sell(['customer_id' => $acc->id], 4);            // 20,000 on an account

        $svc = new DebtService();
        $list = collect($svc->debtors($cid))->keyBy('key');
        $this->assertSame(20000.0, $list['c:'.$acc->id]['owed']);
        $this->assertSame(15000.0, $list['n:member x']['owed']);
        $this->assertSame(2, $list['n:member x']['sales']);
        $this->assertSame(15000.0, $list['n:thembo 1']['owed']);
        $this->assertCount(1, $svc->debtors($cid, 'thembo'));

        // Paying a name: oldest sale first, never more than owed.
        $svc->receive($cid, 'n:member x', 12000, 'cash', $uid);
        $this->assertEquals(0, (float) $a->fresh()->balance);
        $this->assertEquals(3000, (float) $b->fresh()->balance);
        try {
            $svc->receive($cid, 'n:member x', 5000, 'cash', $uid);
            $this->fail('overpayment must be refused');
        } catch (BusinessRuleException $e) {
            $this->assertSame('overpayment', $e->errorCode());
        }

        // Making the name a customer moves every sale under it onto the account.
        $c = $svc->adopt($cid, 'n:member x', $uid, ['name' => 'Member X', 'phone' => '0772 111 222']);
        $this->assertSame(2, SaleRecord::withoutGlobalScopes()->where('customer_id', $c->id)->count());
        $this->assertEquals(3000, (float) $c->balance);
        $this->assertSame(0, DB::table('payments')->whereIn('sale_record_id', [$a->id, $b->id])->whereNull('customer_id')->count(), 'their payments follow the sales');
        $keys = array_column($svc->debtors($cid), 'key');
        $this->assertContains('c:'.$c->id, $keys);
        $this->assertNotContains('n:member x', $keys);

        // Other shops see none of it.
        $other = $this->makeTenant('company');
        $this->assertSame([], $svc->debtors($other['company']->id));
    }
}
