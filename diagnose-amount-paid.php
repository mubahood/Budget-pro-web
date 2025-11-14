<?php

/**
 * Diagnostic script to check amount_paid display issue
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\SaleRecord;
use Illuminate\Support\Facades\Auth;

echo "\n========================================\n";
echo "AMOUNT_PAID DIAGNOSTIC\n";
echo "========================================\n\n";

try {
    // Get recent sale records
    $sales = SaleRecord::orderBy('id', 'desc')
        ->take(10)
        ->get();
    
    echo "Testing SaleRecord with relationships:\n";
    foreach ($sales as $sale) {
        echo sprintf(
            "ID: %d | Receipt: %s | Total: %s | Paid: %s | Balance: %s\n",
            $sale->id,
            $sale->receipt_number,
            $sale->total_amount,
            $sale->amount_paid,
            $sale->balance
        );
    }
    
    echo "\n" . str_repeat("-", 60) . "\n\n";
    
    // Test with explicit select (like in grid)
    echo "Testing with explicit select (like grid controller):\n";
    $salesSelect = SaleRecord::select([
            'id',
            'receipt_number',
            'total_amount',
            'amount_paid',
            'balance'
        ])
        ->orderBy('id', 'desc')
        ->take(10)
        ->get();
    
    foreach ($salesSelect as $sale) {
        echo sprintf(
            "ID: %d | Receipt: %s | Total: %s | Paid: %s | Balance: %s\n",
            $sale->id,
            $sale->receipt_number,
            $sale->total_amount,
            $sale->amount_paid,
            $sale->balance
        );
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "✅ Diagnostic complete!\n\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR: {$e->getMessage()}\n";
    echo "   File: {$e->getFile()}:{$e->getLine()}\n\n";
    exit(1);
}
