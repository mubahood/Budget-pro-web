<?php

namespace App\Services;

use App\Models\FinancialRecord;
use App\Models\FinancialCategory;
use App\Models\StockItem;
use App\Models\StockRecord;
use App\Models\StockCategory;
use App\Models\SaleRecord;
use App\Models\SaleRecordItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class FinancialReportService
{
    /**
     * Calculate financial data with optimized queries
     */
    public function calculateFinancialData($companyId, $startDate, $endDate)
    {
        $cacheKey = "financial_data_{$companyId}_{$startDate}_{$endDate}";
        
        return Cache::remember($cacheKey, 300, function () use ($companyId, $startDate, $endDate) {
            $data = DB::selectOne("
                SELECT 
                    COALESCE(SUM(CASE WHEN type = 'Income' THEN amount ELSE 0 END), 0) as total_income,
                    COALESCE(SUM(CASE WHEN type = 'Expense' THEN amount ELSE 0 END), 0) as total_expense,
                    COALESCE(COUNT(CASE WHEN type = 'Income' THEN 1 END), 0) as income_count,
                    COALESCE(COUNT(CASE WHEN type = 'Expense' THEN 1 END), 0) as expense_count
                FROM financial_records
                WHERE company_id = ?
                AND date >= ? 
                AND date <= ?
            ", [$companyId, $startDate, $endDate]);

            return [
                'total_income' => (float) $data->total_income,
                'total_expense' => (float) $data->total_expense,
                'profit' => (float) ($data->total_income - $data->total_expense),
                'income_count' => (int) $data->income_count,
                'expense_count' => (int) $data->expense_count,
            ];
        });
    }

    /**
     * Get financial accounts summary with optimized queries
     */
    public function getFinanceAccounts($companyId, $startDate, $endDate)
    {
        return DB::select("
            SELECT 
                fc.id,
                fc.name,
                fc.description,
                COALESCE(SUM(CASE WHEN fr.type = 'Income' THEN fr.amount ELSE 0 END), 0) as total_income,
                COALESCE(SUM(CASE WHEN fr.type = 'Expense' THEN fr.amount ELSE 0 END), 0) as total_expense,
                COALESCE(COUNT(DISTINCT fr.id), 0) as transaction_count
            FROM financial_categories fc
            LEFT JOIN financial_records fr ON fc.id = fr.financial_category_id 
                AND fr.company_id = ? 
                AND fr.date >= ? 
                AND fr.date <= ?
            WHERE fc.company_id = ?
            GROUP BY fc.id, fc.name, fc.description
            HAVING transaction_count > 0
            ORDER BY (total_income - total_expense) DESC
        ", [$companyId, $startDate, $endDate, $companyId]);
    }

    /**
     * Get financial records with pagination
     */
    public function getFinanceRecords($companyId, $startDate, $endDate, $limit = 1000)
    {
        return FinancialRecord::where('company_id', $companyId)
            ->whereBetween('date', [$startDate, $endDate])
            ->with(['financial_category', 'createdBy'])
            ->orderBy('date', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Calculate inventory data with proper joins and calculations
     */
    public function calculateInventoryData($companyId, $startDate, $endDate)
    {
        $cacheKey = "inventory_data_{$companyId}_{$startDate}_{$endDate}";
        
        return Cache::remember($cacheKey, 300, function () use ($companyId, $startDate, $endDate) {
            // Get sales data from sale_records
            $salesData = DB::selectOne("
                SELECT 
                    COALESCE(SUM(sr.total_amount), 0) as total_sales,
                    COALESCE(SUM(sri.quantity * si.buying_price), 0) as total_cost,
                    COALESCE(SUM(sri.quantity * (sri.unit_price - si.buying_price)), 0) as earned_profit,
                    COALESCE(COUNT(DISTINCT sr.id), 0) as sales_count
                FROM sale_records sr
                LEFT JOIN sale_record_items sri ON sr.id = sri.sale_record_id
                LEFT JOIN stock_items si ON sri.stock_item_id = si.id
                WHERE sr.company_id = ?
                AND sr.sale_date >= ?
                AND sr.sale_date <= ?
            ", [$companyId, $startDate, $endDate]);

            // Get current inventory value
            $inventoryValue = DB::selectOne("
                SELECT 
                    COALESCE(SUM(current_quantity * buying_price), 0) as total_value,
                    COALESCE(SUM(current_quantity * selling_price), 0) as expected_value,
                    COALESCE(SUM(current_quantity * (selling_price - buying_price)), 0) as expected_profit,
                    COALESCE(COUNT(*), 0) as item_count
                FROM stock_items
                WHERE company_id = ?
                AND current_quantity > 0
            ", [$companyId]);

            return [
                'inventory_total_buying_price' => (float) $inventoryValue->total_value,
                'inventory_total_selling_price' => (float) $salesData->total_sales,
                'inventory_total_expected_profit' => (float) $inventoryValue->expected_profit,
                'inventory_total_earned_profit' => (float) $salesData->earned_profit,
                'inventory_total_cost' => (float) $salesData->total_cost,
                'sales_count' => (int) $salesData->sales_count,
                'item_count' => (int) $inventoryValue->item_count,
            ];
        });
    }

    /**
     * Get inventory categories summary
     */
    public function getInventoryCategories($companyId, $startDate, $endDate)
    {
        return DB::select("
            SELECT 
                sc.id,
                sc.name,
                COALESCE(SUM(sr.total_amount), 0) as total_sales,
                COALESCE(SUM(sri.quantity * si.buying_price), 0) as total_investment,
                COALESCE(SUM(sri.quantity * (sri.unit_price - si.buying_price)), 0) as profit,
                COALESCE(COUNT(DISTINCT si.id), 0) as product_count,
                COALESCE(SUM(sri.quantity), 0) as quantity_sold
            FROM stock_categories sc
            LEFT JOIN stock_sub_categories ssc ON sc.id = ssc.stock_category_id
            LEFT JOIN stock_items si ON ssc.id = si.stock_sub_category_id
            LEFT JOIN sale_record_items sri ON si.id = sri.stock_item_id
            LEFT JOIN sale_records sr ON sri.sale_record_id = sr.id 
                AND sr.sale_date >= ? 
                AND sr.sale_date <= ?
            WHERE sc.company_id = ?
            GROUP BY sc.id, sc.name
            HAVING total_sales > 0
            ORDER BY total_sales DESC
        ", [$startDate, $endDate, $companyId]);
    }

    /**
     * Get inventory products
     */
    public function getInventoryProducts($companyId, $startDate, $endDate, $limit = 500)
    {
        return DB::select("
            SELECT 
                si.id,
                si.name,
                si.sku,
                si.buying_price,
                si.selling_price,
                si.original_quantity,
                si.current_quantity,
                COALESCE(SUM(sri.quantity), 0) as quantity_sold,
                COALESCE(SUM(sri.quantity * sri.unit_price), 0) as revenue,
                COALESCE(SUM(sri.quantity * (sri.unit_price - si.buying_price)), 0) as profit,
                sc.name as category_name
            FROM stock_items si
            LEFT JOIN stock_sub_categories ssc ON si.stock_sub_category_id = ssc.id
            LEFT JOIN stock_categories sc ON ssc.stock_category_id = sc.id
            LEFT JOIN sale_record_items sri ON si.id = sri.stock_item_id
            LEFT JOIN sale_records sr ON sri.sale_record_id = sr.id 
                AND sr.sale_date >= ? 
                AND sr.sale_date <= ?
            WHERE si.company_id = ?
            GROUP BY si.id, si.name, si.sku, si.buying_price, si.selling_price, 
                     si.original_quantity, si.current_quantity, sc.name
            ORDER BY revenue DESC
            LIMIT ?
        ", [$startDate, $endDate, $companyId, $limit]);
    }

    /**
     * Get top performing products
     */
    public function getTopProducts($companyId, $startDate, $endDate, $limit = 10)
    {
        return DB::select("
            SELECT 
                si.id,
                si.name,
                si.sku,
                si.image,
                COALESCE(SUM(sri.quantity), 0) as quantity_sold,
                COALESCE(SUM(sri.quantity * sri.unit_price), 0) as revenue,
                COALESCE(SUM(sri.quantity * (sri.unit_price - si.buying_price)), 0) as profit,
                COALESCE(COUNT(DISTINCT sr.id), 0) as transaction_count
            FROM stock_items si
            INNER JOIN sale_record_items sri ON si.id = sri.stock_item_id
            INNER JOIN sale_records sr ON sri.sale_record_id = sr.id
            WHERE si.company_id = ?
            AND sr.sale_date >= ?
            AND sr.sale_date <= ?
            GROUP BY si.id, si.name, si.sku, si.image
            HAVING revenue > 0
            ORDER BY revenue DESC
            LIMIT ?
        ", [$companyId, $startDate, $endDate, $limit]);
    }

    /**
     * Get summary statistics
     */
    public function getSummaryStatistics($companyId, $startDate, $endDate)
    {
        $financial = $this->calculateFinancialData($companyId, $startDate, $endDate);
        $inventory = $this->calculateInventoryData($companyId, $startDate, $endDate);

        return [
            'financial' => $financial,
            'inventory' => $inventory,
            'overall_profit' => $financial['profit'] + $inventory['inventory_total_earned_profit'],
            'total_revenue' => $financial['total_income'] + $inventory['inventory_total_selling_price'],
            'total_expenses' => $financial['total_expense'] + $inventory['inventory_total_cost'],
        ];
    }

    /**
     * Clear cache for company reports
     */
    public function clearCache($companyId)
    {
        $patterns = [
            "financial_data_{$companyId}_*",
            "inventory_data_{$companyId}_*",
        ];

        foreach ($patterns as $pattern) {
            Cache::forget($pattern);
        }
    }
}
