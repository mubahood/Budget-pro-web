<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\FinancialPeriod;
use App\Models\Company;
use App\Models\StockCategory;
use App\Models\FinancialCategory;
use App\Models\BudgetProgram;

class CacheService
{
    /**
     * Cache duration constants (in minutes)
     */
    const CACHE_DURATION_SHORT = 60;        // 1 hour
    const CACHE_DURATION_MEDIUM = 360;      // 6 hours
    const CACHE_DURATION_LONG = 1440;       // 24 hours
    const CACHE_DURATION_VERY_LONG = 10080; // 7 days

    /**
     * Cache key prefixes
     */
    const PREFIX_COMPANY = 'company';
    const PREFIX_FINANCIAL_PERIOD = 'financial_period';
    const PREFIX_STOCK_CATEGORY = 'stock_category';
    const PREFIX_FINANCIAL_CATEGORY = 'financial_category';
    const PREFIX_BUDGET_PROGRAM = 'budget_program';

    /**
     * Get active financial period for a company (cached for 1 hour)
     */
    public static function getActiveFinancialPeriod($companyId)
    {
        $cacheKey = self::PREFIX_FINANCIAL_PERIOD . ':active:' . $companyId;
        
        return Cache::remember($cacheKey, self::CACHE_DURATION_SHORT, function () use ($companyId) {
            Log::info("Cache miss: Fetching active financial period for company {$companyId}");
            return FinancialPeriod::where('company_id', $companyId)
                ->where('status', 'Active')
                ->first();
        });
    }

    /**
     * Get all financial periods for a company (cached for 6 hours)
     */
    public static function getFinancialPeriods($companyId)
    {
        $cacheKey = self::PREFIX_FINANCIAL_PERIOD . ':all:' . $companyId;
        
        return Cache::remember($cacheKey, self::CACHE_DURATION_MEDIUM, function () use ($companyId) {
            Log::info("Cache miss: Fetching all financial periods for company {$companyId}");
            return FinancialPeriod::where('company_id', $companyId)
                ->orderBy('start_date', 'desc')
                ->get();
        });
    }

    /**
     * Get company settings (cached for 24 hours)
     */
    public static function getCompany($companyId)
    {
        $cacheKey = self::PREFIX_COMPANY . ':' . $companyId;
        
        return Cache::remember($cacheKey, self::CACHE_DURATION_LONG, function () use ($companyId) {
            Log::info("Cache miss: Fetching company {$companyId}");
            return Company::find($companyId);
        });
    }

    /**
     * Get active stock categories for a company (cached for 6 hours)
     */
    public static function getActiveStockCategories($companyId)
    {
        $cacheKey = self::PREFIX_STOCK_CATEGORY . ':active:' . $companyId;
        
        return Cache::remember($cacheKey, self::CACHE_DURATION_MEDIUM, function () use ($companyId) {
            Log::info("Cache miss: Fetching active stock categories for company {$companyId}");
            return StockCategory::where('company_id', $companyId)
                ->active()
                ->orderBy('name', 'asc')
                ->get();
        });
    }

    /**
     * Get all stock categories for a company (cached for 6 hours)
     */
    public static function getStockCategories($companyId)
    {
        $cacheKey = self::PREFIX_STOCK_CATEGORY . ':all:' . $companyId;
        
        return Cache::remember($cacheKey, self::CACHE_DURATION_MEDIUM, function () use ($companyId) {
            Log::info("Cache miss: Fetching all stock categories for company {$companyId}");
            return StockCategory::where('company_id', $companyId)
                ->orderBy('name', 'asc')
                ->get();
        });
    }

    /**
     * Get financial categories for a company (cached for 6 hours)
     */
    public static function getFinancialCategories($companyId, $type = null)
    {
        $cacheKey = self::PREFIX_FINANCIAL_CATEGORY . ':' . $companyId . ':' . ($type ?? 'all');
        
        return Cache::remember($cacheKey, self::CACHE_DURATION_MEDIUM, function () use ($companyId, $type) {
            Log::info("Cache miss: Fetching financial categories (type: {$type}) for company {$companyId}");
            
            $query = FinancialCategory::where('company_id', $companyId);
            
            if ($type) {
                $query->where('type', $type);
            }
            
            return $query->orderBy('name', 'asc')->get();
        });
    }

