<?php

namespace App\Services\Reports;

use App\Models\Company;
use App\Models\Payment;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Support\LocalDate;
use App\Support\LocalTime;
use App\Support\StoreScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The web portal's list exports for the phone (MOBILE_EXCELLENCE_PLAN.md D6): sales, products and
 * customers with the same columns as budget-pro-new's Sales / Products / Customers "Export" (their
 * exportRows), filtered by the query the phone sends. Rows come lazily, header first; the controller
 * writes them as CSV or XLSX (XlsxWriter).
 */
class ListExports
{
    public const KINDS = ['sales', 'products', 'customers'];

    /** Any of these permissions may export the list. */
    public const PERMISSIONS = ['sales' => ['view_reports'], 'products' => ['manage_products', 'view_reports'], 'customers' => ['sell', 'view_reports']];

    public const MAX = ['sales' => 20000, 'products' => 50000, 'customers' => 50000];

    public static function allowed(User $user, string $kind): bool
    {
        return collect(self::PERMISSIONS[$kind] ?? [])->contains(fn ($p) => Permissions::can($user, $p));
    }

    /** @return iterable<int, list<string|int|float|null>> */
    public function rows(string $kind, User $user, Company $company, array $f): iterable
    {
        return match ($kind) {
            'sales' => $this->sales($user, $company, $f),
            'products' => $this->products($user, $company, $f),
            'customers' => $this->customers($company, $f),
        };
    }

    /** [from, to] local days: the query's, else the last 30 days for sales. @return array{0: ?string, 1: ?string} */
    public static function period(string $kind, int $companyId, array $f): array
    {
        if ($kind !== 'sales') {
            return [$f['from'] ?? null, $f['to'] ?? null];
        }
        $today = LocalDate::today($companyId);

        return [$f['from'] ?? $today->copy()->subDays(29)->toDateString(), $f['to'] ?? $today->toDateString()];
    }

    private function sales(User $user, Company $company, array $f): \Generator
    {
        $cid = (int) $company->id;
        [$from, $to] = self::period('sales', $cid, $f);
        $tz = LocalTime::timezone($company);
        $labels = (array) config('onboarding.payment_methods', ['cash' => 'Cash']);
        $store = StoreScope::forUser($user, $company);
        yield ['Date', 'Time', 'Receipt', 'Customer', 'Phone', 'Total', 'Paid', 'Still owed', 'Status', 'Paid by', 'Sold by'];
        $q = DB::table('sale_records as s')->leftJoin('admin_users as u', 'u.id', '=', 's.created_by_id')->where('s.company_id', $cid)->where(fn ($w) => $w->whereNull('s.is_deleted')->orWhere('s.is_deleted', 0))
            ->whereBetween('s.sale_date', [$from, $to])
            ->when(! empty($f['status']), fn ($w) => $f['status'] === 'Voided' ? $w->where(fn ($x) => $x->whereNotNull('s.voided_at')->orWhere('s.status', 'Voided'))
                : $w->where('s.status', $f['status'])->whereNull('s.voided_at'))
            ->when(($f['scope'] ?? '') === 'unpaid', fn ($w) => $w->where('s.balance', '>', 0)->whereNull('s.voided_at'))
            ->when(($f['scope'] ?? '') === 'paid', fn ($w) => $w->where('s.balance', '<=', 0)->whereNull('s.voided_at'))
            ->when(! empty($f['sold_by']), fn ($w) => $w->where('s.created_by_id', (int) $f['sold_by']))
            ->when(! empty($f['customer_id']), fn ($w) => $w->where('s.customer_id', (int) $f['customer_id']))
            ->when($store !== null, fn ($w) => $w->whereRaw(StoreScope::saleLocationSql('s', $cid).' = ?', [$store]))
            ->orderByDesc('s.id')->limit(self::MAX['sales'])
            ->select(['s.id', 's.sale_date', 's.created_at', 's.receipt_number', 's.customer_name', 's.customer_phone', 's.total_amount', 's.refunded_amount',
                's.amount_paid', 's.balance', 's.status', 's.voided_at', 's.payment_method', 'u.name as cashier']);
        foreach ($q->cursor() as $s) {
            $voided = $s->voided_at !== null || $s->status === 'Voided';
            $raw = (string) $s->payment_method;
            yield [
                substr((string) $s->sale_date, 0, 10), $s->created_at ? Carbon::parse($s->created_at)->setTimezone($tz)->format('H:i') : '—',
                (string) ($s->receipt_number ?: '#'.$s->id), (string) ($s->customer_name ?: 'Walk-in Customer'), (string) ($s->customer_phone ?? ''),
                round((float) $s->total_amount - (float) $s->refunded_amount, 2), round((float) $s->amount_paid, 2), $voided ? 0.0 : round((float) $s->balance, 2),
                $voided ? 'Voided' : (in_array($s->status, ['Refunded', 'Partially Refunded'], true) ? $s->status : 'Completed'),
                $raw === '' ? '—' : (Payment::TENDERS[$raw] ?? $labels[Payment::normalizeMethod($raw)] ?? ucfirst(str_replace('_', ' ', $raw))),
                (string) ($s->cashier ?? ''),
            ];
        }
    }

