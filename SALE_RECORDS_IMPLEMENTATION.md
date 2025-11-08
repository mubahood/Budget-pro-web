# Sale Records System Implementation

## Overview
Complete multi-item sales system with automatic stock management, audit trail, and document tracking.

## Database Structure

### Table: `sale_records`
Main sale header table.

**Fields (26 total):**
- `id` - Primary key
- `company_id` - Foreign key to companies
- `financial_period_id` - Foreign key to financial_periods
- `created_by_id` - Foreign key to users (salesperson)
- `sale_date` - Date of sale
- `customer_name` - Customer name (nullable)
- `customer_phone` - Customer phone (nullable)
- `customer_address` - Customer address (text, nullable)
- `total_amount` - Total sale amount (decimal 15,2)
- `amount_paid` - Amount paid by customer (decimal 15,2)
- `balance` - Outstanding balance (decimal 15,2)
- `payment_method` - Enum: Cash, Credit Card, Bank Transfer, Mobile Money
- `payment_status` - Enum: Paid, Unpaid, Partial
- `status` - Enum: Completed, Pending, Cancelled
- `receipt_number` - Unique receipt number (auto-generated)
- `receipt_pdf_url` - PDF file path (nullable)
- `receipt_pdf_is_generated` - Status: Yes/No (default 'No')
- `invoice_number` - Unique invoice number (auto-generated)
- `invoice_pdf_url` - PDF file path (nullable)
- `invoice_pdf_is_generated` - Status: Yes/No (default 'No')
- `notes` - Additional notes (text, nullable)
- `created_at` - Timestamp
- `updated_at` - Timestamp

**Indexes (8):**
- `company_id`
- `financial_period_id`
- `created_by_id`
- `sale_date`
- `receipt_number` (unique)
- `invoice_number` (unique)
- `payment_status`
- `status`

### Table: `sale_record_items`
Sale line items (pivot table).

**Fields (11 total):**
- `id` - Primary key
- `sale_record_id` - Foreign key to sale_records
- `stock_item_id` - Foreign key to stock_items
- `stock_record_id` - Foreign key to stock_records (nullable, linked after stock record creation)
- `item_name` - Snapshot of item name at time of sale
- `item_sku` - Snapshot of SKU at time of sale
- `quantity` - Quantity sold (decimal 15,2)
- `unit_price` - Selling price per unit (decimal 15,2)
- `subtotal` - Line total (quantity * unit_price) (decimal 15,2)
- `unit_cost` - Cost price per unit from stock item (decimal 15,2)
- `profit` - Line profit (subtotal - (unit_cost * quantity)) (decimal 15,2)
- `created_at` - Timestamp
- `updated_at` - Timestamp

**Indexes (3):**
- `sale_record_id`
- `stock_item_id`
- `stock_record_id`

---

## Models

### 1. SaleRecord Model (`app/Models/SaleRecord.php`)

**Features:**
- Uses `AuditLogger` trait for audit trails
- Uses `CompanyScope` global scope for multi-tenancy
- Eager loads: `saleRecordItems`, `company`, `createdBy`
- Proper date and decimal casting

**Unique Number Generation:**
- **Receipt Number Format:** `RCP-{CompanyCode}-{YYYYMMDD}-{Sequence}`
  - Example: `RCP-ABC-20251108-0001`
  - 10 retry attempts, falls back to UUID suffix if needed
  
- **Invoice Number Format:** `INV-{CompanyCode}-{YYYYMMDD}-{Sequence}`
  - Example: `INV-ABC-20251108-0001`
  - 10 retry attempts, falls back to UUID suffix if needed

**Model Events:**

#### `creating` Event:
1. Generates unique `receipt_number` if not provided
2. Generates unique `invoice_number` if not provided
3. Auto-sets `created_by_id` from authenticated user
4. Validates financial period exists and is active

#### `created` Event:
1. For each sale item:
   - Reduces `stock_item.current_quantity` by item quantity
   - Creates a `StockRecord` entry with:
     - Type: 'Sale'
     - Description: "Sale #{receipt_number} - {customer_name}"
     - All pricing and profit information
     - Links sale item to stock record via `stock_record_id`

#### `deleting` Event:
1. For each sale item:
   - Restores `stock_item.current_quantity` by adding back item quantity
   - Deletes the associated `StockRecord` entry
   - Deletes the `SaleRecordItem` entry

**Helper Methods:**
- `generateUniqueReceiptNumber()` - Creates unique receipt number with retry logic
- `generateUniqueInvoiceNumber()` - Creates unique invoice number with retry logic
- `calculateTotals()` - Recalculates total_amount and balance from items

**Relationships:**
- `belongsTo(Company)`
- `belongsTo(FinancialPeriod)`
- `belongsTo(User, 'created_by_id')` - The salesperson
- `hasMany(SaleRecordItem)` - Sale line items