    /**
     * Get active budget programs for a company (cached for 1 hour)
     */
    public static function getActiveBudgetPrograms($companyId)
    {
        $cacheKey = self::PREFIX_BUDGET_PROGRAM . ':active:' . $companyId;
        
        return Cache::remember($cacheKey, self::CACHE_DURATION_SHORT, function () use ($companyId) {
            Log::info("Cache miss: Fetching active budget programs for company {$companyId}");
            return BudgetProgram::where('company_id', $companyId)
                ->active()
                ->orderBy('name', 'asc')
                ->get();
        });
    }

    /**
     * Clear all cache for a company
     */
    public static function clearCompanyCache($companyId)
    {
        $patterns = [
            self::PREFIX_COMPANY . ':' . $companyId,
            self::PREFIX_FINANCIAL_PERIOD . ':*:' . $companyId,
            self::PREFIX_STOCK_CATEGORY . ':*:' . $companyId,
            self::PREFIX_FINANCIAL_CATEGORY . ':' . $companyId . ':*',
            self::PREFIX_BUDGET_PROGRAM . ':*:' . $companyId,
        ];

        foreach ($patterns as $pattern) {
            Cache::forget($pattern);
            Log::info("Cache cleared: {$pattern}");
        }
    }

    /**
     * Clear financial period cache for a company
     */
    public static function clearFinancialPeriodCache($companyId)
    {
        Cache::forget(self::PREFIX_FINANCIAL_PERIOD . ':active:' . $companyId);
        Cache::forget(self::PREFIX_FINANCIAL_PERIOD . ':all:' . $companyId);
        Log::info("Financial period cache cleared for company {$companyId}");
    }

    /**
     * Clear stock category cache for a company
     */
    public static function clearStockCategoryCache($companyId)
    {
        Cache::forget(self::PREFIX_STOCK_CATEGORY . ':active:' . $companyId);
        Cache::forget(self::PREFIX_STOCK_CATEGORY . ':all:' . $companyId);
        Log::info("Stock category cache cleared for company {$companyId}");
    }

    /**
     * Clear financial category cache for a company
     */
    public static function clearFinancialCategoryCache($companyId)
    {
        Cache::forget(self::PREFIX_FINANCIAL_CATEGORY . ':' . $companyId . ':all');
        Cache::forget(self::PREFIX_FINANCIAL_CATEGORY . ':' . $companyId . ':Income');
        Cache::forget(self::PREFIX_FINANCIAL_CATEGORY . ':' . $companyId . ':Expense');
        Log::info("Financial category cache cleared for company {$companyId}");
    }

    /**
     * Clear budget program cache for a company
     */
    public static function clearBudgetProgramCache($companyId)
    {
        Cache::forget(self::PREFIX_BUDGET_PROGRAM . ':active:' . $companyId);
        Cache::forget(self::PREFIX_BUDGET_PROGRAM . ':all:' . $companyId);
        Log::info("Budget program cache cleared for company {$companyId}");
    }

    /**
     * Clear company cache
     */
    public static function clearCompanySettingsCache($companyId)
    {
        Cache::forget(self::PREFIX_COMPANY . ':' . $companyId);
        Log::info("Company settings cache cleared for company {$companyId}");
    }

    /**
     * Warm up cache for a company (preload frequently accessed data)
     */
    public static function warmUpCache($companyId)
    {
        Log::info("Warming up cache for company {$companyId}");
        
        // Preload commonly accessed data
        self::getCompany($companyId);
        self::getActiveFinancialPeriod($companyId);
        self::getActiveStockCategories($companyId);
        self::getFinancialCategories($companyId);
        self::getActiveBudgetPrograms($companyId);
        
        Log::info("Cache warmed up for company {$companyId}");
    }

    /**
     * Get cache statistics for debugging
     */
    public static function getCacheStats($companyId)
    {
        $stats = [
            'company_id' => $companyId,
            'cached_items' => [],
        ];

        $keys = [
            'company' => self::PREFIX_COMPANY . ':' . $companyId,
            'active_period' => self::PREFIX_FINANCIAL_PERIOD . ':active:' . $companyId,
            'all_periods' => self::PREFIX_FINANCIAL_PERIOD . ':all:' . $companyId,
            'active_stock_categories' => self::PREFIX_STOCK_CATEGORY . ':active:' . $companyId,
            'all_stock_categories' => self::PREFIX_STOCK_CATEGORY . ':all:' . $companyId,
            'financial_categories' => self::PREFIX_FINANCIAL_CATEGORY . ':' . $companyId . ':all',
            'active_budget_programs' => self::PREFIX_BUDGET_PROGRAM . ':active:' . $companyId,
        ];

        foreach ($keys as $name => $key) {
            $stats['cached_items'][$name] = Cache::has($key) ? 'cached' : 'not cached';
        }

        return $stats;
    }
}
