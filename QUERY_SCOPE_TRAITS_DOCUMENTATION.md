# Query Scope Traits Documentation

## Overview

This document describes the reusable query scope traits created to provide consistent filtering operations across models in the Budget Pro application.

## Available Traits

### 1. HasDateScopes
**Location**: `app/Traits/HasDateScopes.php`
**Purpose**: Provides date-based filtering for models with date columns.

**Common Usage**:
```php
use App\Traits\HasDateScopes;

class FinancialRecord extends Model
{
    use HasDateScopes;
}

// Usage examples:
$todaysRecords = FinancialRecord::today()->get();
$thisMonth = FinancialRecord::thisMonth()->get();
$lastWeek = FinancialRecord::lastWeek()->get();
$custom = FinancialRecord::byDateRange('2024-01-01', '2024-12-31')->get();
```

**Available Scopes**:
- `today($column = 'date')` - Records from today
- `yesterday($column = 'date')` - Records from yesterday
- `thisWeek($column = 'date')` - Records from current week
- `lastWeek($column = 'date')` - Records from last week
- `thisMonth($column = 'date')` - Records from current month
- `lastMonth($column = 'date')` - Records from last month
- `thisQuarter($column = 'date')` - Records from current quarter
- `thisYear($column = 'date')` - Records from current year
- `lastYear($column = 'date')` - Records from last year
- `byYear($year, $column = 'date')` - Records from specific year
- `byMonth($month, $year, $column = 'date')` - Records from specific month
- `byDateRange($startDate, $endDate, $column = 'date')` - Records within date range
- `betweenDates($startDate, $endDate, $column = 'date')` - Records between dates (inclusive)
- `beforeDate($date, $column = 'date')` - Records before a date
- `afterDate($date, $column = 'date')` - Records after a date
- `lastDays($days, $column = 'date')` - Records from last N days
- `lastMonths($months, $column = 'date')` - Records from last N months

---

### 2. HasActiveScope
**Location**: `app/Traits/HasActiveScope.php`
**Purpose**: Provides status-based filtering for models with status columns.

**Common Usage**:
```php
use App\Traits\HasActiveScope;

class StockCategory extends Model
{
    use HasActiveScope;
}

// Usage examples:
$activeCategories = StockCategory::active()->get();
$pendingItems = BudgetItem::pending()->get();
$approvedPrograms = BudgetProgram::approved()->get();
```

**Available Scopes**:
- `active($column = 'status')` - Only active records
- `inactive($column = 'status')` - Only inactive records
- `closed($column = 'status')` - Only closed records
- `pending($column = 'status')` - Only pending records
- `approved($column = 'status')` - Only approved records
- `rejected($column = 'status')` - Only rejected records
- `byStatus($status, $column = 'status')` - Filter by specific status
- `excludeStatus($statuses, $column = 'status')` - Exclude specific statuses
- `inStatuses(array $statuses, $column = 'status')` - Include multiple statuses
- `activeOrPending($column = 'status')` - Active or pending records
- `notClosed($column = 'status')` - Non-closed records

---

### 3. HasFinancialScopes
**Location**: `app/Traits/HasFinancialScopes.php`
**Purpose**: Provides financial transaction filtering for models with financial data.

**Common Usage**:
```php
use App\Traits\HasFinancialScopes;

class FinancialRecord extends Model
{
    use HasFinancialScopes, HasDateScopes;
}

// Usage examples:
$income = FinancialRecord::income()->thisMonth()->get();
$expenses = FinancialRecord::expense()->byDateRange('2024-01-01', '2024-12-31')->get();
$largeTransactions = FinancialRecord::amountGreaterThan(1000000)->get();
$cashTransactions = FinancialRecord::cashOnly()->today()->get();

// Get totals:
$totalIncome = FinancialRecord::thisMonth()->totalIncome();
$totalExpense = FinancialRecord::thisMonth()->totalExpense();
```