---

### 2. SaleRecordItem Model (`app/Models/SaleRecordItem.php`)

**Features:**
- Proper decimal casting for all financial fields
- Automatic calculation of subtotal and profit

**Model Events:**

#### `creating` Event:
1. Gets stock item details
2. **Snapshots** item data at time of sale:
   - `item_name` from `stock_item.name`
   - `item_sku` from `stock_item.sku`
   - `unit_cost` from `stock_item.buying_price`
3. Auto-sets `unit_price` from `stock_item.selling_price` if not provided
4. **Calculates:**
   - `subtotal = quantity * unit_price`
   - `profit = subtotal - (unit_cost * quantity)`
5. **Validates** sufficient stock quantity

#### `updating` Event:
1. Recalculates `subtotal`
2. Recalculates `profit`

**Helper Methods:**
- `calculateSubtotal()` - Returns quantity * unit_price
- `calculateProfit()` - Returns subtotal - (unit_cost * quantity)

**Relationships:**
- `belongsTo(SaleRecord)` - The main sale
- `belongsTo(StockItem)` - The stock item sold
- `belongsTo(StockRecord)` - The audit trail record (nullable initially)

---

## Workflow

### Creating a Sale (Automatic Process):

```
1. User creates SaleRecord with sale_date, customer_name, etc.
   ↓
2. System generates unique receipt_number and invoice_number
   ↓
3. System validates financial_period is active
   ↓
4. User adds SaleRecordItems (stock_item_id + quantity)
   ↓
5. For each item:
   - System snapshots item_name, item_sku, unit_cost
   - System gets unit_price from stock_item.selling_price
   - System calculates subtotal and profit
   - System validates sufficient stock
   ↓
6. SaleRecord is saved
   ↓
7. AFTER CREATION (Model Events):
   For each SaleRecordItem:
   - Reduce stock_item.current_quantity
   - Create StockRecord entry (audit trail)
   - Link stock_record_id to sale_record_item
```

### Deleting a Sale (Automatic Restoration):

```
1. User deletes SaleRecord
   ↓
2. Model deleting event triggers
   ↓
3. For each SaleRecordItem:
   - Restore stock_item.current_quantity (add back)
   - Delete associated StockRecord
   - Delete SaleRecordItem
   ↓
4. SaleRecord is deleted
```

---

## Key Design Decisions

### 1. **Snapshot Pattern**
Sale items store `item_name`, `item_sku`, and `unit_cost` at time of sale. This ensures historical accuracy even if stock item is later modified or deleted.

### 2. **No Database Cascading**
All cascade logic is handled in **model events**, not database foreign key constraints. This gives full control and audit logging.

### 3. **Automatic Stock Management**
Stock quantities are automatically reduced on sale creation and restored on sale deletion. No manual intervention needed.

### 4. **Audit Trail Integration**
Every sale item creates a `StockRecord` entry with type 'Sale', providing complete audit trail for stock movements.

### 5. **Unique Number Generation**
Receipt and invoice numbers use company code + date + sequence pattern with retry logic to ensure uniqueness even in high-concurrency scenarios.

### 6. **Financial Calculations**
- Subtotal: `quantity * unit_price`
- Profit: `subtotal - (unit_cost * quantity)`
- Total Amount: Sum of all item subtotals
- Balance: `total_amount - amount_paid`

### 7. **Payment Status Auto-Update**
System automatically determines payment status:
- **Paid**: balance <= 0
- **Partial**: amount_paid > 0 AND balance > 0
- **Unpaid**: amount_paid = 0

---

## Data Integrity Features

### 1. **Validation in Events**
- Stock item must exist
- Financial period must exist and be active
- Sufficient stock quantity available
- Quantities and prices must be positive

### 2. **Transaction Safety**
All database operations wrapped in try-catch blocks with logging to `storage/logs/laravel.log`.

### 3. **Immutable Historical Data**
Once created, sale item snapshots (name, SKU, cost) never change, preserving historical accuracy.

### 4. **Referential Integrity**
- `stock_record_id` links each sale item to its audit trail record
- Relationships maintained through Eloquent ORM
- Soft constraints via application logic, not database cascades

---

## Next Steps (Controller Implementation)

To complete the system, you need to create:

### 1. **SaleRecordController** (`app/Admin/Controllers/SaleRecordController.php`)
   - Grid with filters (date range, customer, payment status, status)
   - Multi-item form using `hasMany` for dynamic items
   - Validation in `saving()` hook
   - Export functionality
   - Detail view with items table and totals

### 2. **Admin Menu Entry**
Add route to `app/Admin/routes.php`:
```php
$router->resource('sale-records', SaleRecordController::class);
```

