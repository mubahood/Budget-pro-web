<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\FinancialPeriod;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockRecord;
use App\Models\StockSubCategory;
use App\Models\User;
use App\Services\Shop\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan §A2: 20 parallel checkouts against stock 10 → exactly 10 succeed, stock ends at 0,
 * never negative. Real processes (pcntl_fork), real MySQL row locks, no wrapping transaction.
 * Rows are created outside DatabaseTransactions and removed in tearDown.
 */
class ConcurrentCheckoutTest extends TestCase
{
    private ?int $companyId = null;

    protected function tearDown(): void
    {
        if ($this->companyId) {
            DB::reconnect();
            foreach (['payments', 'financial_records', 'sale_record_items', 'sale_records', 'stock_records', 'stock_items', 'stock_sub_categories', 'stock_categories', 'financial_categories', 'financial_periods', 'number_sequences', 'company_members'] as $table) {
                DB::table($table)->where('company_id', $this->companyId)->delete();
            }
            DB::table((new User())->getTable())->where('company_id', $this->companyId)->delete();
            DB::table('companies')->where('id', $this->companyId)->delete();
        }
        parent::tearDown();
    }

    public function test_twenty_parallel_checkouts_never_oversell(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }

        $company = Company::factory()->create(['name' => 'Concurrency Co', 'status' => 'Active']);
        $this->companyId = $company->id;
        $user = User::factory()->create(['company_id' => $company->id, 'email' => 'conc_'.uniqid().'@example.com', 'password' => bcrypt('secret123')]);
        $company->owner_id = $user->id;
        $company->save();
        FinancialPeriod::create(['company_id' => $company->id, 'name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear(), 'status' => 'Active']);
        $cat = StockCategory::create(['company_id' => $company->id, 'name' => 'C']);
        $sub = StockSubCategory::create(['company_id' => $company->id, 'stock_category_id' => $cat->id, 'name' => 'S', 'measurement_unit' => 'pcs']);
        $item = StockItem::create([
            'company_id' => $company->id, 'created_by_id' => $user->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Hot item', 'sku' => 'HOT-'.uniqid(), 'buying_price' => 5, 'selling_price' => 10, 'original_quantity' => 10,
        ]);

        // Parent hands its connection over: each child must open its own socket.
        DB::disconnect();

        $pids = [];
        for ($i = 0; $i < 20; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                // child
                DB::reconnect();
                $code = 2;
                try {
                    (new SaleService())->checkout($company->id, $user->id, [
                        'amount_paid' => 10,
                        'items' => [['stock_item_id' => $item->id, 'quantity' => 1]],
                    ]);
                    $code = 0;
                } catch (BusinessRuleException $e) {
                    $code = $e->errorCode() === 'insufficient_stock' ? 1 : 2;
                } catch (\Throwable $e) {
                    fwrite(STDERR, 'child error: '.get_class($e).' '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine()."\n".implode("\n", array_slice(array_filter(explode("\n", $e->getTraceAsString()), fn ($l) => str_contains($l, '/app/')), 0, 6))."\n");
                    $code = 2;
                }
                DB::disconnect();
                exit($code);
            }
            $pids[] = $pid;
        }

        $ok = $rejected = $errors = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            match (pcntl_wexitstatus($status)) {
                0 => $ok++,
                1 => $rejected++,
                default => $errors++,
            };
        }

        DB::reconnect();
        $this->assertSame(0, $errors, 'no unexpected errors in children');
        $this->assertSame(10, $ok, 'exactly the available stock is sold');
        $this->assertSame(10, $rejected, 'the rest are rejected with insufficient_stock');
        $this->assertSame('0.000', (string) DB::table('stock_items')->where('id', $item->id)->value('current_quantity'));
        $this->assertSame(10, SaleRecord::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(10, StockRecord::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $receipts = SaleRecord::withoutGlobalScopes()->where('company_id', $company->id)->pluck('receipt_number');
        $this->assertSame(10, $receipts->unique()->count(), 'receipt numbers are unique under contention');
        $this->assertSame(100.0, (float) DB::table('financial_records')->where('company_id', $company->id)->sum('amount'));
    }
}