**Available Scopes**:
- `income($column = 'type')` - Only income records
- `expense($column = 'type')` - Only expense records
- `byType($type, $column = 'type')` - Filter by transaction type
- `amountGreaterThan($amount, $column = 'amount')` - Amount > value
- `amountLessThan($amount, $column = 'amount')` - Amount < value
- `amountBetween($min, $max, $column = 'amount')` - Amount between values
- `byPaymentMethod($method)` - Filter by payment method
- `cashOnly()` - Cash transactions only
- `mobileMoneyOnly()` - Mobile money transactions only
- `bankOnly()` - Bank transactions only
- `byCategory($categoryId)` - Filter by financial category
- `byPeriod($periodId)` - Filter by financial period
- `totalIncome()` - Calculate total income (returns sum)
- `totalExpense()` - Calculate total expenses (returns sum)

---

### 4. HasStockScopes
**Location**: `app/Traits/HasStockScopes.php`
**Purpose**: Provides stock/inventory filtering for stock-related models.

**Common Usage**:
```php
use App\Traits\HasStockScopes;

class StockItem extends Model
{
    use HasStockScopes, HasActiveScope;
}

class StockRecord extends Model
{
    use HasStockScopes, HasDateScopes;
}

// Usage examples:
$lowStockItems = StockItem::lowStock()->active()->get();
$outOfStock = StockItem::outOfStock()->get();
$salesThisMonth = StockRecord::sales()->thisMonth()->get();
$profitableItems = StockRecord::profitable()->get();
```

**Available Scopes**:
- `lowStock()` - Items below reorder level (but > 0)
- `outOfStock()` - Items with quantity <= 0
- `inStock()` - Items with quantity > 0
- `sufficientStock()` - Items at or above reorder level
- `byCategory($categoryId)` - Filter by stock category
- `bySubCategory($subCategoryId)` - Filter by stock sub-category
- `quantityGreaterThan($quantity)` - Quantity > value
- `quantityLessThan($quantity)` - Quantity < value
- `quantityBetween($min, $max)` - Quantity between values
- `byType($type)` - Filter by transaction type
- `stockIn()` - Stock-in transactions
- `stockOut()` - Stock-out transactions (Sale + Stock Out)
- `sales()` - Sales transactions only
- `profitGreaterThan($amount)` - Profit > value
- `profitLessThan($amount)` - Profit < value
- `profitable()` - Items with profit > 0
- `withLoss()` - Items with profit < 0
- `byStockItem($itemId)` - Filter by stock item

---

### 5. HasBudgetScopes
**Location**: `app/Traits/HasBudgetScopes.php`
**Purpose**: Provides budget-specific filtering for budget-related models.

**Common Usage**:
```php
use App\Traits\HasBudgetScopes;

class BudgetItem extends Model
{
    use HasBudgetScopes, HasActiveScope;
}

class ContributionRecord extends Model
{
    use HasBudgetScopes, HasDateScopes;
}

// Usage examples:
$overBudgetItems = BudgetItem::overBudget()->get();
$pendingApproval = BudgetItem::pending()->byProgram($programId)->get();
$unpaidContributions = ContributionRecord::notFullyPaid()->get();
$highUtilization = BudgetItem::utilizationGreaterThan(80)->get();
```

**Available Scopes**:
- `byProgram($programId)` - Filter by budget program
- `byCategory($categoryId)` - Filter by budget item category
- `overBudget()` - Items where spent > target
- `underBudget()` - Items where spent < target
- `atBudget()` - Items where spent = target
- `withinBudget()` - Items where spent <= target
- `pending()` - Pending approval items
- `approved()` - Approved items
- `rejected()` - Rejected items
- `fullyPaid()` - Fully paid contributions
- `notFullyPaid()` - Not fully paid contributions
- `withBalance()` - Contributions with outstanding balance
- `byTreasurer($treasurerId)` - Filter by treasurer
- `targetGreaterThan($amount)` - Target amount > value
- `targetLessThan($amount)` - Target amount < value
- `spentGreaterThan($amount)` - Spent amount > value
- `spentLessThan($amount)` - Spent amount < value
- `utilizationGreaterThan($percentage)` - Budget utilization > percentage
- `utilizationLessThan($percentage)` - Budget utilization < percentage

---

## Combining Traits

