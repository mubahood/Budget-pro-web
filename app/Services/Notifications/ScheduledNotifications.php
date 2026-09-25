<?php

namespace App\Services\Notifications;

use App\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Time-of-day business notifications in each shop's own timezone (plan C7, P3-5).
 * Runs hourly; each notice fires once per day (or week) thanks to Notices::once,
 * so a missed scheduler hour only delays it.
 */
class ScheduledNotifications
{
    public const DAILY_SUMMARY_HOUR = 20;

    public const LOW_STOCK_HOUR = 8;

    public const UNSYNCED_HOUR = 9;

    public const UNSYNCED_DAYS = 3;

    public function __construct(private readonly Notifier $notifier = new Notifier())
    {
    }

    /** @return array<string, int> */
    public function run(): array
    {
        $counts = ['daily_summary' => 0, 'low_stock' => 0, 'unsynced_device' => 0];
        Company::withoutGlobalScopes()->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'Inactive'))->where(fn ($q) => $q->whereNull('is_demo')->orWhere('is_demo', false))
            ->chunkById(200, function ($companies) use (&$counts) {
                foreach ($companies as $company) {
                    $local = now()->setTimezone($this->tz($company));
                    if ($local->hour >= self::DAILY_SUMMARY_HOUR) {
                        $counts['daily_summary'] += (int) Notices::once((int) $company->id, 'daily_summary', $local->toDateString(), fn () => $this->dailySummary($company, $local));
                    }
                    if ($local->hour >= self::LOW_STOCK_HOUR) {
                        $counts['low_stock'] += (int) Notices::once((int) $company->id, 'low_stock', $local->toDateString(), fn () => $this->lowStock($company));
                    }
                    if ($local->hour >= self::UNSYNCED_HOUR) {
                        $counts['unsynced_device'] += $this->unsyncedDevices($company, $local);
                    }
                }
            });

        return $counts;
    }

    public function tz(Company $company): string
    {
        $tz = (string) ($company->timezone ?: config('saas.display_timezone', 'Africa/Kampala'));

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Africa/Kampala';
    }

    /** @return array{count: int, total: float, cash: float, credit: float, top: ?string} */
    public function daySales(Company $company, Carbon $local): array
    {
        $from = $local->copy()->startOfDay()->utc();
        $to = $local->copy()->endOfDay()->utc();
        $sales = DB::table('sale_records')->where('company_id', $company->id)->where('status', '!=', 'Voided')->whereBetween('created_at', [$from, $to]);
        $top = DB::table('sale_record_items')->join('sale_records', 'sale_records.id', '=', 'sale_record_items.sale_record_id')
            ->where('sale_records.company_id', $company->id)->where('sale_records.status', '!=', 'Voided')->whereBetween('sale_records.created_at', [$from, $to])
            ->groupBy('sale_record_items.item_name')->orderByRaw('SUM(sale_record_items.line_total) DESC')->value('sale_record_items.item_name');

        return [
            'count' => (int) (clone $sales)->count(),
            'total' => (float) (clone $sales)->sum('total_amount'),
            'cash' => (float) (clone $sales)->sum('amount_paid'),
            'credit' => (float) (clone $sales)->where('balance', '>', 0)->sum('balance'),
            'top' => $top,
        ];
    }

    private function dailySummary(Company $company, Carbon $local): void
    {
        $d = $this->daySales($company, $local);
        $cur = $company->currency ?: 'UGX';
        $body = $d['count'] === 0
            ? 'No sales recorded today.'
            : "{$d['count']} sale".($d['count'] > 1 ? 's' : '').', '.number_format($d['total'])." {$cur} (paid ".number_format($d['cash']).', on credit '.number_format($d['credit']).').'
                .($d['top'] ? " Best seller: {$d['top']}." : '');
        $this->notifier->notify((int) $company->id, 'daily_summary', "{$company->name} today", $body, ['date' => $local->toDateString()] + $d);
    }

    private function lowStock(Company $company): void
    {
        $default = (float) ($company->low_stock_default ?? config('saas.low_stock_threshold', 10));
        $rows = DB::table('stock_items')->where('company_id', $company->id)->where('is_deleted', false)->where('track_stock', true)
            ->whereRaw('current_quantity <= COALESCE(min_stock, ?)', [$default])->orderBy('current_quantity')->limit(50)->get(['id', 'name', 'current_quantity']);
        $expiring = DB::table('stock_batches as b')->join('stock_items as p', 'p.id', '=', 'b.stock_item_id')->where('b.company_id', $company->id)->where('b.quantity', '>', 0)
            ->whereNotNull('b.expiry_date')->where('b.expiry_date', '<=', now()->addDays(30)->toDateString())->orderBy('b.expiry_date')->limit(20)->get(['p.name', 'b.batch_number', 'b.expiry_date']);
        if ($rows->isEmpty() && $expiring->isEmpty()) {
            return;
        }
        if ($rows->isEmpty()) {
            $this->notifier->notify((int) $company->id, 'low_stock', $expiring->count().' batch(es) expire within 30 days',
                'Sell or return first: '.$expiring->take(5)->map(fn ($b) => "{$b->name} {$b->batch_number} ({$b->expiry_date})")->implode(', ').'.', []);

            return;
        }
        $names = $rows->take(5)->map(fn ($r) => $r->name.' ('.rtrim(rtrim(number_format((float) $r->current_quantity, 3, '.', ''), '0'), '.').')')->implode(', ');
        $more = $rows->count() > 5 ? ' and '.($rows->count() - 5).' more' : '';
        $exp = $expiring->isEmpty() ? '' : ' Expiring within 30 days: '.$expiring->take(3)->map(fn ($b) => "{$b->name} {$b->batch_number} ({$b->expiry_date})")->implode(', ').'.';
        $this->notifier->notify((int) $company->id, 'low_stock', $rows->count().' product'.($rows->count() > 1 ? 's are' : ' is').' running low', "Reorder soon: {$names}{$more}.{$exp}", ['product_ids' => $rows->pluck('id')->all()]);
    }

    private function unsyncedDevices(Company $company, Carbon $local): int
    {
        $sent = 0;
        $stale = DB::table('devices')->where('company_id', $company->id)->where('status', 'active')->whereNull('revoked_at')
            ->where('last_seen_at', '<', now()->subDays(self::UNSYNCED_DAYS))->where('last_seen_at', '>', now()->subDays(30))->get(['id', 'name', 'last_seen_at']);
        foreach ($stale as $device) {
            $days = (int) floor(Carbon::parse($device->last_seen_at)->diffInHours(now()) / 24);
            $sent += (int) Notices::once((int) $company->id, 'unsynced_device', "device:{$device->id}:".$local->format('o-W'), fn () => $this->notifier->notify((int) $company->id, 'unsynced_device',
                "Phone '{$device->name}' hasn't synced for {$days} days", 'Its sales and stock changes are not on the server yet. Open the app on that phone with internet so it can sync.', ['device_id' => $device->id]));
        }

        return $sent;
    }
}
