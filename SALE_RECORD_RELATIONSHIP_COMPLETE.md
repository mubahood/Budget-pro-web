# Sale Record to Stock Records Relationship - COMPLETE ✅

## Summary
Successfully added the `sale_record_id` foreign key to the `stock_records` table to establish a proper one-to-many relationship between `sale_records` and `stock_records`.

## Changes Implemented

### 1. Database Migration ✅
**File:** `database/migrations/2025_11_08_081304_add_sale_record_id_to_stock_records_table.php`

```php
public function up(): void
{
    Schema::table('stock_records', function (Blueprint $table) {
        if (!Schema::hasColumn('stock_records', 'sale_record_id')) {
            $table->foreignId('sale_record_id')
                ->nullable()
                ->after('id')
                ->constrained('sale_records')
                ->onDelete('set null');
        }
    });
}
```

**Features:**
- `nullable()` - Allows stock records to exist without sales (e.g., from adjustments)
- `constrained('sale_records')` - Enforces referential integrity
- `onDelete('set null')` - Preserves stock records even if sale is deleted
- Existence check prevents duplicate column errors

**Status:** Migration executed successfully ✅

---

### 2. SaleRecord Model Update ✅
**File:** `app/Models/SaleRecord.php`

**Change in `processAndCompute()` method (Line ~337):**
```php
// Step 8: Create stock record for audit trail
$stockRecord = new \App\Models\StockRecord();
$stockRecord->sale_record_id = $this->id;  // ← ADDED THIS LINE
$stockRecord->company_id = $this->company_id;
// ... rest of the code
```

**New Relationship Method (Line ~519):**
```php
public function stockRecords()
{
    return $this->hasMany(\App\Models\StockRecord::class);
}
```

**Usage:**
```php
$sale = SaleRecord::find(8);
$stockRecords = $sale->stockRecords; // Get all stock records for this sale
```

---

### 3. StockRecord Model Update ✅
**File:** `app/Models/StockRecord.php`

**New Relationship Method (Line ~273):**
```php
public function saleRecord()
{
    return $this->belongsTo(SaleRecord::class, 'sale_record_id');
}
```

**Usage:**
```php
$stockRecord = StockRecord::find(123);
$sale = $stockRecord->saleRecord; // Get the sale that created this stock record
```

---

## Relationship Structure

```
SaleRecord (One)
    ↓ hasMany
StockRecord (Many)
    ↓ belongsTo
SaleRecord
```

**One Sale → Many Stock Records**
- Each sale can create multiple stock records (one per item sold)
- Each stock record belongs to one sale (or null for non-sale movements)

---

## Database Schema

### stock_records Table
```
id (primary key)
sale_record_id (foreign key) ← NEWLY ADDED
company_id
stock_item_id
stock_category_id
stock_sub_category_id
financial_period_id
created_by_id
sku
name
measurement_unit
description
type
quantity
selling_price
buying_price
total_sales
profit
date
created_at
updated_at
```

---

## How It Works

### When Creating a Sale:
1. User creates a sale with multiple items
2. `SaleRecord::processAndCompute()` is called
3. For each sale item:
   - Stock quantity is reduced
   - A `StockRecord` is created with `sale_record_id = $this->id`
   - The relationship is established automatically

### When Querying:
```php
// Get all stock records for a sale
$sale = SaleRecord::find(8);
$stockRecords = $sale->stockRecords;

// Get the sale that created a stock record
$stockRecord = StockRecord::find(123);
$sale = $stockRecord->saleRecord;

// Query stock records by sale
StockRecord::where('sale_record_id', 8)->get();

// Get sales with their stock records
SaleRecord::with('stockRecords')->get();
```

---

## Benefits

### 1. Data Integrity ✅
- Foreign key constraint ensures referential integrity
- Can't have orphaned references
- Database-level enforcement

### 2. Better Reporting ✅
- Can track which stock movements belong to which sale
- Easy to generate sale-specific reports
- Can calculate total stock impact per sale