Multiple traits can be used together for powerful filtering:

```php
use App\Traits\HasDateScopes;
use App\Traits\HasFinancialScopes;
use App\Traits\HasActiveScope;

class FinancialRecord extends Model
{
    use HasDateScopes, HasFinancialScopes, HasActiveScope;
}

// Combine scopes from multiple traits:
$records = FinancialRecord::income()                    // HasFinancialScopes
    ->thisMonth()                                       // HasDateScopes
    ->amountGreaterThan(500000)                        // HasFinancialScopes
    ->byPaymentMethod('Cash')                          // HasFinancialScopes
    ->get();

$monthlyIncome = FinancialRecord::income()
    ->thisMonth()
    ->sum('amount');
```

## Chaining Scopes

All scopes return a query builder, so they can be chained:

```php
// Stock management example:
$criticalItems = StockItem::active()
    ->lowStock()
    ->byCategory($categoryId)
    ->orderBy('current_quantity', 'asc')
    ->get();

// Financial reporting example:
$quarterReport = FinancialRecord::income()
    ->thisQuarter()
    ->byCategory($categoryId)
    ->amountGreaterThan(100000)
    ->orderBy('date', 'desc')
    ->get();

// Budget tracking example:
$criticalBudgets = BudgetItem::approved()
    ->overBudget()
    ->byProgram($programId)
    ->utilizationGreaterThan(90)
    ->orderBy('spent_amount', 'desc')
    ->get();
```

## Custom Column Names

Most scopes support custom column names for flexibility:

```php
// Using different column name for dates:
$records = Model::thisMonth('created_at')->get();
$records = Model::byDateRange('2024-01-01', '2024-12-31', 'updated_at')->get();

// Using different column name for status:
$records = Model::active('approval_status')->get();
$records = Model::byStatus('Completed', 'task_status')->get();
```

## Performance Benefits

1. **Code Reusability**: Common filtering logic defined once, used everywhere
2. **Consistency**: Same filtering behavior across all models
3. **Maintainability**: Easy to update filtering logic in one place
4. **Readability**: Chainable, expressive query building
5. **Type Safety**: Methods are documented and IDE-friendly

## Best Practices

1. **Use Specific Traits**: Only include traits your model actually needs
2. **Chain Wisely**: Order scopes from most to least restrictive for better performance
3. **Index Columns**: Ensure filtered columns have database indexes
4. **Combine with Eager Loading**: Use with `with()` to prevent N+1 queries

```php
// Good: Specific filtering with eager loading
$items = StockItem::active()
    ->lowStock()
    ->with(['stockCategory', 'stockSubCategory'])
    ->get();

// Better: Add ordering and limiting
$items = StockItem::active()
    ->lowStock()
    ->with(['stockCategory', 'stockSubCategory'])
    ->orderBy('current_quantity', 'asc')
    ->limit(20)
    ->get();
```

## Testing Examples

```php
// In your tests:
public function test_can_filter_by_date_range()
{
    $records = FinancialRecord::byDateRange('2024-01-01', '2024-12-31')->get();
    $this->assertTrue($records->every(fn($r) => 
        $r->date >= '2024-01-01' && $r->date <= '2024-12-31'
    ));
}

public function test_low_stock_scope_works()
{
    $items = StockItem::lowStock()->get();
    $this->assertTrue($items->every(fn($i) => 
        $i->current_quantity < $i->reorder_level && $i->current_quantity > 0
    ));
}
```

## Migration Guide

To adopt these traits in existing models:

1. Add the trait use statement
2. Remove duplicate scope methods if they exist
3. Update controller queries to use new scopes
4. Test thoroughly

```php
// Before:
class FinancialRecord extends Model
{
    public function scopeThisMonth($query)
    {
        return $query->whereMonth('date', now()->month)
                    ->whereYear('date', now()->year);
    }
}

// After:
class FinancialRecord extends Model
{
    use HasDateScopes, HasFinancialScopes;
    
    // Remove duplicate scope methods - now provided by traits
}
```

---

**Created**: November 7, 2025
**Version**: 1.0
**Phase**: 3 - Performance Optimization
