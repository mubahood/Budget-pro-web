<?php

namespace Tests\Feature\Admin;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\FinancialPeriod;
use App\Models\Payment;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\Subscription;
use App\Services\Shop\SaleService;
use Encore\Admin\Form;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Web sale form, sale page (payments, reversals, void) and the product look-up. */
class SaleFormTest extends AdminTestCase
{
    private array $t;

    private StockSubCategory $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->shop();
    }

    private function shop(): array
    {
        $t = $this->makeTenant('company');
        $t['company']->forceFill(['onboarding_state' => ['step' => 'done', 'completed_at' => now()->toIso8601String()], 'timezone' => 'Africa/Kampala'])->saveQuietly();
        Subscription::create(['company_id' => $t['company']->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $t['company']->id, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->subYear(), 'end_date' => now()->addYear()]);
        $cat = StockCategory::create(['company_id' => $t['company']->id, 'name' => 'Food']);
        $this->sub = StockSubCategory::create(['company_id' => $t['company']->id, 'stock_category_id' => $cat->id, 'name' => 'Dry', 'measurement_unit' => 'kg']);
        Auth::guard('admin')->login($t['user']);

        return $t;
    }

    private function product(string $name, float $qty = 20, array $extra = []): StockItem
    {
        $p = StockItem::create(['company_id' => $this->t['company']->id, 'created_by_id' => $this->t['user']->id, 'stock_category_id' => $this->sub->stock_category_id,
            'stock_sub_category_id' => $this->sub->id, 'name' => $name, 'sku' => strtoupper(substr($name, 0, 2)).'-'.uniqid(), 'buying_price' => 4000,
            'selling_price' => 5000, 'original_quantity' => $qty]);
        if ($extra !== []) {
            DB::table('stock_items')->where('id', $p->id)->update($extra);
        }

        return $p->fresh();
    }

    private function postSale(array $data)
    {
        return $this->asAdmin($this->t['user'])->post('/sale-records', $data + ['sale_date' => now()->toDateString(), 'customer_name' => '', 'customer_phone' => '', 'amount_paid' => '']);
    }

    private function row(StockItem $p, float $qty, array $extra = []): array
    {
        return ['stock_item_id' => $p->id, 'quantity' => $qty, 'unit_price' => '', 'id' => '', Form::REMOVE_FLAG_NAME => 0] + $extra;
    }

    public function test_a_cash_sale_with_no_amount_entered_is_fully_paid(): void
    {
        $rice = $this->product('Rice');
        $r = $this->postSale(['payment_method' => 'cash', 'saleRecordItems' => ['new_1' => $this->row($rice, 2), 'new_2' => array_merge($this->row($rice, 5), [Form::REMOVE_FLAG_NAME => 1])]]);

        $sale = SaleRecord::withoutGlobalScopes()->where('company_id', $this->t['company']->id)->firstOrFail();
        $r->assertRedirect(admin_url('sale-records/'.$sale->id));
        $this->assertEquals(10000, (float) $sale->total_amount, 'the removed row is not sold');
        $this->assertEquals(10000, (float) $sale->amount_paid);
        $this->assertEquals(0, (float) $sale->balance);
        $this->assertSame('Paid', $sale->payment_status);
        $this->assertSame('cash', $sale->payment_method);
        $this->assertSame('Walk-in Customer', $sale->customer_name);
        $this->assertNull($sale->customer_id);
        $this->assertSame(now('Africa/Kampala')->toDateString(), $sale->sale_date->toDateString());
        $this->assertEquals(10000, (float) Payment::withoutGlobalScopes()->where('sale_record_id', $sale->id)->sum('amount'));
        $this->assertEquals(18, (float) $rice->fresh()->current_quantity);
    }

    public function test_a_credit_sale_links_the_customer_and_lands_in_the_debt_book(): void
    {
        $rice = $this->product('Rice');

        // Money owed by nobody is refused.
        $this->postSale(['payment_method' => 'credit', 'saleRecordItems' => ['new_1' => $this->row($rice, 1)]])->assertRedirect();
        $this->assertSame(0, SaleRecord::withoutGlobalScopes()->where('company_id', $this->t['company']->id)->count());
        $this->assertEquals(20, (float) $rice->fresh()->current_quantity);

        $this->postSale(['payment_method' => 'credit', 'amount_paid' => '3000', 'customer_name' => 'Okello John', 'customer_phone' => '+256 772 123 456',
            'saleRecordItems' => ['new_1' => $this->row($rice, 2)]])->assertRedirect();
        $sale = SaleRecord::withoutGlobalScopes()->where('company_id', $this->t['company']->id)->firstOrFail();
        $customer = Customer::withoutGlobalScopes()->where('company_id', $this->t['company']->id)->firstOrFail();
        $this->assertSame('+256772123456', $customer->phone, 'international numbers are accepted');
        $this->assertSame('Okello John', $customer->name);
        $this->assertSame($customer->id, (int) $sale->customer_id);
        $this->assertEquals(0, (float) $sale->amount_paid, 'credit means nothing paid now');
        $this->assertEquals(10000, (float) $sale->balance);
        $this->assertEquals(10000, (float) $customer->fresh()->balance);

        // Same phone again: the same customer, and a part-paid cash sale adds to what they owe.
        $this->postSale(['payment_method' => 'cash', 'amount_paid' => '2,000', 'customer_name' => 'Okello', 'customer_phone' => '+256772123456',
            'saleRecordItems' => ['new_1' => $this->row($rice, 1)]])->assertRedirect();
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('company_id', $this->t['company']->id)->count());
        $this->assertEquals(13000, (float) $customer->fresh()->balance);

        // Choosing the account works too.
        $this->postSale(['payment_method' => 'credit', 'customer_id' => $customer->id, 'saleRecordItems' => ['new_1' => $this->row($rice, 1)]])->assertRedirect();
        $this->assertEquals(18000, (float) $customer->fresh()->balance);
        $this->assertSame('Okello John', SaleRecord::withoutGlobalScopes()->where('company_id', $this->t['company']->id)->orderByDesc('id')->value('customer_name'));
    }

    public function test_services_and_negative_stock_products_can_be_sold_but_short_stock_is_blocked(): void
    {
        $delivery = $this->product('Delivery', 0, ['track_stock' => 0]);
        $bread = $this->product('Bread', 0, ['allow_negative_stock' => 1]);
        $rice = $this->product('Rice', 3);

        $this->postSale(['payment_method' => 'cash', 'saleRecordItems' => ['new_1' => $this->row($delivery, 1), 'new_2' => $this->row($bread, 2)]])->assertRedirect();
        $this->assertSame(1, SaleRecord::withoutGlobalScopes()->where('company_id', $this->t['company']->id)->count());

        // Two lines of the same product are added up before the stock check.
        $this->postSale(['payment_method' => 'cash', 'saleRecordItems' => ['new_1' => $this->row($rice, 2), 'new_2' => $this->row($rice, 2)]])->assertRedirect();
        $this->assertSame(1, SaleRecord::withoutGlobalScopes()->where('company_id', $this->t['company']->id)->count());
        $this->assertEquals(3, (float) $rice->fresh()->current_quantity);

        // Per-line discount flows into the total.
        $this->postSale(['payment_method' => 'cash', 'saleRecordItems' => ['new_1' => $this->row($rice, 2, ['discount_amount' => '1000'])]])->assertRedirect();
        $sale = SaleRecord::withoutGlobalScopes()->where('company_id', $this->t['company']->id)->orderByDesc('id')->firstOrFail();
        $this->assertEquals(9000, (float) $sale->total_amount);
        $this->assertSame('Paid', $sale->payment_status);
    }

    public function test_status_of_a_recorded_sale_cannot_be_changed(): void
    {
        $rice = $this->product('Rice');
        $svc = new SaleService();
        $sale = $svc->checkout($this->t['company']->id, $this->t['user']->id, ['amount_paid' => 5000, 'items' => [['stock_item_id' => $rice->id, 'quantity' => 1]]])['sale'];
        $svc->void($sale, 'mistake', $this->t['user']->id);

        // The old inline "status" editor in the grid.
        $this->asAdmin($this->t['user'])->put('/sale-records/'.$sale->id, ['name' => 'status', 'value' => 'Completed', 'pk' => $sale->id, '_editable' => 1])
            ->assertJson(['status' => false]);
        $this->assertSame('Voided', SaleRecord::withoutGlobalScopes()->find($sale->id)->status);

        $fresh = SaleRecord::withoutGlobalScopes()->find($sale->id);
        $fresh->status = 'Completed';
        try {
            $fresh->save();
            $this->fail('status change allowed');
        } catch (BusinessRuleException $e) {
            $this->assertSame('status_read_only', $e->errorCode());
        }
        $this->assertSame('Voided', SaleRecord::withoutGlobalScopes()->find($sale->id)->status);

        // Customer details can still be corrected.
        $this->asAdmin($this->t['user'])->put('/sale-records/'.$sale->id, ['customer_name' => 'Nakato', 'customer_phone' => '0772 000111', 'notes' => 'typo'])
            ->assertRedirect(admin_url('sale-records/'.$sale->id));
        $this->assertSame('Nakato', SaleRecord::withoutGlobalScopes()->find($sale->id)->customer_name);
    }

    public function test_receiving_a_payment_on_the_sale_page_is_capped_at_the_balance_and_can_be_reversed(): void
    {
        $rice = $this->product('Rice');
        $customer = Customer::create(['company_id' => $this->t['company']->id, 'name' => 'Achieng', 'phone' => '0701000222']);
        $sale = (new SaleService())->checkout($this->t['company']->id, $this->t['user']->id, ['customer_id' => $customer->id, 'amount_paid' => 0,
            'items' => [['stock_item_id' => $rice->id, 'quantity' => 2]]])['sale'];
        $this->assertEquals(10000, (float) $customer->fresh()->balance);

        // Editing amount_paid directly may not go past what is owed.
        $s = SaleRecord::withoutGlobalScopes()->find($sale->id);
        $s->amount_paid = 25000;
        try {
            $s->save();
            $this->fail('overpayment accepted');
        } catch (BusinessRuleException $e) {
            $this->assertSame('overpayment', $e->errorCode());
        }

        $this->asAdmin($this->t['user'])->post('/sale-records/'.$sale->id.'/payments', ['amount' => '4,000', 'method' => 'mobile_money', 'reference' => 'MP123'])
            ->assertRedirect(admin_url('sale-records/'.$sale->id));
        $this->assertEquals(6000, (float) $sale->fresh()->balance);
        $this->assertSame('Partial', $sale->fresh()->payment_status);
        $this->assertEquals(6000, (float) $customer->fresh()->balance);

        $this->asAdmin($this->t['user'])->post('/sale-records/'.$sale->id.'/payments', ['amount' => 50000, 'method' => 'cash'])->assertRedirect();
        $fresh = $sale->fresh();
        $this->assertEquals(0, (float) $fresh->balance);
        $this->assertEquals(10000, (float) $fresh->amount_paid);
        $this->assertSame('Paid', $fresh->payment_status);
        $this->assertEquals(10000, (float) Payment::withoutGlobalScopes()->where('sale_record_id', $sale->id)->sum('amount'), 'only the balance is income');
        $this->assertEquals(0, (float) $customer->fresh()->balance);

        // Nothing left to pay.
        $this->asAdmin($this->t['user'])->post('/sale-records/'.$sale->id.'/payments', ['amount' => 100, 'method' => 'cash'])->assertRedirect();
        $this->assertEquals(10000, (float) Payment::withoutGlobalScopes()->where('sale_record_id', $sale->id)->sum('amount'));

        // Reverse the mobile-money payment: owed again.
        $momo = Payment::withoutGlobalScopes()->where('sale_record_id', $sale->id)->where('method', 'mobile_money')->firstOrFail();
        $this->asAdmin($this->t['user'])->post('/sale-records/'.$sale->id.'/payments/'.$momo->id.'/reverse', ['reason' => 'wrong sale'])->assertRedirect();
        $this->assertEquals(4000, (float) $sale->fresh()->balance);
        $this->assertEquals(4000, (float) $customer->fresh()->balance);
        $this->asAdmin($this->t['user'])->post('/sale-records/'.$sale->id.'/payments/'.$momo->id.'/reverse', ['reason' => 'again'])->assertRedirect();
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('reverses_id', $momo->id)->count(), 'reversed once');
    }

    public function test_product_lookup_lists_only_this_shops_sellable_products(): void
    {
        $rice = $this->product('Rice');
        $service = $this->product('Delivery', 0, ['track_stock' => 0]);
        $empty = $this->product('Sugar', 0);
        $deleted = $this->product('Old soap', 5, ['is_deleted' => 1]);
        $inactive = $this->product('Hidden salt', 5, ['is_active' => 0]);
        $mine = $this->t;
        $other = $this->t = $this->shop();
        $foreign = $this->product('Rice other shop');
        $this->t = $mine;

        $data = $this->asAdmin($mine['user'])->getJson('/ajax/products')->assertOk()->json('data');
        $ids = array_column($data, 'id');
        sort($ids);
        $expected = [$rice->id, $service->id, $empty->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertNotContains($foreign->id, $ids);
        $this->assertNotContains($deleted->id, $ids);
        $this->assertNotContains($inactive->id, $ids);

        $row = collect($data)->firstWhere('id', $rice->id);
        $this->assertSame('Rice ('.$rice->sku.') — 20 in stock — 5,000', html_entity_decode($row['text']));
        $this->assertEquals(5000, $row['price']);
        $this->assertTrue($row['track_stock']);
        $this->assertFalse(collect($data)->firstWhere('id', $service->id)['track_stock']);

        $this->assertSame([$rice->id], array_column($this->asAdmin($mine['user'])->getJson('/ajax/products?q=ric')->json('data'), 'id'));
        $this->assertSame([$rice->id], array_column($this->asAdmin($mine['user'])->getJson('/ajax/products?q='.$rice->sku)->json('data'), 'id'));
        $this->assertSame([$foreign->id], array_column($this->asAdmin($other['user'])->getJson('/ajax/products?q=rice')->json('data'), 'id'));
    }

    public function test_sale_pages_render(): void
    {
        $rice = $this->product('Rice');
        $customer = Customer::create(['company_id' => $this->t['company']->id, 'name' => 'Achieng', 'phone' => '0701000222']);
        $sale = (new SaleService())->checkout($this->t['company']->id, $this->t['user']->id, ['customer_id' => $customer->id, 'amount_paid' => 2000,
            'items' => [['stock_item_id' => $rice->id, 'quantity' => 2]]])['sale'];

        $this->asAdmin($this->t['user'])->get('/sale-records/'.$sale->id)->assertOk()
            ->assertSee($sale->receipt_number)->assertSee('Rice')->assertSee('Achieng')
            ->assertSee('UGX 10,000')->assertSee('UGX 8,000')->assertDontSee('10,000.00')->assertDontSee('2.000')
            ->assertSee('Receive payment')->assertSee('Record return')->assertSee('Void sale')->assertSee('Print receipt')->assertSee('Send receipt');
        $this->asAdmin($this->t['user'])->get('/sale-records')->assertOk()->assertSee($sale->receipt_number)->assertSee('Today:')->assertSee('8,000 owed')
            ->assertSee('sale-receipt-pdf?id='.$sale->id, false)->assertSee('Achieng<br><small class="text-muted">0701000222', false);
        $this->asAdmin($this->t['user'])->get('/sale-records?_search_='.urlencode('Achieng'))->assertOk()->assertSee($sale->receipt_number);
        $this->asAdmin($this->t['user'])->get('/sale-records/create')->assertOk()->assertSee('ajax/products')->assertDontSee('Financial Period');
        $this->asAdmin($this->t['user'])->get('/sale-records/'.$sale->id.'/edit')->assertOk()->assertSee('void it and record a new sale');

        // Void from the sale page: reason kept, page shows it, the actions are gone.
        $this->asAdmin($this->t['user'])->post('/sale-records/'.$sale->id.'/void', ['reason' => 'Entered twice'])->assertRedirect(admin_url('sale-records/'.$sale->id));
        $this->assertSame('Voided', $sale->fresh()->status);
        $this->asAdmin($this->t['user'])->get('/sale-records/'.$sale->id)->assertOk()->assertSee('Entered twice')->assertDontSee('Receive payment')->assertDontSee('Void sale');
        $this->assertEquals(0, (float) $customer->fresh()->balance);
    }
}
