<?php

namespace App\Services\Dashboard;

use App\Models\Company;
use App\Models\User;
use App\Services\Engage\ReceiptDelivery;
use App\Services\Notifications\ScheduledNotifications;
use App\Services\Shop\ShortDatedService;
use App\Services\Team\Permissions;
use App\Support\LocalDate;
use App\Support\StoreScope;
use Illuminate\Support\Facades\DB;

/**
 * "Worth a look" on the phone's home (MOBILE_EXCELLENCE_PLAN.md D2): what this person should look at
 * now, chosen by the server, most urgent first. Each item names the app screen to open (`route`).
 * Only what the person's role can act on; nothing at all → one `all_clear` item. The figures reuse the
 * web dashboard (DashboardService::stock), the debt reminders' terms (DebtReminders), the short-dated
 * window (StoreFeatures short_dated_days) and the unsynced-phone notice (ScheduledNotifications).
 */
class Suggestions
{
    private const ORDER = ['bad' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];

    /** OwnerNotices key => title (the notice's text is the detail). */
    private const OWNER_TITLES = ['licence' => 'The licence needs attention', 'address' => 'Opened at an address the licence does not cover',
        'updates' => 'Updates for this copy have ended', 'scheduler' => 'The scheduler is not running', 'backup' => 'Backups are not up to date',
        'connections' => 'No email, SMS or WhatsApp is set up'];

    public function __construct(private readonly DashboardService $dashboard = new DashboardService)
    {
    }

