# Performance Testing Guide

## Overview
This guide provides methods to test and verify the performance improvements made in Phase 3 optimization work.

## Testing Methods

### 1. Manual Query Counting

#### Test N+1 Query Prevention

**Before Optimization** (Expected: 100+ queries):
```php
// In Tinker or a test route
php artisan tinker

// Load items without eager loading
$items = StockItem::limit(10)->get();
foreach ($items as $item) {
    echo $item->stockCategory->name; // N+1 query per iteration
}

// Check query count in logs
```

**After Optimization** (Expected: 3-5 queries):
```php
// Load items WITH eager loading (automatic now)
$items = StockItem::limit(10)->get();
foreach ($items as $item) {
    echo $item->stockCategory->name; // No additional queries!
}
```

### 2. Enable Query Logging

Add to any controller or test route:

```php
use Illuminate\Support\Facades\DB;

// Enable query log
DB::enableQueryLog();

// Your code here
$records = FinancialRecord::thisMonth()->income()->get();

// Get executed queries
$queries = DB::getQueryLog();
echo "Total queries: " . count($queries) . "\n";
dd($queries);
```

### 3. Benchmark Response Times

Create a test route in `routes/web.php`:

```php
Route::get('/test-performance', function () {
    $results = [];
    
    // Test 1: Budget Item Creation
    $start = microtime(true);
    $budgetItem = BudgetItem::create([
        'name' => 'Test Item',
        'budget_program_id' => 1,
        'budget_item_category_id' => 1,
        'unit_price' => 1000,
        'quantity' => 10,
        'created_by_id' => 1,
    ]);
    $results['budget_item_create'] = (microtime(true) - $start) * 1000 . ' ms';
    
    // Test 2: Financial Records Query
    $start = microtime(true);
    $records = FinancialRecord::thisMonth()->income()->get();
    $results['financial_records_query'] = (microtime(true) - $start) * 1000 . ' ms';
    
    // Test 3: Stock Items with Categories
    DB::enableQueryLog();
    $start = microtime(true);
    $items = StockItem::limit(20)->get();
    foreach ($items as $item) {
        $cat = $item->stockCategory; // Should be eager loaded
    }
    $queryTime = (microtime(true) - $start) * 1000;
    $queryCount = count(DB::getQueryLog());
    $results['stock_items_eager_load'] = [
        'time' => $queryTime . ' ms',
        'queries' => $queryCount,
    ];
    
    return response()->json($results);
});
```

### 4. Cache Testing

Test cache functionality:

```php
use App\Services\CacheService;

// Test cache hit/miss
$start = microtime(true);
$period1 = CacheService::getActiveFinancialPeriod(1); // Cache miss
$time1 = (microtime(true) - $start) * 1000;

$start = microtime(true);
$period2 = CacheService::getActiveFinancialPeriod(1); // Cache hit
$time2 = (microtime(true) - $start) * 1000;

echo "First call (cache miss): {$time1} ms\n";
echo "Second call (cache hit): {$time2} ms\n";
echo "Cache improvement: " . (($time1 - $time2) / $time1 * 100) . "%\n";

// View cache stats
$stats = CacheService::getCacheStats(1);
dd($stats);
```

### 5. Job Queue Testing

Test background job processing:

```php
use App\Jobs\SendBudgetItemNotification;
use Illuminate\Support\Facades\Queue;

// Test job dispatch
Queue::fake();

$budgetItem = BudgetItem::find(1);
$budgetItem->save(); // Should dispatch job

// Verify job was dispatched
Queue::assertPushed(SendBudgetItemNotification::class);

// Or test actual execution
$job = new SendBudgetItemNotification(1, 1, 1);
$start = microtime(true);
$job->handle();
$time = (microtime(true) - $start) * 1000;
echo "Job execution time: {$time} ms\n";
```

### 6. Query Scope Testing

Test the reusable query scopes:

```php
// Date scopes
$today = FinancialRecord::today()->count();
$thisMonth = FinancialRecord::thisMonth()->count();
$thisYear = FinancialRecord::thisYear()->count();

echo "Records today: {$today}\n";
echo "Records this month: {$thisMonth}\n";
echo "Records this year: {$thisYear}\n";

// Chained scopes
$monthlyIncome = FinancialRecord::income()
    ->thisMonth()
    ->amountGreaterThan(1000)
    ->count();

echo "High-value income this month: {$monthlyIncome}\n";

// Stock scopes
$lowStock = StockItem::lowStock()->count();
$outOfStock = StockItem::outOfStock()->count();

echo "Low stock items: {$lowStock}\n";
echo "Out of stock items: {$outOfStock}\n";
```

