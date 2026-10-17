# Budget Pro - Developer Guide

**Version:** 2.0.0  
**Last Updated:** December 9, 2025

---

## 📋 Table of Contents

1. [Quick Start for Developers](#quick-start-for-developers)
2. [Architecture Overview](#architecture-overview)
3. [Code Standards](#code-standards)
4. [Database Schema](#database-schema)
5. [Multi-Tenant Implementation](#multi-tenant-implementation)
6. [Model Documentation](#model-documentation)
7. [Controller Patterns](#controller-patterns)
8. [Service Layer](#service-layer)
9. [Custom Traits](#custom-traits)
10. [Testing Guide](#testing-guide)
11. [API Development](#api-development)
12. [Common Tasks](#common-tasks)

---

## 🚀 Quick Start for Developers

### Development Setup

```bash
# Clone repository
git clone https://github.com/your-repo/budget-pro.git
cd budget-pro

# Install dependencies
composer install
npm install

# Environment setup
cp .env.example .env
php artisan key:generate

# Database setup
php artisan migrate
php artisan db:seed --class=CompleteDemoSeeder

# Build assets
npm run dev

# Start development server
php artisan serve
```

### Access Development Environment

- **URL:** http://localhost:8000
- **Admin:** admin@admin.com / password
- **Demo Companies:** 3 pre-configured companies with data

---

## 🏗️ Architecture Overview

### Technology Stack

```
Frontend:
├── Blade Templates (resources/views/)
├── Alpine.js (interactive components)
├── TailwindCSS (via Vite)
└── Encore Admin UI

Backend:
├── Laravel 10.x
├── PHP 8.1+ (8.3+ recommended)
├── MySQL 5.7+ / MariaDB 10.3+
└── Encore Admin Panel

Services:
├── DomPDF (PDF generation)
├── Laravel Sanctum (API authentication)
└── Spatie packages (permissions, backups)
```

### Directory Structure

```
budget-pro/
├── app/
│   ├── Admin/              # Encore Admin controllers & config
│   │   ├── Controllers/    # Admin panel controllers (26)
│   │   └── bootstrap.php   # Admin initialization
│   ├── Http/
│   │   ├── Controllers/    # API controllers
│   │   └── Middleware/     # Custom middleware (3)
│   ├── Models/             # Eloquent models (24)
│   ├── Policies/           # Authorization policies (7)
│   ├── Scopes/             # Global query scopes
│   ├── Services/           # Business logic services (7)
│   └── Traits/             # Reusable traits (7)
├── database/
│   ├── migrations/         # Schema definitions (51)
│   └── seeders/            # Data seeders
├── resources/
│   ├── views/              # Blade templates
│   └── js/                 # Frontend assets
├── routes/
│   ├── web.php             # Web routes
│   ├── api.php             # API routes
│   └── admin.php           # Admin routes
└── tests/                  # PHPUnit tests
```

---

## 📝 Code Standards

### PSR-12 Compliance

All code follows PSR-12 standards. Run Laravel Pint before committing:

```bash
# Check code style
./vendor/bin/pint --test

# Fix code style
./vendor/bin/pint
```

### Naming Conventions

**Models:**
```php
// Singular, PascalCase
class StockItem extends Model {}
class PurchaseOrder extends Model {}
```

**Controllers:**
```php
// PascalCase with Controller suffix
class StockItemController extends AdminController {}
class FinancialReportController extends AdminController {}
```

**Methods:**
```php
// camelCase for methods
public function getActiveItems() {}
public function calculateTotal() {}

// snake_case for database columns
$stockItem->current_quantity;
$purchaseOrder->po_number;
```

**Variables:**
```php
// camelCase
$activeItems = [];
$totalAmount = 0;

// snake_case for array keys
$data['stock_item_id'] = 1;
```

### PHPDoc Standards

Always document public methods:

```php
/**
 * Get active stock items for current company.
 *
 * @param int $categoryId Category to filter by (optional)
 * @param int $limit Maximum items to return
 * @return \Illuminate\Database\Eloquent\Collection
 * @throws \Exception If company not found
 */
public function getActiveItems(int $categoryId = null, int $limit = 100)
{
    // Implementation
}
```

### Model Properties Documentation

```php
/**
 * Stock Item Model
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property float $current_quantity
 * @property \Illuminate\Support\Carbon|null $created_at
 *
 * @property-read Company $company
 * @property-read StockCategory $category
 */
class StockItem extends Model
{
    // ...
}
```

---

## 🗄️ Database Schema

### Core Tables

**Companies (Multi-Tenant Root)**
```sql
CREATE TABLE companies (
    id BIGINT UNSIGNED PRIMARY KEY,
    owner_id BIGINT UNSIGNED,
    name VARCHAR(255),
    phone_number VARCHAR(255),
    email VARCHAR(255),
    address TEXT,
    logo VARCHAR(255),
    currency VARCHAR(10) DEFAULT 'UGX',
    status VARCHAR(50) DEFAULT 'Active',
    license_package VARCHAR(50),
    license_expire DATETIME,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

**Users (Admin Users)**
```sql
CREATE TABLE admin_users (
    id BIGINT UNSIGNED PRIMARY KEY,
    username VARCHAR(190) UNIQUE,
    password VARCHAR(60),
    name VARCHAR(255),
    first_name VARCHAR(255),
    last_name VARCHAR(255),
    email VARCHAR(255) UNIQUE,
    company_id BIGINT UNSIGNED,
    phone_number VARCHAR(255),
    address TEXT,
    avatar VARCHAR(255),
    remember_token VARCHAR(100),
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);
```

**Stock Items**
```sql
CREATE TABLE stock_items (
    id BIGINT UNSIGNED PRIMARY KEY,
    company_id BIGINT UNSIGNED,
    stock_category_id BIGINT UNSIGNED,
    stock_sub_category_id BIGINT UNSIGNED,
    name VARCHAR(255),
    sku VARCHAR(255),
    barcode VARCHAR(255),
    description TEXT,
    unit_of_measure VARCHAR(50),
    buying_price DECIMAL(10,2),
    selling_price DECIMAL(10,2),
    current_quantity DECIMAL(10,2),
    reorder_level DECIMAL(10,2),
    status VARCHAR(50) DEFAULT 'Active',
    photo VARCHAR(255),
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_id (company_id),
    INDEX idx_sku (sku),
    INDEX idx_barcode (barcode)
);
```

**Sale Records**
```sql
CREATE TABLE sale_records (
    id BIGINT UNSIGNED PRIMARY KEY,
    company_id BIGINT UNSIGNED,
    financial_period_id BIGINT UNSIGNED,
    stock_item_id BIGINT UNSIGNED,
    created_by_id BIGINT UNSIGNED,
    sale_date DATE,
    quantity DECIMAL(10,2),
    unit_price DECIMAL(10,2),
    total_amount DECIMAL(10,2),
    customer_name VARCHAR(255),
    customer_phone VARCHAR(255),
    customer_email VARCHAR(255),
    payment_method VARCHAR(50),
    status VARCHAR(50) DEFAULT 'Completed',
    notes TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_sale_date (company_id, sale_date),
    INDEX idx_stock_item (stock_item_id)
);
```

### Financial Tables

**Financial Periods**
```sql
CREATE TABLE financial_periods (
    id BIGINT UNSIGNED PRIMARY KEY,
    company_id BIGINT UNSIGNED,
    name VARCHAR(255),
    start_date DATE,
    end_date DATE,
    status VARCHAR(50) DEFAULT 'Active',
    description TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY unique_active_period (company_id, status)
);
```

**Financial Records**
```sql
CREATE TABLE financial_records (
    id BIGINT UNSIGNED PRIMARY KEY,
    company_id BIGINT UNSIGNED,
    financial_period_id BIGINT UNSIGNED,
    amount DECIMAL(10,2),
    type VARCHAR(50), -- Income, Expense
    category VARCHAR(255),
    description TEXT,
    transaction_date DATE,
    created_by_id BIGINT UNSIGNED,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Indexes for Performance

```sql
-- Company-based queries (most common)
CREATE INDEX idx_company_id ON stock_items(company_id);
CREATE INDEX idx_company_id ON sale_records(company_id);
CREATE INDEX idx_company_id ON purchase_orders(company_id);

-- Date range queries
CREATE INDEX idx_sale_date ON sale_records(sale_date);
CREATE INDEX idx_po_date ON purchase_orders(po_date);

-- Composite indexes for common queries
CREATE INDEX idx_company_status ON stock_items(company_id, status);
CREATE INDEX idx_company_period ON sale_records(company_id, financial_period_id);

-- Search indexes
CREATE INDEX idx_sku ON stock_items(sku);
CREATE INDEX idx_barcode ON stock_items(barcode);
CREATE INDEX idx_customer_phone ON sale_records(customer_phone);
```

---

## 🏢 Multi-Tenant Implementation

### Company Scope (Global Query Scope)

All tenant-specific models automatically filter by company:

```php
// app/Scopes/CompanyScope.php
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        // Get current company ID from authenticated user
        $companyId = Admin::user()?->company_id;
        
        if ($companyId) {
            $builder->where('company_id', $companyId);
        }
    }
}
```

### Applying CompanyScope to Models

```php
use App\Scopes\CompanyScope;

class StockItem extends Model
{
    /**
     * The "booted" method applies global scope.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
```

### Bypassing CompanyScope (Admin Only)

```php
// Get all stock items across companies (super admin)
$allItems = StockItem::withoutGlobalScope(CompanyScope::class)->get();

// Get specific company's items
$items = StockItem::withoutGlobalScope(CompanyScope::class)
    ->where('company_id', 5)
    ->get();
```

### Setting Company on Create

```php
// Automatic via model boot
protected static function boot()
{
    parent::boot();
    
    static::creating(function ($model) {
        if (!$model->company_id) {
            $model->company_id = Admin::user()->company_id;
        }
    });
}
```

### Multi-Tenant Best Practices

1. **Always use CompanyScope** for tenant-specific models
2. **Never expose company_id** in forms (set automatically)
3. **Validate company access** in controllers
4. **Use database transactions** for multi-model operations
5. **Test cross-tenant data leakage** regularly

---

## 📚 Model Documentation

### Core Models

**Company.php**
```php
// Purpose: Organization/tenant management
// Relationships: hasMany(User), hasMany(StockItem)
// Key Methods:
//   - prepare_account_categories($company_id): Set up default categories
//   - boot(): Ensure owner gets role and categories
```

**User.php (extends Administrator)**
```php
// Purpose: System users with admin access
// Relationships: belongsTo(Company), belongsToMany(Role)
// Key Methods:
//   - boot(): Auto-generate name from first/last name
//   - ensureCompanyOwnerRole(): Assign owner role automatically
```

**StockItem.php**
```php
// Purpose: Inventory items
// Scopes: CompanyScope
// Relationships: belongsTo(Company, Category, SubCategory)
// Key Methods:
//   - booted(): Apply company scope
//   - updateQuantity(): Adjust stock levels
// Special: $skipQuantityCheck flag for sale processing
```

**SaleRecord.php**
```php
// Purpose: Sales transactions
// Scopes: CompanyScope
// Relationships: belongsTo(StockItem, Company, FinancialPeriod)
// Key Methods:
//   - boot(): Auto-deduct stock on save
//   - updateStockQuantity(): Manual stock adjustment
```

**PurchaseOrder.php**
```php
// Purpose: Procurement orders
// Scopes: CompanyScope, SoftDeletes
// Relationships: belongsTo(Company, User as createdBy, approvedBy)
// Key Methods:
//   - approve(): Mark as approved
//   - receive(): Mark items as received
```

**FinancialPeriod.php**
```php
// Purpose: Accounting periods
// Scopes: CompanyScope
// Relationships: belongsTo(Company), hasMany(SaleRecord)
// Key Methods:
//   - boot(): Ensure only one active period per company
//   - close(): Close period and create new one
```

### Model Relationships Quick Reference

```php
// One-to-Many
Company -> hasMany -> Users
Company -> hasMany -> StockItems
Company -> hasMany -> SaleRecords
StockCategory -> hasMany -> StockItems
FinancialPeriod -> hasMany -> SaleRecords

// Many-to-One
StockItem -> belongsTo -> Company
StockItem -> belongsTo -> StockCategory
SaleRecord -> belongsTo -> StockItem
SaleRecord -> belongsTo -> Company
SaleRecord -> belongsTo -> FinancialPeriod

// Many-to-Many
User -> belongsToMany -> Role
Role -> belongsToMany -> Permission
```

---

## 🎮 Controller Patterns

### Encore Admin Controller Structure

```php
use App\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class StockItemController extends AdminController
{
    protected $title = 'Stock Items';
    
    /**
     * Make a grid builder.
     */
    protected function grid()
    {
        $grid = new Grid(new StockItem());
        
        // Filters
        $grid->filter(function($filter) {
            $filter->like('name');
            $filter->equal('stock_category_id')->select(
                StockCategory::pluck('name', 'id')
            );
        });
        
        // Columns
        $grid->column('sku', 'SKU');
        $grid->column('name', 'Name')->sortable();
        $grid->column('current_quantity', 'Stock')->sortable();
        
        // Actions
        $grid->actions(function ($actions) {
            $actions->disableDelete(); // Customize
        });
        
        return $grid;
    }
    
    /**
     * Make a form builder.
     */
    protected function form()
    {
        $form = new Form(new StockItem());
        
        // Auto-set company_id
        $form->hidden('company_id')->default(Admin::user()->company_id);
        
        $form->text('name', 'Name')->required();
        $form->text('sku', 'SKU')->required();
        $form->decimal('buying_price', 'Buying Price')->required();
        
        // Saving callback
        $form->saving(function (Form $form) {
            // Custom logic before save
        });
        
        return $form;
    }
    
    /**
     * Make a show builder.
     */
    protected function detail($id)
    {
        $show = new Show(StockItem::findOrFail($id));
        
        $show->field('name', 'Name');
        $show->field('sku', 'SKU');
        $show->field('current_quantity', 'Stock Level');
        
        return $show;
    }
}
```

### Common Controller Patterns

**Bulk Actions:**
```php
$grid->batchActions(function ($batch) {
    $batch->add(new \App\Admin\Actions\BulkActivate());
    $batch->add(new \App\Admin\Actions\BulkExport());
});
```

**Custom Actions:**
```php
$grid->actions(function ($actions) {
    $actions->add(new \App\Admin\Actions\CloneItem());
    $actions->add(new \App\Admin\Actions\GeneratePDF($actions->row));
});
```

**Export:**
```php
$grid->exporter(new \App\Admin\Exporters\StockItemExporter());
```

---

## 🔧 Service Layer

### Service Structure

```php
// app/Services/FinancialReportService.php
namespace App\Services;

class FinancialReportService
{
    /**
     * Generate comprehensive financial report.
     *
     * @param FinancialReport $report
     * @return array Report data
     */
    public static function generate(FinancialReport $report): array
    {
        // Complex business logic here
        $data = [
            'sales' => self::calculateSales($report),
            'expenses' => self::calculateExpenses($report),
            'profit' => self::calculateProfit($report),
        ];
        
        return $data;
    }
    
    private static function calculateSales(FinancialReport $report): float
    {
        return SaleRecord::where('financial_period_id', $report->financial_period_id)
            ->sum('total_amount');
    }
}
```

### When to Use Services

- Complex business logic
- Multi-model operations
- External API integrations
- Heavy calculations
- Reusable operations

### Service Examples

```php
// StockManagementService
StockManagementService::adjustQuantity($item, $quantity, $reason);
StockManagementService::checkReorderLevel($item);
StockManagementService::bulkImport($csvFile);

// InvoiceService
InvoiceService::generatePDF($saleRecord);
InvoiceService::sendEmail($invoice, $recipient);

// ReportService
ReportService::generateMonthlyReport($companyId, $month);
ReportService::exportToExcel($data);
```

---

## 🎨 Custom Traits

### AuditLogger Trait

```php
use App\Traits\AuditLogger;

class StockItem extends Model
{
    use AuditLogger;
    // Automatically logs created_by, updated_by
}
```

### CompanyScope Trait

```php
trait HasCompanyScope
{
    protected static function bootHasCompanyScope()
    {
        static::addGlobalScope(new CompanyScope);
    }
}
```

---

## 🧪 Testing Guide

### Running Tests

```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test --filter=StockItemTest

# Run with coverage
php artisan test --coverage

# Run parallel
php artisan test --parallel
```

### Writing Model Tests

```php
// tests/Unit/StockItemTest.php
class StockItemTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_stock_deducts_on_sale()
    {
        $item = StockItem::factory()->create([
            'current_quantity' => 100
        ]);
        
        SaleRecord::create([
            'stock_item_id' => $item->id,
            'quantity' => 10,
            'status' => 'Completed'
        ]);
        
        $this->assertEquals(90, $item->fresh()->current_quantity);
    }
}
```

### Testing Multi-Tenancy

```php
public function test_users_only_see_own_company_data()
{
    $company1 = Company::factory()->create();
    $company2 = Company::factory()->create();
    
    $user1 = User::factory()->create(['company_id' => $company1->id]);
    $item1 = StockItem::factory()->create(['company_id' => $company1->id]);
    $item2 = StockItem::factory()->create(['company_id' => $company2->id]);
    
    $this->actingAs($user1);
    $items = StockItem::all();
    
    $this->assertCount(1, $items);
    $this->assertEquals($item1->id, $items->first()->id);
}
```

---

## 🚀 API Development

### Creating API Endpoints

```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('stock-items', StockItemApiController::class);
});

// app/Http/Controllers/Api/StockItemApiController.php
class StockItemApiController extends Controller
{
    public function index(Request $request)
    {
        $items = StockItem::query()
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->paginate($request->per_page ?? 20);
            
        return response()->json($items);
    }
}
```

### API Authentication

```bash
# Generate token
$token = $user->createToken('api-token')->plainTextToken;

# Use token
curl -H "Authorization: Bearer TOKEN" https://api.example.com/api/stock-items
```

---

## 📋 Common Tasks

### Adding New Module

1. **Create Migration**
```bash
php artisan make:migration create_custom_module_table
```

2. **Create Model**
```bash
php artisan make:model CustomModule
# Add CompanyScope in booted()
# Add relationships
```

3. **Create Controller**
```bash
php artisan admin:make CustomModuleController --model=App\\Models\\CustomModule
```

4. **Add Route**
```php
// app/Admin/routes.php
$router->resource('custom-module', CustomModuleController::class);
```

### Running Seeders

```bash
# Demo data
php artisan db:seed --class=CompleteDemoSeeder

# Specific seeder
php artisan db:seed --class=YourSeeder
```

### Cache Management

```bash
# Clear all caches
php artisan optimize:clear

# Cache config for production
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

**Need More Help?**  
- Full Documentation: [README.md](README.md)
- Troubleshooting: [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
- API Reference: [API_DOCUMENTATION.md](API_DOCUMENTATION.md)

---

**Last Updated:** December 9, 2025  
**Version:** 2.0.0
