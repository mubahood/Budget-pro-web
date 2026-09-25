<?php

namespace Tests\Feature\Reports;

use App\Models\FinancialPeriod;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Services\Shop\SaleService;
use App\Services\Shop\StockService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminTestCase;

/** A Kampala shop with two products (Rice 4,000 → 5,000; Soap 600 → 1,000). */
abstract class ReportsTestCase extends AdminTestCase
{
    protected int $companyId;

    protected int $userId;

    protected StockItem $rice;

    protected StockItem $soap;

    protected function setUp(): void
    {
        parent::setUp();
        $t = $this->makeTenant('company');
        $t['company']->forceFill(['timezone' => 'Africa/Kampala'])->saveQuietly();
        $this->companyId = (int) $t['company']->id;
        $this->userId = (int) $t['user']->id;
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $this->companyId, 'status' => 'Active'],
            ['name' => 'FY', 'start_date' => now()->subYear()->startOfYear(), 'end_date' => now()->addYear()->endOfYear()]);
        $food = StockCategory::create(['company_id' => $this->companyId, 'name' => 'Food']);
        $dry = StockSubCategory::create(['company_id' => $this->companyId, 'stock_category_id' => $food->id, 'name' => 'Dry', 'measurement_unit' => 'kg']);
        $home = StockCategory::create(['company_id' => $this->companyId, 'name' => 'Home']);
        $wash = StockSubCategory::create(['company_id' => $this->companyId, 'stock_category_id' => $home->id, 'name' => 'Wash', 'measurement_unit' => 'pcs']);
        $this->rice = $this->product('Rice', $food->id, $dry->id, 4000, 5000);
        $this->soap = $this->product('Soap', $home->id, $wash->id, 600, 1000);
    }

    protected function product(string $name, int $catId, int $subId, float $buy, float $sell): StockItem
    {
        return StockItem::create(['company_id' => $this->companyId, 'created_by_id' => $this->userId, 'stock_category_id' => $catId, 'stock_sub_category_id' => $subId,
            'name' => $name, 'sku' => $name.'-'.uniqid(), 'buying_price' => $buy, 'selling_price' => $sell, 'original_quantity' => 100]);
    }

    /** @param array<int, array{0: StockItem, 1: float}> $lines */
    protected function sell(array $lines, float $paid, array $extra = []): SaleRecord
    {
        $items = array_map(fn ($l) => ['stock_item_id' => $l[0]->id, 'quantity' => $l[1]], $lines);
        $payments = $paid > 0 ? [['method' => $extra['method'] ?? 'cash', 'amount' => $paid]] : [];
        unset($extra['method']);

        return (new SaleService())->checkout($this->companyId, $this->userId, $extra + ['items' => $items, 'payments' => $payments])['sale'];
    }

    /** What the old app records: a bare Sale movement with no sale document. */
    protected function oldAppSale(StockItem $p, float $qty): \App\Models\StockRecord
    {
        return (new StockService())->record(['stock_item_id' => $p->id, 'type' => 'Sale', 'quantity' => $qty, 'selling_price' => (float) $p->selling_price, 'created_by_id' => $this->userId]);
    }

    protected function expense(float $amount, ?string $sourceType = null, bool $deleted = false): int
    {
        $categoryId = DB::table('financial_categories')->insertGetId(['company_id' => $this->companyId, 'name' => 'Exp '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $r = new \App\Models\FinancialRecord();
        $r->financial_category_id = $categoryId;
        $r->company_id = $this->companyId;
        $r->user_id = $this->userId;
        $r->created_by_id = $this->userId;
        $r->amount = $amount;
        $r->quantity = 1;
        $r->type = 'Expense';
        $r->payment_method = 'cash';
        $r->recipient = '';
        $r->description = 'test expense';
        $r->receipt = '';
        $r->date = now();
        $r->source_type = $sourceType;
        $r->save();
        if ($deleted) {
            DB::table('financial_records')->where('id', $r->id)->update(['is_deleted' => 1]);
        }

        return (int) $r->id;
    }
}
