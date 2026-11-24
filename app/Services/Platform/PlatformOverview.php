<?php

namespace App\Services\Platform;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything on the platform at a glance, for platform admins (classic /platform-overview): every account
 * sorted into its app, people, plans and money, each app's own books, sign-ups over time and the system's
 * health. Read-only; all tenants, so it reads the tables directly (no tenant scope).
 */
final class PlatformOverview
{
    /** Apps in menu order, with the module that marks them (see appOf). */
    public const APPS = [
        'budget-pro' => ['name' => 'Budget Pro', 'what' => 'Shop, farm, events and money in one account'],
        'shelfie' => ['name' => 'Shelfie', 'what' => 'Shops'],
        'kuku-tally' => ['name' => 'Kuku Tally', 'what' => 'Poultry farms'],
        'pledge-and-budget' => ['name' => 'Pledge & Budget', 'what' => 'Events, pledges and budgets'],
        'akiba' => ['name' => 'Akiba Ledger', 'what' => 'SACCOs'],
        'estate-ledger' => ['name' => 'Estate Ledger', 'what' => 'Rentals'],
    ];

    /**
     * The app an account belongs to, from its modules: a SACCO or rentals account is that app alone (exclusive
     * modules); one main module is that module's own app; several, or none chosen yet, is Budget Pro.
     */
    public static function appOf(mixed $modules): string
    {
        $m = is_array($modules) ? $modules : (json_decode((string) $modules, true) ?: []);
        if (in_array('sacco', $m, true)) {
            return 'akiba';
        }
        if (in_array('rentals', $m, true)) {
            return 'estate-ledger';
        }

        return match (array_values(array_diff($m, ['finance']))) {
            ['shop'] => 'shelfie',
            ['poultry'] => 'kuku-tally',
            ['budget'] => 'pledge-and-budget',
            default => 'budget-pro',
        };
    }

    /** Every account, newest first: id, name, app, demo, owner, contact, users, seen, plan, sub_status, paid, ends, currency, created. */
    public function accounts(): \Illuminate\Support\Collection
    {
        $companies = DB::table('companies')->orderByDesc('id')
            ->get(['id', 'name', 'owner_id', 'email', 'phone_number', 'status', 'currency', 'business_type', 'is_demo', 'demo_parent_id', 'enabled_modules', 'created_at']);

        $users = DB::table('admin_users')->groupBy('company_id')
            ->selectRaw('company_id, COUNT(*) AS n, MAX(COALESCE(last_active_at, last_login_at)) AS seen')->get()->keyBy('company_id');
        $owners = DB::table('admin_users')->whereIn('id', $companies->pluck('owner_id')->filter()->unique())
            ->get(['id', 'name', 'username', 'email', 'phone_number'])->keyBy('id');
        $subs = DB::table('subscriptions as s')->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->orderBy('s.id')->get(['s.company_id', 's.status', 's.ends_at', 's.trial_ends_at', 'p.name as plan', 'p.slug', 'p.price_ugx'])->keyBy('company_id');

        return $companies->map(function ($c) use ($users, $owners, $subs) {
            $sub = $subs[$c->id] ?? null;
            $owner = $owners[$c->owner_id] ?? null;
            $seen = $users[$c->id]->seen ?? null;

            return (object) [
                'id' => $c->id, 'name' => $c->name, 'app' => self::appOf($c->enabled_modules), 'demo' => (bool) $c->is_demo || $c->demo_parent_id,
                'owner' => $owner?->name, 'contact' => $owner?->email ?: ($owner?->username ?: ($c->email ?: $c->phone_number)),
                'users' => (int) ($users[$c->id]->n ?? 0), 'seen' => $seen ? Carbon::parse($seen) : null,
                // On a paid plan that is running now: active (not a trial) and not past its end.
                'plan' => $sub?->plan, 'sub_status' => $sub?->status,
                'paid' => $sub && (float) $sub->price_ugx > 0 && $sub->status === 'active' && ($sub->ends_at === null || Carbon::parse($sub->ends_at)->isFuture()),
                'ends' => $sub ? ($sub->ends_at ?? $sub->trial_ends_at) : null, 'currency' => $c->currency,
                'created' => $c->created_at ? Carbon::parse($c->created_at) : null, 'status' => $c->status, 'type' => $c->business_type,
            ];
        });
    }