    private function products(User $user, Company $company, array $f): \Generator
    {
        $cost = Permissions::can($user, 'view_cost');
        $min = (float) ($company->low_stock_default ?? config('saas.low_stock_threshold', 10));
        yield array_merge(['Name', 'SKU', 'Barcode', 'Category', 'Sub-category', 'Unit', 'In stock', 'Reorder level', 'Selling price'],
            $cost ? ['Buying price', 'Stock value at cost'] : [], ['Stock value at selling price', 'Active']);
        $like = '%'.trim((string) ($f['q'] ?? '')).'%';
        $scope = (string) ($f['scope'] ?? 'active');
        $q = DB::table('stock_items as p')->leftJoin('stock_categories as c', 'c.id', '=', 'p.stock_category_id')
            ->leftJoin('stock_sub_categories as sc', 'sc.id', '=', 'p.stock_sub_category_id')->leftJoin('units as un', 'un.id', '=', 'p.unit_id')
            ->where('p.company_id', $company->id)->where('p.is_deleted', 0)
            ->when(trim((string) ($f['q'] ?? '')) !== '', fn ($w) => $w->where(fn ($x) => $x->where('p.name', 'like', $like)->orWhere('p.sku', 'like', $like)->orWhere('p.barcode', 'like', $like)))
            ->when(! empty($f['category_id']), fn ($w) => $w->where('p.stock_category_id', (int) $f['category_id']))
            ->when($scope === 'active', fn ($w) => $w->where('p.is_active', 1))->when($scope === 'inactive', fn ($w) => $w->where('p.is_active', 0))
            ->when($scope === 'low', fn ($w) => $w->where('p.track_stock', 1)->where('p.current_quantity', '>', 0)->whereRaw('p.current_quantity <= COALESCE(p.min_stock, ?)', [$min]))
            ->when($scope === 'out', fn ($w) => $w->where('p.track_stock', 1)->where('p.current_quantity', '<=', 0))
            ->orderBy('p.name')->orderBy('p.id')->limit(self::MAX['products'])
            ->select(['p.id', 'p.name', 'p.sku', 'p.barcode', 'p.current_quantity', 'p.min_stock', 'p.track_stock', 'p.selling_price', 'p.buying_price', 'p.is_active',
                'c.name as category_name', 'sc.name as sub_category_name', 'sc.measurement_unit', 'un.abbreviation as unit_abbr']);
        foreach ($q->cursor() as $p) {
            $qty = (float) $p->current_quantity;
            $onShelf = $p->track_stock ? max($qty, 0) : 0.0;
            yield array_merge([(string) $p->name, (string) $p->sku, (string) $p->barcode, (string) $p->category_name, (string) $p->sub_category_name, (string) ($p->unit_abbr ?: $p->measurement_unit),
                $qty, $p->min_stock === null ? '' : (float) $p->min_stock, (float) $p->selling_price],
                $cost ? [(float) $p->buying_price, round($onShelf * (float) $p->buying_price, 2)] : [], [round($onShelf * (float) $p->selling_price, 2), $p->is_active ? 'Yes' : 'No']);
        }
    }

    private function customers(Company $company, array $f): \Generator
    {
        yield ['Name', 'Phone', 'Balance', 'Credit limit', 'Last sale'];
        $like = '%'.trim((string) ($f['q'] ?? '')).'%';
        $scope = (string) ($f['scope'] ?? 'all');
        $q = DB::table('customers')->where('company_id', $company->id)->where('is_deleted', 0)
            ->when(trim((string) ($f['q'] ?? '')) !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like)))
            ->when($scope === 'owes', fn ($w) => $w->where('balance', '>', 0))
            ->when($scope === 'inactive', fn ($w) => $w->where('is_active', 0))->when($scope === 'all', fn ($w) => $w->where('is_active', 1))
            ->addSelect(['id', 'name', 'phone', 'balance', 'credit_limit', 'last_sale' => DB::table('sale_records')->selectRaw('MAX(sale_date)')->whereColumn('customer_id', 'customers.id')->whereNull('voided_at')])
            ->orderByDesc('balance')->orderBy('name')->limit(self::MAX['customers']);
        foreach ($q->cursor() as $c) {
            yield [(string) $c->name, (string) $c->phone, round((float) $c->balance, 2), $c->credit_limit !== null ? round((float) $c->credit_limit, 2) : null, $c->last_sale ? substr((string) $c->last_sale, 0, 10) : null];
        }
    }
}