## Performance Benchmarks

### Expected Results

| Test | Before | After | Target |
|------|--------|-------|--------|
| Budget Item Save | 5-10s | <100ms | ✅ 98% improvement |
| Financial Record Query | 500ms | 50ms | ✅ 90% improvement |
| Stock Items (20) + Categories | 21 queries | 3 queries | ✅ 86% reduction |
| Cache Hit | 50ms | <1ms | ✅ 98% faster |
| Monthly Report Generation | 30s | 3s | ✅ 90% faster |

### Measurement Criteria

**Response Time** (Target: <100ms):
- ✅ Excellent: <50ms
- ✅ Good: 50-100ms
- ⚠️ Acceptable: 100-500ms
- ❌ Needs Work: >500ms

**Query Count** (Target: <10 per page):
- ✅ Excellent: 1-5 queries
- ✅ Good: 5-10 queries
- ⚠️ Acceptable: 10-20 queries
- ❌ Needs Work: >20 queries

**Cache Hit Rate** (Target: >80%):
- ✅ Excellent: >90%
- ✅ Good: 80-90%
- ⚠️ Acceptable: 60-80%
- ❌ Needs Work: <60%

## Testing Checklist

### ✅ Database Query Optimization
- [ ] Verify eager loading is active (`$with` property)
- [ ] Test that relationships load without additional queries
- [ ] Confirm query count is <10 for list pages
- [ ] Check that query scopes work correctly

### ✅ Caching Strategy
- [ ] Verify cache is storing data (check cache stats)
- [ ] Test cache hit rate is >80%
- [ ] Confirm cache invalidation works on updates
- [ ] Test warmUp cache function

### ✅ Query Scopes
- [ ] Test date scopes (today, thisMonth, thisYear)
- [ ] Test status scopes (active, inactive, pending)
- [ ] Test financial scopes (income, expense)
- [ ] Test stock scopes (lowStock, outOfStock)
- [ ] Test budget scopes (overBudget, underBudget)
- [ ] Test scope chaining

### ✅ Model Events
- [ ] Verify jobs are dispatched (not executed inline)
- [ ] Test queue worker processes jobs
- [ ] Confirm emails are sent asynchronously
- [ ] Test aggregate updates happen in background

## Alternative Testing Tools

Since Laravel Debugbar requires PHP 8.1-8.3 and project uses PHP 8.4.7:

### 1. Clockwork (Alternative Debugger)

```bash
composer require itsgoingd/clockwork --dev
```

Then visit: `http://your-app/__clockwork`

### 2. Laravel Telescope

```bash
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate
```

Then visit: `http://your-app/telescope`

### 3. Custom Logging

Add to `app/Http/Middleware/QueryLogger.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QueryLogger
{
    public function handle($request, Closure $next)
    {
        DB::enableQueryLog();
        $start = microtime(true);
        
        $response = $next($request);
        
        $time = (microtime(true) - $start) * 1000;
        $queries = DB::getQueryLog();
        
        Log::channel('performance')->info('Request Performance', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'time' => round($time, 2) . ' ms',
            'query_count' => count($queries),
            'queries' => $queries,
        ]);
        
        return $response;
    }
}
```

Register in `app/Http/Kernel.php`:
```php
protected $middlewareGroups = [
    'web' => [
        // ... other middleware
        \App\Http\Middleware\QueryLogger::class,
    ],
];
```

### 4. Simple Performance Monitor

Create `routes/web.php` test route:

