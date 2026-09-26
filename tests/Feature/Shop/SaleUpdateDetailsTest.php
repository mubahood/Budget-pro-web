<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\FinancialPeriod;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Services\Shop\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminTestCase;

/** SaleService::updateDetails: only who bought a recorded sale and its notes may change. */
class SaleUpdateDetailsTest extends AdminTestCase
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

    private function customer(string $name): Customer
    {
        $c = new Customer();
        $c->forceFill(['company_id' => $this->t['company']->id, 'name' => $name, 'phone' => '07'.random_int(10000000, 99999999)])->save();

        return $c;
    }

    private function sell(array $extra, float $paid = 0): SaleRecord
    {
        $payments = $paid > 0 ? [['amount' => $paid, 'method' => 'cash']] : [];

        return (new SaleService())->checkout($this->t['company']->id, $this->t['user']->id, $extra + ['payments' => $payments, 'payments_explicit' => true, 'from_sync' => true,
            'items' => [['stock_item_id' => $this->p->id, 'quantity' => 2]]])['sale'];
    }

    public function test_moving_a_sale_between_customers_moves_its_debt_and_payments(): void
    {
        $a = $this->customer('Akello');
        $b = $this->customer('Birungi');
        $sale = $this->sell(['customer_id' => $a->id], 4000); // 10,000 total, 6,000 owed
        $this->assertEquals(6000, (float) $a->fresh()->balance);

        $svc = new SaleService();
        $out = $svc->updateDetails($sale, ['customer_id' => $b->id, 'customer_name' => ' Birungi ', 'customer_phone' => '0772 111-222',
            'customer_address' => 'Kasese', 'notes' => 'Delivered'], $this->t['user']->id);

        $this->assertSame($b->id, (int) $out->customer_id);
        $this->assertSame('Birungi', $out->customer_name);
        $this->assertSame('0772111222', $out->customer_phone);
        $this->assertSame('Delivered', $out->notes);
        $this->assertEquals(10000, (float) $out->total_amount, 'amounts untouched');
        $this->assertEquals(6000, (float) $out->balance);
        $this->assertEquals(0, (float) $a->fresh()->balance);
        $this->assertEquals(6000, (float) $b->fresh()->balance);
        $this->assertSame(0, DB::table('payments')->where('sale_record_id', $sale->id)->where('customer_id', '<>', $b->id)->count(), 'payments follow the sale');

        // Unlinking the account: the debt leaves the customer.
        $svc->updateDetails($out, ['customer_id' => null], $this->t['user']->id);
        $this->assertEquals(0, (float) $b->fresh()->balance);
        $this->assertNull($sale->fresh()->customer_id);
    }

    public function test_derived_fields_voided_sales_and_foreign_customers_are_refused(): void
    {
        $svc = new SaleService();
        $sale = $this->sell(['customer_name' => 'Walk-in']);
        foreach ([['total_amount' => 1], ['balance' => 0], ['status' => 'Completed'], ['sale_date' => '2020-01-01']] as $bad) {
            try {
                $svc->updateDetails($sale, $bad, $this->t['user']->id);
                $this->fail('derived field must be refused: '.key($bad));
            } catch (BusinessRuleException $e) {
                $this->assertSame('derived_field', $e->errorCode());
            }
        }

        $other = $this->makeTenant('company');
        $foreign = new Customer();
        $foreign->forceFill(['company_id' => $other['company']->id, 'name' => 'Elsewhere'])->save();
        try {
            $svc->updateDetails($sale, ['customer_id' => $foreign->id], $this->t['user']->id);
            $this->fail('another shop\'s customer must be refused');
        } catch (BusinessRuleException $e) {
            $this->assertSame('customer_not_found', $e->errorCode());
        }

        $svc->void($sale, 'Mistake', $this->t['user']->id);
        try {
            $svc->updateDetails($sale->fresh(), ['notes' => 'x'], $this->t['user']->id);
            $this->fail('voided sale must be refused');
        } catch (BusinessRuleException $e) {
            $this->assertSame('sale_voided', $e->errorCode());
        }
    }
}
