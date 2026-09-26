<?php

namespace App\Services\Dashboard;

use App\Models\Company;
use App\Support\LocalTime;
use App\Support\SalesSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the shop owner's dashboard: one date range (the shop's local days) drives every
 * money figure, compared with the same length of time just before it. Sales come from
 * SalesSource, so web, new-app and old-app sales count once, voids never, returns net.
 */
class DashboardService
{
    public const RANGES = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month', 'last_month' => 'Last month', 'year' => 'This year'];

    /** @return array{key: string, label: string, from: string, to: string, prev_from: string, prev_to: string, days: int} */
    public function range(Company $company, ?string $key, ?string $from = null, ?string $to = null): array
    {
        $today = now()->setTimezone(LocalTime::timezone($company))->startOfDay();
        $key = $key === 'custom' || array_key_exists((string) $key, self::RANGES) ? $key : 'today';
        [$start, $end] = match ($key) {
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            '7d' => [$today->copy()->subDays(6), $today],
            '30d' => [$today->copy()->subDays(29), $today],
            'month' => [$today->copy()->startOfMonth(), $today],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'year' => [$today->copy()->startOfYear(), $today],
            'custom' => [$this->date($from) ?? $today, $this->date($to) ?? $today],
            default => [$today, $today],
        };
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        $days = (int) $start->diffInDays($end) + 1;
        $label = $key === 'custom'
            ? ($days === 1 ? $start->format('D d M Y') : $start->format('d M').' – '.$end->format('d M Y'))
            : self::RANGES[$key];

        return [
            'key' => (string) $key, 'label' => $label, 'from' => $start->toDateString(), 'to' => $end->toDateString(), 'days' => $days,
            'prev_from' => $start->copy()->subDays($days)->toDateString(), 'prev_to' => $start->copy()->subDay()->toDateString(),
        ];
    }

    /** Headline figures for a range: sales, profit, money in, expenses, net, returns. */
    public function kpis(int $companyId, string $from, string $to): array
    {
        LocalTime::prime($companyId);
        [$src, $bind] = SalesSource::sql($companyId);
        $s = DB::selectOne("SELECT COUNT(CASE WHEN s.total_amount > 0 THEN 1 END) AS n, COALESCE(SUM(s.total_amount), 0) AS total, COALESCE(SUM(s.profit), 0) AS profit,
                COALESCE(SUM(s.balance), 0) AS on_credit
            FROM {$src} WHERE s.sale_date BETWEEN ? AND ?", [...$bind, $from, $to]);
        $collected = $this->collectedByMethod($companyId, $from, $to);
        $expenses = (float) DB::selectOne('SELECT COALESCE(SUM(f.amount), 0) AS v FROM financial_records f
            WHERE f.company_id = ? AND f.type = ? AND COALESCE(f.is_deleted, 0) = 0
              AND (f.source_type IS NULL OR f.source_type NOT IN (?, ?, ?)) AND '.SalesSource::localDay('f.date', 'f.created_at').' BETWEEN ? AND ?',
            [$companyId, 'Expense', 'goods_receipt', 'supplier_payment', 'purchase_return', $from, $to])->v;
        $returns = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(r.value), 0) AS v FROM sale_returns r
            WHERE r.company_id = ? AND COALESCE(r.is_deleted, 0) = 0 AND DATE(CONVERT_TZ(r.created_at, '+00:00', @tz_offset)) BETWEEN ? AND ?", [$companyId, $from, $to]);

        $n = (int) $s->n;

        return [
            'sales' => (float) $s->total, 'count' => $n, 'avg' => $n > 0 ? (float) $s->total / $n : 0.0, 'profit' => (float) $s->profit,
            'margin' => (float) $s->total > 0 ? (float) $s->profit / (float) $s->total * 100 : 0.0,
            'collected' => array_sum(array_column($collected, 'amount')), 'on_credit' => (float) $s->on_credit,
            'expenses' => $expenses, 'net' => (float) $s->profit - $expenses,
            'returns_count' => (int) $returns->n, 'returns_value' => (float) $returns->v,
        ];
    }

