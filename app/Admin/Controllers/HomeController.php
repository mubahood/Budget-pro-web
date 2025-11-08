<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Admin\Widgets\SalesAnalyticsWidget;
use App\Admin\Widgets\ReturnsReportWidget;

class HomeController extends Controller
{
    public function index(Content $content)
    {
        $u = Admin::user();
        $company = Company::find($u->company_id);
        $companyId = $u->company_id;
        
        // Get comprehensive dashboard data
        $dashboardData = $this->getDashboardData($companyId);
        
        return $content
            ->title($company->name . " - Dashboard")
            ->description('Welcome back, ' . $u->name . ' | ' . now()->format('l, d F Y'))
            ->row(new SalesAnalyticsWidget())
            ->row(new ReturnsReportWidget())
            ->body(view('admin.dashboard', [
                'company' => $company,
                'user' => $u,
                'data' => $dashboardData
            ]));
    }
    
    /**
     * Get all dashboard data using optimized DB queries
     */
    private function getDashboardData($companyId)
    {
        return [
            'sales_overview' => $this->getSalesOverview($companyId),
            'debts_receivables' => $this->getDebtsAndReceivables($companyId),
            'inventory_overview' => $this->getInventoryOverview($companyId),
            'financial_overview' => $this->getFinancialOverview($companyId),
            'quick_stats' => $this->getQuickStats($companyId),
            'stock_alerts' => $this->getStockAlerts($companyId),
            'top_performers' => $this->getTopPerformers($companyId),
            'employees_stats' => $this->getEmployeesStats($companyId),
        ];
    }
    