    public function snapshot(): array
    {
        $now = now();
        $d7 = $now->copy()->subDays(7);
        $d30 = $now->copy()->subDays(30);

        $rows = $this->accounts();
        $ugx = DB::table('companies')->where(fn ($q) => $q->whereNull('currency')->orWhereRaw('UPPER(currency) = ?', ['UGX']))->pluck('id')->all();

        $apps = [];
        foreach (self::APPS as $key => $meta) {
            $mine = $rows->where('app', $key);
            $real = $mine->where('demo', false);
            $apps[$key] = $meta + [
                'accounts' => $real->count(), 'demo' => $mine->count() - $real->count(),
                'new_30d' => $real->filter(fn ($r) => $r->created && $r->created->gte($d30))->count(),
                'active_7d' => $real->filter(fn ($r) => $r->seen && $r->seen->gte($d7))->count(),
                'paying' => $real->where('paid', true)->count(),
                'people' => $mine->sum('users'),
            ];
        }

        return [
            'at' => $now,
            'totals' => [
                'accounts' => $rows->where('demo', false)->count(), 'demo' => $rows->where('demo', true)->count(),
                'people' => DB::table('admin_users')->count(),
                'active_7d' => DB::table('admin_users')->where(fn ($q) => $q->where('last_active_at', '>=', $d7)->orWhere('last_login_at', '>=', $d7))->count(),
                'new_accounts_30d' => $rows->where('demo', false)->filter(fn ($r) => $r->created && $r->created->gte($d30))->count(),
                'new_people_30d' => DB::table('admin_users')->where('created_at', '>=', $d30)->count(),
                'phones' => $this->count('devices', fn ($q) => $q->whereNull('revoked_at')),
                'phones_7d' => $this->count('devices', fn ($q) => $q->where('last_seen_at', '>=', $d7)),
            ],
            'apps' => $apps,
            'books' => $this->books($ugx, $d30),
            'billing' => $this->billing($rows, $now),
            'growth' => $this->growth($rows),
            'health' => $this->health(),
            'videos' => Schema::hasTable('promo_videos')
                ? DB::table('promo_videos')->groupBy('app')->selectRaw('app, COUNT(*) AS n, SUM(published_at IS NOT NULL) AS live')->get()->keyBy('app') : collect(),
            'rows' => $rows,
            'recent_people' => DB::table('admin_users as u')->leftJoin('companies as c', 'c.id', '=', 'u.company_id')
                ->orderByDesc('u.id')->limit(12)->get(['u.id', 'u.name', 'u.username', 'u.created_at', 'u.last_active_at', 'u.company_id', 'c.name as company']),
        ];
    }

    /** Each app's own records, all accounts together. Money is UGX accounts only (whole shillings). */
    private function books(array $ugx, Carbon $d30): array
    {
        $live = fn ($q) => $q->where(fn ($w) => $w->whereNull('is_deleted')->orWhere('is_deleted', 0));

        return [
            'shop' => [
                'products' => $this->count('stock_items', $live),
                'sales' => $this->count('sale_records', $live),
                'sales_30d' => $this->count('sale_records', fn ($q) => $live($q)->where('sale_date', '>=', $d30)->where('status', '!=', 'Voided')),
                'takings_30d' => $this->sum('sale_records', 'total_amount', fn ($q) => $live($q)->whereIn('company_id', $ugx)->where('sale_date', '>=', $d30)->where('status', '!=', 'Voided')),
                'unpaid' => $this->sum('sale_records', 'balance', fn ($q) => $live($q)->whereIn('company_id', $ugx)->where('status', '!=', 'Voided')->where('balance', '>', 0)),
                'customers' => $this->count('customers'),
            ],
            'poultry' => [
                'flocks' => $this->count('poultry_batches', fn ($q) => $live($q)->where('status', '!=', 'closed')),
                'flocks_all' => $this->count('poultry_batches', $live),
                'birds_started' => $this->sum('poultry_batches', 'start_count', fn ($q) => $live($q)->where('status', '!=', 'closed')),
                'days_30d' => $this->count('poultry_daily_records', fn ($q) => $live($q)->where('date', '>=', $d30)),
                'deaths_30d' => $this->sum('poultry_daily_records', 'mortality', fn ($q) => $live($q)->where('date', '>=', $d30)),
                'sales_30d' => $this->sum('poultry_sales', 'total', fn ($q) => $live($q)->whereIn('company_id', $ugx)->where('date', '>=', $d30)),
            ],
            'budget' => [
                'events' => $this->count('budget_programs', $live),
                'open' => $this->count('budget_programs', fn ($q) => $live($q)->where('status', 'Active')),
                'pledges' => $this->count('contribution_records', fn ($q) => $live($q)->whereNull('withdrawn_at')),
                'pledged' => $this->sum('contribution_records', 'amount', fn ($q) => $live($q)->whereIn('company_id', $ugx)->whereNull('withdrawn_at')),
                'received' => $this->sum('contribution_records', 'paid_amount', fn ($q) => $live($q)->whereIn('company_id', $ugx)->whereNull('withdrawn_at')),
                'payments_30d' => $this->sum('contribution_payments', 'amount', fn ($q) => $live($q)->whereIn('company_id', $ugx)->where('paid_on', '>=', $d30)),
                'cards' => $this->count('event_invitations', $live),
            ],
            'sacco' => [
                'members' => $this->count('sacco_members', fn ($q) => $live($q)->where('status', 'active')),
                'applicants' => $this->count('sacco_members', fn ($q) => $live($q)->where('status', 'applicant')),
                'loans_active' => $this->count('sacco_loans', fn ($q) => $q->where('status', 'active')),
                'loans_waiting' => $this->count('sacco_loans', fn ($q) => $q->whereIn('status', ['submitted', 'appraised', 'in_committee', 'pending_guarantor', 'pending_chair', 'approved'])),
                'outstanding' => $this->sum('sacco_loans', 'principal_outstanding_minor', fn ($q) => $q->whereIn('company_id', $ugx)->where('status', 'active')),
                'transactions_30d' => $this->count('sacco_transactions', fn ($q) => $live($q)->where('value_date', '>=', $d30)),
            ],
            'rentals' => [
                'properties' => $this->count('rental_properties'),
                'units' => $this->count('rental_units'),
                'let' => $this->count('rental_leases', fn ($q) => $q->whereIn('status', ['active', 'notice'])),
                'tenants' => $this->count('rental_tenants'),
                'collected_30d' => $this->sum('rental_payments', 'amount_minor', fn ($q) => $q->whereIn('company_id', $ugx)->whereNull('reversed_at')->where('paid_on', '>=', $d30)),
            ],
            'money' => [
                'records' => $this->count('financial_records', $live),
            ],
        ];
    }