```php
Route::get('/performance-monitor', function () {
    $results = [
        'php_version' => phpversion(),
        'laravel_version' => app()->version(),
        'environment' => app()->environment(),
        'cache_driver' => config('cache.default'),
        'queue_driver' => config('queue.default'),
    ];
    
    // Test database performance
    DB::enableQueryLog();
    $start = microtime(true);
    
    FinancialRecord::with(['financial_category', 'createdBy'])
        ->thisMonth()
        ->limit(50)
        ->get();
    
    $results['database'] = [
        'time' => round((microtime(true) - $start) * 1000, 2) . ' ms',
        'queries' => count(DB::getQueryLog()),
    ];
    
    // Test cache performance
    $start = microtime(true);
    Cache::remember('test_key', 60, function () {
        return 'test_value';
    });
    $results['cache_write'] = round((microtime(true) - $start) * 1000, 2) . ' ms';
    
    $start = microtime(true);
    Cache::get('test_key');
    $results['cache_read'] = round((microtime(true) - $start) * 1000, 2) . ' ms';
    
    return response()->json($results, 200, [], JSON_PRETTY_PRINT);
});
```

## Monitoring in Production

### 1. Application Performance Monitoring (APM)

Consider using:
- **New Relic** - Full APM solution
- **Datadog** - Infrastructure + APM
- **Scout APM** - Laravel-specific
- **Blackfire.io** - PHP profiling

### 2. Database Monitoring

```sql
-- MySQL: Show slow queries
SHOW VARIABLES LIKE 'slow_query_log';
SHOW VARIABLES LIKE 'long_query_time';

-- Enable slow query log
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 1; -- queries > 1 second

-- View table sizes
SELECT 
    table_name AS 'Table',
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size (MB)'
FROM information_schema.TABLES
WHERE table_schema = 'budget_pro'
ORDER BY (data_length + index_length) DESC;
```

### 3. Queue Monitoring

```bash
# Check queue status
php artisan queue:work --once

# Monitor failed jobs
php artisan queue:failed

# Check queue size
php artisan queue:monitor

# View horizon (if installed)
php artisan horizon:status
```

### 4. Log Analysis

Monitor `storage/logs/laravel.log`:

```bash
# View recent errors
tail -n 100 storage/logs/laravel.log | grep ERROR

# Count queries by type
grep "select" storage/logs/laravel.log | wc -l

# Find slow operations
grep "took longer than" storage/logs/laravel.log
```

## Performance Testing Results Template

Create `PERFORMANCE_TEST_RESULTS.md`:

```markdown
# Performance Test Results

**Date**: [Test Date]
**Environment**: [Local/Staging/Production]
**PHP Version**: [Version]
**Database**: [MySQL Version]

## Test 1: Query Optimization

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| Queries per page | 150 | 5 | 97% ↓ |
| Page load time | 3.5s | 0.2s | 94% ↓ |

## Test 2: Caching

| Operation | Without Cache | With Cache | Improvement |
|-----------|---------------|------------|-------------|
| Get active period | 45ms | <1ms | 98% ↓ |
| Get categories | 120ms | 2ms | 98% ↓ |

## Test 3: Background Jobs

| Operation | Synchronous | Asynchronous | Improvement |
|-----------|-------------|--------------|-------------|
| Budget save + email | 8.5s | 0.08s | 99% ↓ |

## Recommendations

- [ ] Action item 1
- [ ] Action item 2
```

## Troubleshooting

### High Query Count

**Issue**: Still seeing many queries
**Solutions**:
1. Check if `$with` property is defined
2. Verify relationships are loaded
3. Look for lazy loading in loops
4. Add `->with(['relation'])` explicitly

### Cache Not Working

**Issue**: Cache misses every time
**Solutions**:
1. Check cache driver: `php artisan config:cache`
2. Verify cache is enabled: `config('cache.default')`
3. Check cache permissions: `storage/framework/cache`
4. Test manually: `Cache::put('test', 'value', 60)`

### Jobs Not Processing

**Issue**: Jobs stuck in queue
**Solutions**:
1. Start queue worker: `php artisan queue:work`
2. Check failed jobs: `php artisan queue:failed`
3. Verify queue driver: `config('queue.default')`
4. Check database queue table: `jobs`

### Slow Performance Still

**Issue**: Still experiencing slow operations
**Solutions**:
1. Check database indexes
2. Optimize complex queries
3. Add more caching
4. Review event listeners
5. Profile with Blackfire

## Next Steps

1. ✅ Run all tests from this guide
2. ✅ Document results in template
3. ✅ Identify any remaining bottlenecks
4. ✅ Implement additional optimizations if needed
5. ✅ Monitor production performance

---

**Created**: November 7, 2025
**Phase**: 3 - Performance Optimization - Task 5
**Status**: Complete (PHP 8.4.7 incompatible with Laravel Debugbar)
