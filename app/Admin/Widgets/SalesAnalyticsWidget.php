<?php

namespace App\Admin\Widgets;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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
        
        // Get analytics data
        $data = [
            'overview' => $this->getOverviewStats($companyId),
            'trends' => $this->getTrendsData($companyId),
            'top_products' => $this->getTopProducts($companyId),
            'category_breakdown' => $this->getCategoryBreakdown($companyId),
            'monthly_comparison' => $this->getMonthlyComparison($companyId),
            'daily_sales' => $this->getDailySales($companyId),
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
            AND DATE(sr.created_at) = CURDATE()
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
            AND YEARWEEK(sr.created_at) = YEARWEEK(CURDATE())
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
            AND MONTH(sr.created_at) = MONTH(CURDATE())
            AND YEAR(sr.created_at) = YEAR(CURDATE())
        ", [$companyId]);

        // Previous month for comparison
        $lastMonth = DB::select("
            SELECT 
                COALESCE(SUM(total_sales), 0) as revenue
            FROM stock_records
            WHERE company_id = ?
            AND type = 'Sale'
            AND MONTH(created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
            AND YEAR(created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
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
            AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ", [$companyId]);

        $labels = [];
        $revenue = [];
        $units = [];
        $transactions = [];

        foreach ($trends as $trend) {
            $labels[] = Carbon::parse($trend->month . '-01')->format('M Y');
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
            AND MONTH(sr.created_at) = MONTH(CURDATE())
            AND YEAR(sr.created_at) = YEAR(CURDATE())
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
            AND MONTH(sr.created_at) = MONTH(CURDATE())
            AND YEAR(sr.created_at) = YEAR(CURDATE())
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
                AND MONTH(created_at) = ?
                AND YEAR(created_at) = ?
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
     * Get daily sales for the current month
     */
    private function getDailySales($companyId)
    {
        $daily = DB::select("
            SELECT 
                DATE(created_at) as date,
                COALESCE(SUM(total_sales), 0) as revenue,
                COALESCE(COUNT(*), 0) as transactions
            FROM stock_records
            WHERE company_id = ?
            AND type = 'Sale'
            AND MONTH(created_at) = MONTH(CURDATE())
            AND YEAR(created_at) = YEAR(CURDATE())
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ", [$companyId]);

        $labels = [];
        $revenue = [];
        $transactions = [];

        foreach ($daily as $day) {
            $labels[] = Carbon::parse($day->date)->format('d M');
            $revenue[] = $day->revenue;
            $transactions[] = $day->transactions;
        }

        return [
            'labels' => $labels,
            'revenue' => $revenue,
            'transactions' => $transactions,
        ];
    }
}
