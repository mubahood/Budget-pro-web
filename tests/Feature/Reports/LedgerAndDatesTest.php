<?php

namespace Tests\Feature\Reports;

use App\Admin\Widgets\SalesAnalyticsWidget;
use App\Models\Customer;
use App\Models\FinancialRecord;
use App\Models\Payment;
use App\Models\SaleRecord;
use App\Services\Shop\CustomerService;
use App\Services\Shop\PaymentService;
use App\Services\Shop\SaleService;
use App\Services\Shop\ShiftService;
use App\Support\LocalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LedgerAndDatesTest extends ReportsTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Income row the pre-Phase-0 code posted for every sale movement (source_type stock_record). */
    private function legacyIncome(SaleRecord $sale): void
    {
        $category = (new PaymentService())->salesCategory($this->companyId);
        foreach (DB::table('stock_records')->where('sale_record_id', $sale->id)->where('is_reversal', 0)->get() as $m) {
            $r = new FinancialRecord();
            $r->financial_category_id = $category->id;
            $r->company_id = $this->companyId;
            $r->user_id = $this->userId;
            $r->created_by_id = $this->userId;
            $r->amount = $m->total_sales;
            $r->quantity = $m->quantity;
            $r->type = 'Income';
            $r->payment_method = 'cash';
            $r->recipient = '';
            $r->receipt = '';
            $r->description = 'Sales of #'.$m->id;
            $r->date = $m->date;
            $r->source_type = 'stock_record';
            $r->source_id = $m->id;
            $r->save();
        }
    }

    /** A sale as it looked before payment rows existed: amount_paid set, no payments, income booked per movement. */
    private function historicSale(float $paid, ?int $customerId = null): SaleRecord
    {
        $sale = $this->sell([[$this->rice, 1], [$this->soap, 5]], 0, $customerId ? ['customer_id' => $customerId] : []); // 5,000 + 5,000
        $this->legacyIncome($sale);
        DB::table('sale_records')->where('id', $sale->id)->update(['amount_paid' => $paid, 'balance' => 10000 - $paid, 'payment_status' => $paid >= 10000 ? 'Paid' : 'Partial']);

        return SaleRecord::withoutGlobalScopes()->find($sale->id);
    }

    private function saleIncome(SaleRecord $sale): float
    {
        $viaMovements = (float) DB::table('financial_records as f')->join('stock_records as m', 'm.id', '=', 'f.source_id')
            ->where('f.source_type', 'stock_record')->where('m.sale_record_id', $sale->id)->sum('f.amount');
        $viaPayments = (float) DB::table('financial_records as f')->join('payments as p', 'p.financial_record_id', '=', 'f.id')
            ->where('p.sale_record_id', $sale->id)->sum('f.amount');

        return round($viaMovements + $viaPayments, 2);
    }

    public function test_voiding_an_old_sale_reverses_the_income_its_movements_posted(): void
    {
        $sale = $this->historicSale(10000);
        $this->assertEquals(10000, $this->saleIncome($sale));

        (new SaleService())->void($sale, 'duplicate', $this->userId);

        $this->assertEquals(0, $this->saleIncome($sale), 'legacy income taken back');
        $contras = FinancialRecord::withoutGlobalScopes()->where('source_type', 'stock_record')->where('is_reversal', true)->whereNotNull('reverses_id')
            ->where('company_id', $this->companyId)->get();
        $this->assertCount(2, $contras);
        $sale->refresh();
        $this->assertEquals(0, (float) $sale->amount_paid, 'money taken at the till is reversed with the sale');

        (new SaleService())->void($sale->fresh(), 'again', $this->userId);
        $this->assertEquals(0, $this->saleIncome($sale), 'voiding twice changes nothing');
    }

    public function test_a_payment_on_an_old_sale_does_not_book_its_income_twice(): void
    {
        $customer = Customer::create(['company_id' => $this->companyId, 'name' => 'Okello', 'phone' => '0701000222', 'created_by_id' => $this->userId]);
        $sale = $this->historicSale(4000, $customer->id);

        $statement = (new CustomerService())->statement($customer);
        $this->assertContains('Paid at sale', array_column($statement['entries'], 'description'));
        $this->assertEquals(6000, $statement['closing_balance'], 'what was paid at the till is credited');

        $payment = (new SaleService())->addPayment($sale, ['amount' => 6000, 'method' => 'cash'], $this->userId);

        $this->assertNull($payment->financial_record_id, 'income for this sale was booked when it was sold');
        $this->assertEquals(10000, $this->saleIncome($sale));
        $sale->refresh();
        $this->assertEquals(10000, (float) $sale->amount_paid, 'money paid at the till is not forgotten');
        $this->assertEquals(0, (float) $sale->balance);
        $this->assertSame('Paid', $sale->payment_status);
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('sale_record_id', $sale->id)->where('notes', PaymentService::PAID_AT_SALE)->count());

        $statement = (new CustomerService())->statement($customer);
        $this->assertEquals(0, $statement['closing_balance']);
        $this->assertSame(1, count(array_filter($statement['entries'], fn ($e) => $e['description'] === 'Paid at sale')), 'paid-at-sale shown once');
    }

    public function test_a_new_sale_still_books_income_per_payment(): void
    {
        $sale = $this->sell([[$this->rice, 2]], 4000);
        (new SaleService())->addPayment($sale, ['amount' => 6000, 'method' => 'cash'], $this->userId);
        $this->assertEquals(10000, $this->saleIncome($sale));
    }

    public function test_a_payment_reversal_lands_in_the_same_shift(): void
    {
        $shift = (new ShiftService())->open($this->companyId, $this->userId, 0);
        $sale = $this->sell([[$this->rice, 1]], 5000, ['shift_id' => $shift->id]);
        $this->assertEquals(5000, (new ShiftService())->totals($shift)['cash_in']);

        $contra = (new PaymentService())->reverse($sale->payments->first(), 'wrong till', $this->userId);

        $this->assertSame((int) $shift->id, (int) $contra->shift_id);
        $this->assertEquals(0, (new ShiftService())->totals($shift)['cash_in']);
    }

    public function test_a_sale_at_half_past_one_in_kampala_is_dated_that_kampala_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 22:30:00', 'UTC')); // 01:30 on the 25th in Kampala

        $sale = $this->sell([[$this->rice, 1]], 5000);
        $this->assertSame('2026-09-25', $sale->fresh()->sale_date->toDateString());
        $this->assertSame('2026-09-25', (string) DB::table('stock_records')->where('sale_record_id', $sale->id)->value('date'));

        $plain = new SaleRecord(['company_id' => $this->companyId, 'created_by_id' => $this->userId, 'customer_name' => 'Walk-in']);
        $plain->save();
        $this->assertSame('2026-09-25', $plain->fresh()->sale_date->toDateString(), 'model default is the local day');

        $this->assertSame('2026-09-25', LocalDate::fromMs($this->companyId, Carbon::now()->getTimestampMs())->toDateString());
        $this->assertSame('2026-09-25', LocalDate::date($this->companyId, '2026-09-25')->toDateString());

        $o = (new SalesAnalyticsWidget())->data($this->companyId)['overview']['today'];
        $this->assertSame(1, $o['transactions']);
        $this->assertEquals(5000, $o['revenue']);
    }

    public function test_an_offline_sale_synced_later_keeps_its_local_day(): void
    {
        $sale = (new SaleService())->checkout($this->companyId, $this->userId, [
            'items' => [['stock_item_id' => $this->soap->id, 'quantity' => 1]], 'payments' => [],
            'sale_date' => LocalDate::fromMs($this->companyId, Carbon::parse('2026-09-24 22:30:00', 'UTC')->getTimestampMs()),
        ])['sale'];
        $this->assertSame('2026-09-25', $sale->sale_date->toDateString());
    }
}
