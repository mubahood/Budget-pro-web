<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $companyId = (int) $request->user()->company_id;
        $monthStart = now()->startOfMonth();

        $inventory = [
            'stock_item_count' => (int) DB::table('stock_items')->where('company_id', $companyId)->count(),
            'low_stock_count' => (int) DB::table('stock_items')->where('company_id', $companyId)->where('current_quantity', '<', 10)->count(),
            'out_of_stock_count' => (int) DB::table('stock_items')->where('company_id', $companyId)->where('current_quantity', '<=', 0)->count(),
        ];

        $salesMonth = DB::table('sale_records')
            ->where('company_id', $companyId)
            ->where('sale_date', '>=', $monthStart)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as revenue, COALESCE(SUM(amount_paid),0) as collected, COALESCE(SUM(balance),0) as outstanding')
            ->first();

        $sales = [
            'this_month_count' => (int) ($salesMonth->cnt ?? 0),
            'this_month_revenue' => (float) ($salesMonth->revenue ?? 0),
            'this_month_collected' => (float) ($salesMonth->collected ?? 0),
            'this_month_outstanding' => (float) ($salesMonth->outstanding ?? 0),
        ];

        $finance = DB::table('financial_records')
            ->where('company_id', $companyId)
            ->selectRaw("COALESCE(SUM(CASE WHEN type='Income' THEN amount ELSE 0 END),0) as income, COALESCE(SUM(CASE WHEN type='Expense' THEN amount ELSE 0 END),0) as expense")
            ->first();

        $finance = [
            'total_income' => (float) ($finance->income ?? 0),
            'total_expense' => (float) ($finance->expense ?? 0),
            'net' => (float) (($finance->income ?? 0) - ($finance->expense ?? 0)),
        ];

        $budget = DB::table('budget_programs')
            ->where('company_id', $companyId)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(budget_total),0) as budget_total, COALESCE(SUM(budget_spent),0) as budget_spent, COALESCE(SUM(total_collected),0) as collected')
            ->first();

        $budget = [
            'program_count' => (int) ($budget->cnt ?? 0),
            'budget_total' => (float) ($budget->budget_total ?? 0),
            'budget_spent' => (float) ($budget->budget_spent ?? 0),
            'total_collected' => (float) ($budget->collected ?? 0),
        ];

        $recentSales = DB::table('sale_records')
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'customer_name', 'total_amount', 'amount_paid', 'payment_status', 'sale_date']);

        return $this->success([
            'inventory' => $inventory,
            'sales' => $sales,
            'finance' => $finance,
            'budget' => $budget,
            'recent_sales' => $recentSales,
        ], 'Dashboard loaded.');
    }
}
