<?php

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
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
    ];

    public const GROUPS = ['day', 'cashier', 'method', 'customer', 'category', 'product'];

    private int $companyId;

    private Carbon $from;

    private Carbon $to;

    public function run(int $companyId, string $name, ?string $from = null, ?string $to = null, array $options = []): array
    {
        if (! array_key_exists($name, self::REPORTS)) {
            throw BusinessRuleException::make('unknown_report', 'Unknown report.', ['reports' => array_keys(self::REPORTS)]);
        }
        $this->companyId = $companyId;
        $company = Company::withoutGlobalScopes()->findOrFail($companyId);
        $tz = \App\Support\LocalTime::timezone($company);
        $this->to = $to ? Carbon::parse($to, $tz)->endOfDay() : now($tz)->endOfDay();
        $this->from = $from ? Carbon::parse($from, $tz)->startOfDay() : $this->to->copy()->subDays(29)->startOfDay();
        if ($this->from->greaterThan($this->to)) {
            throw BusinessRuleException::make('invalid_range', 'The start date is after the end date.');
        }
        $method = lcfirst(str_replace('_', '', ucwords($name, '_')));
        $r = $this->{$method}($options);

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

    private function sales()
    {
        return DB::table('sale_records as s')->where('s.company_id', $this->companyId)->where('s.status', '!=', 'Voided')
            ->whereBetween('s.sale_date', [$this->from->toDateString(), $this->to->toDateString()]);
    }

    private function totals(array $rows, array $keys): array
    {
        $t = [];
        foreach ($keys as $k) {
            $t[$k] = round(array_sum(array_map(fn ($r) => (float) ($r[$k] ?? 0), $rows)), 2);
        }

        return $t;
    }

    private function salesSummary(array $o): array
    {
        $by = in_array($o['group_by'] ?? 'day', self::GROUPS, true) ? ($o['group_by'] ?? 'day') : 'day';
        if ($by === 'method') {
            $rows = DB::table('payments as p')->join('sale_records as s', 's.id', '=', 'p.sale_record_id')->where('p.company_id', $this->companyId)
                ->where('s.status', '!=', 'Voided')->whereBetween('s.sale_date', [$this->from->toDateString(), $this->to->toDateString()])
                ->groupBy('p.method')->selectRaw('p.method AS label, COUNT(DISTINCT p.sale_record_id) AS sales, SUM(CASE WHEN p.is_reversal = 1 THEN -p.amount ELSE p.amount END) AS amount')
                ->orderByDesc('amount')->get()->map(fn ($r) => ['label' => ucwords(str_replace('_', ' ', (string) $r->label)), 'sales' => (int) $r->sales, 'amount' => round((float) $r->amount, 2)])->all();
            $credit = (float) $this->sales()->sum('s.balance');
            if ($credit > 0) {
                $rows[] = ['label' => 'On credit (still owed)', 'sales' => (int) $this->sales()->where('s.balance', '>', 0)->count(), 'amount' => round($credit, 2)];
            }

            return ['columns' => [$this->text('label', 'Paid with'), $this->num('sales', 'Sales'), $this->money('amount', 'Amount')], 'rows' => $rows, 'totals' => $this->totals($rows, ['sales', 'amount'])];
        }
        if (in_array($by, ['category', 'product'], true)) {
            $q = DB::table('sale_record_items as i')->join('sale_records as s', 's.id', '=', 'i.sale_record_id')->leftJoin('stock_items as p', 'p.id', '=', 'i.stock_item_id')
                ->leftJoin('stock_categories as c', 'c.id', '=', 'p.stock_category_id')->where('s.company_id', $this->companyId)->where('s.status', '!=', 'Voided')
                ->whereBetween('s.sale_date', [$this->from->toDateString(), $this->to->toDateString()]);
            $label = $by === 'category' ? 'COALESCE(c.name, "Uncategorised")' : 'i.item_name';
            $rows = $q->groupByRaw($label)->selectRaw("{$label} AS label, SUM(i.quantity - i.returned_quantity) AS quantity,
                    SUM(i.line_total * (i.quantity - i.returned_quantity) / NULLIF(i.quantity, 0)) AS amount")
                ->orderByDesc('amount')->get()->map(fn ($r) => ['label' => (string) $r->label, 'quantity' => round((float) $r->quantity, 3), 'amount' => round((float) $r->amount, 2)])->all();

            return ['columns' => [$this->text('label', ucfirst($by)), $this->num('quantity', 'Quantity'), $this->money('amount', 'Sales')], 'rows' => $rows, 'totals' => $this->totals($rows, ['quantity', 'amount'])];
        }
        [$label, $join] = match ($by) {
            'cashier' => ['COALESCE(u.name, "—")', true],
            'customer' => ['COALESCE(NULLIF(s.customer_name, ""), "Walk-in")', false],
            default => ['s.sale_date', false],
        };
        $q = $this->sales();
        if ($join) {
            $q->leftJoin('admin_users as u', 'u.id', '=', 's.created_by_id');
        }
        $rows = $q->groupByRaw($label)->selectRaw("{$label} AS label, COUNT(*) AS sales, SUM(s.total_amount - s.refunded_amount) AS amount,
                SUM(s.discount_amount) AS discounts, SUM(s.balance) AS owed")->orderBy($by === 'day' ? 'label' : 'amount', $by === 'day' ? 'asc' : 'desc')->get()
            ->map(fn ($r) => ['label' => (string) $r->label, 'sales' => (int) $r->sales, 'amount' => round((float) $r->amount, 2), 'discounts' => round((float) $r->discounts, 2), 'owed' => round((float) $r->owed, 2)])->all();

        return ['columns' => [$this->text('label', ucfirst($by)), $this->num('sales', 'Sales'), $this->money('amount', 'Net sales'), $this->money('discounts', 'Discounts'), $this->money('owed', 'Still owed')],
            'rows' => $rows, 'totals' => $this->totals($rows, ['sales', 'amount', 'discounts', 'owed'])];
    }

    private function profit(array $o): array
    {
        $by = ($o['group_by'] ?? 'day') === 'product' ? 'product' : 'day';
        $label = $by === 'product' ? 'i.item_name' : 's.sale_date';
        $rows = DB::table('sale_record_items as i')->join('sale_records as s', 's.id', '=', 'i.sale_record_id')->where('s.company_id', $this->companyId)
            ->where('s.status', '!=', 'Voided')->whereBetween('s.sale_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->groupByRaw($label)->selectRaw("{$label} AS label,
                SUM(i.line_total * (i.quantity - i.returned_quantity) / NULLIF(i.quantity, 0)) AS revenue,
                SUM(i.unit_cost * (i.quantity - i.returned_quantity)) AS cost")
            ->orderBy($by === 'day' ? 'label' : 'revenue', $by === 'day' ? 'asc' : 'desc')->get()
            ->map(function ($r) {
                $rev = round((float) $r->revenue, 2);
                $cost = round((float) $r->cost, 2);

                return ['label' => (string) $r->label, 'revenue' => $rev, 'cost' => $cost, 'profit' => round($rev - $cost, 2), 'margin' => $rev > 0 ? round(($rev - $cost) * 100 / $rev, 1) : 0.0];
            })->all();
        $t = $this->totals($rows, ['revenue', 'cost', 'profit']);
        $t['margin'] = $t['revenue'] > 0 ? round($t['profit'] * 100 / $t['revenue'], 1) : 0.0;
        $expenses = (float) DB::table('financial_records')->where('company_id', $this->companyId)->where('type', 'Expense')
            ->where(fn ($q) => $q->whereNull('source_type')->orWhereNotIn('source_type', ['goods_receipt', 'supplier_payment']))
            ->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()])->sum('amount');

        return ['columns' => [$this->text('label', $by === 'day' ? 'Day' : 'Product'), $this->money('revenue', 'Sales'), $this->money('cost', 'Cost of goods'), $this->money('profit', 'Gross profit'),
            ['key' => 'margin', 'label' => 'Margin %', 'type' => 'percent']], 'rows' => $rows, 'totals' => $t,
            'meta' => ['operating_expenses' => round($expenses, 2), 'net_profit' => round($t['profit'] - $expenses, 2)]];
    }

    private function products()
    {
        return DB::table('stock_items as p')->where('p.company_id', $this->companyId)->where('p.is_deleted', false);
    }

    private function stockValuation(array $o): array
    {
        $rows = $this->products()->where('p.track_stock', true)->leftJoin('stock_categories as c', 'c.id', '=', 'p.stock_category_id')
            ->orderBy('c.name')->orderBy('p.name')->get(['p.name', 'c.name as category', 'p.current_quantity', 'p.buying_price', 'p.selling_price'])
            ->map(fn ($r) => ['name' => $r->name, 'category' => $r->category, 'quantity' => round((float) $r->current_quantity, 3), 'unit_cost' => (float) $r->buying_price,
                'value_at_cost' => round(max(0, (float) $r->current_quantity) * (float) $r->buying_price, 2), 'value_at_price' => round(max(0, (float) $r->current_quantity) * (float) $r->selling_price, 2)])->all();

        return ['columns' => [$this->text('name', 'Product'), $this->text('category', 'Category'), $this->num('quantity', 'On hand'), $this->money('unit_cost', 'Cost'),
            $this->money('value_at_cost', 'Value at cost'), $this->money('value_at_price', 'Value at price')], 'rows' => $rows, 'totals' => $this->totals($rows, ['value_at_cost', 'value_at_price'])];
    }

    private function lowStock(array $o): array
    {
        $default = (float) (Company::withoutGlobalScopes()->find($this->companyId)?->low_stock_default ?? config('saas.low_stock_threshold', 10));
        $rows = $this->products()->where('p.track_stock', true)->whereRaw('p.current_quantity <= COALESCE(p.min_stock, ?)', [$default])->orderBy('p.current_quantity')
            ->get(['p.name', 'p.current_quantity', 'p.min_stock'])
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
        $rows = DB::table('sale_record_items as i')->join('sale_records as s', 's.id', '=', 'i.sale_record_id')->where('s.company_id', $this->companyId)->where('s.status', '!=', 'Voided')
            ->whereBetween('s.sale_date', [$this->from->toDateString(), $this->to->toDateString()])->groupBy('i.item_name')
            ->selectRaw('i.item_name AS name, SUM(i.quantity - i.returned_quantity) AS quantity, SUM(i.line_total * (i.quantity - i.returned_quantity) / NULLIF(i.quantity, 0)) AS amount')
            ->orderByDesc('quantity')->limit($limit)->get()->map(fn ($r) => ['name' => $r->name, 'quantity' => round((float) $r->quantity, 3), 'amount' => round((float) $r->amount, 2)])->all();

        return ['columns' => [$this->text('name', 'Product'), $this->num('quantity', 'Sold'), $this->money('amount', 'Sales')], 'rows' => $rows, 'totals' => $this->totals($rows, ['quantity', 'amount'])];
    }

    private function customerAging(array $o): array
    {
        $today = now()->toDateString();
        $rows = DB::table('sale_records as s')->where('s.company_id', $this->companyId)->where('s.status', '!=', 'Voided')->where('s.balance', '>', 0)
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
        $sales = (float) $this->sales()->sum(DB::raw('s.total_amount - s.refunded_amount'));
        $purchases = (float) DB::table('goods_receipts')->where('company_id', $this->companyId)->whereBetween('received_on', [$this->from->toDateString(), $this->to->toDateString()])->sum('total_cost');
        $returns = (float) DB::table('purchase_returns')->where('company_id', $this->companyId)->whereBetween('returned_on', [$this->from->toDateString(), $this->to->toDateString()])->sum('total_value');
        $vat = fn (float $gross) => $rate > 0 ? round($gross * $rate / (100 + $rate), 2) : 0.0;
        $rows = [
            ['label' => 'Sales (VAT inclusive)', 'gross' => round($sales, 2), 'vat' => $vat($sales)],
            ['label' => 'Purchases (VAT inclusive, less returns)', 'gross' => round($purchases - $returns, 2), 'vat' => $vat($purchases - $returns)],
        ];

        return ['columns' => [$this->text('label', 'Item'), $this->money('gross', 'Amount'), $this->money('vat', 'VAT')], 'rows' => $rows,
            'totals' => ['vat' => round($rows[0]['vat'] - $rows[1]['vat'], 2)], 'meta' => ['rate' => $rate, 'vat_payable' => round($rows[0]['vat'] - $rows[1]['vat'], 2),
                'note' => $rate > 0 ? "VAT at {$rate}% included in prices." : 'No VAT rate is set for this shop (Company settings → VAT).']];
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
}
