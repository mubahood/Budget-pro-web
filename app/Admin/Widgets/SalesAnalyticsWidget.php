<?php

namespace App\Admin\Widgets;

use App\Support\LocalDate;
use App\Support\LocalTime;
use App\Support\SalesSource;
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
        $data = $this->data((int) Admin::user()->company_id);

        return view($this->view, compact('data'));
    }

    /**
     * Every figure comes from App\Support\SalesSource: one row per sale (web/app sale documents and
     * old-app sale movements), voids left out, net of returns, profit as stored when sold, dated by
     * the shop's local day. A fully returned sale (net 0) is not counted as a transaction.
     */
    public function data(int $companyId): array
    {
        LocalTime::prime($companyId);

        return [
            'overview' => $this->getOverviewStats($companyId),
            'trends' => $this->getTrendsData($companyId),
            'top_products' => $this->getTopProducts($companyId),
            'category_breakdown' => $this->getCategoryBreakdown($companyId),
            'monthly_comparison' => $this->getMonthlyComparison($companyId),
            'daily_sales' => $this->getDailySales($companyId),
            'last_30_days' => $this->getLast30Days($companyId),
            'financial_data' => $this->getFinancialData($companyId),
        ];
    }

    private const TODAY = 'sale_date = @local_today';

    private const WEEK = 'sale_date BETWEEN DATE_SUB(@local_today, INTERVAL 6 DAY) AND @local_today';

    private const MONTH = "sale_date BETWEEN DATE_FORMAT(@local_today, '%Y-%m-01') AND @local_today";

    private const LAST_MONTH = "sale_date >= DATE_FORMAT(DATE_SUB(@local_today, INTERVAL 1 MONTH), '%Y-%m-01') AND sale_date < DATE_FORMAT(@local_today, '%Y-%m-01')";

    /** transactions, units_sold, revenue, profit for sales matching a condition on sale_date. */
    private function periodStats(int $companyId, string $where): array
    {
        [$sales, $bind] = SalesSource::sql($companyId);
        $s = DB::selectOne("SELECT COUNT(CASE WHEN s.total_amount > 0 THEN 1 END) AS transactions, COALESCE(SUM(s.total_amount), 0) AS revenue, COALESCE(SUM(s.profit), 0) AS profit
            FROM {$sales} WHERE s.{$where}", $bind);
        [$lines, $lineBind] = SalesSource::linesSql($companyId);
        $units = (float) DB::selectOne("SELECT COALESCE(SUM(l.quantity), 0) AS units FROM {$lines} WHERE l.{$where}", $lineBind)->units;

        return [
            'transactions' => (int) $s->transactions,
            'units_sold' => round($units, 3),
            'revenue' => round((float) $s->revenue, 2),
            'profit' => round((float) $s->profit, 2),
        ];
    }

    /**
     * Today, the last 7 days (including today) and this calendar month.
     */
    private function getOverviewStats(int $companyId): array
    {
        $today = $this->periodStats($companyId, self::TODAY);
        $week = $this->periodStats($companyId, self::WEEK);
        $month = $this->periodStats($companyId, self::MONTH);
        $lastMonthRevenue = $this->periodStats($companyId, self::LAST_MONTH)['revenue'];

        $growth = $lastMonthRevenue > 0 ? (($month['revenue'] - $lastMonthRevenue) / $lastMonthRevenue) * 100 : 0;

        return [
            'today' => $today + ['avg_transaction' => $today['transactions'] > 0 ? $today['revenue'] / $today['transactions'] : 0],
            'week' => $week,
            'month' => $month + ['growth_rate' => round($growth, 2)],
        ];
    }

    /** Month keys (Y-m) from $count-1 months ago up to the shop's current month. */
    private function monthKeys(int $companyId, int $count): array
    {
        $first = LocalDate::today($companyId)->startOfMonth();
        $keys = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $keys[] = $first->copy()->subMonthsNoOverflow($i)->format('Y-m');
        }

        return $keys;
    }

    /** revenue, units, transactions, profit per month (Y-m) for the last $count months. */
    private function monthly(int $companyId, int $count): array
    {
        $keys = $this->monthKeys($companyId, $count);
        $start = $keys[0].'-01';
        [$sales, $bind] = SalesSource::sql($companyId);
        $rows = DB::select("SELECT DATE_FORMAT(s.sale_date, '%Y-%m') AS ym, COUNT(CASE WHEN s.total_amount > 0 THEN 1 END) AS transactions,
                COALESCE(SUM(s.total_amount), 0) AS revenue, COALESCE(SUM(s.profit), 0) AS profit
            FROM {$sales} WHERE s.sale_date >= ? AND s.sale_date <= @local_today GROUP BY DATE_FORMAT(s.sale_date, '%Y-%m')", array_merge($bind, [$start]));
        [$lines, $lineBind] = SalesSource::linesSql($companyId);
        $units = collect(DB::select("SELECT DATE_FORMAT(l.sale_date, '%Y-%m') AS ym, COALESCE(SUM(l.quantity), 0) AS units
            FROM {$lines} WHERE l.sale_date >= ? AND l.sale_date <= @local_today GROUP BY DATE_FORMAT(l.sale_date, '%Y-%m')", array_merge($lineBind, [$start])))
            ->pluck('units', 'ym');
        $byMonth = collect($rows)->keyBy('ym');

        $out = [];
        foreach ($keys as $ym) {
            $r = $byMonth->get($ym);
            $out[$ym] = [
                'revenue' => round((float) ($r->revenue ?? 0), 2),
                'units' => round((float) ($units[$ym] ?? 0), 3),
                'transactions' => (int) ($r->transactions ?? 0),
                'profit' => round((float) ($r->profit ?? 0), 2),
            ];
        }

        return $out;
    }

    /**
     * Sales per month for the last 12 months (local months).
     */
    private function getTrendsData(int $companyId): array
    {
        $labels = [];
        $revenue = [];
        $units = [];
        $transactions = [];
        foreach ($this->monthly($companyId, 12) as $ym => $m) {
            $labels[] = Carbon::parse($ym.'-01')->format('M Y');
            $revenue[] = $m['revenue'];
            $units[] = $m['units'];
            $transactions[] = $m['transactions'];
        }

        return ['labels' => $labels, 'revenue' => $revenue, 'units' => $units, 'transactions' => $transactions];
    }

    /**
     * Top 10 products this month by revenue (net of returns), with the profit stored when sold.
     */
    private function getTopProducts(int $companyId): array
    {
        [$lines, $bind] = SalesSource::linesSql($companyId);

        return DB::select("
            SELECT si.id, COALESCE(si.name, MAX(l.item_name)) AS name, si.image,
                COALESCE(SUM(l.quantity), 0) AS total_sold, COALESCE(SUM(l.revenue), 0) AS total_revenue, COALESCE(SUM(l.profit), 0) AS total_profit
            FROM {$lines}
            JOIN stock_items si ON si.id = l.stock_item_id
            WHERE l.".self::MONTH.'
            GROUP BY si.id, si.name, si.image
            HAVING total_revenue > 0
            ORDER BY total_revenue DESC
            LIMIT 10
        ', $bind);
    }

    /**
     * This month's sales by category.
     */
    private function getCategoryBreakdown(int $companyId): array
    {
        [$lines, $bind] = SalesSource::linesSql($companyId);
        $categories = DB::select("
            SELECT COALESCE(sc.name, 'Uncategorised') AS name, COALESCE(SUM(l.revenue), 0) AS total_sales, COALESCE(SUM(l.quantity), 0) AS total_units,
                COUNT(DISTINCT l.stock_item_id) AS product_count
            FROM {$lines}
            LEFT JOIN stock_items si ON si.id = l.stock_item_id
            LEFT JOIN stock_sub_categories ssc ON ssc.id = si.stock_sub_category_id
            LEFT JOIN stock_categories sc ON sc.id = COALESCE(si.stock_category_id, ssc.stock_category_id)
            WHERE l.".self::MONTH."
            GROUP BY COALESCE(sc.name, 'Uncategorised')
            HAVING total_sales > 0
            ORDER BY total_sales DESC
        ", $bind);

        $totalSales = array_sum(array_map(fn ($c) => (float) $c->total_sales, $categories));

        $result = [];
        foreach ($categories as $cat) {
            $result[] = [
                'name' => $cat->name,
                'sales' => round((float) $cat->total_sales, 2),
                'units' => round((float) $cat->total_units, 3),
                'products' => (int) $cat->product_count,
                'percentage' => $totalSales > 0 ? round(((float) $cat->total_sales / $totalSales) * 100, 2) : 0,
            ];
        }

        return $result;
    }

    /**
     * This month and the two before it (local months).
     */
    private function getMonthlyComparison(int $companyId): array
    {
        $months = [];
        foreach ($this->monthly($companyId, 3) as $ym => $m) {
            $months[] = ['label' => Carbon::parse($ym.'-01')->format('M Y'), 'revenue' => $m['revenue'], 'units' => $m['units'], 'transactions' => $m['transactions']];
        }

        return $months;
    }

    /** date, sales (count), revenue, profit per local day for a condition on sale_date. */
    private function daily(int $companyId, string $where, string $order): array
    {
        [$sales, $bind] = SalesSource::sql($companyId);

        return DB::select("
            SELECT s.sale_date AS date, COUNT(CASE WHEN s.total_amount > 0 THEN 1 END) AS sales, COALESCE(SUM(s.total_amount), 0) AS revenue, COALESCE(SUM(s.profit), 0) AS profit
            FROM {$sales}
            WHERE s.{$where}
            GROUP BY s.sale_date
            ORDER BY s.sale_date {$order}
        ", $bind);
    }

    /**
     * Daily sales and profit for the current month, up to today.
     */
    private function getDailySales(int $companyId): array
    {
        $labels = [];
        $revenue = [];
        $transactions = [];
        $profit = [];
        foreach ($this->daily($companyId, self::MONTH, 'ASC') as $day) {
            $labels[] = Carbon::parse($day->date)->format('d M');
            $revenue[] = round((float) $day->revenue, 2);
            $transactions[] = (int) $day->sales;
            $profit[] = round((float) $day->profit, 2);
        }

        return ['labels' => $labels, 'revenue' => $revenue, 'transactions' => $transactions, 'profit' => $profit];
    }

    /**
     * One row per day with sales in the last 30 days (including today), newest first.
     */
    private function getLast30Days(int $companyId): array
    {
        return $this->daily($companyId, 'sale_date BETWEEN DATE_SUB(@local_today, INTERVAL 29 DAY) AND @local_today', 'DESC');
    }

    /**
     * Get financial data (income and expense) for daily transactions from FinancialRecord
     */
    private function getFinancialData(int $companyId)
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
            WHERE company_id = ? AND is_deleted = 0
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