    private function billing($rows, Carbon $now): array
    {
        $real = $rows->where('demo', false);
        $soon = $real->filter(fn ($r) => $r->ends && Carbon::parse($r->ends)->between($now, $now->copy()->addDays(14)))->sortBy('ends');

        return [
            'by_plan' => $real->groupBy(fn ($r) => $r->plan ?? 'No plan')->map->count()->sortDesc(),
            'by_status' => $real->groupBy(fn ($r) => $r->sub_status ?? 'none')->map->count(),
            'paying' => $real->where('paid', true)->count(),
            'ending_soon' => $soon->values(),
            'invoices_paid' => $this->sum('subscription_invoices', 'amount', fn ($q) => $q->where('status', 'paid')),
            'invoices_open' => $this->count('subscription_invoices', fn ($q) => $q->whereNotIn('status', ['paid', 'void', 'abandoned', 'refunded'])),
        ];
    }

    /** Real sign-ups per week, the last 12 weeks (oldest first). */
    private function growth($rows): array
    {
        $weeks = [];
        $start = now()->startOfWeek()->subWeeks(11);
        for ($i = 0; $i < 12; $i++) {
            $weeks[$start->copy()->addWeeks($i)->format('d M')] = 0;
        }
        foreach ($rows->where('demo', false) as $r) {
            if ($r->created && $r->created->gte($start)) {
                $key = $r->created->copy()->startOfWeek()->format('d M');
                if (isset($weeks[$key])) {
                    $weeks[$key]++;
                }
            }
        }

        return $weeks;
    }

    private function health(): array
    {
        $since = now()->subDay();

        return [
            'errors' => $this->count('error_events', fn ($q) => $q->whereNull('resolved_at')),
            'failed_jobs' => $this->count('failed_jobs'),
            'waiting_jobs' => $this->count('jobs'),
            'last_backup' => Schema::hasTable('backup_runs') ? DB::table('backup_runs')->where('kind', 'backup')->where('status', 'ok')->max('created_at') : null,
            'messages_24h' => Schema::hasTable('message_log') ? DB::table('message_log')->where('created_at', '>=', $since)->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status') : collect(),
            'deletions' => $this->count('data_requests', fn ($q) => $q->where('kind', 'delete')->where('status', 'scheduled')),
        ];
    }

    private function count(string $table, ?\Closure $where = null): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }
        $q = DB::table($table);
        $where && $where($q);

        return (int) $q->count();
    }

    private function sum(string $table, string $column, ?\Closure $where = null): float
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }
        $q = DB::table($table);
        $where && $where($q);

        return (float) $q->sum($column);
    }
}