### 3. **Form Features Needed**
- Date picker for `sale_date`
- Customer information fields
- **Dynamic items table** with:
  - Stock Item dropdown (searchable)
  - Quantity input
  - Unit Price (auto-filled from stock item)
  - Subtotal display (auto-calculated)
  - Add/Remove item buttons
- Payment section (method, amount paid, status)
- Auto-calculate total and balance
- Notes field

### 4. **Grid Features Needed**
- Columns: ID, Date, Customer, Items Count, Total Amount, Payment Status, Status
- Filters: Date range, customer name, payment status, status
- Quick view actions
- Export to Excel
- Batch actions (if needed)

### 5. **Detail View Features**
- Customer information section
- **Items table:**
  - Item Name (SKU)
  - Quantity
  - Unit Price
  - Subtotal
  - Profit (optional, may hide for non-admin)
- **Payment summary:**
  - Total Amount
  - Amount Paid
  - Balance
  - Payment Method
  - Payment Status
- **Document actions:**
  - Generate Receipt PDF (when implemented)
  - Generate Invoice PDF (when implemented)
  - View Stock Impact (links to StockRecords)

---

## Testing Checklist

After controller implementation:

1. **Create Sale:**
   - [ ] Select multiple stock items
   - [ ] Verify quantities reduced from stock
   - [ ] Verify StockRecord entries created
   - [ ] Verify unique receipt/invoice numbers generated
   - [ ] Verify totals and balance calculated correctly

2. **Edit Sale:**
   - [ ] Change customer information
   - [ ] Change payment amount
   - [ ] Verify payment status updates automatically

3. **Delete Sale:**
   - [ ] Verify stock quantities restored
   - [ ] Verify StockRecord entries deleted
   - [ ] Verify sale items deleted

4. **Validation:**
   - [ ] Try to sell more than available stock
   - [ ] Try to create sale with inactive financial period
   - [ ] Try to create sale with missing required fields

5. **Edge Cases:**
   - [ ] Multiple sales on same day (unique number generation)
   - [ ] High concurrency (multiple users creating sales simultaneously)
   - [ ] Sale deletion after stock item was deleted
   - [ ] Sale with zero-quantity items

---

## File Locations

**Migration:**
- `database/migrations/2025_11_08_063515_create_sale_records_table.php`

**Models:**
- `app/Models/SaleRecord.php`
- `app/Models/SaleRecordItem.php`

**Next to Create:**
- `app/Admin/Controllers/SaleRecordController.php`

**Documentation:**
- `SALE_RECORDS_IMPLEMENTATION.md` (this file)

---

## Example Data Flow

### Example: Customer buys 2 items

**Step 1: Create Sale**
```php
SaleRecord:
- sale_date: 2025-11-08
- customer_name: John Doe
- customer_phone: +1234567890
- receipt_number: RCP-ABC-20251108-0001 (auto-generated)
- invoice_number: INV-ABC-20251108-0001 (auto-generated)
```

**Step 2: Add Items**
```php
Item 1:
- stock_item_id: 5 (Laptop)
- quantity: 1
- unit_price: 1200.00 (from stock_item.selling_price)
- unit_cost: 1000.00 (from stock_item.buying_price)
- subtotal: 1200.00 (auto-calculated)
- profit: 200.00 (auto-calculated)

Item 2:
- stock_item_id: 8 (Mouse)
- quantity: 2
- unit_price: 25.00
- unit_cost: 15.00
- subtotal: 50.00 (auto-calculated)
- profit: 20.00 (auto-calculated)
```

**Step 3: Payment**
```php
- total_amount: 1250.00 (auto-calculated from items)
- amount_paid: 1250.00
- balance: 0.00
- payment_method: Cash
- payment_status: Paid (auto-set because balance = 0)
```

**Step 4: Automatic Actions After Save**
```
Stock Updates:
- Laptop: current_quantity reduced by 1
- Mouse: current_quantity reduced by 2

StockRecords Created:
1. Type: Sale, Item: Laptop, Quantity: -1, Selling Price: 1200, Profit: 200
2. Type: Sale, Item: Mouse, Quantity: -2, Selling Price: 25, Profit: 20

Links Created:
- SaleRecordItem 1 → StockRecord 1 (via stock_record_id)
- SaleRecordItem 2 → StockRecord 2 (via stock_record_id)
```

---

## System Benefits

1. **Complete Audit Trail:** Every sale creates immutable stock records
2. **Automatic Stock Management:** No manual stock adjustments needed
3. **Historical Accuracy:** Snapshots preserve sale data even if items change
4. **Multi-Tenant Ready:** Company scope ensures data isolation
5. **Scalable:** Unique number generation handles high concurrency
6. **Flexible:** Supports partial payments, multiple payment methods
7. **Reportable:** Easy to generate sales reports, profit analysis, customer history

---

**Status:** ✅ Migration and Models Complete | ⏳ Controller Pending
