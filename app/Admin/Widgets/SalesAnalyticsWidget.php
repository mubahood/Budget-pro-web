<?php

namespace App\Admin\Widgets;

use Carbon\Carbon;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Widgets\Widget;
use Illuminate\Support\Facades\DB;

class SalesAnalyticsWidget extends Widget
{
    protected $view = 'admin.widgets.sales-analytics';

    public function __construct()
    {
        $this->class = 'sales-analytics-widget';
        $this->style = '.sales-analytics-widget { margin-bottom: 20px; }';
    }

    public function render()
    {
        $u = Admin::user();
        $companyId = $u->company_id;

        \App\Support\LocalTime::prime((int) $companyId);

        // Get analytics data
        $data = [
            'overview' => $this->getOverviewStats($companyId),
            'trends' => $this->getTrendsData($companyId),
            'top_products' => $this->getTopProducts($companyId),
            'category_breakdown' => $this->getCategoryBreakdown($companyId),
            'monthly_comparison' => $this->getMonthlyComparison($companyId),
            'daily_sales' => $this->getDailySales($companyId),
            'financial_data' => $this->getFinancialData($companyId),
        ];

        return view($this->view, compact('data'));
    }

    /**
     * Get overview statistics for today, this week, this month
     */
    private function getOverviewStats($companyId)
    {
        // Today's sales
        $today = DB::select("
            SELECT 
                COALESCE(COUNT(*), 0) as transactions,
                COALESCE(SUM(quantity), 0) as units_sold,
                COALESCE(SUM(total_sales), 0) as revenue,
                COALESCE(SUM(total_sales - (quantity * si.buying_price)), 0) as profit
            FROM stock_records sr
            JOIN stock_items si ON sr.stock_item_id = si.id
            WHERE sr.company_id = ?
            AND sr.type = 'Sale'
            AND DATE(CONVERT_TZ(sr.created_at, '+00:00', @tz_offset)) = @local_today
        ", [$companyId]);

        // This week's sales
        $week = DB::select("
            SELECT 
                COALESCE(COUNT(*), 0) as transactions,
                COALESCE(SUM(quantity), 0) as units_sold,
                COALESCE(SUM(total_sales), 0) as revenue,
                COALESCE(SUM(total_sales - (quantity * si.buying_price)), 0) as profit
            FROM stock_records sr
            JOIN stock_items si ON sr.stock_item_id = si.id
            WHERE sr.company_id = ?
            AND sr.type = 'Sale'
            AND YEARWEEK(CONVERT_TZ(sr.created_at, '+00:00', @tz_offset)) = YEARWEEK(@local_today)
        ", [$companyId]);

        // This month's sales
        $month = DB::select("
            SELECT 
                COALESCE(COUNT(*), 0) as transactions,
                COALESCE(SUM(quantity), 0) as units_sold,
                COALESCE(SUM(total_sales), 0) as revenue,
                COALESCE(SUM(total_sales - (quantity * si.buying_price)), 0) as profit
            FROM stock_records sr
            JOIN stock_items si ON sr.stock_item_id = si.id
            WHERE sr.company_id = ?
            AND sr.type = 'Sale'
            AND MONTH(CONVERT_TZ(sr.created_at, '+00:00', @tz_offset)) = MONTH(@local_today)
            AND YEAR(CONVERT_TZ(sr.created_at, '+00:00', @tz_offset)) = YEAR(@local_today)
        ", [$companyId]);

        // Previous month for comparison
        $lastMonth = DB::select("
            SELECT 
                COALESCE(SUM(total_sales), 0) as revenue
            FROM stock_records
            WHERE company_id = ?
            AND type = 'Sale'
            AND MONTH(CONVERT_TZ(created_at, '+00:00', @tz_offset)) = MONTH(DATE_SUB(@local_today, INTERVAL 1 MONTH))
            AND YEAR(CONVERT_TZ(created_at, '+00:00', @tz_offset)) = YEAR(DATE_SUB(@local_today, INTERVAL 1 MONTH))
        ", [$companyId]);

        $todayData = $today[0];
        $weekData = $week[0];
        $monthData = $month[0];
        $lastMonthRevenue = $lastMonth[0]->revenue ?? 0;

        // Calculate growth percentage
        $growth = $lastMonthRevenue > 0
            ? (($monthData->revenue - $lastMonthRevenue) / $lastMonthRevenue) * 100
            : 0;

        return [
            'today' => [
                'transactions' => $todayData->transactions,
                'units_sold' => $todayData->units_sold,
                'revenue' => $todayData->revenue,
                'profit' => $todayData->profit,
                'avg_transaction' => $todayData->transactions > 0
                    ? $todayData->revenue / $todayData->transactions
                    : 0,
            ],
            'week' => [
                'transactions' => $weekData->transactions,
                'units_sold' => $weekData->units_sold,
                'revenue' => $weekData->revenue,
                'profit' => $weekData->profit,
            ],
            'month' => [
                'transactions' => $monthData->transactions,
                'units_sold' => $monthData->units_sold,
                'revenue' => $monthData->revenue,
                'profit' => $monthData->profit,
                'growth_rate' => round($growth, 2),
            ],
        ];
    }

    /**
     * Get sales trends for the last 12 months
     */
    private function getTrendsData($companyId)
    {
        $trends = DB::select("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COALESCE(SUM(total_sales), 0) as revenue,
                COALESCE(SUM(quantity), 0) as units,
                COALESCE(COUNT(*), 0) as transactions
            FROM stock_records
            WHERE company_id = ?
            AND type = 'Sale'
            AND created_at >= DATE_SUB(@local_today, INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ", [$companyId]);

        $labels = [];
        $revenue = [];
        $units = [];
        $transactions = [];

        foreach ($trends as $trend) {
            $labels[] = Carbon::parse($trend->month.'-01')->format('M Y');
            $revenue[] = $trend->revenue;
            $units[] = $trend->units;
            $transactions[] = $trend->transactions;
        }

        return [
            'labels' => $labels,
            'revenue' => $revenue,
            'units' => $units,
            'transactions' => $transactions,
        ];
    }

    /**
     * Get top 10 best-selling products
     */
    private function getTopProducts($companyId)
    {
        $products = DB::select("
            SELECT 
                si.id,
                si.name,
                si.image,
                COALESCE(SUM(sr.quantity), 0) as total_sold,
                COALESCE(SUM(sr.total_sales), 0) as total_revenue,
                COALESCE(SUM(sr.total_sales - (sr.quantity * si.buying_price)), 0) as total_profit
            FROM stock_records sr
            JOIN stock_items si ON sr.stock_item_id = si.id
            WHERE sr.company_id = ?
            AND sr.type = 'Sale'
            AND MONTH(CONVERT_TZ(sr.created_at, '+00:00', @tz_offset)) = MONTH(@local_today)
            AND YEAR(CONVERT_TZ(sr.created_at, '+00:00', @tz_offset)) = YEAR(@local_today)
            GROUP BY si.id, si.name, si.image
            ORDER BY total_revenue DESC
            LIMIT 10
        ", [$companyId]);

        return $products;
    }

    /**
     * Get sales breakdown by category
     */
    private function getCategoryBreakdown($companyId)
    {
        $categories = DB::select("
            SELECT 
                sc.id,
                sc.name,
                COALESCE(SUM(sr.total_sales), 0) as total_sales,
                COALESCE(SUM(sr.quantity), 0) as total_units,
                COUNT(DISTINCT sr.stock_item_id) as product_count
            FROM stock_records sr
            JOIN stock_items si ON sr.stock_item_id = si.id
            JOIN stock_sub_categories ssc ON si.stock_sub_category_id = ssc.id
            JOIN stock_categories sc ON ssc.stock_category_id = sc.id
            WHERE sr.company_id = ?
            AND sr.type = 'Sale'
            AND MONTH(CONVERT_TZ(sr.created_at, '+00:00', @tz_offset)) = MONTH(@local_today)
            AND YEAR(CONVERT_TZ(sr.created_at, '+00:00', @tz_offset)) = YEAR(@local_today)
            GROUP BY sc.id, sc.name
            ORDER BY total_sales DESC
        ", [$companyId]);

        // Calculate percentages
        $totalSales = array_sum(array_column($categories, 'total_sales'));

        $result = [];
        foreach ($categories as $cat) {
            $percentage = $totalSales > 0 ? ($cat->total_sales / $totalSales) * 100 : 0;
            $result[] = [
                'name' => $cat->name,
                'sales' => $cat->total_sales,
                'units' => $cat->total_units,
                'products' => $cat->product_count,
                'percentage' => round($percentage, 2),
            ];
        }

        return $result;
    }

    /**
     * Get monthly comparison with previous months
     */
    private function getMonthlyComparison($companyId)
    {
        $months = [];
        for ($i = 2; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $monthData = DB::select("
                SELECT 
                    COALESCE(SUM(total_sales), 0) as revenue,
                    COALESCE(SUM(quantity), 0) as units,
                    COALESCE(COUNT(*), 0) as transactions
                FROM stock_records
                WHERE company_id = ?
                AND type = 'Sale'
                AND MONTH(CONVERT_TZ(created_at, '+00:00', @tz_offset)) = ?
                AND YEAR(CONVERT_TZ(created_at, '+00:00', @tz_offset)) = ?
            ", [$companyId, $date->month, $date->year]);

            $data = $monthData[0];
            $months[] = [
                'label' => $date->format('M Y'),
                'revenue' => $data->revenue,
                'units' => $data->units,
                'transactions' => $data->transactions,
            ];
        }

        return $months;
    }

    /**
     * Get daily sales for the current month including today with profit calculation
     */
    private function getDailySales($companyId)
    {
        // Every sale: sale documents and the old app's stand-alone sale movements (client report 2026-09-25).
        [$from, $bind] = \App\Support\SalesSource::sql((int) $companyId);
        $daily = DB::select("
            SELECT s.sale_date as date, COALESCE(SUM(s.total_amount), 0) as revenue, COUNT(*) as transactions, COALESCE(SUM(s.profit), 0) as profit
            FROM {$from}
            WHERE MONTH(s.sale_date) = MONTH(@local_today) AND YEAR(s.sale_date) = YEAR(@local_today) AND s.sale_date <= @local_today
            GROUP BY s.sale_date
            ORDER BY date ASC
        ", $bind);

        $labels = [];
        $revenue = [];
        $transactions = [];
        $profit = [];

        foreach ($daily as $day) {
            $labels[] = Carbon::parse($day->date)->format('d M');
            $revenue[] = $day->revenue;
            $transactions[] = $day->transactions;
            $profit[] = $day->profit;
        }

        return [
            'labels' => $labels,
            'revenue' => $revenue,
            'transactions' => $transactions,
            'profit' => $profit,
        ];
    }

    /**
     * Get financial data (income and expense) for daily transactions from FinancialRecord
     */
    private function getFinancialData($companyId)
    {
        // Get income and expense data for current month (daily)
        $financial = DB::select("
            SELECT 
                DATE(date) as day,
                COALESCE(SUM(CASE WHEN type = 'Income' THEN amount ELSE 0 END), 0) as income,
                COALESCE(SUM(CASE WHEN type = 'Expense' THEN amount ELSE 0 END), 0) as expense,
                COALESCE(COUNT(CASE WHEN type = 'Income' THEN 1 END), 0) as income_count,
                COALESCE(COUNT(CASE WHEN type = 'Expense' THEN 1 END), 0) as expense_count
            FROM financial_records
            WHERE company_id = ?
            AND MONTH(date) = MONTH(@local_today)
            AND YEAR(date) = YEAR(@local_today)
            AND DATE(date) <= @local_today
            GROUP BY DATE(date)
            ORDER BY day ASC
        ", [$companyId]);

        $labels = [];
        $income = [];
        $expense = [];
        $totalIncome = 0;
        $totalExpense = 0;

        foreach ($financial as $record) {
            $labels[] = Carbon::parse($record->day)->format('d M');
            $income[] = $record->income;
            $expense[] = $record->expense;
            $totalIncome += $record->income;
            $totalExpense += $record->expense;
        }

        return [
            'labels' => $labels,
            'income' => $income,
            'expense' => $expense,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'balance' => $totalIncome - $totalExpense,
        ];
    }
}
