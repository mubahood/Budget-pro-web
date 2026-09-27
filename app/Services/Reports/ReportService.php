<?php

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Support\SalesSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shop reports (plan A6, P4-2). Every figure comes from the documents that
 * cannot double count: sales net of voids and returns, payments by method,
 * movements for stock, the ledger for money. Each report returns
 * { title, columns: [{key,label,type}], rows, totals, meta } so JSON, PDF and
 * XLSX (and the phone) all render the same thing.
 */
class ReportService
{
    /** name => [title, permission] */
    public const REPORTS = [
        'sales_summary' => ['Sales summary', 'view_reports'],
        'profit' => ['Profit & margin', 'view_profit'],
        'stock_valuation' => ['Stock on hand & value', 'view_cost'],
        'low_stock' => ['Low stock', 'view_reports'],
        'dead_stock' => ['Dead stock (not selling)', 'view_reports'],
        'fast_movers' => ['Best sellers', 'view_reports'],
        'customer_aging' => ['What customers owe (aging)', 'view_reports'],
        'supplier_balances' => ['What we owe suppliers', 'view_reports'],
        'cash_up' => ['Cash-up by shift', 'view_reports'],
        'vat_summary' => ['VAT summary', 'view_reports'],
        'purchase_summary' => ['Purchases', 'view_cost'],
        'movement_audit' => ['Stock movement audit', 'view_reports'],
        'expiry' => ['Expiring stock', 'view_reports'],
        'income_statement' => ['Income statement (profit & loss)', 'view_profit'],
        // Supermarket insight (budget-pro-new/docs/SUPERMARKET_PLAN.md H2, H3, H4, H6): read-only, for every shop.
        'category_margin' => ['Category & margin', 'view_profit'],
        'basket' => ['Basket & busy hours', 'view_reports'],
        'abc' => ['ABC analysis & days of cover', 'view_reports'],
        'shrink' => ['Shrink (write-offs)', 'view_cost'],
        'cash_control' => ['Cash control by cashier', 'view_reports'],
        // Supermarket plan H5: what each promotion gave and did to its products' sales (for every shop; empty without promotions).
        'promotion_results' => ['Promotion results', 'view_profit'],
        // Supermarket plan C1/C2: money owed to customers (gift cards, store credit) and loyalty points (empty without them).
        'gift_cards' => ['Gift cards, store credit & points', 'view_reports'],
        // Supermarket plan D5: how each supplier delivers (fill rate, days late, cost changes); for every shop.
        'supplier_scorecard' => ['Supplier scorecard', 'view_reports'],
    ];

    public const GROUPS = ['day', 'cashier', 'method', 'customer', 'category', 'product'];

    /**
     * Reports that can show one store (option `location_id`, SUPERMARKET_PLAN.md G3): sales by the sale's store
     * (App\Support\StoreScope), stock by the store's shelf. Other reports are about the whole business
     * (customers, suppliers, the ledger) and ignore the option.
     */
    public const LOCATION_AWARE = ['sales_summary', 'profit', 'stock_valuation', 'low_stock', 'fast_movers', 'category_margin', 'basket', 'abc'];

    /** One store only (null = every store: results exactly as without the option). */
    private ?int $locationId = null;

    private int $companyId;

    private Carbon $from;

    private Carbon $to;

    public function run(int $companyId, string $name, ?string $from = null, ?string $to = null, array $options = []): array
    {
        if (! array_key_exists($name, self::REPORTS)) {
            throw BusinessRuleException::make('unknown_report', 'Unknown report.', ['reports' => array_keys(self::REPORTS)]);
        }
        $this->companyId = $companyId;
        $this->locationId = ! empty($options['location_id']) && in_array($name, self::LOCATION_AWARE, true) ? (int) $options['location_id'] : null;
        if ($this->locationId !== null && ! DB::table('locations')->where('company_id', $companyId)->where('id', $this->locationId)->exists()) {
            throw BusinessRuleException::make('location_not_found', 'Location not found.');
        }
        $company = Company::withoutGlobalScopes()->findOrFail($companyId);
        $tz = \App\Support\LocalTime::timezone($company);
        $this->to = $to ? Carbon::parse($to, $tz)->endOfDay() : now($tz)->endOfDay();
        $this->from = $from ? Carbon::parse($from, $tz)->startOfDay() : $this->to->copy()->subDays(29)->startOfDay();
        if ($this->from->greaterThan($this->to)) {
            throw BusinessRuleException::make('invalid_range', 'The start date is after the end date.');
        }
        \App\Support\LocalTime::prime($companyId); // SalesSource works in the shop's local days
        $method = lcfirst(str_replace('_', '', ucwords($name, '_')));
        $r = $this->{$method}($options);
        if ($this->locationId !== null) {
            $r['meta'] = ($r['meta'] ?? []) + ['location_id' => $this->locationId, 'location' => (string) DB::table('locations')->where('id', $this->locationId)->value('name')];
        }

        return $r + ['name' => $name, 'title' => self::REPORTS[$name][0], 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString(),
            'currency' => $company->currency, 'company' => $company->name, 'generated_at' => now($tz)->format('Y-m-d H:i')];
    }

