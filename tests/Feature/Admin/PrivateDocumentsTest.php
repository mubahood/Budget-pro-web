<?php

namespace Tests\Feature\Admin;

use App\Models\FinancialPeriod;
use App\Models\FinancialReport;
use Illuminate\Support\Facades\Auth;

/** Shop documents never land in public storage under guessable names. */
class PrivateDocumentsTest extends AdminTestCase
{
    public function test_financial_reports_get_their_own_unguessable_file_and_sale_pdfs_are_streamed_only(): void
    {
        $t = $this->makeTenant('company');
        Auth::guard('admin')->login($t['user']);
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $t['company']->id, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);

        $make = fn () => FinancialReport::create(['company_id' => $t['company']->id, 'user_id' => $t['user']->id, 'type' => 'Financial', 'period_type' => 'This Month', 'currency' => 'UGX']);
        $a = $make();
        $b = $make();
        $this->assertMatchesRegularExpression('/^files\/report-'.$a->id.'-[A-Za-z0-9]{24}\.pdf$/', (string) $a->fresh()->file);
        $this->assertNotSame($a->fresh()->file, $b->fresh()->file);
        $this->assertFileExists(public_path('storage/'.$a->fresh()->file));
        @unlink(public_path('storage/'.$a->fresh()->file));
        @unlink(public_path('storage/'.$b->fresh()->file));

        $this->asAdmin($t['user'])->get('/financial-report?id='.$a->id)->assertOk()->assertHeader('content-type', 'application/pdf');

        $cat = \App\Models\StockCategory::create(['company_id' => $t['company']->id, 'name' => 'Elec']);
        $sub = \App\Models\StockSubCategory::create(['company_id' => $t['company']->id, 'stock_category_id' => $cat->id, 'name' => 'Bulbs', 'measurement_unit' => 'pcs']);
        $p = \App\Models\StockItem::create(['company_id' => $t['company']->id, 'created_by_id' => $t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Bulb', 'sku' => 'B-'.uniqid(), 'buying_price' => 3000, 'selling_price' => 5000, 'original_quantity' => 5]);
        $sale = (new \App\Services\Shop\SaleService())->checkout($t['company']->id, $t['user']->id, ['items' => [['stock_item_id' => $p->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 5000]], 'payments_explicit' => true])['sale'];
        @unlink(public_path('storage/files/invoice-'.$sale->id.'.pdf'));
        $this->asAdmin($t['user'])->get('/sale-invoice-pdf?id='.$sale->id)->assertOk();
        $this->asAdmin($t['user'])->get('/sale-receipt-pdf?id='.$sale->id)->assertOk();
        $this->assertFileDoesNotExist(public_path('storage/files/invoice-'.$sale->id.'.pdf'));
        $this->assertFileDoesNotExist(public_path('storage/files/receipt-'.$sale->id.'.pdf'));
    }
}