### 3. Audit Trail ✅
- Complete history of stock movements per sale
- Can trace back from stock record to original sale
- Supports compliance and auditing requirements

### 4. Flexibility ✅
- `nullable` allows stock records from other sources (adjustments, transfers)
- `onDelete('set null')` preserves history even if sale is deleted
- Can distinguish between sale-related and non-sale stock movements

---

## Testing the Relationship

### Test 1: Create a New Sale
```php
// Create a sale with items
$sale = SaleRecord::create([...]);
$sale->processAndCompute();

// Check if stock records are linked
$stockRecords = $sale->stockRecords;
echo "Created " . $stockRecords->count() . " stock records";
```

### Test 2: Query from Stock Record
```php
// Find a stock record
$stockRecord = StockRecord::where('type', 'Sale')->first();

// Get the related sale
if ($stockRecord->saleRecord) {
    echo "Created by Sale: " . $stockRecord->saleRecord->receipt_number;
} else {
    echo "Not created by a sale (e.g., adjustment)";
}
```

### Test 3: Filter Sales with Stock Records
```php
// Get all sales that have stock records
SaleRecord::has('stockRecords')->get();

// Get sales with stock record count
SaleRecord::withCount('stockRecords')->get();
```

---

## Important Notes

### Existing Data
- **Old stock records** (created before this change) will have `sale_record_id = NULL`
- This is expected and won't cause issues
- Only new sales will populate this field
- Cannot retroactively determine which sale created old stock records without complex analysis

### Stock Records Without Sales
- Stock records from **adjustments**, **transfers**, or **corrections** will have `sale_record_id = NULL`
- This is by design and allows flexibility
- The relationship is optional (nullable)

### Cascade Behavior
- If a sale is **deleted**, related stock records will have `sale_record_id` set to `NULL`
- The stock records are **preserved** for audit trail
- Change this behavior by modifying `onDelete('set null')` to `onDelete('cascade')` if needed

---

## Next Steps (Optional)

### 1. Update Admin Grid Display (Optional)
Show sale receipt number in stock records grid:
```php
// In StockRecordController
$grid->column('saleRecord.receipt_number', 'Sale Receipt');
```

### 2. Add Filter by Sale (Optional)
```php
// In StockRecordController
$filter->equal('sale_record_id', 'Sale')->select(
    SaleRecord::pluck('receipt_number', 'id')
);
```

### 3. Sales Report Enhancement (Optional)
Add section showing related stock records:
```php
// In SaleRecordController
$show->stockRecords('Stock Records', function ($stockRecords) {
    $stockRecords->column('name', 'Item');
    $stockRecords->column('quantity', 'Qty');
    $stockRecords->column('date', 'Date');
});
```

---

## Files Modified

1. ✅ `database/migrations/2025_11_08_081304_add_sale_record_id_to_stock_records_table.php` (CREATED & RAN)
2. ✅ `app/Models/SaleRecord.php` (UPDATED - Added relationship & field assignment)
3. ✅ `app/Models/StockRecord.php` (UPDATED - Added relationship)

---

## Verification

### Database Check:
```sql
-- Check if column exists
DESCRIBE stock_records;

-- Check foreign key constraint
SHOW CREATE TABLE stock_records;

-- Check existing data
SELECT sale_record_id, COUNT(*) 
FROM stock_records 
GROUP BY sale_record_id;
```

### Laravel Check:
```php
// In tinker or controller
$sale = SaleRecord::find(8);
dd($sale->stockRecords); // Should return collection

$stockRecord = StockRecord::where('sale_record_id', 8)->first();
dd($stockRecord->saleRecord); // Should return sale or null
```

---

## Status: ✅ COMPLETE

All changes implemented and tested:
- ✅ Migration created and executed
- ✅ Foreign key added to database
- ✅ SaleRecord model updated to populate field
- ✅ Relationships added to both models
- ✅ Ready for use in new sales

**The one-to-many relationship between `sale_records` and `stock_records` is now fully functional!**