    private function money(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'money'];
    }

    private function num(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'number'];
    }

    private function text(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'text'];
    }

    /**
     * Every sale in the range (web/app sale documents and old-app sale movements, net of returns,
     * voids left out) — App\Support\SalesSource, one row per sale, local sale day.
     *
     * @return array{0: string, 1: array<int, mixed>} [derived table aliased "s", bindings]
     */
    private function saleRows(): array
    {
        [$from, $bind] = SalesSource::sql($this->companyId, 'x', $this->from->toDateString(), $this->to->toDateString(), $this->locationId);

        return ["(SELECT x.* FROM {$from} WHERE x.sale_date BETWEEN ? AND ?) s", array_merge($bind, [$this->from->toDateString(), $this->to->toDateString()])];
    }

    /**
     * One row per product line, same rules as saleRows(), with the product ("p") and category ("c") joined.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function lineRows(): array
    {
        [$from, $bind] = SalesSource::linesSql($this->companyId, 'x', $this->from->toDateString(), $this->to->toDateString(), $this->locationId);

        return ["(SELECT x.* FROM {$from} WHERE x.sale_date BETWEEN ? AND ?) l LEFT JOIN stock_items p ON p.id = l.stock_item_id LEFT JOIN stock_categories c ON c.id = p.stock_category_id",
            array_merge($bind, [$this->from->toDateString(), $this->to->toDateString()])];
    }

    private function totals(array $rows, array $keys): array
    {
        $t = [];
        foreach ($keys as $k) {
            $t[$k] = round(array_sum(array_map(fn ($r) => (float) ($r[$k] ?? 0), $rows)), 2);
        }

        return $t;
    }

    /** Money received per payment method for the sales in the range. */
    private function salesByMethod(): array
    {
        [$from, $bind] = $this->saleRows();
        $byMethod = [];
        $add = function (string $method, int $sales, float $amount) use (&$byMethod) {
            $key = \App\Models\Payment::methodKey($method); // gift card / points / store credit stay apart (C1, C2)
            $byMethod[$key] ??= ['sales' => 0, 'amount' => 0.0];
            $byMethod[$key]['sales'] += $sales;
            $byMethod[$key]['amount'] += $amount;
        };
        // Payment rows are signed: refunds and reversals are stored negative already.
        foreach (DB::select("SELECT p.method, COUNT(DISTINCT p.sale_record_id) AS sales, SUM(p.amount) AS amount
            FROM {$from} JOIN payments p ON p.sale_record_id = s.sale_id AND p.is_deleted = 0 WHERE s.sale_id > 0 GROUP BY p.method", $bind) as $r) {
            $add((string) $r->method, (int) $r->sales, (float) $r->amount);
        }
        // Sales from before payment rows existed: what was paid at the till lives only in amount_paid.
        foreach (DB::select("SELECT r.payment_method AS method, COUNT(*) AS sales, SUM(s.amount_paid - COALESCE(pp.total, 0)) AS amount
            FROM {$from} JOIN sale_records r ON r.id = s.sale_id
            LEFT JOIN (SELECT sale_record_id, SUM(amount) AS total FROM payments WHERE company_id = ? AND is_deleted = 0 GROUP BY sale_record_id) pp ON pp.sale_record_id = s.sale_id
            WHERE s.sale_id > 0 AND s.amount_paid - COALESCE(pp.total, 0) > 0 GROUP BY r.payment_method", array_merge($bind, [$this->companyId])) as $r) {
            $add((string) $r->method, (int) $r->sales, (float) $r->amount);
        }
        // Old-app sale movements have no payment rows; they were cash at the till.
        $old = DB::selectOne("SELECT COUNT(CASE WHEN s.total_amount > 0 THEN 1 END) AS sales, COALESCE(SUM(s.total_amount), 0) AS amount FROM {$from} WHERE s.sale_id <= 0", $bind);
        if ((float) $old->amount != 0.0) {
            $add('cash', (int) $old->sales, (float) $old->amount);
        }
        uasort($byMethod, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        $rows = [];
        foreach ($byMethod as $method => $v) {
            $rows[] = ['label' => ucwords(str_replace('_', ' ', (string) $method)), 'sales' => $v['sales'], 'amount' => round($v['amount'], 2)];
        }
        $credit = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(s.balance), 0) AS owed FROM {$from} WHERE s.balance > 0", $bind);
        if ((float) $credit->owed > 0) {
            $rows[] = ['label' => 'On credit (still owed)', 'sales' => (int) $credit->n, 'amount' => round((float) $credit->owed, 2)];
        }

        return $rows;
    }

    private function salesSummary(array $o): array
    {
        $by = in_array($o['group_by'] ?? 'day', self::GROUPS, true) ? ($o['group_by'] ?? 'day') : 'day';
        if ($by === 'method') {
            $rows = $this->salesByMethod();

            return ['columns' => [$this->text('label', 'Paid with'), $this->num('sales', 'Sales'), $this->money('amount', 'Amount')], 'rows' => $rows, 'totals' => $this->totals($rows, ['sales', 'amount'])];
        }
        if (in_array($by, ['category', 'product'], true)) {
            [$from, $bind] = $this->lineRows();
            $label = $by === 'category' ? 'COALESCE(c.name, "Uncategorised")' : 'COALESCE(l.item_name, p.name)';
            $rows = collect(DB::select("SELECT {$label} AS label, SUM(l.quantity) AS quantity, SUM(l.revenue) AS amount
                FROM {$from} GROUP BY {$label} ORDER BY amount DESC", $bind))
                ->map(fn ($r) => ['label' => (string) $r->label, 'quantity' => round((float) $r->quantity, 3), 'amount' => round((float) $r->amount, 2)])->all();

            return ['columns' => [$this->text('label', ucfirst($by)), $this->num('quantity', 'Quantity'), $this->money('amount', 'Sales')], 'rows' => $rows, 'totals' => $this->totals($rows, ['quantity', 'amount'])];
        }
        $label = match ($by) {
            'cashier' => 'COALESCE(u.name, "—")',
            'customer' => 'COALESCE(NULLIF(s.customer_name, ""), "Walk-in")',
            default => 's.sale_date',
        };
        [$from, $bind] = $this->saleRows();
        $joins = 'LEFT JOIN sale_records r ON r.id = s.sale_id'.($by === 'cashier' ? ' LEFT JOIN admin_users u ON u.id = s.created_by_id' : '');
        // Fully returned sales (net 0) are not counted as sales.
        $rows = collect(DB::select("SELECT {$label} AS label, COUNT(CASE WHEN s.total_amount > 0 THEN 1 END) AS sales, SUM(s.total_amount) AS amount,
                SUM(COALESCE(r.discount_amount, 0)) AS discounts, SUM(s.balance) AS owed
            FROM {$from} {$joins} GROUP BY {$label} ORDER BY ".($by === 'day' ? 'label ASC' : 'amount DESC'), $bind))
            ->map(fn ($r) => ['label' => (string) $r->label, 'sales' => (int) $r->sales, 'amount' => round((float) $r->amount, 2), 'discounts' => round((float) $r->discounts, 2), 'owed' => round((float) $r->owed, 2)])->all();

        return ['columns' => [$this->text('label', ucfirst($by)), $this->num('sales', 'Sales'), $this->money('amount', 'Net sales'), $this->money('discounts', 'Discounts'), $this->money('owed', 'Still owed')],
            'rows' => $rows, 'totals' => $this->totals($rows, ['sales', 'amount', 'discounts', 'owed'])];
    }

    /** Operating expenses in the range: stock bought is cost of goods (counted when sold), not an expense. */
    private function operatingExpenses(): float
    {
        if ($this->locationId !== null) {
            return 0.0; // expenses belong to the business, not to one store
        }

        return (float) DB::table('financial_records')->where('company_id', $this->companyId)->where('type', 'Expense')->where('is_deleted', 0)
            ->where(fn ($q) => $q->whereNull('source_type')->orWhereNotIn('source_type', ['goods_receipt', 'supplier_payment']))
            ->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()])->sum('amount');
    }

    private function profit(array $o): array
    {
        $by = ($o['group_by'] ?? 'day') === 'product' ? 'product' : 'day';
        $label = $by === 'product' ? 'COALESCE(l.item_name, p.name)' : 'l.sale_date';
        [$from, $bind] = $this->lineRows();
        $rows = collect(DB::select("SELECT {$label} AS label, SUM(l.revenue) AS revenue, SUM(l.profit) AS profit
            FROM {$from} GROUP BY {$label} ORDER BY ".($by === 'day' ? 'label ASC' : 'revenue DESC'), $bind))
            ->map(function ($r) {
                $rev = round((float) $r->revenue, 2);
                $profit = round((float) $r->profit, 2);

                return ['label' => (string) $r->label, 'revenue' => $rev, 'cost' => round($rev - $profit, 2), 'profit' => $profit, 'margin' => $rev > 0 ? round($profit * 100 / $rev, 1) : 0.0];
            })->all();
        $t = $this->totals($rows, ['revenue', 'cost', 'profit']);
        $t['margin'] = $t['revenue'] > 0 ? round($t['profit'] * 100 / $t['revenue'], 1) : 0.0;
        $expenses = $this->operatingExpenses();

        return ['columns' => [$this->text('label', $by === 'day' ? 'Day' : 'Product'), $this->money('revenue', 'Sales'), $this->money('cost', 'Cost of goods'), $this->money('profit', 'Gross profit'),
            ['key' => 'margin', 'label' => 'Margin %', 'type' => 'percent']], 'rows' => $rows, 'totals' => $t,
            'meta' => ['operating_expenses' => round($expenses, 2), 'net_profit' => round($t['profit'] - $expenses, 2)]];
    }

    private function products()
    {
        return DB::table('stock_items as p')->where('p.company_id', $this->companyId)->where('p.is_deleted', false);
    }

    /** SQL for a product's on-hand quantity: the product total, or one store's shelf. */
    private function onHandSql(string $alias = 'p'): string
    {
        return $this->locationId === null ? "{$alias}.current_quantity"
            : "(SELECT COALESCE(SUM(sl.quantity), 0) FROM stock_levels sl WHERE sl.stock_item_id = {$alias}.id AND sl.location_id = ".(int) $this->locationId.')';
    }

    private function stockValuation(array $o): array
    {
        $rows = $this->products()->where('p.track_stock', true)->leftJoin('stock_categories as c', 'c.id', '=', 'p.stock_category_id')
            ->orderBy('c.name')->orderBy('p.name')->get(['p.name', 'c.name as category', DB::raw($this->onHandSql().' AS current_quantity'), 'p.buying_price', 'p.selling_price'])
            ->map(fn ($r) => ['name' => $r->name, 'category' => $r->category, 'quantity' => round((float) $r->current_quantity, 3), 'unit_cost' => (float) $r->buying_price,
                'value_at_cost' => round(max(0, (float) $r->current_quantity) * (float) $r->buying_price, 2), 'value_at_price' => round(max(0, (float) $r->current_quantity) * (float) $r->selling_price, 2)])->all();

        return ['columns' => [$this->text('name', 'Product'), $this->text('category', 'Category'), $this->num('quantity', 'On hand'), $this->money('unit_cost', 'Cost'),
            $this->money('value_at_cost', 'Value at cost'), $this->money('value_at_price', 'Value at price')], 'rows' => $rows, 'totals' => $this->totals($rows, ['value_at_cost', 'value_at_price'])];
    }

    private function lowStock(array $o): array
    {
        $default = (float) (Company::withoutGlobalScopes()->find($this->companyId)?->low_stock_default ?? config('saas.low_stock_threshold', 10));
        $onHand = $this->onHandSql();
        $rows = $this->products()->where('p.track_stock', true)->whereRaw("{$onHand} <= COALESCE(p.min_stock, ?)", [$default])->orderByRaw($onHand)
            ->get(['p.name', DB::raw("{$onHand} AS current_quantity"), 'p.min_stock'])
            ->map(fn ($r) => ['name' => $r->name, 'quantity' => round((float) $r->current_quantity, 3), 'minimum' => $r->min_stock === null ? $default : (float) $r->min_stock])->all();

        return ['columns' => [$this->text('name', 'Product'), $this->num('quantity', 'On hand'), $this->num('minimum', 'Minimum')], 'rows' => $rows, 'totals' => []];
    }

    private function deadStock(array $o): array
    {
        $days = max(7, (int) ($o['days'] ?? 60));
        $since = now()->subDays($days);
        $rows = $this->products()->where('p.track_stock', true)->where('p.current_quantity', '>', 0)
            ->whereNotExists(fn ($q) => $q->from('stock_records as r')->whereColumn('r.stock_item_id', 'p.id')->where('r.type', 'Sale')->where('r.date', '>=', $since))
            ->leftJoin('product_stats as ps', 'ps.stock_item_id', '=', 'p.id')->orderByDesc(DB::raw('p.current_quantity * p.buying_price'))
            ->get(['p.name', 'p.current_quantity', 'p.buying_price', 'ps.last_sold_at'])
            ->map(fn ($r) => ['name' => $r->name, 'quantity' => round((float) $r->current_quantity, 3), 'value_at_cost' => round((float) $r->current_quantity * (float) $r->buying_price, 2),
                'last_sold' => $r->last_sold_at ? substr((string) $r->last_sold_at, 0, 10) : 'never'])->all();

        return ['columns' => [$this->text('name', 'Product'), $this->num('quantity', 'On hand'), $this->money('value_at_cost', 'Money tied up'), $this->text('last_sold', 'Last sold')],
            'rows' => $rows, 'totals' => $this->totals($rows, ['value_at_cost']), 'meta' => ['days' => $days]];
    }

    private function fastMovers(array $o): array
    {
        $limit = min(100, max(5, (int) ($o['limit'] ?? 20)));
        [$from, $bind] = $this->lineRows();
        $rows = collect(DB::select("SELECT COALESCE(MAX(p.name), MAX(l.item_name)) AS name, SUM(l.quantity) AS quantity, SUM(l.revenue) AS amount
            FROM {$from} GROUP BY l.stock_item_id HAVING SUM(l.quantity) > 0 ORDER BY quantity DESC LIMIT {$limit}", $bind))
            ->map(fn ($r) => ['name' => $r->name, 'quantity' => round((float) $r->quantity, 3), 'amount' => round((float) $r->amount, 2)])->all();

        return ['columns' => [$this->text('name', 'Product'), $this->num('quantity', 'Sold'), $this->money('amount', 'Sales')], 'rows' => $rows, 'totals' => $this->totals($rows, ['quantity', 'amount'])];
    }

    private function customerAging(array $o): array
    {
        $today = now($this->to->getTimezone())->toDateString();
        $rows = DB::table('sale_records as s')->where('s.company_id', $this->companyId)->where('s.status', '!=', 'Voided')->whereNull('s.voided_at')->where('s.balance', '>', 0)
            ->groupByRaw('COALESCE(s.customer_id, 0), COALESCE(NULLIF(s.customer_name, ""), "Walk-in")')
            ->selectRaw('COALESCE(NULLIF(s.customer_name, ""), "Walk-in") AS name, MAX(s.customer_phone) AS phone,
                SUM(CASE WHEN DATEDIFF(?, s.sale_date) <= 30 THEN s.balance ELSE 0 END) AS d0_30,
                SUM(CASE WHEN DATEDIFF(?, s.sale_date) BETWEEN 31 AND 60 THEN s.balance ELSE 0 END) AS d31_60,
                SUM(CASE WHEN DATEDIFF(?, s.sale_date) > 60 THEN s.balance ELSE 0 END) AS d60_plus, SUM(s.balance) AS total, MIN(s.sale_date) AS oldest', [$today, $today, $today])
            ->orderByDesc('total')->get()
            ->map(fn ($r) => ['name' => $r->name, 'phone' => $r->phone, 'd0_30' => round((float) $r->d0_30, 2), 'd31_60' => round((float) $r->d31_60, 2), 'd60_plus' => round((float) $r->d60_plus, 2),
                'total' => round((float) $r->total, 2), 'oldest' => (string) $r->oldest])->all();

        return ['columns' => [$this->text('name', 'Customer'), $this->text('phone', 'Phone'), $this->money('d0_30', '0–30 days'), $this->money('d31_60', '31–60 days'),
            $this->money('d60_plus', 'Over 60 days'), $this->money('total', 'Total owed'), $this->text('oldest', 'Oldest sale')], 'rows' => $rows, 'totals' => $this->totals($rows, ['d0_30', 'd31_60', 'd60_plus', 'total'])];
    }

    private function supplierBalances(array $o): array
    {
        $rows = DB::table('suppliers')->where('company_id', $this->companyId)->where('is_deleted', false)->orderByDesc('balance')->get(['name', 'phone', 'balance', 'payment_terms_days'])
            ->map(fn ($r) => ['name' => $r->name, 'phone' => $r->phone, 'terms' => (int) $r->payment_terms_days, 'balance' => round((float) $r->balance, 2)])->all();

        return ['columns' => [$this->text('name', 'Supplier'), $this->text('phone', 'Phone'), $this->num('terms', 'Terms (days)'), $this->money('balance', 'We owe')], 'rows' => $rows, 'totals' => $this->totals($rows, ['balance'])];
    }

    /** D5: per supplier with orders or deliveries in the range, SmartReorderService::scorecard over the range. */
    private function supplierScorecard(array $o): array
    {
        $from = $this->from->toDateString();
        $to = $this->to->toDateString();
        $active = DB::table('suppliers as s')->where('s.company_id', $this->companyId)->where('s.is_deleted', false)
            ->where(fn ($q) => $q->whereExists(fn ($e) => $e->from('purchase_orders as p')->whereColumn('p.supplier_id', 's.id')->whereNull('p.deleted_at')->whereBetween('p.order_date', [$from, $to.' 23:59:59']))
                ->orWhereExists(fn ($e) => $e->from('goods_receipts as g')->whereColumn('g.supplier_id', 's.id')->where('g.is_deleted', 0)->whereBetween('g.received_on', [$from, $to.' 23:59:59'])))
            ->orderBy('s.name')->pluck('s.id');
        $svc = new \App\Services\Shop\SmartReorderService();
        $rows = [];
        foreach (\App\Models\Supplier::withoutGlobalScopes()->whereIn('id', $active)->orderBy('name')->get() as $s) {
            $c = $svc->scorecard($s, 90, $from, $to);
            $rows[] = ['name' => (string) $s->name, 'orders' => $c['orders'], 'fill_rate' => $c['fill_rate'], 'lead_time' => $c['lead_time_days'], 'avg_lead' => $c['avg_lead_days'],
                'avg_days_late' => $c['avg_days_late'], 'on_time' => $c['on_time_pct'], 'price_changes' => $c['price_changes'], 'price_change_pct' => $c['price_change_pct']];
        }

        return ['columns' => [$this->text('name', 'Supplier'), $this->num('orders', 'Orders'), $this->percent('fill_rate', 'Fill rate %'), $this->num('lead_time', 'Promised days'),
            $this->num('avg_lead', 'Took (days)'), $this->num('avg_days_late', 'Days late'), $this->percent('on_time', 'On time %'), $this->num('price_changes', 'Cost changes'),
            $this->percent('price_change_pct', 'Average change %')], 'rows' => $rows, 'totals' => [],
            'meta' => ['note' => 'Fill rate is what arrived of what was ordered (orders received, part received, or overdue). Days late count from the expected date, or the order date plus the supplier\'s delivery time; negative is early. Cost changes compare each product\'s cost from the supplier at the start and end of the range.']];
    }

    private function cashUp(array $o): array
    {
        $rows = DB::table('shifts as sh')->where('sh.company_id', $this->companyId)->where('sh.status', 'closed')
            ->whereBetween('sh.closed_at', [$this->from->copy()->utc(), $this->to->copy()->utc()])->leftJoin('admin_users as u', 'u.id', '=', 'sh.opened_by_id')->orderBy('sh.closed_at')
            ->get(['sh.number', 'u.name as cashier', 'sh.closed_at', 'sh.sales_total', 'sh.expected_cash', 'sh.counted_cash', 'sh.variance'])
            ->map(fn ($r) => ['number' => $r->number, 'cashier' => $r->cashier, 'closed_at' => substr((string) $r->closed_at, 0, 16), 'sales' => round((float) $r->sales_total, 2),
                'expected' => round((float) $r->expected_cash, 2), 'counted' => round((float) $r->counted_cash, 2), 'variance' => round((float) $r->variance, 2)])->all();

        return ['columns' => [$this->text('number', 'Shift'), $this->text('cashier', 'Cashier'), $this->text('closed_at', 'Closed'), $this->money('sales', 'Sales'), $this->money('expected', 'Expected cash'),
            $this->money('counted', 'Counted'), $this->money('variance', 'Difference')], 'rows' => $rows, 'totals' => $this->totals($rows, ['sales', 'expected', 'counted', 'variance'])];
    }

    /** Prices include VAT at the shop's rate: VAT = amount × rate ÷ (100 + rate). */
    private function vatSummary(array $o): array
    {
        $rate = (float) (Company::withoutGlobalScopes()->find($this->companyId)?->tax_rate ?? 0);
        // Sales taxed per line (tax classes, F1) use the tax stored on their lines, net of returns. Every other sale
        // (older ones, shops without tax classes, old-app movements) is worked out from the gross at the shop's rate.
        [$from, $bind] = $this->saleRows();
        $taxed = "(SELECT i.sale_record_id, COUNT(i.tax_rate) AS n,
                SUM(COALESCE(i.tax_amount, 0) * (i.quantity - COALESCE(i.returned_quantity, 0)) / NULLIF(i.quantity, 0)) AS tax
            FROM sale_record_items i WHERE i.company_id = ? AND COALESCE(i.is_deleted, 0) = 0 GROUP BY i.sale_record_id) t";
        $sum = DB::selectOne("SELECT COALESCE(SUM(s.total_amount), 0) AS total,
                COALESCE(SUM(CASE WHEN t.n > 0 THEN s.total_amount END), 0) AS taxed_gross, COALESCE(SUM(CASE WHEN t.n > 0 THEN t.tax END), 0) AS taxed_vat
            FROM {$from} LEFT JOIN {$taxed} ON t.sale_record_id = s.sale_id", array_merge($bind, [$this->companyId]));
        $sales = (float) $sum->total;
        $purchases = (float) DB::table('goods_receipts')->where('company_id', $this->companyId)->whereBetween('received_on', [$this->from->toDateString(), $this->to->toDateString()])->sum('total_cost');
        $returns = (float) DB::table('purchase_returns')->where('company_id', $this->companyId)->whereBetween('returned_on', [$this->from->toDateString(), $this->to->toDateString()])->sum('total_value');
        $vat = fn (float $gross) => $rate > 0 ? round($gross * $rate / (100 + $rate), 2) : 0.0;
        $rows = [
            ['label' => 'Sales (VAT inclusive)', 'gross' => round($sales, 2), 'vat' => round((float) $sum->taxed_vat + $vat($sales - (float) $sum->taxed_gross), 2)],
            ['label' => 'Purchases (VAT inclusive, less returns)', 'gross' => round($purchases - $returns, 2), 'vat' => $vat($purchases - $returns)],
        ];
        $meta = ['rate' => $rate, 'vat_payable' => round($rows[0]['vat'] - $rows[1]['vat'], 2),
            'note' => $rate > 0 ? "VAT at {$rate}% included in prices." : 'No VAT rate is set for this shop (Company settings → VAT).'];
        if ((float) $sum->taxed_gross > 0) {
            $meta['by_class'] = $this->vatByClass();
            foreach ($meta['by_class'] as $c) { // after the two rows above (readers use rows 0 and 1): the per-line sales, per class
                $rows[] = ['label' => 'of which sales at '.$c['class'].' '.\App\Services\Shop\TaxClassService::pct($c['rate']), 'gross' => $c['sales'], 'vat' => $c['vat']];
            }
            $meta['note'] = 'Sales with tax classes use the tax worked out on each line; others use '.($rate > 0 ? "VAT at {$rate}% included in prices." : 'no VAT (no rate is set).');
        }

        return ['columns' => [$this->text('label', 'Item'), $this->money('gross', 'Amount'), $this->money('vat', 'VAT')], 'rows' => $rows,
            'totals' => ['vat' => $meta['vat_payable']], 'meta' => $meta];
    }

    /** Sales tax per class and rate for the lines taxed per line (net of returns). @return list<array{class: string, rate: float, sales: float, vat: float}> */
    private function vatByClass(): array
    {
        [$from, $bind] = $this->saleRows();

        return collect(DB::select("SELECT COALESCE(tc.name, 'VAT') AS class, i.tax_rate AS rate,
                SUM(i.line_total * (i.quantity - COALESCE(i.returned_quantity, 0)) / NULLIF(i.quantity, 0)) AS sales,
                SUM(i.tax_amount * (i.quantity - COALESCE(i.returned_quantity, 0)) / NULLIF(i.quantity, 0)) AS vat
            FROM {$from} JOIN sale_record_items i ON i.sale_record_id = s.sale_id AND COALESCE(i.is_deleted, 0) = 0
            LEFT JOIN tax_classes tc ON tc.id = i.tax_class_id
            WHERE s.sale_id > 0 AND i.tax_rate IS NOT NULL GROUP BY tc.name, i.tax_rate ORDER BY i.tax_rate DESC", $bind))
            ->map(fn ($r) => ['class' => (string) $r->class, 'rate' => (float) $r->rate, 'sales' => round((float) $r->sales, 2), 'vat' => round((float) $r->vat, 2)])->all();
    }

    private function purchaseSummary(array $o): array
    {
        $rows = DB::table('goods_receipts as g')->leftJoin('suppliers as s', 's.id', '=', 'g.supplier_id')->where('g.company_id', $this->companyId)
            ->whereBetween('g.received_on', [$this->from->toDateString(), $this->to->toDateString()])->groupByRaw('COALESCE(s.name, "No supplier")')
            ->selectRaw('COALESCE(s.name, "No supplier") AS supplier, COUNT(*) AS deliveries, SUM(g.total_cost) AS total, SUM(g.amount_paid) AS paid')->orderByDesc('total')->get()
            ->map(fn ($r) => ['supplier' => $r->supplier, 'deliveries' => (int) $r->deliveries, 'total' => round((float) $r->total, 2), 'paid' => round((float) $r->paid, 2)])->all();
        $returned = (float) DB::table('purchase_returns')->where('company_id', $this->companyId)->whereBetween('returned_on', [$this->from->toDateString(), $this->to->toDateString()])->sum('total_value');

        return ['columns' => [$this->text('supplier', 'Supplier'), $this->num('deliveries', 'Deliveries'), $this->money('total', 'Received'), $this->money('paid', 'Paid on delivery')],
            'rows' => $rows, 'totals' => $this->totals($rows, ['deliveries', 'total', 'paid']), 'meta' => ['returned_to_suppliers' => round($returned, 2)]];
    }

    private function movementAudit(array $o): array
    {
        $rows = DB::table('stock_records as r')->where('r.company_id', $this->companyId)->whereBetween('r.date', [$this->from->copy()->utc(), $this->to->copy()->utc()])
            ->when(! empty($o['type']), fn ($q) => $q->where('r.type', $o['type']))->when(! empty($o['stock_item_id']), fn ($q) => $q->where('r.stock_item_id', (int) $o['stock_item_id']))
            ->leftJoin('admin_users as u', 'u.id', '=', 'r.created_by_id')->orderByDesc('r.id')->limit(min(5000, (int) ($o['limit'] ?? 1000)))
            ->get(['r.date', 'r.name', 'r.type', 'r.quantity_delta', 'r.reason', 'r.description', 'u.name as by'])
            ->map(fn ($r) => ['date' => substr((string) $r->date, 0, 16), 'product' => $r->name, 'type' => $r->type, 'change' => round((float) $r->quantity_delta, 3),
                'reason' => $r->reason ?: $r->description, 'by' => $r->by])->all();

        return ['columns' => [$this->text('date', 'When'), $this->text('product', 'Product'), $this->text('type', 'Type'), $this->num('change', 'Change'), $this->text('reason', 'Reason'), $this->text('by', 'By')],
            'rows' => $rows, 'totals' => []];
    }

    /**
     * The period's profit & loss (classic FinancialReport PDF): sales − cost of goods = gross profit,
     * + other income − operating expenses = net profit, from FinancialReportService (live figures).
     * The statement is the main table; `sections` add the ledger by category, the ledger entries,
     * and sales and profit by stock category and by product. Stock bought is cost of goods (counted
     * when sold) and sales income in the ledger is not added again.
     */
    private function incomeStatement(array $o): array
    {
        $fin = new \App\Services\FinancialReportService();
        $cid = $this->companyId;
        $from = $this->from->toDateString();
        $to = $this->to->toDateString();
        $sum = $fin->getSummaryStatistics($cid, $from, $to, true);
        $sales = round((float) $sum['inventory']['inventory_total_selling_price'], 2);
        $cogs = round((float) $sum['inventory']['inventory_total_cost'], 2);
        $gross = (float) $sum['gross_profit'];
        $other = (float) $sum['other_income'];
        $opex = (float) $sum['operating_expenses'];
        $net = (float) $sum['overall_profit'];
        $rows = [
            ['line' => 'Sales (net of returns)', 'amount' => $sales],
            ['line' => 'Less: cost of the goods sold', 'amount' => -$cogs],
            ['line' => 'Gross profit', 'amount' => $gross],
            ['line' => 'Add: other income', 'amount' => $other],
            ['line' => 'Less: running expenses', 'amount' => -$opex],
            ['line' => 'Net profit', 'amount' => $net],
        ];

        // Ledger by category (every entry, as the classic report and categories grid show it).
        $accounts = array_map(fn ($a) => ['name' => (string) $a->name, 'income' => round((float) $a->total_income, 2), 'expense' => round((float) $a->total_expense, 2),
            'balance' => round((float) $a->total_income - (float) $a->total_expense, 2), 'entries' => (int) $a->transaction_count], $fin->getFinanceAccounts($cid, $from, $to));
        $records = $fin->getFinanceRecords($cid, $from, $to)->map(fn ($r) => ['date' => substr((string) $r->date, 0, 10), 'type' => (string) $r->type,
            'category' => (string) ($r->financial_category?->name ?? ''), 'description' => (string) ($r->description ?: $r->recipient),
            'method' => ucfirst(str_replace('_', ' ', (string) $r->payment_method)), 'amount' => round((float) $r->amount * ($r->type === 'Expense' ? -1 : 1), 2)])->all();

        // Stock sold, by category (products without one are the remainder, so the rows add up to sales).
        $cats = array_map(fn ($c) => ['name' => (string) $c->name, 'quantity' => round((float) $c->quantity_sold, 3), 'sales' => round((float) $c->total_sales, 2),
            'cost' => round((float) $c->total_sales - (float) $c->profit, 2), 'profit' => round((float) $c->profit, 2)], $fin->getInventoryCategories($cid, $from, $to));
        $catSales = array_sum(array_column($cats, 'sales'));
        $catProfit = array_sum(array_column($cats, 'profit'));
        if (abs($sales - $catSales) >= 0.01 || abs($gross - $catProfit) >= 0.01) {
            $cats[] = ['name' => 'Uncategorised', 'quantity' => 0.0, 'sales' => round($sales - $catSales, 2), 'cost' => round(($sales - $catSales) - ($gross - $catProfit), 2), 'profit' => round($gross - $catProfit, 2)];
        }
        $products = [];
        foreach ($fin->getInventoryProducts($cid, $from, $to) as $p) {
            if ((float) $p->quantity_sold == 0.0 && (float) $p->revenue == 0.0) {
                continue;
            }
            $products[] = ['name' => (string) $p->name, 'category' => (string) ($p->category_name ?? ''), 'quantity' => round((float) $p->quantity_sold, 3),
                'sales' => round((float) $p->revenue, 2), 'profit' => round((float) $p->profit, 2)];
        }

        return [
            'columns' => [$this->text('line', 'Statement'), $this->money('amount', 'Amount')],
            'rows' => $rows,
            'totals' => [],
            'meta' => ['sales' => $sales, 'cost_of_goods' => $cogs, 'gross_profit' => $gross, 'other_income' => $other, 'operating_expenses' => $opex, 'net_profit' => $net,
                'note' => 'Stock you bought is counted as cost of goods when it sells, not as an expense; sales money in the ledger is not counted twice.'],
            'sections' => [
                ['key' => 'categories', 'title' => 'Income and expenses by category',
                    'columns' => [$this->text('name', 'Category'), $this->money('income', 'Income'), $this->money('expense', 'Expenses'), $this->money('balance', 'Balance'), $this->num('entries', 'Entries')],
                    'rows' => $accounts, 'totals' => $this->totals($accounts, ['income', 'expense', 'balance', 'entries'])],
                ['key' => 'records', 'title' => 'Ledger entries',
                    'columns' => [$this->text('date', 'Date'), $this->text('type', 'Type'), $this->text('category', 'Category'), $this->text('description', 'What for'), $this->text('method', 'Paid with'), $this->money('amount', 'Amount')],
                    'rows' => $records, 'totals' => []],
                ['key' => 'sales_by_category', 'title' => 'Stock sales and profit by category',
                    'columns' => [$this->text('name', 'Category'), $this->num('quantity', 'Quantity'), $this->money('sales', 'Sales'), $this->money('cost', 'Cost of goods'), $this->money('profit', 'Profit')],
                    'rows' => $cats, 'totals' => $this->totals($cats, ['sales', 'cost', 'profit'])],
                ['key' => 'sales_by_product', 'title' => 'Stock sales and profit by product',
                    'columns' => [$this->text('name', 'Product'), $this->text('category', 'Category'), $this->num('quantity', 'Quantity'), $this->money('sales', 'Sales'), $this->money('profit', 'Profit')],
                    'rows' => $products, 'totals' => $this->totals($products, ['quantity', 'sales', 'profit'])],
            ],
        ];
    }

    private function expiry(array $o): array
    {
        $days = max(1, (int) ($o['days'] ?? 60));
        if (! \Illuminate\Support\Facades\Schema::hasTable('stock_batches')) {
            return ['columns' => [], 'rows' => [], 'totals' => []];
        }
        $rows = DB::table('stock_batches as b')->join('stock_items as p', 'p.id', '=', 'b.stock_item_id')->where('b.company_id', $this->companyId)->where('b.quantity', '>', 0)
            ->whereNotNull('b.expiry_date')->where('b.expiry_date', '<=', now()->addDays($days)->toDateString())->orderBy('b.expiry_date')
            ->get(['p.name', 'b.batch_number', 'b.expiry_date', 'b.quantity', 'p.buying_price'])
            ->map(fn ($r) => ['name' => $r->name, 'batch' => $r->batch_number, 'expiry' => (string) $r->expiry_date, 'days_left' => (int) now()->startOfDay()->diffInDays(Carbon::parse($r->expiry_date), false),
                'quantity' => round((float) $r->quantity, 3), 'value_at_cost' => round((float) $r->quantity * (float) $r->buying_price, 2)])->all();

        return ['columns' => [$this->text('name', 'Product'), $this->text('batch', 'Batch'), $this->text('expiry', 'Expires'), $this->num('days_left', 'Days left'), $this->num('quantity', 'Quantity'),
            $this->money('value_at_cost', 'Value at cost')], 'rows' => $rows, 'totals' => $this->totals($rows, ['value_at_cost']), 'meta' => ['days' => $days]];
    }

    // ── Supermarket insight (SUPERMARKET_PLAN.md H2, H3, H4, H6) ──────────────────────────────

    /** Stock-out movement types that are a loss, not a sale, transfer or return to a supplier (H6 shrink). */
    public const WRITE_OFF_TYPES = ['Damage', 'Expired', 'Lost', 'Internal Use', 'Adjustment Out', 'Other', 'Stock Out'];

    private const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private function percent(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'percent'];
    }

    /** Local days in the report's range (both ends counted). */
    private function rangeDays(): int
    {
        return (int) round((strtotime($this->to->toDateString()) - strtotime($this->from->toDateString())) / 86400) + 1;
    }

    /**
     * Sales and profit of the product lines between two local days, grouped by $label (SQL over l = line,
     * p = product, c = category, sc = sub-category). SalesSource, so old-app sale movements count once.
     *
     * @return array<string, array{sales: float, profit: float}>
     */
    private function linesBy(string $from, string $to, string $label): array
    {
        [$sql, $bind] = SalesSource::linesSql($this->companyId, 'x', $from, $to, $this->locationId);
        $out = [];
        foreach (DB::select("SELECT {$label} AS label, SUM(l.revenue) AS sales, SUM(l.profit) AS profit
            FROM (SELECT x.* FROM {$sql} WHERE x.sale_date BETWEEN ? AND ?) l
            LEFT JOIN stock_items p ON p.id = l.stock_item_id LEFT JOIN stock_categories c ON c.id = p.stock_category_id
            LEFT JOIN stock_sub_categories sc ON sc.id = p.stock_sub_category_id
            GROUP BY {$label}", array_merge($bind, [$from, $to])) as $r) {
            $out[(string) $r->label] = ['sales' => (float) $r->sales, 'profit' => (float) $r->profit];
        }

        return $out;
    }

    /**
     * H3: sales, cost, margin, share of sales and growth against the previous period of the same length,
     * by category; a section by sub-category (the shop's brand / line).
     */
    private function categoryMargin(array $o): array
    {
        $from = $this->from->toDateString();
        $to = $this->to->toDateString();
        $days = $this->rangeDays();
        $prevTo = date('Y-m-d', (int) strtotime($from.' -1 day'));
        $prevFrom = date('Y-m-d', (int) strtotime($prevTo.' -'.($days - 1).' days'));
        $cat = 'COALESCE(c.name, "Uncategorised")';
        $sub = 'CONCAT(COALESCE(c.name, "Uncategorised"), " › ", COALESCE(sc.name, "No sub-category"))';

        $build = function (array $now, array $prev): array {
            $total = array_sum(array_column($now, 'sales'));
            $rows = [];
            foreach (array_keys($now + $prev) as $label) {
                $sales = round($now[$label]['sales'] ?? 0, 2);
                $profit = round($now[$label]['profit'] ?? 0, 2);
                $before = round($prev[$label]['sales'] ?? 0, 2);
                $rows[] = ['label' => (string) $label, 'sales' => $sales, 'cost' => round($sales - $profit, 2), 'profit' => $profit,
                    'margin' => $sales > 0 ? round($profit * 100 / $sales, 1) : 0.0, 'share' => $total > 0 ? round($sales * 100 / $total, 1) : 0.0,
                    'previous' => $before, 'growth' => $before > 0 ? round(($sales - $before) * 100 / $before, 1) : null];
            }
            usort($rows, fn ($a, $b) => $b['sales'] <=> $a['sales']);
            $t = $this->totals($rows, ['sales', 'cost', 'profit', 'previous']);
            $t['margin'] = $t['sales'] > 0 ? round($t['profit'] * 100 / $t['sales'], 1) : 0.0;
            $t['growth'] = $t['previous'] > 0 ? round(($t['sales'] - $t['previous']) * 100 / $t['previous'], 1) : null;

            return [$rows, $t];
        };
        $columns = fn (string $first) => [$this->text('label', $first), $this->money('sales', 'Sales'), $this->money('cost', 'Cost of goods'), $this->money('profit', 'Gross profit'),
            $this->percent('margin', 'Margin %'), $this->percent('share', 'Share of sales %'), $this->money('previous', 'Previous period'), $this->percent('growth', 'Growth %')];
        [$rows, $totals] = $build($this->linesBy($from, $to, $cat), $this->linesBy($prevFrom, $prevTo, $cat));
        [$subRows, $subTotals] = $build($this->linesBy($from, $to, $sub), $this->linesBy($prevFrom, $prevTo, $sub));

        return ['columns' => $columns('Category'), 'rows' => $rows, 'totals' => $totals,
            'meta' => ['previous_from' => $prevFrom, 'previous_to' => $prevTo, 'note' => "Growth compares with the {$days} day(s) before: {$prevFrom} to {$prevTo}."],
            'sections' => [['key' => 'sub_categories', 'title' => 'By sub-category (brand / line)', 'columns' => $columns('Sub-category'), 'rows' => $subRows, 'totals' => $subTotals]]];
    }

    /**
     * H2: number of sales, average basket, items per basket; by weekday, a weekday × hour heatmap of the
     * number of sales (section type "heatmap", shop-local hours) and by hour; best hour and best day.
     */
    private function basket(array $o): array
    {
        [$from, $bind] = $this->saleRows();
        // A sale's hour is when it was rung up (created_at, UTC) in the shop's timezone; its weekday is its local sale day.
        $hour = "HOUR(CONVERT_TZ(COALESCE(r.created_at, m.created_at), '+00:00', COALESCE(@tz_offset, '+00:00')))";
        $cells = DB::select("SELECT WEEKDAY(s.sale_date) AS wd, {$hour} AS hr, COUNT(*) AS n, SUM(s.total_amount) AS amount
            FROM {$from} LEFT JOIN sale_records r ON s.sale_id > 0 AND r.id = s.sale_id LEFT JOIN stock_records m ON s.sale_id < 0 AND m.id = -s.sale_id
            WHERE s.total_amount > 0 GROUP BY wd, hr", $bind);
        [$lines, $lineBind] = $this->lineRows();
        $items = [];
        foreach (DB::select("SELECT WEEKDAY(l.sale_date) AS wd, SUM(l.quantity) AS qty FROM {$lines} GROUP BY wd", $lineBind) as $r) {
            $items[(int) $r->wd] = (float) $r->qty;
        }

        $byDay = array_fill(0, 7, ['n' => 0, 'amount' => 0.0]);
        $byHour = [];
        $grid = [];
        foreach ($cells as $c) {
            $wd = (int) $c->wd;
            $hr = (int) $c->hr;
            $byDay[$wd]['n'] += (int) $c->n;
            $byDay[$wd]['amount'] += (float) $c->amount;
            $byHour[$hr] ??= ['n' => 0, 'amount' => 0.0];
            $byHour[$hr]['n'] += (int) $c->n;
            $byHour[$hr]['amount'] += (float) $c->amount;
            $grid[$wd][$hr] = ($grid[$wd][$hr] ?? 0) + (int) $c->n;
        }
        ksort($byHour);
        $avg = fn (float $amount, int $n) => $n > 0 ? round($amount / $n, 2) : 0.0;

        $rows = [];
        foreach (self::WEEKDAYS as $wd => $name) {
            $n = $byDay[$wd]['n'];
            $rows[] = ['day' => $name, 'sales' => $n, 'amount' => round($byDay[$wd]['amount'], 2), 'avg_basket' => $avg($byDay[$wd]['amount'], $n),
                'items_per_basket' => $n > 0 ? round(($items[$wd] ?? 0) / $n, 2) : 0.0];
        }
        $count = array_sum(array_column($rows, 'sales'));
        $amount = array_sum(array_column($rows, 'amount'));
        $totals = ['sales' => $count, 'amount' => round($amount, 2), 'avg_basket' => $avg($amount, $count),
            'items_per_basket' => $count > 0 ? round(array_sum($items) / $count, 2) : 0.0];

        // Heatmap: one row per weekday, one column per hour from the first to the last hour with a sale.
        $heatRows = [];
        $heatCols = [$this->text('day', 'Day')];
        if ($byHour !== []) {
            $hours = range(min(array_keys($byHour)), max(array_keys($byHour)));
            foreach ($hours as $h) {
                $heatCols[] = $this->num('h'.sprintf('%02d', $h), sprintf('%02d', $h));
            }
            foreach (self::WEEKDAYS as $wd => $name) {
                $row = ['day' => substr($name, 0, 3)];
                foreach ($hours as $h) {
                    $row['h'.sprintf('%02d', $h)] = $grid[$wd][$h] ?? 0;
                }
                $heatRows[] = $row;
            }
        }
        $hourRows = [];
        foreach ($byHour as $h => $v) {
            $hourRows[] = ['hour' => sprintf('%02d:00–%02d:00', $h, ($h + 1) % 24), 'sales' => $v['n'], 'amount' => round($v['amount'], 2), 'avg_basket' => $avg($v['amount'], $v['n'])];
        }

        $bestHour = null;
        foreach ($byHour as $h => $v) {
            if ($bestHour === null || $v['n'] > $byHour[$bestHour]['n'] || ($v['n'] === $byHour[$bestHour]['n'] && $v['amount'] > $byHour[$bestHour]['amount'])) {
                $bestHour = $h;
            }
        }
        $bestDay = null;
        foreach ($rows as $wd => $r) {
            if ($r['sales'] > 0 && ($bestDay === null || $r['sales'] > $rows[$bestDay]['sales'] || ($r['sales'] === $rows[$bestDay]['sales'] && $r['amount'] > $rows[$bestDay]['amount']))) {
                $bestDay = $wd;
            }
        }

        return ['columns' => [$this->text('day', 'Day'), $this->num('sales', 'Sales'), $this->money('amount', 'Sales value'), $this->money('avg_basket', 'Average basket'),
            $this->num('items_per_basket', 'Items per basket')], 'rows' => $rows, 'totals' => $totals,
            'meta' => ['sales_count' => $count, 'avg_basket' => $totals['avg_basket'], 'items_per_basket' => $totals['items_per_basket'],
                'best_hour' => $bestHour === null ? null : sprintf('%02d:00–%02d:00', $bestHour, ($bestHour + 1) % 24), 'best_day' => $bestDay === null ? null : self::WEEKDAYS[$bestDay],
                'note' => 'Hours are the shop\'s local time, when each sale was rung up. Fully returned sales are left out.'],
            'sections' => [
                ['key' => 'heatmap', 'type' => 'heatmap', 'title' => 'Sales by weekday and hour', 'columns' => $heatCols, 'rows' => $heatRows, 'totals' => []],
                ['key' => 'hours', 'title' => 'By hour of the day', 'columns' => [$this->text('hour', 'Hour'), $this->num('sales', 'Sales'), $this->money('amount', 'Sales value'),
                    $this->money('avg_basket', 'Average basket')], 'rows' => $hourRows, 'totals' => $this->totals($hourRows, ['sales', 'amount'])],
            ]];
    }

    /**
     * H4: products ranked by sales value; A = the products making the first 80% of sales, B = the next 15%,
     * C = the last 5%. Days of cover = stock on hand ÷ average daily units sold over the period.
     */
    private function abc(array $o): array
    {
        [$from, $bind] = $this->lineRows();
        $items = DB::select("SELECT l.stock_item_id AS id, COALESCE(MAX(p.name), MAX(l.item_name), 'Item') AS name, MAX(c.name) AS category,
                SUM(l.quantity) AS quantity, SUM(l.revenue) AS revenue, MAX({$this->onHandSql()}) AS on_hand, MAX(p.track_stock) AS track
            FROM {$from} GROUP BY l.stock_item_id HAVING SUM(l.revenue) > 0 ORDER BY revenue DESC, name ASC", $bind);
        $days = $this->rangeDays();
        $total = array_sum(array_map(fn ($r) => (float) $r->revenue, $items));
        $rows = [];
        $count = ['A' => 0, 'B' => 0, 'C' => 0];
        $value = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        $cum = 0.0;
        foreach ($items as $r) {
            $revenue = (float) $r->revenue;
            // Classed by where the product starts on the cumulative curve, so the top seller is always A.
            $class = $cum < 80 ? 'A' : ($cum < 95 ? 'B' : 'C');
            $share = $total > 0 ? $revenue * 100 / $total : 0.0;
            $cum += $share;
            $perDay = (float) $r->quantity / $days;
            $tracked = $r->id !== null && (bool) $r->track;
            $count[$class]++;
            $value[$class] += $revenue;
            $rows[] = ['name' => (string) $r->name, 'category' => (string) ($r->category ?? ''), 'class' => $class, 'quantity' => round((float) $r->quantity, 3),
                'sales' => round($revenue, 2), 'share' => round($share, 1), 'cumulative' => round(min(100, $cum), 1),
                'on_hand' => $tracked ? round((float) $r->on_hand, 3) : null, 'per_day' => round($perDay, 2),
                'cover' => $tracked && $perDay > 0 ? round(max(0, (float) $r->on_hand) / $perDay, 1) : null];
        }
        $t = $this->totals($rows, ['quantity', 'sales']);

        return ['columns' => [$this->text('name', 'Product'), $this->text('category', 'Category'), $this->text('class', 'Class'), $this->num('quantity', 'Sold'),
            $this->money('sales', 'Sales'), $this->percent('share', 'Share %'), $this->percent('cumulative', 'Cumulative %'), $this->num('on_hand', 'On hand'),
            $this->num('per_day', 'Sold per day'), $this->num('cover', 'Days of cover')], 'rows' => $rows,
            'totals' => $t + ['class_a' => $count['A'], 'class_b' => $count['B'], 'class_c' => $count['C']],
            'meta' => ['days' => $days, 'class_a_sales' => round($value['A'], 2), 'class_b_sales' => round($value['B'], 2), 'class_c_sales' => round($value['C'], 2),
                'note' => "A: the products that make the first 80% of sales; B: the next 15%; C: the last 5%. Days of cover = on hand ÷ units sold per day over these {$days} day(s)."]];
    }

    /**
     * H6: stock written off (damage, expiry, loss and theft, own use, samples, count shortages and other
     * stock-outs), at cost, by reason, by category and by week, and as a % of the period's sales.
     * Reversed write-offs are left out.
     */
    private function shrink(array $o): array
    {
        $f = $this->from->toDateString();
        $t = $this->to->toDateString();
        [$win, $winBind] = SalesSource::window('r.date', 'r.created_at', $f, $t);
        $day = SalesSource::localDay('r.date', 'r.created_at');
        $in = implode(',', array_fill(0, count(self::WRITE_OFF_TYPES), '?'));
        $why = "CASE WHEN r.reason = 'theft' THEN 'Stolen' WHEN r.reason = 'gift' THEN 'Sample / gift' WHEN r.type = 'Damage' THEN 'Damaged'
            WHEN r.type = 'Expired' THEN 'Expired' WHEN r.type = 'Lost' THEN 'Lost' WHEN r.type = 'Internal Use' THEN 'Own use'
            WHEN r.type = 'Adjustment Out' THEN 'Count shortage' ELSE 'Other stock out' END";
        $w = "(SELECT r.stock_item_id, {$day} AS local_day, -r.quantity_delta AS qty, -r.quantity_delta * COALESCE(r.unit_cost, r.buying_price, 0) AS value, {$why} AS why
            FROM stock_records r WHERE r.company_id = ? AND r.type IN ({$in}) AND r.is_reversal = 0 AND COALESCE(r.is_deleted, 0) = 0
              AND NOT EXISTS (SELECT 1 FROM stock_records rv WHERE rv.reverses_id = r.id){$win}) w";
        $bind = array_merge([$this->companyId], self::WRITE_OFF_TYPES, $winBind, [$f, $t]);

        [$sales, $salesBind] = $this->saleRows();
        $salesTotal = (float) DB::selectOne("SELECT COALESCE(SUM(s.total_amount), 0) AS total FROM {$sales}", $salesBind)->total;
        $pct = fn (float $v, float $of) => $of > 0 ? round($v * 100 / $of, 2) : null;

        $rows = array_map(fn ($r) => ['reason' => (string) $r->why, 'entries' => (int) $r->n, 'quantity' => round((float) $r->qty, 3), 'value' => round((float) $r->value, 2),
            'pct_sales' => $pct((float) $r->value, $salesTotal)],
            DB::select("SELECT w.why, COUNT(*) AS n, SUM(w.qty) AS qty, SUM(w.value) AS value FROM {$w} WHERE w.local_day BETWEEN ? AND ? GROUP BY w.why ORDER BY value DESC", $bind));
        $cats = array_map(fn ($r) => ['category' => (string) $r->category, 'quantity' => round((float) $r->qty, 3), 'value' => round((float) $r->value, 2), 'pct_sales' => $pct((float) $r->value, $salesTotal)],
            DB::select("SELECT COALESCE(c.name, 'Uncategorised') AS category, SUM(w.qty) AS qty, SUM(w.value) AS value FROM {$w}
                LEFT JOIN stock_items p ON p.id = w.stock_item_id LEFT JOIN stock_categories c ON c.id = p.stock_category_id
                WHERE w.local_day BETWEEN ? AND ? GROUP BY COALESCE(c.name, 'Uncategorised') ORDER BY value DESC", $bind));
        $weekSales = [];
        foreach (DB::select("SELECT DATE_SUB(s.sale_date, INTERVAL WEEKDAY(s.sale_date) DAY) AS wk, SUM(s.total_amount) AS total FROM {$sales} GROUP BY wk", $salesBind) as $r) {
            $weekSales[(string) $r->wk] = (float) $r->total;
        }
        $weeks = array_map(fn ($r) => ['week' => (string) $r->wk, 'value' => round((float) $r->value, 2), 'sales' => round($weekSales[(string) $r->wk] ?? 0, 2),
            'pct_sales' => $pct((float) $r->value, $weekSales[(string) $r->wk] ?? 0)],
            DB::select("SELECT DATE_SUB(w.local_day, INTERVAL WEEKDAY(w.local_day) DAY) AS wk, SUM(w.value) AS value FROM {$w} WHERE w.local_day BETWEEN ? AND ? GROUP BY wk ORDER BY wk", $bind));

        $totals = $this->totals($rows, ['entries', 'quantity', 'value']);
        $totals['pct_sales'] = $pct($totals['value'], $salesTotal);

        return ['columns' => [$this->text('reason', 'Reason'), $this->num('entries', 'Write-offs'), $this->num('quantity', 'Quantity'), $this->money('value', 'Value at cost'),
            $this->percent('pct_sales', '% of sales')], 'rows' => $rows, 'totals' => $totals,
            'meta' => ['sales' => round($salesTotal, 2), 'shrink_pct' => $totals['pct_sales'], 'note' => 'Stock written off at what it cost, against sales for the same days. Reversed write-offs are left out.'],
            'sections' => [
                ['key' => 'categories', 'title' => 'By category', 'columns' => [$this->text('category', 'Category'), $this->num('quantity', 'Quantity'), $this->money('value', 'Value at cost'),
                    $this->percent('pct_sales', '% of sales')], 'rows' => $cats, 'totals' => $this->totals($cats, ['value'])],
                ['key' => 'weeks', 'title' => 'By week (from Monday)', 'columns' => [$this->text('week', 'Week of'), $this->money('value', 'Value at cost'), $this->money('sales', 'Sales that week'),
                    $this->percent('pct_sales', '% of that week\'s sales')], 'rows' => $weeks, 'totals' => $this->totals($weeks, ['value'])],
            ]];
    }

    /**
     * H6: per cashier, sales, voids, refunds, price overrides and no-sale drawer opens (when the supermarket
     * approvals / cash-movement tables exist), and cash over/short of the shifts they opened and closed.
     */
    private function cashControl(array $o): array
    {
        $utc = [$this->from->copy()->utc(), $this->to->copy()->utc()];
        $keys = ['sales', 'amount', 'voids', 'voided', 'refunds', 'refunded', 'overrides', 'no_sales', 'shifts', 'over_short'];
        $by = [];
        $add = function ($id, array $values) use (&$by, $keys) {
            $id = (int) $id;
            $by[$id] ??= array_fill_keys($keys, 0);
            foreach ($values as $k => $v) {
                $by[$id][$k] += $v;
            }
        };
        [$from, $bind] = $this->saleRows();
        foreach (DB::select("SELECT s.created_by_id AS id, COUNT(CASE WHEN s.total_amount > 0 THEN 1 END) AS n, SUM(s.total_amount) AS amount FROM {$from} GROUP BY s.created_by_id", $bind) as $r) {
            $add($r->id, ['sales' => (int) $r->n, 'amount' => (float) $r->amount]);
        }
        foreach (DB::table('sale_records')->where('company_id', $this->companyId)->whereBetween('voided_at', $utc)->groupBy('created_by_id')
            ->selectRaw('created_by_id AS id, COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS v')->get() as $r) {
            $add($r->id, ['voids' => (int) $r->n, 'voided' => (float) $r->v]);
        }
        foreach (DB::table('sale_returns')->where('company_id', $this->companyId)->where('is_deleted', 0)->whereBetween('created_at', $utc)->groupBy('created_by_id')
            ->selectRaw('created_by_id AS id, COUNT(*) AS n, COALESCE(SUM(value), 0) AS v')->get() as $r) {
            $add($r->id, ['refunds' => (int) $r->n, 'refunded' => (float) $r->v]);
        }
        $approvals = \Illuminate\Support\Facades\Schema::hasTable('approvals');
        if ($approvals) {
            foreach (DB::table('approvals')->where('company_id', $this->companyId)->where('action', 'price_override')->whereBetween('created_at', $utc)->groupBy('requested_by')
                ->selectRaw('requested_by AS id, COUNT(*) AS n')->get() as $r) {
                $add($r->id, ['overrides' => (int) $r->n]);
            }
        }
        $movements = \Illuminate\Support\Facades\Schema::hasTable('cash_movements');
        if ($movements) {
            foreach (DB::table('cash_movements')->where('company_id', $this->companyId)->where('type', 'no_sale')->whereBetween('created_at', $utc)->groupBy('created_by')
                ->selectRaw('created_by AS id, COUNT(*) AS n')->get() as $r) {
                $add($r->id, ['no_sales' => (int) $r->n]);
            }
        }
        foreach (DB::table('shifts')->where('company_id', $this->companyId)->where('status', 'closed')->whereBetween('closed_at', $utc)->groupBy('opened_by_id')
            ->selectRaw('opened_by_id AS id, COUNT(*) AS n, COALESCE(SUM(variance), 0) AS v')->get() as $r) {
            $add($r->id, ['shifts' => (int) $r->n, 'over_short' => (float) $r->v]);
        }
        $names = DB::table('admin_users')->whereIn('id', array_keys($by))->pluck('name', 'id');
        $rows = [];
        foreach ($by as $id => $v) {
            $rows[] = ['cashier' => (string) ($names[$id] ?? '—')] + array_map(fn ($x) => is_float($x) ? round($x, 2) : $x, $v);
        }
        usort($rows, fn ($a, $b) => [$b['amount'], $b['voids']] <=> [$a['amount'], $a['voids']]);

        return ['columns' => [$this->text('cashier', 'Cashier'), $this->num('sales', 'Sales'), $this->money('amount', 'Sales value'), $this->num('voids', 'Voids'),
            $this->money('voided', 'Voided value'), $this->num('refunds', 'Returns'), $this->money('refunded', 'Returned value'), $this->num('overrides', 'Price overrides'),
            $this->num('no_sales', 'No-sales'), $this->num('shifts', 'Shifts closed'), $this->money('over_short', 'Cash over / short')],
            'rows' => $rows, 'totals' => $this->totals($rows, $keys),
            'meta' => ['approvals_tracked' => $approvals, 'drawer_tracked' => $movements,
                'note' => 'Voids count against the cashier who rang the sale; over/short is counted cash minus expected cash of the shifts each person opened. Price overrides and no-sales come from supervisor approvals and drawer opens.']];
    }

    /**
     * H5: per promotion, the sales that got it, the discount given, and the units, sales and margin of its
     * products while it ran (within the chosen range), against the same number of days just before.
     */
    private function promotionResults(array $o): array
    {
        $columns = [$this->text('name', 'Promotion'), $this->text('type', 'Type'), $this->num('sales_count', 'Sales'), $this->num('units', 'Units sold'),
            $this->money('discount', 'Discount given'), $this->money('sales', 'Sales of its products'), $this->percent('margin', 'Margin %'),
            $this->money('before', 'Sales before'), $this->percent('before_margin', 'Margin before %'), $this->percent('growth', 'Growth %')];
        if (! \Illuminate\Support\Facades\Schema::hasTable('promotions')) {
            return ['columns' => $columns, 'rows' => [], 'totals' => [], 'meta' => []];
        }
        $from = $this->from->toDateString();
        $to = $this->to->toDateString();
        $tz = $this->from->getTimezone();
        $given = DB::table('sale_promotions as sp')->join('sale_records as r', 'r.id', '=', 'sp.sale_id')
            ->where('sp.company_id', $this->companyId)->whereNull('r.voided_at')->where('r.status', '<>', 'Voided')->whereBetween('r.sale_date', [$from, $to])
            ->groupBy('sp.promotion_id')->selectRaw('sp.promotion_id, COUNT(DISTINCT sp.sale_id) AS n, SUM(sp.amount) AS amount')->get()->keyBy('promotion_id');
        $targets = DB::table('promotion_targets')->whereIn('promotion_id', DB::table('promotions')->where('company_id', $this->companyId)->select('id'))->get()->groupBy('promotion_id');
        $rows = [];
        foreach (DB::table('promotions')->where('company_id', $this->companyId)->orderBy('id')->get() as $p) {
            $start = $p->starts_at ? max($from, Carbon::parse($p->starts_at, 'UTC')->setTimezone($tz)->toDateString()) : $from;
            $end = $p->ends_at ? min($to, Carbon::parse($p->ends_at, 'UTC')->setTimezone($tz)->toDateString()) : $to;
            $g = $given[$p->id] ?? null;
            if ($start > $end && $g === null) {
                continue; // did not run in this range
            }
            if ($start > $end) {
                [$start, $end] = [$from, $to];
            }
            $days = (int) Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;
            $beforeTo = Carbon::parse($start)->subDay()->toDateString();
            $beforeFrom = Carbon::parse($beforeTo)->subDays($days - 1)->toDateString();
            $t = $targets[$p->id] ?? collect();
            $now = $this->promotionTargetSales($t, $start, $end);
            $prev = $this->promotionTargetSales($t, $beforeFrom, $beforeTo);
            $rows[] = ['name' => (string) $p->name, 'type' => \App\Services\Shop\PromotionEngine::TYPES[$p->type] ?? $p->type,
                'sales_count' => (int) ($g->n ?? 0), 'units' => round($now['units'], 3), 'discount' => round((float) ($g->amount ?? 0), 2),
                'sales' => round($now['sales'], 2), 'margin' => $now['sales'] > 0 ? round($now['profit'] * 100 / $now['sales'], 1) : 0.0,
                'before' => round($prev['sales'], 2), 'before_margin' => $prev['sales'] > 0 ? round($prev['profit'] * 100 / $prev['sales'], 1) : 0.0,
                'growth' => $prev['sales'] > 0 ? round(($now['sales'] - $prev['sales']) * 100 / $prev['sales'], 1) : null,
                'from' => $start, 'to' => $end, 'before_from' => $beforeFrom, 'before_to' => $beforeTo];
        }
        usort($rows, fn ($a, $b) => $b['discount'] <=> $a['discount'] ?: $b['sales'] <=> $a['sales']);

        return ['columns' => $columns, 'rows' => $rows, 'totals' => $this->totals($rows, ['sales_count', 'units', 'discount', 'sales', 'before']),
            'meta' => ['note' => 'Sales, units and margin are those of each promotion\'s products (all products for a whole-cart promotion) while it ran in this range, against the same number of days just before it.']];
    }

    /** Units, sales and profit of a promotion's products (every product when it has no targets) on these local days. @return array{units: float, sales: float, profit: float} */
    private function promotionTargetSales($targets, string $from, string $to): array
    {
        [$sql, $bind] = SalesSource::linesSql($this->companyId, 'x', $from, $to);
        $where = '';
        $wb = [];
        if ($targets->isNotEmpty()) {
            $parts = [];
            foreach (['product' => 'p.id', 'category' => 'p.stock_category_id', 'sub_category' => 'p.stock_sub_category_id'] as $type => $col) {
                $ids = $targets->where('target_type', $type)->pluck('target_id')->map(fn ($v) => (int) $v)->all();
                if ($ids !== []) {
                    $parts[] = $col.' IN ('.implode(',', array_fill(0, count($ids), '?')).')';
                    $wb = array_merge($wb, $ids);
                }
            }
            $where = $parts !== [] ? ' AND ('.implode(' OR ', $parts).')' : ' AND 1 = 0';
        }
        $r = DB::selectOne("SELECT COALESCE(SUM(l.quantity), 0) AS units, COALESCE(SUM(l.revenue), 0) AS sales, COALESCE(SUM(l.profit), 0) AS profit
            FROM (SELECT x.* FROM {$sql} WHERE x.sale_date BETWEEN ? AND ?) l LEFT JOIN stock_items p ON p.id = l.stock_item_id WHERE 1 = 1{$where}",
            array_merge($bind, [$from, $to], $wb));

        return ['units' => (float) $r->units, 'sales' => (float) $r->sales, 'profit' => (float) $r->profit];
    }

    /**
     * C1/C2: what the shop owes its customers — each gift card with money on it, store credit per customer,
     * and loyalty points at their value — plus gift cards sold and used in the range. Gift cards sold are
     * money received (cash-up, ledger) but never sales or profit.
     */
    private function giftCards(array $o): array
    {
        $columns = [$this->text('card', 'Card'), $this->text('customer', 'Customer'), $this->text('issued', 'Issued'), $this->text('expires', 'Expires'),
            $this->text('status', 'Status'), $this->money('balance', 'Balance owed')];
        if (! \Illuminate\Support\Facades\Schema::hasTable('gift_cards')) {
            return ['columns' => $columns, 'rows' => [], 'totals' => [], 'meta' => []];
        }
        $cid = $this->companyId;
        $now = now();
        $rows = DB::table('gift_cards as g')->leftJoin('customers as c', 'c.id', '=', 'g.customer_id')->where('g.company_id', $cid)->where('g.balance', '<>', 0)
            ->orderByDesc('g.balance')->limit(2000)->get(['g.last4', 'g.balance', 'g.expires_at', 'g.is_active', 'g.created_at', 'g.source', 'c.name as customer'])
            ->map(fn ($g) => ['card' => '•••• '.$g->last4.($g->source === 'refund' ? ' (refund)' : ''), 'customer' => (string) ($g->customer ?? ''),
                'issued' => substr((string) $g->created_at, 0, 10), 'expires' => $g->expires_at ? substr((string) $g->expires_at, 0, 10) : '',
                'status' => ! $g->is_active ? 'Stopped' : ($g->expires_at && $g->expires_at < $now ? 'Expired' : 'Active'), 'balance' => round((float) $g->balance, 2)])->all();

        $from = $this->from->copy()->utc();
        $to = $this->to->copy()->utc();
        $moved = DB::table('gift_card_ledger')->where('company_id', $cid)->whereBetween('created_at', [$from, $to])
            ->selectRaw("COALESCE(SUM(CASE WHEN reason = 'issue' THEN amount ELSE 0 END), 0) AS sold, COALESCE(SUM(CASE WHEN reason = 'redeem' THEN -amount ELSE 0 END), 0) AS used,
                COALESCE(SUM(CASE WHEN reason = 'refund' THEN amount ELSE 0 END), 0) AS refunded")->first();
        $credit = DB::table('customers')->where('company_id', $cid)->where('is_deleted', 0)->where('balance', '<', 0)->orderBy('balance')->limit(2000)
            ->get(['name', 'phone', 'balance'])->map(fn ($c) => ['name' => (string) $c->name, 'phone' => (string) $c->phone, 'credit' => round(-(float) $c->balance, 2)])->all();
        $pointValue = \App\Services\Shop\LoyaltyService::pointValue(Company::withoutGlobalScopes()->find($cid));
        $points = DB::table('loyalty_ledger as l')->join('customers as c', 'c.id', '=', 'l.customer_id')->where('l.company_id', $cid)
            ->groupBy('l.customer_id', 'c.name', 'c.phone')->havingRaw('SUM(l.points) > 0')->orderByRaw('SUM(l.points) DESC')->limit(2000)
            ->get(['c.name', 'c.phone', DB::raw('SUM(l.points) AS points')])
            ->map(fn ($p) => ['name' => (string) $p->name, 'phone' => (string) $p->phone, 'points' => (int) $p->points, 'value' => round((int) $p->points * $pointValue, 2)])->all();
        $cards = round(array_sum(array_map(fn ($r) => $r['status'] === 'Active' ? $r['balance'] : 0, $rows)), 2);

        return ['columns' => $columns, 'rows' => $rows, 'totals' => $this->totals($rows, ['balance']),
            'meta' => ['gift_cards_owed' => $cards, 'store_credit_owed' => round(array_sum(array_column($credit, 'credit')), 2),
                'points_value' => round(array_sum(array_column($points, 'value')), 2),
                'sold' => round((float) $moved->sold, 2), 'used' => round((float) $moved->used, 2), 'refunded_to_cards' => round((float) $moved->refunded, 2),
                'note' => 'Gift cards sold are money received and owed to the card holder: they are in cash-up and the ledger, never in sales or profit. Sold, used and refunded are for the chosen dates; balances are now.'],
            'sections' => [
                ['key' => 'store_credit', 'title' => 'Store credit (customers in credit)', 'columns' => [$this->text('name', 'Customer'), $this->text('phone', 'Phone'), $this->money('credit', 'Credit owed')],
                    'rows' => $credit, 'totals' => $this->totals($credit, ['credit'])],
                ['key' => 'points', 'title' => 'Loyalty points', 'columns' => [$this->text('name', 'Customer'), $this->text('phone', 'Phone'), $this->num('points', 'Points'), $this->money('value', 'Worth')],
                    'rows' => $points, 'totals' => $this->totals($points, ['points', 'value'])],
            ]];
    }
}
