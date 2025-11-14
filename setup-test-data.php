<?php

/**
 * Setup Test Data for Sale Record Testing
 * 
 * This script creates all necessary data to test the sale record deduction fix.
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\Company;
use App\Models\StockItem;
use App\Models\StockCategory;
use App\Models\StockSubCategory;
use App\Models\FinancialPeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

echo "\n========================================\n";
echo "SETUP TEST DATA\n";
echo "========================================\n\n";

try {
    // Find first active company
    $company = Company::where('status', 'Active')->first();
    
    if (!$company) {
        echo "Creating test company...\n";
        $company = Company::create([
            'name' => 'Test Company Ltd',
            'status' => 'Active',
            'address' => 'Test Address',
            'phone' => '1234567890',
        ]);
    }
    
    echo "✓ Using company: {$company->name}\n";
    
    // Find or create user
    $user = User::where('company_id', $company->id)->first();
    
    if (!$user) {
        echo "Creating test user...\n";
        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Test User',
            'email' => 'test@budgetpro.com',
            'password' => bcrypt('password'),
            'user_type' => 'admin',
        ]);
        
        $company->update(['owner_id' => $user->id]);
    }
    
    echo "✓ Using user: {$user->name} ({$user->email})\n";
    
    // Authenticate user
    Auth::login($user);
    
    // Create or find financial period
    $financialPeriod = FinancialPeriod::where('company_id', $company->id)
        ->where('status', 'Active')
        ->first();
    
    if (!$financialPeriod) {
        echo "Creating financial period...\n";
        $financialPeriod = FinancialPeriod::create([
            'company_id' => $company->id,
            'name' => 'Financial Year ' . date('Y'),
            'start_date' => date('Y') . '-01-01',
            'end_date' => date('Y') . '-12-31',
            'status' => 'Active',
        ]);
    }
    
    echo "✓ Financial period: {$financialPeriod->name}\n";
    
    // Create stock category
    $category = StockCategory::where('company_id', $company->id)->first();
    
    if (!$category) {
        echo "Creating stock category...\n";
        $category = StockCategory::create([
            'company_id' => $company->id,
            'name' => 'Electronics',
            'description' => 'Electronic items',
        ]);
    }
    
    echo "✓ Stock category: {$category->name}\n";
    
    // Create stock sub-category
    $subCategory = StockSubCategory::where('company_id', $company->id)
        ->where('stock_category_id', $category->id)
        ->first();
    
    if (!$subCategory) {
        echo "Creating stock sub-category...\n";
        $subCategory = StockSubCategory::create([
            'company_id' => $company->id,
            'stock_category_id' => $category->id,
            'name' => 'Laptops',
            'description' => 'Laptop computers',
            'measurement_unit' => 'pieces',
        ]);
    }
    
    echo "✓ Stock sub-category: {$subCategory->name}\n";
    
    // Create test stock items
    echo "\nCreating stock items...\n";
    
    $items = [
        ['name' => 'Dell Inspiron 15', 'sku' => 'DELL-INS-15', 'qty' => 100, 'buy' => 500, 'sell' => 800],
        ['name' => 'HP Pavilion 14', 'sku' => 'HP-PAV-14', 'qty' => 50, 'buy' => 450, 'sell' => 750],
        ['name' => 'Lenovo ThinkPad X1', 'sku' => 'LEN-X1', 'qty' => 30, 'buy' => 800, 'sell' => 1200],
    ];
    
    foreach ($items as $itemData) {
        $existing = StockItem::where('company_id', $company->id)
            ->where('sku', $itemData['sku'])
            ->first();
        
        if (!$existing) {
            $item = StockItem::create([
                'company_id' => $company->id,
                'created_by_id' => $user->id,
                'stock_category_id' => $category->id,
                'stock_sub_category_id' => $subCategory->id,
                'financial_period_id' => $financialPeriod->id,
                'name' => $itemData['name'],
                'sku' => $itemData['sku'],
                'buying_price' => $itemData['buy'],
                'selling_price' => $itemData['sell'],
                'original_quantity' => $itemData['qty'],
            ]);
            echo "  ✓ Created: {$item->name} (SKU: {$item->sku}, Qty: {$item->current_quantity})\n";
        } else {
            echo "  ✓ Exists: {$existing->name} (SKU: {$existing->sku}, Qty: {$existing->current_quantity})\n";
        }
    }
    
    echo "\n========================================\n";
    echo "✅ TEST DATA SETUP COMPLETE!\n";
    echo "========================================\n";
    echo "\nYou can now run: php test-sale-deduction.php\n\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR: {$e->getMessage()}\n";
    echo "   File: {$e->getFile()}:{$e->getLine()}\n\n";
    exit(1);
}
