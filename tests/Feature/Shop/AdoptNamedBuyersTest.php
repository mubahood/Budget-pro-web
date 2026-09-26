<?php

namespace Tests\Feature\Shop;

use App\Models\Customer;
use App\Models\FinancialPeriod;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Services\Shop\CustomerService;
use App\Services\Shop\SaleService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminTestCase;

/** Buyers who only exist as names typed on sales become customers, with their sales, payments and balances. */
class AdoptNamedBuyersTest extends AdminTestCase
{
    private array $t;

    private StockItem $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->makeTenant('company');
        $cid = $this->t['company']->id;
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $cid, 'name' => 'Phones']);
        $sub = StockSubCategory::create(['company_id' => $cid, 'stock_category_id' => $cat->id, 'name' => 'Parts', 'measurement_unit' => 'pcs']);
        $this->p = StockItem::create(['company_id' => $cid, 'created_by_id' => $this->t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Screen', 'sku' => 'S-'.uniqid(), 'buying_price' => 4000, 'selling_price' => 5000, 'original_quantity' => 100]);
    }

    /** A sale the way the phone app syncs it: a typed name, no customer account. */
    private function sell(string $name, ?float $paid = null, ?string $phone = null): SaleRecord
    {
        return (new SaleService())->checkout($this->t['company']->id, $this->t['user']->id, ['customer_name' => $name, 'customer_phone' => $phone,
            'payments' => $paid === null ? [['method' => 'cash', 'amount' => 5000]] : ($paid > 0 ? [['method' => 'cash', 'amount' => $paid]] : []),
            'payments_explicit' => true, 'from_sync' => true, 'items' => [['stock_item_id' => $this->p->id, 'quantity' => 1]]])['sale'];
    }

    public function test_names_become_customers_with_their_sales_payments_and_balances(): void
    {
        $cid = $this->t['company']->id;
        $a = $this->sell('Eng ladin');                 // paid
        $this->sell(' eng Ladin ', 2000);              // same buyer, owes 3,000
        $this->sell('Komeni shop', 0.0);               // owes 5,000
        $this->sell('Walk-in Customer');               // placeholder: stays unlinked
        $this->sell('cash');                           // placeholder
        $known = new Customer();
        $known->forceFill(['company_id' => $cid, 'name' => 'Zuzu', 'phone' => '+256700000001'])->save();
        $this->sell('Zuzu', 0.0);                      // joins the existing customer by name

        $r = (new CustomerService())->adoptNamedBuyers($cid);
        $this->assertSame(['created' => 2, 'linked' => 4], $r);

        $ladin = Customer::withoutGlobalScopes()->where('company_id', $cid)->where('name', 'like', '%ladin%')->firstOrFail();
        $this->assertSame(2, SaleRecord::withoutGlobalScopes()->where('customer_id', $ladin->id)->count());
        $this->assertEquals(3000, (float) $ladin->balance);
        $this->assertFalse((bool) $ladin->reminders_enabled, 'no automatic reminders to adopted buyers');
        $this->assertSame(CustomerService::ADOPTED_NOTE, $ladin->notes);
        $this->assertSame(0, DB::table('payments')->where('sale_record_id', $a->id)->whereNull('customer_id')->count(), 'payments follow');
        $this->assertEquals(5000, (float) $known->fresh()->balance);
        $this->assertSame(2, SaleRecord::withoutGlobalScopes()->where('company_id', $cid)->whereNull('customer_id')->count(), 'placeholders untouched');

        // Idempotent, and the command does the same for every shop.
        $this->assertSame(['created' => 0, 'linked' => 0], (new CustomerService())->adoptNamedBuyers($cid));
        $this->sell('New buyer', 0.0);
        Artisan::call('customers:adopt-named');
        $this->assertTrue(Customer::withoutGlobalScopes()->where('company_id', $cid)->where('name', 'New buyer')->exists());

        // Another shop's buyers are its own.
        $other = $this->makeTenant('company');
        $this->assertSame(0, Customer::withoutGlobalScopes()->where('company_id', $other['company']->id)->count());
    }
}
