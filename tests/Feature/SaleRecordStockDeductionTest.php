<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\FinancialPeriod;
use App\Models\SaleRecord;
use App\Models\SaleRecordItem;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockRecord;
use App\Models\StockSubCategory;
use App\Models\User;
use App\Services\Shop\SaleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SaleRecordStockDeductionTest extends TestCase
{
    // Uses DatabaseTransactions (not RefreshDatabase) against the pre-built
    // budget_pro_test schema: several historical migrations are neutered
    // (`return;`) which makes a real `migrate:fresh` fail — see
    // BACKEND_API_MASTER_TASKS.md "Repair migrations". Transactions roll back
    // after each test, so no data is persisted and no migration ever runs here.
    use DatabaseTransactions;

    protected $user;

    protected $company;

    protected $financialPeriod;

    protected $stockCategory;

    protected $stockSubCategory;

    protected $stockItem;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test company
        $this->company = Company::factory()->create([
            'name' => 'Test Company',
            'status' => 'Active',
        ]);

        // Create test user
        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        // Update company owner (owner_id is intentionally not mass-assignable;
        // set it directly, matching how the rest of the app writes it)
        $this->company->owner_id = $this->user->id;
        $this->company->save();

        // Authenticate user
        Auth::login($this->user);

        // Create financial period
        $this->financialPeriod = FinancialPeriod::create([
            'company_id' => $this->company->id,
            'name' => 'Test Period 2025',
            'start_date' => now()->startOfYear(),
            'end_date' => now()->endOfYear(),
            'status' => 'Active',
        ]);

        // Create stock category
        $this->stockCategory = StockCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Electronics',
        ]);

        // Create stock sub-category
        $this->stockSubCategory = StockSubCategory::create([
            'company_id' => $this->company->id,
            'stock_category_id' => $this->stockCategory->id,
            'name' => 'Laptops',
            'measurement_unit' => 'pieces',
        ]);

        // Create stock item with 100 units
        $this->stockItem = StockItem::create([
            'company_id' => $this->company->id,
            'created_by_id' => $this->user->id,
            'stock_category_id' => $this->stockCategory->id,
            'stock_sub_category_id' => $this->stockSubCategory->id,
            'financial_period_id' => $this->financialPeriod->id,
            'name' => 'Dell Laptop',
            'sku' => 'DELL-001',
            'buying_price' => 500.00,
            'selling_price' => 800.00,
            'original_quantity' => 100,
            'current_quantity' => 100, // Will be set automatically
        ]);
    }

    /**
     * Test that stock is deducted ONLY ONCE when creating a sale
     */
    public function test_stock_deduction_happens_only_once()
    {
        // Verify initial stock
        $this->assertEquals(100, $this->stockItem->fresh()->current_quantity);

        // Create a sale record
        $saleRecord = SaleRecord::create([
            'company_id' => $this->company->id,
            'financial_period_id' => $this->financialPeriod->id,
            'created_by_id' => $this->user->id,
            'sale_date' => now(),
            'customer_name' => 'John Doe',
            'amount_paid' => 0,
            'payment_method' => 'Cash',
            'status' => 'Completed',
        ]);

        // Create a sale item (selling 6 units)
        $saleItem = SaleRecordItem::create([
            'sale_record_id' => $saleRecord->id,
            'stock_item_id' => $this->stockItem->id,
            'quantity' => 6,
            'unit_price' => 800.00,
        ]);

        // Process the sale (this should trigger stock deduction)
        $result = $saleRecord->processAndCompute();

        // Assert processing was successful
        $this->assertTrue($result['success'], $result['message'] ?? 'Processing failed');

        // Refresh stock item
        $this->stockItem->refresh();

        // CRITICAL TEST: Stock should be reduced by EXACTLY 6 units, not 12
        $this->assertEquals(94, $this->stockItem->current_quantity,
            "Stock was deducted incorrectly. Expected 94 (100-6), but got {$this->stockItem->current_quantity}");

        // Verify stock record was created
        $stockRecord = StockRecord::where('stock_item_id', $this->stockItem->id)
            ->where('type', 'Sale')
            ->first();

        $this->assertNotNull($stockRecord, 'Stock record was not created');
        $this->assertEquals(6, $stockRecord->quantity);
    }

    /**
     * Test multiple items in one sale
     */
    public function test_multiple_items_sale_deduction()
    {
        // Create another stock item
        $stockItem2 = StockItem::create([
            'company_id' => $this->company->id,
            'created_by_id' => $this->user->id,
            'stock_category_id' => $this->stockCategory->id,
            'stock_sub_category_id' => $this->stockSubCategory->id,
            'financial_period_id' => $this->financialPeriod->id,
            'name' => 'HP Laptop',
            'sku' => 'HP-001',
            'buying_price' => 450.00,
            'selling_price' => 750.00,
            'original_quantity' => 50,
        ]);

        // Create sale with multiple items
        $saleRecord = SaleRecord::create([
            'company_id' => $this->company->id,
            'financial_period_id' => $this->financialPeriod->id,
            'created_by_id' => $this->user->id,
            'sale_date' => now(),
            'customer_name' => 'Jane Doe',
            'amount_paid' => 2300,
            'payment_method' => 'Cash',
            'status' => 'Completed',
        ]);

        // Item 1: Sell 6 Dell laptops
        SaleRecordItem::create([
            'sale_record_id' => $saleRecord->id,
            'stock_item_id' => $this->stockItem->id,
            'quantity' => 6,
            'unit_price' => 800.00,
        ]);

        // Item 2: Sell 3 HP laptops
        SaleRecordItem::create([
            'sale_record_id' => $saleRecord->id,
            'stock_item_id' => $stockItem2->id,
            'quantity' => 3,
            'unit_price' => 750.00,
        ]);

        // Process the sale
        $result = $saleRecord->processAndCompute();

        $this->assertTrue($result['success']);

        // Verify Dell laptop stock
        $this->assertEquals(94, $this->stockItem->fresh()->current_quantity);

        // Verify HP laptop stock
        $this->assertEquals(47, $stockItem2->fresh()->current_quantity);
    }

    /**
     * Sales are never deleted (audit trail): voiding reverses the stock movement exactly once.
     */
    public function test_voiding_a_sale_restores_stock_once()
    {
        $saleRecord = SaleRecord::create([
            'company_id' => $this->company->id,
            'financial_period_id' => $this->financialPeriod->id,
            'created_by_id' => $this->user->id,
            'sale_date' => now(),
            'customer_name' => 'Test Customer',
            'amount_paid' => 0,
            'payment_method' => 'Cash',
            'status' => 'Completed',
        ]);

        SaleRecordItem::create([
            'sale_record_id' => $saleRecord->id,
            'stock_item_id' => $this->stockItem->id,
            'quantity' => 10,
            'unit_price' => 800.00,
        ]);

        $saleRecord->processAndCompute();
        $this->assertEquals(90, $this->stockItem->fresh()->current_quantity);

        // Hard delete is refused.
        try {
            $saleRecord->delete();
            $this->fail('Deleting a sale must be refused');
        } catch (BusinessRuleException $e) {
            $this->assertSame('delete_not_allowed', $e->errorCode());
        }
        $this->assertEquals(90, $this->stockItem->fresh()->current_quantity);

        // Void reverses the movement once; voiding again is a no-op.
        (new SaleService())->void($saleRecord->fresh(), 'test', $this->user->id);
        $this->assertEquals(100, $this->stockItem->fresh()->current_quantity, 'Stock was not restored by the void');
        (new SaleService())->void($saleRecord->fresh(), 'test again', $this->user->id);
        $this->assertEquals(100, $this->stockItem->fresh()->current_quantity, 'Voiding twice must not double-restore');

        $this->assertNotNull($saleRecord->fresh()->voided_at);
        $this->assertEquals(2, StockRecord::where('sale_record_id', $saleRecord->id)->count(), 'original + contra movement are both kept');
    }

    /**
     * Test insufficient stock error
     */
    public function test_insufficient_stock_prevents_sale()
    {
        // Try to sell more than available
        $saleRecord = SaleRecord::create([
            'company_id' => $this->company->id,
            'financial_period_id' => $this->financialPeriod->id,
            'created_by_id' => $this->user->id,
            'sale_date' => now(),
            'customer_name' => 'Test Customer',
            'amount_paid' => 0,
            'payment_method' => 'Cash',
            'status' => 'Completed',
        ]);

        SaleRecordItem::create([
            'sale_record_id' => $saleRecord->id,
            'stock_item_id' => $this->stockItem->id,
            'quantity' => 150, // More than available (100)
            'unit_price' => 800.00,
        ]);

        $result = $saleRecord->processAndCompute();

        // Should fail
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Insufficient', $result['message']);

        // Stock should remain unchanged
        $this->assertEquals(100, $this->stockItem->fresh()->current_quantity);
    }

    /**
     * Test manual stock quantity change is prevented
     */
    public function test_manual_stock_quantity_change_is_prevented()
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Current quantity cannot be changed manually');

        // Try to manually change stock quantity
        $this->stockItem->current_quantity = 50;
        $this->stockItem->save();
    }
}
