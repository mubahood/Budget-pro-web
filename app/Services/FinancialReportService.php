<?php

namespace App\Services;

use App\Models\FinancialRecord;
use App\Support\LocalTime;
use App\Support\SalesSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the Financial / Inventory PDF reports.
 *
 * Sales come from App\Support\SalesSource (sale documents and old-app sale movements, voids left
 * out, net of returns, stored profit at the cost of the day). Ledger figures skip soft-deleted rows.
 * Stock bought (goods receipts, supplier payments, purchase returns) is cost of goods, which is
 * counted when the goods are sold — never again as an operating expense.
 */
class FinancialReportService
{
    /** Ledger rows that are stock purchases, not operating expenses / other income. */
    private const STOCK_EXPENSE_SOURCES = ['goods_receipt', 'supplier_payment'];

    private const NON_OPERATING_INCOME_SOURCES = ['payment', 'stock_record', 'purchase_return'];

    private function day($date): string
    {
        return Carbon::parse($date)->toDateString();
    }

    /** Cached per company under a version number, so clearCache() drops every range at once. */
    private function cacheKey(string $name, $companyId, $startDate, $endDate): string
    {
        $version = (int) Cache::get("financial_report_version_{$companyId}", 1);

        return "{$name}_{$companyId}_v{$version}_{$this->day($startDate)}_{$this->day($endDate)}";
    }

    /** Sales rows in a date range (local sale days), aliased "s". */
    private function salesIn(int $companyId, string $from, string $to): array
    {
        LocalTime::prime($companyId);
        [$sql, $bind] = SalesSource::sql($companyId, 'x');

        return ["(SELECT x.* FROM {$sql} WHERE x.sale_date BETWEEN ? AND ?) s", array_merge($bind, [$from, $to])];
    }

    /** Product lines in a date range, aggregated per product: stock_item_id, quantity_sold, revenue, profit, transaction_count. */
    private function productSalesIn(int $companyId, string $from, string $to): array
    {
        LocalTime::prime($companyId);
        [$sql, $bind] = SalesSource::linesSql($companyId, 'x');

        return ["(SELECT x.stock_item_id, SUM(x.quantity) AS quantity_sold, SUM(x.revenue) AS revenue, SUM(x.profit) AS profit, COUNT(DISTINCT CASE WHEN x.sale_id > 0 THEN x.sale_id END) + COUNT(CASE WHEN x.sale_id <= 0 THEN 1 END) AS transaction_count
            FROM {$sql} WHERE x.sale_date BETWEEN ? AND ? GROUP BY x.stock_item_id) ps", array_merge($bind, [$from, $to])];
    }

