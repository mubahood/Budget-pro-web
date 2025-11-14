<?php

/**
 * Quick Test Script for Sale Record Stock Deduction
 * 
 * Usage: php test-sale-deduction.php
 * 
 * This script creates a test sale and verifies stock is deducted only once.
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\Company;
use App\Models\StockItem;
use App\Models\SaleRecord;
use App\Models\SaleRecordItem;
use App\Models\StockRecord;
use App\Models\FinancialPeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

echo "\n========================================\n";
echo "SALE RECORD STOCK DEDUCTION TEST\n";
echo "========================================\n\n";

try {
    // Find first active company
    $company = Company::where('status', 'Active')->first();
    
    if (!$company) {
        echo "❌ ERROR: No active company found. Please create a company first.\n";
        exit(1);
    }
    
    echo "✓ Using company: {$company->name}\n";
    
    // Find company owner
    $user = User::where('company_id', $company->id)->first();
    
    if (!$user) {
        echo "❌ ERROR: No user found for company. Please create a user first.\n";
        exit(1);
    }
    
    echo "✓ Using user: {$user->name}\n";
    
    // Authenticate user
    Auth::login($user);
    
    // Find an active financial period
    $financialPeriod = FinancialPeriod::where('company_id', $company->id)
        ->where('status', 'Active')
        ->first();
    
    if (!$financialPeriod) {
        echo "❌ ERROR: No active financial period found.\n";
        exit(1);
    }
    
    echo "✓ Using financial period: {$financialPeriod->name}\n\n";
    
    // Find a stock item with sufficient quantity
    $stockItem = StockItem::where('company_id', $company->id)
        ->where('current_quantity', '>', 10)
        ->first();
    
    if (!$stockItem) {
        echo "❌ ERROR: No stock item with sufficient quantity found.\n";
        echo "   Please create a stock item with at least 10 units.\n";
        exit(1);
    }
    
    echo "✓ Found stock item: {$stockItem->name}\n";
    echo "  SKU: {$stockItem->sku}\n";
    echo "  Current Quantity: {$stockItem->current_quantity}\n\n";
    
    // Store initial quantity
    $initialQuantity = $stockItem->current_quantity;
    $saleQuantity = 6;
    $expectedFinalQuantity = $initialQuantity - $saleQuantity;
    
    echo "📊 TEST SCENARIO:\n";
    echo "  Initial Stock: {$initialQuantity} units\n";
    echo "  Sale Quantity: {$saleQuantity} units\n";
    echo "  Expected Final: {$expectedFinalQuantity} units\n\n";
    
    echo "🔄 Creating sale record...\n";
    
    // Start transaction
    DB::beginTransaction();
    
    try {
        // Create sale record
        $saleRecord = SaleRecord::create([
            'company_id' => $company->id,
            'financial_period_id' => $financialPeriod->id,
            'created_by_id' => $user->id,
            'sale_date' => now(),
            'customer_name' => 'TEST CUSTOMER - ' . now()->format('Y-m-d H:i:s'),
            'amount_paid' => 0,
            'payment_method' => 'Cash',
            'status' => 'Completed',
        ]);
        
        echo "✓ Sale record created (ID: {$saleRecord->id})\n";
        
        // Create sale item
        $saleItem = SaleRecordItem::create([
            'sale_record_id' => $saleRecord->id,
            'stock_item_id' => $stockItem->id,
            'quantity' => $saleQuantity,
            'unit_price' => $stockItem->selling_price,
        ]);
        
        echo "✓ Sale item created (Qty: {$saleQuantity})\n";
        
        // Process the sale
        echo "🔄 Processing sale...\n";
        $result = $saleRecord->processAndCompute();
        
        if (!$result['success']) {
            throw new Exception($result['message']);
        }
        
        echo "✓ Sale processed successfully\n\n";
        
        // Refresh stock item
        $stockItem->refresh();
        
        // Check final quantity
        $actualFinalQuantity = $stockItem->current_quantity;
        
        echo "📊 RESULTS:\n";
        echo "  Initial Stock: {$initialQuantity} units\n";
        echo "  Sale Quantity: {$saleQuantity} units\n";
        echo "  Expected Final: {$expectedFinalQuantity} units\n";
        echo "  Actual Final: {$actualFinalQuantity} units\n\n";
        
        // Verify result
        if ($actualFinalQuantity == $expectedFinalQuantity) {
            echo "✅ TEST PASSED! Stock deducted correctly (only once)\n";
            
            // Verify stock record
            $stockRecord = StockRecord::where('stock_item_id', $stockItem->id)
                ->where('sale_record_id', $saleRecord->id)
                ->first();
            
            if ($stockRecord) {
                echo "✓ Stock record created: Type={$stockRecord->type}, Qty={$stockRecord->quantity}\n";
            } else {
                echo "⚠️  WARNING: Stock record not found\n";
            }
            
        } else if ($actualFinalQuantity == ($initialQuantity - ($saleQuantity * 2))) {
            echo "❌ TEST FAILED! Stock deducted TWICE (double deduction bug)\n";
            echo "   Expected: {$expectedFinalQuantity}, Got: {$actualFinalQuantity}\n";
            DB::rollBack();
            exit(1);
        } else {
            echo "❌ TEST FAILED! Unexpected stock quantity\n";
            echo "   Expected: {$expectedFinalQuantity}, Got: {$actualFinalQuantity}\n";
            DB::rollBack();
            exit(1);
        }
        
        // Test deletion and restoration
        echo "\n🔄 Testing sale deletion and stock restoration...\n";
        
        $saleRecord->delete();
        
        $stockItem->refresh();
        $restoredQuantity = $stockItem->current_quantity;
        
        echo "  Stock after deletion: {$restoredQuantity} units\n";
        
        if ($restoredQuantity == $initialQuantity) {
            echo "✅ Stock restored correctly!\n";
        } else {
            echo "❌ Stock restoration failed!\n";
            echo "   Expected: {$initialQuantity}, Got: {$restoredQuantity}\n";
            DB::rollBack();
            exit(1);
        }
        
        // Rollback to cleanup test data
        DB::rollBack();
        echo "\n✓ Test data cleaned up (transaction rolled back)\n";
        
    } catch (Exception $e) {
        DB::rollBack();
        throw $e;
    }
    
    echo "\n========================================\n";
    echo "✅ ALL TESTS PASSED!\n";
    echo "========================================\n\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR: {$e->getMessage()}\n";
    echo "   File: {$e->getFile()}:{$e->getLine()}\n\n";
    exit(1);
}
