<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Dashboard\DashboardService;
use App\Services\Team\Permissions;
use App\Support\StoreScope;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $companyId = (int) $request->user()->company_id;
        \App\Support\LocalTime::prime($companyId);

        $products = fn () => DB::table('stock_items')->where('company_id', $companyId)->where('is_deleted', 0);
        $inventory = [
            'stock_item_count' => (int) $products()->count(),
            'low_stock_count' => (int) $products()->whereRaw('current_quantity <= COALESCE(min_stock, ?)', [(float) config('saas.low_stock_threshold')])->count(),
            'out_of_stock_count' => (int) $products()->where('current_quantity', '<=', 0)->count(),
        ];

        // Every sale this calendar month in the shop's timezone: web/app sale documents and old-app
        // sale movements, net of returns, voids left out. A fully returned sale is not counted.
        [$from, $bind] = \App\Support\SalesSource::sql($companyId);
        $salesMonth = DB::selectOne("SELECT COUNT(CASE WHEN s.total_amount > 0 THEN 1 END) AS cnt, COALESCE(SUM(s.total_amount), 0) AS revenue,
                COALESCE(SUM(LEAST(s.amount_paid, s.total_amount)), 0) AS collected, COALESCE(SUM(CASE WHEN s.balance > 0 THEN s.balance ELSE 0 END), 0) AS outstanding
            FROM {$from} WHERE s.sale_date >= DATE_FORMAT(@local_today, '%Y-%m-01') AND s.sale_date <= @local_today", $bind);

        $sales = [
            'this_month_count' => (int) ($salesMonth->cnt ?? 0),
            'this_month_revenue' => round((float) ($salesMonth->revenue ?? 0), 2),
            'this_month_collected' => round((float) ($salesMonth->collected ?? 0), 2),
            'this_month_outstanding' => round((float) ($salesMonth->outstanding ?? 0), 2),
        ];

        $finance = DB::table('financial_records')
            ->where('company_id', $companyId)
            ->where('is_deleted', 0)
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

        $out = [
            'inventory' => $inventory,
            'sales' => $sales,
            'finance' => $finance,
            'budget' => $budget,
            'recent_sales' => $recentSales,
        ];
        if ($request->filled('from') || $request->filled('to') || $request->filled('range')) {
            $out += $this->ranged($request);
        }

        return $this->success($out, 'Dashboard loaded.');
    }

    /**
     * ?from=&to= (the shop's local days) or ?range=today|7d|month|… (DashboardService::REPORT_RANGES): the web dashboard's
     * figures for that range and the same length just before it (DashboardService::range/kpis), for the member's own
     * store when they are limited to one (StoreScope). Money figures only for view_reports, view_profit or manage_finance
     * (as on the web): `kpis` and `previous` are null otherwise.
     */
    private function ranged(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'range' => ['nullable', 'in:custom,'.implode(',', array_keys(DashboardService::REPORT_RANGES))],
        ]);
        $user = $request->user();
        $company = Company::withoutGlobalScopes()->findOrFail($user->company_id);
        $dash = app(DashboardService::class);
        $key = ! empty($data['from']) || ! empty($data['to']) ? 'custom' : ($data['range'] ?? 'today');
        $range = $dash->range($company, $key, $data['from'] ?? $data['to'] ?? null, $data['to'] ?? $data['from'] ?? null);
        $location = StoreScope::forUser($user, $company);
        $seesMoney = Permissions::can($user, 'view_reports') || Permissions::can($user, 'view_profit') || Permissions::can($user, 'manage_finance');
        $kpis = $seesMoney ? $dash->kpis((int) $company->id, $range['from'], $range['to'], $location) : null;
        $prev = $seesMoney ? $dash->kpis((int) $company->id, $range['prev_from'], $range['prev_to'], $location) : null;
        $change = null;
        if ($kpis !== null) {
            foreach (['sales', 'count', 'profit', 'collected', 'expenses', 'net'] as $k) {
                $pct = DashboardService::change((float) $kpis[$k], (float) $prev[$k]);
                $change[$k] = $pct === null ? null : round($pct, 1);
            }
        }

        return ['range' => $range + ['location_id' => $location], 'kpis' => $kpis, 'previous' => $prev, 'change' => $change];
    }
}