    /**
     * Ledger income and expenses (cash in / cash out) for the range.
     */
    public function calculateFinancialData($companyId, $startDate, $endDate)
    {
        return Cache::remember($this->cacheKey('financial_data', $companyId, $startDate, $endDate), 300, function () use ($companyId, $startDate, $endDate) {
            $data = DB::selectOne("
                SELECT
                    COALESCE(SUM(CASE WHEN type = 'Income' THEN amount ELSE 0 END), 0) as total_income,
                    COALESCE(SUM(CASE WHEN type = 'Expense' THEN amount ELSE 0 END), 0) as total_expense,
                    COALESCE(COUNT(CASE WHEN type = 'Income' THEN 1 END), 0) as income_count,
                    COALESCE(COUNT(CASE WHEN type = 'Expense' THEN 1 END), 0) as expense_count
                FROM financial_records
                WHERE company_id = ? AND is_deleted = 0
                AND date >= ?
                AND date <= ?
            ", [$companyId, $this->day($startDate), $this->day($endDate)]);

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
                AND fr.is_deleted = 0
                AND fr.date >= ?
                AND fr.date <= ?
            WHERE fc.company_id = ?
            GROUP BY fc.id, fc.name, fc.description
            HAVING transaction_count > 0
            ORDER BY (total_income - total_expense) DESC
        ", [$companyId, $this->day($startDate), $this->day($endDate), $companyId]);
    }

    /**
     * Get financial records with pagination
     */
    public function getFinanceRecords($companyId, $startDate, $endDate, $limit = 1000)
    {
        return FinancialRecord::where('company_id', $companyId)
            ->where('is_deleted', 0)
            ->whereBetween('date', [$this->day($startDate), $this->day($endDate)])
            ->with(['financial_category', 'createdBy'])
            ->orderBy('date', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Sales, cost of goods sold and gross profit for the range, plus today's stock value.
     */
    public function calculateInventoryData($companyId, $startDate, $endDate)
    {
        return Cache::remember($this->cacheKey('inventory_data', $companyId, $startDate, $endDate), 300, function () use ($companyId, $startDate, $endDate) {
            [$sales, $bind] = $this->salesIn((int) $companyId, $this->day($startDate), $this->day($endDate));
            $salesData = DB::selectOne("
                SELECT COALESCE(SUM(s.total_amount), 0) AS total_sales, COALESCE(SUM(s.profit), 0) AS earned_profit,
                    COUNT(CASE WHEN s.total_amount > 0 THEN 1 END) AS sales_count
                FROM {$sales}
            ", $bind);

            $inventoryValue = DB::selectOne('
                SELECT
                    COALESCE(SUM(current_quantity * buying_price), 0) as total_value,
                    COALESCE(SUM(current_quantity * selling_price), 0) as expected_value,
                    COALESCE(SUM(current_quantity * (selling_price - buying_price)), 0) as expected_profit,
                    COALESCE(COUNT(*), 0) as item_count
                FROM stock_items
                WHERE company_id = ? AND is_deleted = 0
                AND current_quantity > 0
            ', [$companyId]);

            return [
                'inventory_total_buying_price' => (float) $inventoryValue->total_value,
                'inventory_total_selling_price' => (float) $salesData->total_sales,
                'inventory_total_expected_profit' => (float) $inventoryValue->expected_profit,
                'inventory_total_earned_profit' => (float) $salesData->earned_profit,
                'inventory_total_cost' => round((float) $salesData->total_sales - (float) $salesData->earned_profit, 2),
                'sales_count' => (int) $salesData->sales_count,
                'item_count' => (int) $inventoryValue->item_count,
            ];
        });
    }

    /**
     * Sales per category in the range (every product sold, also ones deleted since, so the
     * categories add up to total sales).
     */
    public function getInventoryCategories($companyId, $startDate, $endDate)
    {
        [$ps, $bind] = $this->productSalesIn((int) $companyId, $this->day($startDate), $this->day($endDate));

        return DB::select("
            SELECT
                sc.id,
                sc.name,
                COALESCE(SUM(ps.revenue), 0) as total_sales,
                COALESCE(SUM(ps.revenue - ps.profit), 0) as total_investment,
                COALESCE(SUM(ps.revenue - ps.profit), 0) as total_buying_price,
                COALESCE(SUM(ps.profit), 0) as profit,
                COUNT(DISTINCT si.id) as product_count,
                COALESCE(SUM(ps.quantity_sold), 0) as quantity_sold
            FROM {$ps}
            JOIN stock_items si ON si.id = ps.stock_item_id
            LEFT JOIN stock_sub_categories ssc ON ssc.id = si.stock_sub_category_id
            JOIN stock_categories sc ON sc.id = COALESCE(si.stock_category_id, ssc.stock_category_id)
            WHERE si.company_id = ?
            GROUP BY sc.id, sc.name
            HAVING total_sales > 0
            ORDER BY total_sales DESC
        ", array_merge($bind, [$companyId]));
    }

    /**
     * Current products (not deleted) with what each sold in the range.
     */
    public function getInventoryProducts($companyId, $startDate, $endDate, $limit = 500)
    {
        [$ps, $bind] = $this->productSalesIn((int) $companyId, $this->day($startDate), $this->day($endDate));

        return DB::select("
            SELECT
                si.id,
                si.name,
                si.sku,
                si.buying_price,
                si.selling_price,
                si.original_quantity,
                si.current_quantity,
                COALESCE(ps.quantity_sold, 0) as quantity_sold,
                COALESCE(ps.revenue, 0) as revenue,
                COALESCE(ps.profit, 0) as profit,
                sc.name as category_name
            FROM stock_items si
            LEFT JOIN stock_sub_categories ssc ON si.stock_sub_category_id = ssc.id
            LEFT JOIN stock_categories sc ON sc.id = COALESCE(si.stock_category_id, ssc.stock_category_id)
            LEFT JOIN {$ps} ON ps.stock_item_id = si.id
            WHERE si.company_id = ? AND si.is_deleted = 0
            ORDER BY revenue DESC, si.name ASC
            LIMIT ?
        ", array_merge($bind, [$companyId, (int) $limit]));
    }

    /**
     * Get top performing products
     */
    public function getTopProducts($companyId, $startDate, $endDate, $limit = 10)
    {
        [$ps, $bind] = $this->productSalesIn((int) $companyId, $this->day($startDate), $this->day($endDate));

        return DB::select("
            SELECT si.id, si.name, si.sku, si.image, ps.quantity_sold, ps.revenue, ps.profit, ps.transaction_count
            FROM {$ps}
            JOIN stock_items si ON si.id = ps.stock_item_id
            WHERE si.company_id = ? AND ps.revenue > 0
            ORDER BY ps.revenue DESC
            LIMIT ?
        ", array_merge($bind, [$companyId, (int) $limit]));
    }

    /**
     * Profit & loss for the range: sales − cost of goods sold (gross profit) + other income − operating expenses.
     * Sales income already in the ledger (payments, old sale movements) is not added again, and stock
     * purchases are not deducted as expenses on top of the cost of the goods sold.
     */
    public function getSummaryStatistics($companyId, $startDate, $endDate)
    {
        $financial = $this->calculateFinancialData($companyId, $startDate, $endDate);
        $inventory = $this->calculateInventoryData($companyId, $startDate, $endDate);

        $ledger = DB::selectOne('
            SELECT
                COALESCE(SUM(CASE WHEN type = \'Income\' AND (source_type IS NULL OR source_type NOT IN (?, ?, ?)) THEN amount ELSE 0 END), 0) AS other_income,
                COALESCE(SUM(CASE WHEN type = \'Expense\' AND (source_type IS NULL OR source_type NOT IN (?, ?)) THEN amount ELSE 0 END), 0) AS operating_expenses
            FROM financial_records
            WHERE company_id = ? AND is_deleted = 0 AND date >= ? AND date <= ?
        ', array_merge(self::NON_OPERATING_INCOME_SOURCES, self::STOCK_EXPENSE_SOURCES, [$companyId, $this->day($startDate), $this->day($endDate)]));

        $sales = (float) $inventory['inventory_total_selling_price'];
        $cogs = (float) $inventory['inventory_total_cost'];
        $grossProfit = (float) $inventory['inventory_total_earned_profit'];
        $otherIncome = (float) $ledger->other_income;
        $operating = (float) $ledger->operating_expenses;

        return [
            'financial' => $financial,
            'inventory' => $inventory,
            'gross_profit' => round($grossProfit, 2),
            'other_income' => round($otherIncome, 2),
            'operating_expenses' => round($operating, 2),
            'overall_profit' => round($grossProfit + $otherIncome - $operating, 2),
            'total_revenue' => round($sales + $otherIncome, 2),
            'total_expenses' => round($cogs + $operating, 2),
        ];
    }

    /**
     * Drop every cached figure of a company: the next read uses a new version key.
     */
    public function clearCache($companyId)
    {
        $key = "financial_report_version_{$companyId}";
        Cache::forever($key, (int) Cache::get($key, 1) + 1);
    }
}