    /**
     * $host: the address the request came to (the licence's address check on a standalone copy).
     *
     * @return list<array{key: string, tone: string, title: string, detail: string, route: string, action_label: ?string, count: int, web_path: ?string}>
     */
    public function for(User $user, Company $company, int $limit = 6, ?string $host = null): array
    {
        $cid = (int) $company->id;
        \App\Support\LocalTime::prime($cid);
        $can = fn (string ...$perms) => collect($perms)->contains(fn ($p) => Permissions::can($user, $p));
        $today = LocalDate::today($cid);
        $out = [];
        $add = function (string $key, string $tone, int $count, string $title, string $detail, string $route, ?string $action) use (&$out) {
            $out[] = ['key' => $key, 'tone' => $tone, 'title' => $title, 'detail' => $detail, 'route' => $route, 'action_label' => $action, 'count' => $count, 'web_path' => null];
        };
        $n = fn (int $x, string $one, string $many) => $x.' '.($x === 1 ? $one : $many);

        if ($can('restock', 'manage_products', 'view_reports')) {
            $stock = $this->dashboard->stock($company, StoreScope::forUser($user, $company));
            if ($stock['out_of_stock'] > 0) {
                $add('out_of_stock', 'bad', $stock['out_of_stock'], $n($stock['out_of_stock'], 'product is', 'products are').' out of stock',
                    'Customers asking for '.($stock['out_of_stock'] === 1 ? 'it' : 'them').' go away empty-handed. Reorder or receive stock.', 'products.out_of_stock', 'See products');
            }
            if ($stock['low'] > 0) {
                $add('low_stock', 'warn', $stock['low'], $n($stock['low'], 'product is', 'products are').' running low', 'At or below the reorder level. Order before they run out.', 'products.low_stock', 'See products');
            }
        }

        if ($can('sell', 'view_reports')) {
            $o = DB::selectOne("SELECT COUNT(DISTINCT CASE WHEN s.customer_id IS NULL THEN CONCAT('n:', LOWER(TRIM(COALESCE(s.customer_name, '')))) ELSE CONCAT('c:', s.customer_id) END) AS who,
                    COALESCE(SUM(s.balance), 0) AS owed
                FROM sale_records s LEFT JOIN customers c ON c.id = s.customer_id
                WHERE s.company_id = ? AND s.balance > 0 AND s.voided_at IS NULL AND s.status <> 'Voided' AND (s.is_deleted IS NULL OR s.is_deleted = 0)
                  AND COALESCE(s.due_date, DATE_ADD(s.sale_date, INTERVAL COALESCE(c.payment_terms_days, ?) DAY)) < ?",
                [$cid, (int) ($company->credit_terms_days ?? 30), $today->toDateString()]);
            if ((int) $o->who > 0) {
                $add('credit_overdue', 'warn', (int) $o->who, $n((int) $o->who, 'customer is', 'customers are').' late paying',
                    \App\Support\Money::format((float) $o->owed, null, $cid).' is past its payment terms. Send a reminder or collect it.', 'debts.overdue', 'See debtors');
            }
        }

        if ($can('sell', 'view_reports')) {
            $shifts = DB::table('shifts')->where('company_id', $cid)->where('status', 'open')->where(fn ($q) => $q->whereNull('is_deleted')->orWhere('is_deleted', 0))
                ->where('opened_at', '<', $today->copy()->startOfDay()->utc())
                ->when(! $can('view_reports'), fn ($q) => $q->where('opened_by_id', $user->id))->count();
            if ($shifts > 0) {
                $add('shift_open', 'warn', $shifts, $shifts === 1 ? 'A till from before today is still open' : $shifts.' tills from before today are still open',
                    'Count the cash and close it, so the day\'s takings and any difference are right.', 'shifts', 'Close the till');
            }
        }

        if ($can('adjust', 'restock')) {
            $days = ShortDatedService::defaultDays($company);
            $batches = DB::table('stock_batches as b')->join('stock_items as p', 'p.id', '=', 'b.stock_item_id')->where('b.company_id', $cid)->where('p.is_deleted', 0)
                ->where('b.quantity', '>', 0)->whereNotNull('b.expiry_date')->where('b.expiry_date', '<=', $today->copy()->addDays($days)->toDateString())->count();
            if ($batches > 0) {
                $add('expiring', 'warn', $batches, $n($batches, 'batch expires', 'batches expire').' within '.$days.' days',
                    'Sell them first, mark them down or return them to the supplier.', 'stock.expiring', 'See batches');
            }
        }

        if ($can('sell') && app(ReceiptDelivery::class)->allowed($company)) {
            $unsent = DB::table('sale_records as s')->leftJoin('customers as c', 'c.id', '=', 's.customer_id')->where('s.company_id', $cid)
                ->where('s.sale_date', $today->toDateString())->whereNull('s.voided_at')->where('s.status', '<>', 'Voided')->whereNull('s.receipt_sent_at')
                ->where(fn ($q) => $q->where('s.customer_phone', '<>', '')->orWhere('c.phone', '<>', ''))->where(fn ($q) => $q->whereNull('c.messages_opt_out')->orWhere('c.messages_opt_out', 0))->count();
            if ($unsent > 0) {
                $add('receipts_unsent', 'info', $unsent, $n($unsent, 'receipt', 'receipts').' not sent today',
                    'These customers left a phone number. Send the receipt on WhatsApp or SMS.', 'sales.receipts_unsent', 'Send receipts');
            }
        }

        if ($can('manage_team')) {
            $stale = DB::table('devices')->where('company_id', $cid)->where('status', 'active')->whereNull('revoked_at')
                ->where('last_seen_at', '<', now()->subDays(ScheduledNotifications::UNSYNCED_DAYS))->where('last_seen_at', '>', now()->subDays(30))->count();
            if ($stale > 0) {
                $add('devices_unsynced', 'warn', $stale, $n($stale, 'phone has', 'phones have').' not synced for '.ScheduledNotifications::UNSYNCED_DAYS.'+ days',
                    'Their sales and stock changes are not on the server yet. Open the app on '.($stale === 1 ? 'that phone' : 'those phones').' with internet.', 'devices', 'See phones');
            }
        }

        if ($can('restock', 'view_reports', 'manage_finance')) {
            [$suppliers, $due] = $this->billsDue($cid, $today->toDateString());
            if ($suppliers > 0) {
                $add('supplier_bills_due', 'warn', $suppliers, $n($suppliers, 'supplier bill is', 'supplier bills are').' due',
                    \App\Support\Money::format($due, null, $cid).' owed past the payment terms.', 'suppliers.due', 'See suppliers');
            }
        }

        // A standalone copy's owner banners (OwnerNotices: licence, address, updates, scheduler, backups, messaging), fixed on the web.
        try {
            $notices = \App\Support\OwnerNotices::for($user, $host);
        } catch (\Throwable) {
            $notices = [];
        }
        foreach ($notices as $notice) {
            $add('owner_'.$notice['key'], 'warn', 1, self::OWNER_TITLES[$notice['key']] ?? 'Your server needs a look', $notice['text'], 'web', 'Open on the web');
            $out[array_key_last($out)]['web_path'] = $notice['key'] === 'connections' ? '/settings/connections' : '/settings/system';
        }

        usort($out, fn ($a, $b) => self::ORDER[$a['tone']] <=> self::ORDER[$b['tone']]);
        if ($out === []) {
            return [['key' => 'all_clear', 'tone' => 'ok', 'title' => 'All clear', 'detail' => 'Nothing needs your attention right now.', 'route' => 'home', 'action_label' => null, 'count' => 0, 'web_path' => null]];
        }

        return array_slice($out, 0, max(1, $limit));
    }

    /**
     * Suppliers we owe money that is past their payment terms: what is owed, less the deliveries still
     * inside the terms (payments settle the oldest deliveries first, so the newest are the ones unpaid).
     *
     * @return array{0: int, 1: float} [suppliers, amount due]
     */
    public function billsDue(int $companyId, string $today): array
    {
        $count = 0;
        $total = 0.0;
        $owed = DB::table('suppliers')->where('company_id', $companyId)->where('is_deleted', 0)->where('balance', '>', 0)->limit(500)->get(['id', 'balance', 'payment_terms_days']);
        foreach ($owed as $s) {
            $since = \Illuminate\Support\Carbon::parse($today)->subDays((int) $s->payment_terms_days)->toDateString();
            $notYet = (float) DB::table('goods_receipts')->where('company_id', $companyId)->where('supplier_id', $s->id)->where('received_on', '>', $since)
                ->sum(DB::raw('GREATEST(total_cost - amount_paid, 0)'));
            $due = round(max(0, (float) $s->balance - $notYet), 2);
            if ($due > 0) {
                $count++;
                $total += $due;
            }
        }

        return [$count, round($total, 2)];
    }
}