    /**
     * Sales Overview from Sale Records
     */
    private function getSalesOverview($companyId)
    {
        $overview = DB::select("
            SELECT 
                COUNT(*) as total_sales,
                COUNT(DISTINCT customer_name) as total_customers,
                COALESCE(SUM(total_amount), 0) as total_revenue,
                COALESCE(SUM(amount_paid), 0) as total_collected,
                COALESCE(SUM(balance), 0) as total_outstanding,
                COALESCE(AVG(total_amount), 0) as avg_sale_value,
                SUM(CASE WHEN payment_status = 'Paid' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN payment_status = 'Unpaid' THEN 1 ELSE 0 END) as unpaid_count,
                SUM(CASE WHEN payment_status = 'Partial' THEN 1 ELSE 0 END) as partial_count,
                SUM(CASE WHEN DATE(sale_date) = CURDATE() THEN total_amount ELSE 0 END) as today_sales,
                SUM(CASE WHEN DATE(sale_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN total_amount ELSE 0 END) as week_sales,
                SUM(CASE WHEN MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE()) THEN total_amount ELSE 0 END) as month_sales,
                SUM(CASE WHEN YEAR(sale_date) = YEAR(CURDATE()) THEN total_amount ELSE 0 END) as year_sales
            FROM sale_records
            WHERE company_id = ?
        ", [$companyId]);
        
        $result = $overview[0] ?? null;
        
        if (!$result) {
            return [
                'total_sales' => 0,
                'total_customers' => 0,
                'total_revenue' => 0,
                'total_collected' => 0,
                'total_outstanding' => 0,
                'avg_sale_value' => 0,
                'paid_count' => 0,
                'unpaid_count' => 0,
                'partial_count' => 0,
                'paid_percentage' => 0,
                'collection_rate' => 0,
                'today_sales' => 0,
                'week_sales' => 0,
                'month_sales' => 0,
                'year_sales' => 0
            ];
        }
        
        $paidPercentage = $result->total_sales > 0 
            ? ($result->paid_count / $result->total_sales) * 100 
            : 0;
            
        $collectionRate = $result->total_revenue > 0 
            ? ($result->total_collected / $result->total_revenue) * 100 
            : 0;
        
        return [
            'total_sales' => $result->total_sales,
            'total_customers' => $result->total_customers,
            'total_revenue' => $result->total_revenue,
            'total_collected' => $result->total_collected,
            'total_outstanding' => $result->total_outstanding,
            'avg_sale_value' => round($result->avg_sale_value, 2),
            'paid_count' => $result->paid_count,
            'unpaid_count' => $result->unpaid_count,
            'partial_count' => $result->partial_count,
            'paid_percentage' => round($paidPercentage, 2),
            'collection_rate' => round($collectionRate, 2),
            'today_sales' => $result->today_sales,
            'week_sales' => $result->week_sales,
            'month_sales' => $result->month_sales,
            'year_sales' => $result->year_sales
        ];
    }
    
    /**
     * Debts and Receivables Tracking
     */
    private function getDebtsAndReceivables($companyId)
    {
        $debts = DB::select("
            SELECT 
                COUNT(*) as total_debtors,
                COALESCE(SUM(balance), 0) as total_debt,
                COALESCE(AVG(balance), 0) as avg_debt,
                SUM(CASE WHEN payment_status = 'Unpaid' THEN balance ELSE 0 END) as fully_unpaid,
                SUM(CASE WHEN payment_status = 'Partial' THEN balance ELSE 0 END) as partial_unpaid,
                SUM(CASE WHEN DATE(sale_date) < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN balance ELSE 0 END) as overdue_30,
                SUM(CASE WHEN DATE(sale_date) < DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN balance ELSE 0 END) as overdue_60,
                SUM(CASE WHEN DATE(sale_date) < DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN balance ELSE 0 END) as overdue_90
            FROM sale_records
            WHERE company_id = ?
            AND balance > 0
        ", [$companyId]);
        
        $topDebtors = DB::select("
            SELECT 
                customer_name,
                customer_phone,
                COUNT(*) as sale_count,
                COALESCE(SUM(balance), 0) as total_debt,
                MAX(sale_date) as last_sale_date
            FROM sale_records
            WHERE company_id = ?
            AND balance > 0
            AND customer_name IS NOT NULL
            AND customer_name != ''
            GROUP BY customer_name, customer_phone
            ORDER BY total_debt DESC
            LIMIT 10
        ", [$companyId]);
        
        $result = $debts[0] ?? null;
        
        if (!$result) {
            return [
                'total_debtors' => 0,
                'total_debt' => 0,
                'avg_debt' => 0,
                'fully_unpaid' => 0,
                'partial_unpaid' => 0,
                'overdue_30' => 0,
                'overdue_60' => 0,
                'overdue_90' => 0,
                'top_debtors' => []
            ];
        }
        
        return [
            'total_debtors' => $result->total_debtors,
            'total_debt' => $result->total_debt,
            'avg_debt' => round($result->avg_debt, 2),
            'fully_unpaid' => $result->fully_unpaid,
            'partial_unpaid' => $result->partial_unpaid,
            'overdue_30' => $result->overdue_30,
            'overdue_60' => $result->overdue_60,
            'overdue_90' => $result->overdue_90,
            'top_debtors' => $topDebtors
        ];
    }
    
    /**
     * Financial Overview - Cash Flow Summary
     */
    private function getFinancialOverview($companyId)
    {
        // Get sales revenue from sale_records
        $salesRevenue = DB::select("
            SELECT COALESCE(SUM(amount_paid), 0) as sales_income
            FROM sale_records
            WHERE company_id = ?
        ", [$companyId]);
        
        // Get other financial records
        $financial = DB::select("
            SELECT 
                COALESCE(SUM(CASE WHEN type = 'Income' THEN amount ELSE 0 END), 0) as other_income,
                COALESCE(SUM(CASE WHEN type = 'Expense' THEN amount ELSE 0 END), 0) as total_expense
            FROM financial_records
            WHERE company_id = ?
        ", [$companyId]);
        
        $salesIncome = $salesRevenue[0]->sales_income ?? 0;
        $result = $financial[0] ?? null;
        
        if (!$result) {
            return [
                'sales_income' => $salesIncome,
                'other_income' => 0,
                'total_income' => $salesIncome,
                'total_expense' => 0,
                'net_profit' => $salesIncome,
                'profit_margin' => 100
            ];
        }
        
        $totalIncome = $salesIncome + $result->other_income;
        $netProfit = $totalIncome - $result->total_expense;
        $profitMargin = $totalIncome > 0 ? ($netProfit / $totalIncome) * 100 : 0;
        
        return [
            'sales_income' => $salesIncome,
            'other_income' => $result->other_income,
            'total_income' => $totalIncome,
            'total_expense' => $result->total_expense,
            'net_profit' => $netProfit,
            'profit_margin' => round($profitMargin, 2)
        ];
    }
    
    /**
     * Inventory Overview
     */
    private function getInventoryOverview($companyId)
    {
        $inventory = DB::select("
            SELECT 
                COUNT(*) as total_items,
                COALESCE(SUM(current_quantity * buying_price), 0) as total_stock_value,
                COALESCE(SUM(current_quantity * selling_price), 0) as potential_revenue,
                SUM(CASE WHEN current_quantity = 0 THEN 1 ELSE 0 END) as out_of_stock,
                SUM(CASE WHEN current_quantity > 0 AND current_quantity <= 10 THEN 1 ELSE 0 END) as low_stock,
                COALESCE(AVG(CASE 
                    WHEN buying_price > 0 
                    THEN ((selling_price - buying_price) / buying_price) * 100 
                    ELSE 0 
                END), 0) as avg_profit_margin
            FROM stock_items
            WHERE company_id = ?
        ", [$companyId]);
        
        // Get month sales from sale_records
        $monthlySales = DB::select("
            SELECT COALESCE(SUM(total_amount), 0) as month_sales
            FROM sale_records
            WHERE company_id = ?
            AND MONTH(sale_date) = MONTH(CURDATE())
            AND YEAR(sale_date) = YEAR(CURDATE())
        ", [$companyId]);
        
        // Get best selling category from sale_record_items
        $bestCategory = DB::select("
            SELECT 
                sc.name,
                COUNT(DISTINCT sri.sale_record_id) as sales_count,
                SUM(sri.quantity) as total_quantity,
                SUM(sri.subtotal) as total_revenue
            FROM sale_record_items sri
            JOIN sale_records sr ON sri.sale_record_id = sr.id
            JOIN stock_items si ON sri.stock_item_id = si.id
            JOIN stock_sub_categories ssc ON si.stock_sub_category_id = ssc.id
            JOIN stock_categories sc ON ssc.stock_category_id = sc.id
            WHERE sr.company_id = ?
            AND MONTH(sr.sale_date) = MONTH(CURDATE())
            AND YEAR(sr.sale_date) = YEAR(CURDATE())
            GROUP BY sc.id, sc.name
            ORDER BY total_revenue DESC
            LIMIT 1
        ", [$companyId]);
        
        $result = $inventory[0] ?? null;
        $salesResult = $monthlySales[0] ?? null;
        $bestCat = $bestCategory[0] ?? null;
        
        $potentialProfit = ($result->potential_revenue ?? 0) - ($result->total_stock_value ?? 0);
        
        return [
            'total_items' => $result->total_items ?? 0,
            'total_stock_value' => $result->total_stock_value ?? 0,
            'potential_revenue' => $result->potential_revenue ?? 0,
            'potential_profit' => $potentialProfit,
            'out_of_stock' => $result->out_of_stock ?? 0,
            'low_stock' => $result->low_stock ?? 0,
            'month_sales' => $salesResult->month_sales ?? 0,
            'avg_profit_margin' => round($result->avg_profit_margin ?? 0, 2),
            'best_category' => $bestCat->name ?? 'N/A',
            'best_category_sales' => $bestCat->sales_count ?? 0
        ];
    }
    

    
    /**
     * Stock Alerts
     */
    private function getStockAlerts($companyId)
    {
        $outOfStock = DB::select("
            SELECT id, name, sku
            FROM stock_items
            WHERE company_id = ?
            AND current_quantity = 0
            ORDER BY name
            LIMIT 10
        ", [$companyId]);
        
        $lowStock = DB::select("
            SELECT id, name, sku, current_quantity
            FROM stock_items
            WHERE company_id = ?
            AND current_quantity > 0
            AND current_quantity <= 10
            ORDER BY current_quantity ASC
            LIMIT 10
        ", [$companyId]);
        
        $weekActivity = DB::select("
            SELECT 
                type,
                COUNT(*) as count,
                COALESCE(SUM(total_sales), 0) as total_value,
                COALESCE(SUM(quantity), 0) as total_quantity
            FROM stock_records
            WHERE company_id = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY type
        ", [$companyId]);
        
        return [
            'out_of_stock' => $outOfStock,
            'low_stock' => $lowStock,
            'week_activity' => $weekActivity
        ];
    }
    
    /**
     * Quick Stats (Today, Week, Month, Year)
     */
    private function getQuickStats($companyId)
    {
        $stats = [];
        $periods = [
            'today' => ['condition' => 'DATE(sale_date) = CURDATE()'],
            'week' => ['condition' => 'DATE(sale_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)'],
            'month' => ['condition' => 'MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())'],
            'year' => ['condition' => 'YEAR(sale_date) = YEAR(CURDATE())']
        ];
        
        foreach ($periods as $period => $config) {
            $result = DB::select("
                SELECT 
                    COALESCE(SUM(total_amount), 0) as sales,
                    COALESCE(SUM(amount_paid), 0) as collected,
                    COUNT(*) as transactions,
                    COALESCE(AVG(total_amount), 0) as avg_value
                FROM sale_records
                WHERE company_id = ?
                AND {$config['condition']}
            ", [$companyId]);
            
            $data = $result[0] ?? (object)['sales' => 0, 'collected' => 0, 'transactions' => 0, 'avg_value' => 0];
            $stats[$period] = [
                'sales' => $data->sales,
                'collected' => $data->collected,
                'transactions' => $data->transactions,
                'avg_value' => round($data->avg_value, 2)
            ];
        }
        
        return $stats;
    }
    
    /**
     * Top Performers
     */
    private function getTopPerformers($companyId)
    {
        // Top selling products from sale_record_items
        $topProducts = DB::select("
            SELECT 
                si.name,
                si.sku,
                COUNT(DISTINCT sri.sale_record_id) as sale_count,
                SUM(sri.quantity) as total_quantity,
                SUM(sri.subtotal) as total_revenue,
                COALESCE(AVG(sri.unit_price), 0) as avg_price
            FROM sale_record_items sri
            JOIN sale_records sr ON sri.sale_record_id = sr.id
            JOIN stock_items si ON sri.stock_item_id = si.id
            WHERE sr.company_id = ?
            GROUP BY si.id, si.name, si.sku
            ORDER BY total_revenue DESC
            LIMIT 10
        ", [$companyId]);
        
        // Top customers by purchase value
        $topCustomers = DB::select("
            SELECT 
                customer_name,
                customer_phone,
                COUNT(*) as purchase_count,
                COALESCE(SUM(total_amount), 0) as total_spent,
                COALESCE(SUM(balance), 0) as outstanding_balance,
                MAX(sale_date) as last_purchase_date
            FROM sale_records
            WHERE company_id = ?
            AND customer_name IS NOT NULL
            AND customer_name != ''
            GROUP BY customer_name, customer_phone
            ORDER BY total_spent DESC
            LIMIT 10
        ", [$companyId]);
        
        return [
            'products' => $topProducts,
            'customers' => $topCustomers
        ];
    }
    

    
    /**
     * Employees Statistics
     */
    private function getEmployeesStats($companyId)
    {
        // Check if employees table exists, otherwise use admin_users
        try {
            $stats = DB::select("
                SELECT 
                    COUNT(*) as total_employees,
                    COUNT(*) as active_employees
                FROM admin_users
                WHERE company_id = ?
            ", [$companyId]);

            $result = $stats[0] ?? null;

            return [
                'total_employees' => $result->total_employees ?? 0,
                'active_employees' => $result->active_employees ?? 0
            ];
        } catch (\Exception $e) {
            // If table doesn't exist or query fails, return zeros
            return [
                'total_employees' => 0,
                'active_employees' => 0
            ];
        }
    }
}
