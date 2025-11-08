<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Traits\AuditLogger;
use App\Scopes\CompanyScope;

class SaleRecord extends Model
{
    use HasFactory, AuditLogger;
    
    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
    
    /**
     * The relationships that should always be loaded.
     */
    protected $with = ['saleRecordItems', 'company', 'createdBy'];
    
    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'sale_date' => 'date',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
    ];
    
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'company_id',
        'financial_period_id',
        'created_by_id',
        'sale_date',
        'customer_name',
        'customer_phone',
        'customer_address',
        'total_amount',
        'amount_paid',
        'balance',
        'payment_method',
        'payment_status',
        'status',
        'receipt_number',
        'receipt_pdf_url',
        'receipt_pdf_is_generated',
        'invoice_number',
        'invoice_pdf_url',
        'invoice_pdf_is_generated',
        'notes',
    ];
    
    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();
        
        // Before creating a sale record
        static::creating(function ($saleRecord) {
            try {
                // Generate unique receipt and invoice numbers
                if (empty($saleRecord->receipt_number)) {
                    $saleRecord->receipt_number = $saleRecord->generateUniqueReceiptNumber();
                }
                
                if (empty($saleRecord->invoice_number)) {
                    $saleRecord->invoice_number = $saleRecord->generateUniqueInvoiceNumber();
                }
                
                // Auto-set created_by_id if not set
                if (empty($saleRecord->created_by_id)) {
                    $saleRecord->created_by_id = Auth::id();
                }
                
                // Validate financial period
                if (!empty($saleRecord->financial_period_id)) {
                    $financialPeriod = \App\Models\FinancialPeriod::find($saleRecord->financial_period_id);
                    if (!$financialPeriod) {
                        throw new \Exception('Invalid financial period.');
                    }
                    if ($financialPeriod->status != 'Active') {
                        throw new \Exception('Financial period is not active.');
                    }
                }
                
            } catch (\Exception $e) {
                Log::error('SaleRecord creating error: ' . $e->getMessage());
                throw $e;
            }
        });
        
        // After creating a sale record
        // Note: Processing is now handled by the explicit processAndCompute() method
        // called from the controller's saved() hook to ensure all items are saved first
        static::created(function ($saleRecord) {
            try {
                // Log the creation
                Log::info('SaleRecord created', [
                    'id' => $saleRecord->id,
                    'receipt_number' => $saleRecord->receipt_number,
                    'company_id' => $saleRecord->company_id
                ]);
                
                // Processing will be done by processAndCompute() method
                // This ensures proper transaction handling and error recovery
                
            } catch (\Exception $e) {
                Log::error('SaleRecord created event error: ' . $e->getMessage());
            }
        });
        
        // Before updating a sale record
        static::updating(function ($saleRecord) {
            try {
                // Check if payment status manually changed to "Paid"
                $paymentStatusChangedToPaid = $saleRecord->isDirty('payment_status') && 
                                             $saleRecord->payment_status == 'Paid' &&
                                             !$saleRecord->isDirty('amount_paid'); // Only if amount_paid not also changed
                
                if ($paymentStatusChangedToPaid) {
                    // Automatically set amount_paid = total_amount when marked as "Paid"
                    $saleRecord->amount_paid = $saleRecord->total_amount;
                    $saleRecord->balance = 0;
                    return; // Skip further calculations
                }
                
                // If amount_paid changed (or payment_status changed to something else), recalculate
                if ($saleRecord->isDirty('amount_paid') || $saleRecord->isDirty('payment_status')) {
                    $totalAmount = floatval($saleRecord->total_amount);
                    $amountPaid = floatval($saleRecord->amount_paid);
                    
                    // Calculate balance
                    $saleRecord->balance = $totalAmount - $amountPaid;
                    
                    // Auto-update payment status based on the payment (unless manually set)
                    if (!$saleRecord->isDirty('payment_status') || $saleRecord->payment_status != 'Paid') {
                        if ($saleRecord->balance <= 0) {
                            $saleRecord->payment_status = 'Paid';
                        } elseif ($amountPaid > 0) {
                            $saleRecord->payment_status = 'Partial';
                        } else {
                            $saleRecord->payment_status = 'Unpaid';
                        }
                    }
                }
                
            } catch (\Exception $e) {
                Log::error('SaleRecord updating event error: ' . $e->getMessage());
            }
        });
        
        // Before deleting a sale record
        static::deleting(function ($saleRecord) {
            try {
                // Restore stock quantities and delete stock records
                if ($saleRecord->saleRecordItems) {
                    foreach ($saleRecord->saleRecordItems as $item) {
                        // Restore stock quantity
                        $stockItem = \App\Models\StockItem::find($item->stock_item_id);
                        if ($stockItem) {
                            $stockItem->current_quantity += $item->quantity;
                            $stockItem->save();
                        }
                        
                        // Delete associated stock record
                        if ($item->stock_record_id) {
                            $stockRecord = \App\Models\StockRecord::find($item->stock_record_id);
                            if ($stockRecord) {
                                $stockRecord->delete();
                            }
                        }
                        
                        // Delete the sale record item
                        $item->delete();
                    }
                }
                
            } catch (\Exception $e) {
                Log::error('SaleRecord deleting error: ' . $e->getMessage());
                throw $e;
            }
        });
    }
    
    /**
     * Generate unique receipt number.
     * Format: RCP-{CompanyCode}-{YYYYMMDD}-{Sequence}
     */
    public function generateUniqueReceiptNumber()
    {
        $company = \App\Models\Company::find($this->company_id);
        $companyCode = $company ? strtoupper(substr($company->name, 0, 3)) : 'COM';
        $date = date('Ymd', strtotime($this->sale_date ?? now()));
        
        $maxAttempts = 10;
        for ($i = 1; $i <= $maxAttempts; $i++) {
            // Get the last receipt number for today
            $lastReceipt = self::where('company_id', $this->company_id)
                ->where('receipt_number', 'LIKE', "RCP-{$companyCode}-{$date}-%")
                ->orderBy('receipt_number', 'DESC')
                ->first();
            
            if ($lastReceipt && preg_match('/-(\d+)$/', $lastReceipt->receipt_number, $matches)) {
                $sequence = intval($matches[1]) + 1;
            } else {
                $sequence = 1;
            }
            
            $receiptNumber = sprintf("RCP-%s-%s-%04d", $companyCode, $date, $sequence);
            
            // Check if this receipt number already exists
            $exists = self::where('receipt_number', $receiptNumber)->exists();
            if (!$exists) {
                return $receiptNumber;
            }
        }
        
        // If all attempts failed, use a UUID suffix
        return sprintf("RCP-%s-%s-%s", $companyCode, $date, substr(uniqid(), -4));
    }
    
    /**
     * Generate unique invoice number.
     * Format: INV-{CompanyCode}-{YYYYMMDD}-{Sequence}
     */
    public function generateUniqueInvoiceNumber()
    {
        $company = \App\Models\Company::find($this->company_id);
        $companyCode = $company ? strtoupper(substr($company->name, 0, 3)) : 'COM';
        $date = date('Ymd', strtotime($this->sale_date ?? now()));
        
        $maxAttempts = 10;
        for ($i = 1; $i <= $maxAttempts; $i++) {
            // Get the last invoice number for today
            $lastInvoice = self::where('company_id', $this->company_id)
                ->where('invoice_number', 'LIKE', "INV-{$companyCode}-{$date}-%")
                ->orderBy('invoice_number', 'DESC')
                ->first();
            
            if ($lastInvoice && preg_match('/-(\d+)$/', $lastInvoice->invoice_number, $matches)) {
                $sequence = intval($matches[1]) + 1;
            } else {
                $sequence = 1;
            }
            
            $invoiceNumber = sprintf("INV-%s-%s-%04d", $companyCode, $date, $sequence);
            
            // Check if this invoice number already exists
            $exists = self::where('invoice_number', $invoiceNumber)->exists();
            if (!$exists) {
                return $invoiceNumber;
            }
        }
        
        // If all attempts failed, use a UUID suffix
        return sprintf("INV-%s-%s-%s", $companyCode, $date, substr(uniqid(), -4));
    }
    
    /**
     * Calculate total amount from sale items.
     */
    public function calculateTotals()
    {
        if ($this->saleRecordItems) {
            $this->total_amount = $this->saleRecordItems->sum('subtotal');
            $this->balance = $this->total_amount - ($this->amount_paid ?? 0);
            
            // Update payment status
            if ($this->balance <= 0) {
                $this->payment_status = 'Paid';
            } elseif ($this->amount_paid > 0) {
                $this->payment_status = 'Partial';
            } else {
                $this->payment_status = 'Unpaid';
            }
        }
    }
    
    /**
     * Process and compute everything for a sale record.
     * This is the main method called after form submission to:
     * 1. Validate all items and stock availability
     * 2. Calculate all amounts, profits, and totals
     * 3. Reduce stock quantities
     * 4. Create stock records for audit trail
     * 5. Update payment status
     * 6. Generate receipt and invoice numbers
     * 
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public function processAndCompute()
    {
        DB::beginTransaction();
        
        try {
            // Step 1: Validate financial period
            if (!empty($this->financial_period_id)) {
                $financialPeriod = \App\Models\FinancialPeriod::find($this->financial_period_id);
                if (!$financialPeriod) {
                    throw new \Exception('Invalid financial period selected.');
                }
                if ($financialPeriod->status != 'Active') {
                    throw new \Exception('The selected financial period is not active. Please select an active period.');
                }
            }
            
            // Step 2: Validate sale record items exist
            if (!$this->saleRecordItems || $this->saleRecordItems->count() == 0) {
                throw new \Exception('Sale must have at least one item. Please add items before saving.');
            }
            
            // Step 3: Reload sale items to ensure we have fresh data
            $this->load('saleRecordItems');
            
            $processedItems = [];
            $totalAmount = 0;
            $totalProfit = 0;
            $errors = [];
            
            // Step 4: Process each sale item
            foreach ($this->saleRecordItems as $index => $item) {
                // Fetch stock item fresh from database to avoid cached/stale data
                $stockItem = \App\Models\StockItem::find($item->stock_item_id);
                
                if (!$stockItem) {
                    $errors[] = "Item #" . ($index + 1) . ": Stock item not found or has been deleted.";
                    continue;
                }
                
                // Validate stock availability
                if ($stockItem->current_quantity < $item->quantity) {
                    $errors[] = "{$stockItem->name}: Insufficient Stock. Available: " . number_format($stockItem->current_quantity, 2) . ", Requested: " . number_format($item->quantity, 2);
                    continue;//new
                }
                
                // Step 5: Update stock item details (snapshot at time of sale)
                $item->item_name = $stockItem->name;
                $item->item_sku = $stockItem->sku ?? '';
                $item->unit_cost = $stockItem->buying_price ?? 0;
                
                // Use stock selling price if unit price is zero
                if (empty($item->unit_price) || $item->unit_price <= 0) {
                    $item->unit_price = $stockItem->selling_price;
                }
                
                // Step 6: Calculate item financials
                $item->subtotal = $item->quantity * $item->unit_price;
                $item->profit = $item->subtotal - ($item->unit_cost * $item->quantity);
                $item->save();
                
                // Step 7: Reduce stock quantity
                $oldQuantity = $stockItem->current_quantity;
                $stockItem->current_quantity -= $item->quantity;
                // Skip quantity check for sale processing
                $stockItem->skipQuantityCheck = true;
                $stockItem->save();
                
                // Step 8: Create stock record for audit trail
                $stockRecord = new \App\Models\StockRecord();
                $stockRecord->sale_record_id = $this->id;  // Link to this sale
                $stockRecord->company_id = $this->company_id;
                $stockRecord->stock_item_id = $stockItem->id;
                $stockRecord->stock_category_id = $stockItem->stock_category_id;
                $stockRecord->stock_sub_category_id = $stockItem->stock_sub_category_id;
                $stockRecord->financial_period_id = $this->financial_period_id;
                $stockRecord->created_by_id = $this->created_by_id;
                $stockRecord->sku = $item->item_sku;
                $stockRecord->name = $item->item_name;
                $stockRecord->measurement_unit = $stockItem->measurement_unit ?? 'pieces';
                $stockRecord->description = "Sale #" . $this->receipt_number . " - " . ($this->customer_name ?? 'Walk-in Customer');
                $stockRecord->type = 'Sale';
                $stockRecord->quantity = $item->quantity;
                $stockRecord->selling_price = $item->unit_price;
                $stockRecord->buying_price = $item->unit_cost;
                $stockRecord->total_sales = $item->subtotal;
                $stockRecord->profit = $item->profit;
                $stockRecord->date = $this->sale_date;
                $stockRecord->save();
                
                // Step 9: Link stock record to sale item
                $item->stock_record_id = $stockRecord->id;
                $item->save();
                
                // Accumulate totals
                $totalAmount += $item->subtotal;
                $totalProfit += $item->profit;
                
                $processedItems[] = [
                    'item_name' => $item->item_name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal' => $item->subtotal,
                    'profit' => $item->profit,
                    'old_stock' => $oldQuantity,
                    'new_stock' => $stockItem->current_quantity,
                    'stock_record_id' => $stockRecord->id
                ];
            }
            
            // Step 10: Check for validation errors
            if (!empty($errors)) {
                throw new \Exception("Validation errors:\n" . implode("\n", $errors));
            }
            
            // Step 11: Update sale record totals
            $this->total_amount = $totalAmount;
            $this->balance = $totalAmount - ($this->amount_paid ?? 0);
            
            // Step 12: Update payment status based on balance
            if ($this->balance <= 0) {
                $this->payment_status = 'Paid';
                $this->balance = 0; // Ensure balance doesn't go negative
            } elseif ($this->amount_paid > 0 && $this->amount_paid < $totalAmount) {
                $this->payment_status = 'Partial';
            } else {
                $this->payment_status = 'Unpaid';
            }
            
            // Step 13: Generate receipt and invoice numbers if not already set
            if (empty($this->receipt_number)) {
                $this->receipt_number = $this->generateUniqueReceiptNumber();
            }
            
            if (empty($this->invoice_number)) {
                $this->invoice_number = $this->generateUniqueInvoiceNumber();
            }
            
            // Step 14: Save the updated sale record
            $this->save();
            
            // Step 15: Commit transaction
            DB::commit();
            
            // Step 16: Log success
            Log::info('SaleRecord processed successfully', [
                'sale_record_id' => $this->id,
                'receipt_number' => $this->receipt_number,
                'total_amount' => $totalAmount,
                'total_profit' => $totalProfit,
                'items_count' => count($processedItems)
            ]);
            
            return [
                'success' => true,
                'message' => 'Sale record processed successfully',
                'data' => [
                    'sale_record_id' => $this->id,
                    'receipt_number' => $this->receipt_number,
                    'invoice_number' => $this->invoice_number,
                    'total_amount' => $totalAmount,
                    'amount_paid' => $this->amount_paid,
                    'balance' => $this->balance,
                    'payment_status' => $this->payment_status,
                    'total_profit' => $totalProfit,
                    'items_processed' => count($processedItems),
                    'items' => $processedItems
                ]
            ];
            
        } catch (\Exception $e) {
            // Rollback transaction on any error
            DB::rollBack();
            
            Log::error('SaleRecord processing failed', [
                'sale_record_id' => $this->id ?? 'NEW',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to process sale: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Validate stock availability before processing.
     * Can be called before processAndCompute() for pre-validation.
     * 
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateStockAvailability()
    {
        $errors = [];
        
        if (!$this->saleRecordItems || $this->saleRecordItems->count() == 0) {
            return [
                'valid' => false,
                'errors' => ['No items added to the sale. Please add at least one item.']
            ];
        }
        
        $this->load('saleRecordItems.stockItem');
        
        foreach ($this->saleRecordItems as $index => $item) {
            $stockItem = $item->stockItem;
            
            if (!$stockItem) {
                $errors[] = "Item #" . ($index + 1) . ": Stock item not found.";
                continue;
            }
            
            if ($stockItem->current_quantity < $item->quantity) {
                $errors[] = "{$stockItem->name}: Insufficient stock. Available: " . number_format($stockItem->current_quantity, 2) . ", Requested: " . number_format($item->quantity, 2);
            }
            
            if ($item->quantity <= 0) {
                $errors[] = "{$stockItem->name}: Quantity must be greater than zero.";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Relationships
     */
    public function company()
    {
        return $this->belongsTo(\App\Models\Company::class);
    }
    
    public function financialPeriod()
    {
        return $this->belongsTo(\App\Models\FinancialPeriod::class);
    }
    
    public function createdBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by_id');
    }
    
    public function saleRecordItems()
    {
        return $this->hasMany(SaleRecordItem::class);
    }
    
    public function stockRecords()
    {
        return $this->hasMany(\App\Models\StockRecord::class);
    }
}