    /**
     * Money actually received in the range, by payment method (refunds and reversals are negative rows).
     * Old-app sales and sales from before payments were recorded count as paid when made.
     *
     * @return array<int, array{method: string, label: string, amount: float}>
     */
    public function collectedByMethod(int $companyId, string $from, string $to): array
    {
        LocalTime::prime($companyId);
        $rows = DB::select("
            SELECT x.method, SUM(x.amount) AS amount FROM (
                SELECT p.method, p.amount FROM payments p
                WHERE p.company_id = ? AND COALESCE(p.is_deleted, 0) = 0
                  AND DATE(CONVERT_TZ(COALESCE(p.received_at, p.created_at), '+00:00', @tz_offset)) BETWEEN ? AND ?
                UNION ALL
                SELECT LOWER(COALESCE(NULLIF(r.payment_method, ''), 'cash')), r.amount_paid FROM sale_records r
                WHERE r.company_id = ? AND r.voided_at IS NULL AND r.status <> 'Voided' AND r.amount_paid > 0
                  AND NOT EXISTS (SELECT 1 FROM payments p2 WHERE p2.sale_record_id = r.id)
                  AND ".SalesSource::localDay('r.sale_date', 'r.created_at')." BETWEEN ? AND ?
                UNION ALL
                SELECT 'cash', m.total_sales FROM stock_records m
                WHERE m.company_id = ? AND m.type = 'Sale' AND m.sale_record_id IS NULL AND m.is_reversal = 0
                  AND NOT EXISTS (SELECT 1 FROM stock_records rv WHERE rv.reverses_id = m.id)
                  AND ".SalesSource::localDay('m.date', 'm.created_at').' BETWEEN ? AND ?
            ) x GROUP BY x.method ORDER BY amount DESC', [$companyId, $from, $to, $companyId, $from, $to, $companyId, $from, $to]);

        $labels = config('onboarding.payment_methods', []);
        $out = [];
        foreach ($rows as $r) {
            $method = \App\Models\Payment::normalizeMethod((string) $r->method);
            $out[$method] = ['method' => $method, 'label' => $labels[$method] ?? ucfirst(str_replace('_', ' ', $method)), 'amount' => ($out[$method]['amount'] ?? 0) + (float) $r->amount];
        }

        return array_values(array_filter($out, fn ($r) => abs($r['amount']) >= 0.01));
    }

    /**
     * Sales and profit per local day. Short ranges show the 14 days up to the range end so there is a trend.
     *
     * @return array{labels: array<int, string>, sales: array<int, float>, profit: array<int, float>, count: array<int, int>}
     */
    public function daily(int $companyId, string $from, string $to): array
    {
        $end = Carbon::parse($to);
        $start = Carbon::parse($from);
        if ($start->diffInDays($end) < 13) {
            $start = $end->copy()->subDays(13);
        }
        if ($start->diffInDays($end) > 92) {
            return $this->monthly($companyId, $start, $end);
        }
        LocalTime::prime($companyId);
        [$src, $bind] = SalesSource::sql($companyId);
        $rows = collect(DB::select("SELECT s.sale_date AS d, SUM(s.total_amount) AS v, SUM(s.profit) AS p, COUNT(*) AS n FROM {$src}
            WHERE s.sale_date BETWEEN ? AND ? GROUP BY s.sale_date", [...$bind, $start->toDateString(), $end->toDateString()]))->keyBy('d');
        $out = ['labels' => [], 'sales' => [], 'profit' => [], 'count' => []];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $r = $rows->get($d->toDateString());
            $out['labels'][] = $d->format('D d M');
            $out['sales'][] = round((float) ($r->v ?? 0), 2);
            $out['profit'][] = round((float) ($r->p ?? 0), 2);
            $out['count'][] = (int) ($r->n ?? 0);
        }

        return $out;
    }

    private function monthly(int $companyId, Carbon $start, Carbon $end): array
    {
        LocalTime::prime($companyId);
        [$src, $bind] = SalesSource::sql($companyId);
        $rows = collect(DB::select("SELECT DATE_FORMAT(s.sale_date, '%Y-%m') AS m, SUM(s.total_amount) AS v, SUM(s.profit) AS p, COUNT(*) AS n FROM {$src}
            WHERE s.sale_date BETWEEN ? AND ? GROUP BY DATE_FORMAT(s.sale_date, '%Y-%m')", [...$bind, $start->toDateString(), $end->toDateString()]))->keyBy('m');
        $out = ['labels' => [], 'sales' => [], 'profit' => [], 'count' => []];
        for ($d = $start->copy()->startOfMonth(); $d->lte($end); $d->addMonthNoOverflow()) {
            $r = $rows->get($d->format('Y-m'));
            $out['labels'][] = $d->format('M Y');
            $out['sales'][] = round((float) ($r->v ?? 0), 2);
            $out['profit'][] = round((float) ($r->p ?? 0), 2);
            $out['count'][] = (int) ($r->n ?? 0);
        }

        return $out;
    }

    /** Best sellers in the range by money taken (returns netted). */
    public function topProducts(int $companyId, string $from, string $to, int $limit = 6): array
    {
        LocalTime::prime($companyId);
        [$src, $bind] = SalesSource::linesSql($companyId);

        return DB::select("SELECT si.id, COALESCE(si.name, MAX(l.item_name)) AS name, SUM(l.quantity) AS quantity, SUM(l.revenue) AS revenue, SUM(l.profit) AS profit
            FROM {$src} LEFT JOIN stock_items si ON si.id = l.stock_item_id
            WHERE l.sale_date BETWEEN ? AND ? GROUP BY si.id, si.name HAVING SUM(l.quantity) > 0 ORDER BY revenue DESC LIMIT ".(int) $limit, [...$bind, $from, $to]);
    }

    /** The latest sales with what is still owed on each. */
    public function recentSales(int $companyId, int $limit = 8): array
    {
        return DB::table('sale_records')->where('company_id', $companyId)->orderByDesc('id')->limit($limit)
            ->get(['id', 'receipt_number', 'customer_name', 'total_amount', 'refunded_amount', 'balance', 'payment_status', 'status', 'voided_at', 'created_at'])->all();
    }

    /** What customers owe the shop (customer accounts plus credit sales without a customer account). */
    public function receivables(int $companyId): array
    {
        $top = DB::table('customers')->where('company_id', $companyId)->where('is_deleted', 0)->where('balance', '>', 0)
            ->orderByDesc('balance')->limit(5)->get(['id', 'name', 'phone', 'balance', 'last_reminded_at'])->all();
        $accounts = DB::table('customers')->where('company_id', $companyId)->where('is_deleted', 0)->where('balance', '>', 0)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(balance), 0) AS total')->first();
        $walkIn = DB::table('sale_records')->where('company_id', $companyId)->whereNull('customer_id')->whereNull('voided_at')->where('status', '<>', 'Voided')
            ->where('balance', '>', 0)->selectRaw('COUNT(*) AS n, COALESCE(SUM(balance), 0) AS total')->first();

        return [
            'total' => (float) $accounts->total + (float) $walkIn->total, 'customers' => (int) $accounts->n, 'top' => $top,
            'unlinked_total' => (float) $walkIn->total, 'unlinked_count' => (int) $walkIn->n,
        ];
    }

    /** What the shop owes suppliers. */
    public function payables(int $companyId): array
    {
        $q = DB::table('suppliers')->where('company_id', $companyId)->where('is_deleted', 0)->where('balance', '>', 0);

        return ['total' => (float) (clone $q)->sum('balance'), 'count' => (clone $q)->count(), 'top' => (clone $q)->orderByDesc('balance')->limit(5)->get(['id', 'name', 'phone', 'balance'])->all()];
    }

    /** Stock value and the products that need attention. */
    public function stock(Company $company): array
    {
        $default = (float) ($company->low_stock_default ?? config('saas.low_stock_threshold', 10));
        $base = DB::table('stock_items')->where('company_id', $company->id)->where('is_deleted', 0)->where('track_stock', 1);
        $sum = (clone $base)->selectRaw('COUNT(*) AS items,
            COALESCE(SUM(GREATEST(current_quantity, 0) * buying_price), 0) AS cost_value,
            COALESCE(SUM(GREATEST(current_quantity, 0) * selling_price), 0) AS sale_value,
            SUM(CASE WHEN current_quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock,
            SUM(CASE WHEN current_quantity < 0 THEN 1 ELSE 0 END) AS negative,
            SUM(CASE WHEN current_quantity > 0 AND current_quantity <= COALESCE(min_stock, ?) THEN 1 ELSE 0 END) AS low', [$default])->first();
        $low = (clone $base)->whereRaw('current_quantity <= COALESCE(min_stock, ?)', [$default])
            ->orderByRaw('current_quantity <= 0 DESC')->orderBy('current_quantity')->limit(8)
            ->get(['id', 'name', 'current_quantity', 'min_stock'])->all();

        return [
            'items' => (int) $sum->items, 'cost_value' => (float) $sum->cost_value, 'sale_value' => (float) $sum->sale_value,
            'out_of_stock' => (int) $sum->out_of_stock, 'negative' => (int) $sum->negative, 'low' => (int) $sum->low, 'low_items' => $low, 'threshold' => $default,
        ];
    }

    /**
     * Things the owner should look at, most urgent first.
     *
     * Each alert has a stable `key` (negative_stock, no_cost, expiring, reorder, open_shifts). The link
     * comes from `$link($key)`, so each interface points at its own screen; by default the classic admin's.
     *
     * @param  (callable(string): string)|null  $link
     * @return array<int, array{key: string, level: string, text: string, link: string, action: string}>
     */
    public function alerts(Company $company, array $stock, ?callable $link = null): array
    {
        $link ??= fn (string $key) => admin_url(self::CLASSIC_LINKS[$key]);
        $cid = (int) $company->id;
        $out = [];
        $add = function (string $key, string $level, string $text, string $action) use (&$out, $link) {
            $out[] = ['key' => $key, 'level' => $level, 'text' => $text, 'link' => $link($key), 'action' => $action];
        };
        if ($stock['negative'] > 0) {
            $add('negative_stock', 'danger', "{$stock['negative']} product(s) show less than zero in stock. Some sales were made without stock recorded — count them and correct.", 'Count stock');
        }
        $noCost = DB::table('stock_items')->where('company_id', $cid)->where('is_deleted', 0)->where('buying_price', '<=', 0)->where('selling_price', '>', 0)->count();
        if ($noCost > 0) {
            $add('no_cost', 'warning', "{$noCost} product(s) have no buying price, so their profit shows as the full selling price.", 'Add buying prices');
        }
        $expiring = DB::table('stock_batches')->where('company_id', $cid)->where('quantity', '>', 0)->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->addDays(30)->toDateString())->count();
        if ($expiring > 0) {
            $add('expiring', 'warning', "{$expiring} batch(es) expire within 30 days.", 'See expiry');
        }
        if ($stock['out_of_stock'] > 0 || $stock['low'] > 0) {
            $add('reorder', 'info', "{$stock['out_of_stock']} product(s) out of stock and {$stock['low']} running low.", 'Reorder list');
        }
        $openShifts = DB::table('shifts')->where('company_id', $cid)->where('status', 'open')->where('opened_at', '<', now()->subHours(16))->count();
        if ($openShifts > 0) {
            $add('open_shifts', 'warning', "{$openShifts} till shift(s) have been open for more than 16 hours. Close them to see the cash variance.", 'Shifts');
        }

        return $out;
    }

    /** Where each alert points in the classic admin. */
    public const CLASSIC_LINKS = [
        'negative_stock' => 'stock-takes/create', 'no_cost' => 'stock-items?_scope_=no_cost', 'expiring' => 'reports',
        'reorder' => 'reorder-suggestions', 'open_shifts' => 'shifts',
    ];

    /** Percentage change (null when there is nothing to compare with). */
    public static function change(float $now, float $before): ?float
    {
        return abs($before) < 0.01 ? null : ($now - $before) / abs($before) * 100;
    }

    private function date(?string $v): ?Carbon
    {
        if (! $v || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        try {
            return Carbon::parse($v)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
