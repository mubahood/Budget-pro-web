<?php

namespace App\Services\Poultry;

use App\Models\Company;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A poultry farm at a glance, for the web portal's dashboard (budget-pro-new docs/FAMILY_PLAN.md Part 2).
 *
 * Read from the same tables the phone syncs (poultry_*; rows with is_deleted = 1 are left out), with the
 * phone's own definitions (budget-pro-mobo lib/poultry/domain/poultry_math.dart and farm_stats.dart), so
 * the portal and Kuku Tally show the same figures:
 *
 * - an egg tray holds 30 eggs; a day's eggs = eggs_trays × 30 + eggs_loose;
 * - a batch is active until its status is `closed`; layers are `layer` and `kienyeji` batches;
 * - birds alive in a batch = start_count − deaths (daily records' mortality + mortality events)
 *   − birds sold (sales of `broilers`, `spent_layers` or `birds` from that batch), never below 0;
 * - lay rate = eggs collected ÷ live layer birds × 100 (today: today's eggs; 7 days: the average
 *   day among the days that have a record), null when there are no live layers;
 * - feed in stock = feed bought (`in`) − feed taken out (`out`) ± adjustments − feed eaten (daily records);
 *   days of feed left = stock ÷ the average day's feed over the latest 42 daily records (by distinct date);
 *   low = 7 days or fewer;
 * - this month's sales and expenses follow the farm's own calendar (its time zone).
 *
 * Seven small queries, each on company_id; a company with no batch costs one.
 */
final class PoultrySummary
{
    public const EGGS_PER_TRAY = 30;

    /** Batch types that lay (the phone's Batch.isLayer). */
    public const LAYER_TYPES = ['layer', 'kienyeji'];

    /** Sale categories that take birds out of a batch: the phone's two and the classic screen's one. */
    public const BIRD_SALES = ['broilers', 'spent_layers', 'birds'];

    /** Daily records the average day's feed is taken over (the phone's 14-day window × 3 batches). */
    public const FEED_RECORDS = 42;

    public const LOW_FEED_DAYS = 7;

    /** Days in the "last 7 days" window, today included. */
    public const WINDOW_DAYS = 7;

    /**
     * @return array{
     *   has_batches: bool, batches_total: int, batches_active: int, birds_alive: int, layer_birds: int,
     *   eggs_today: int, eggs_7d: int, lay_rate_today: ?float, lay_rate_7d: ?float, deaths_7d: int,
     *   feed_kg: float, feed_days_left: ?int, feed_low: bool, sales_month: float, expenses_month: float,
     *   batches: list<array{id: int, name: string, type: string, birds: int, age_weeks: int}>,
     *   today: string, window_from: string, month_from: string
     * }
     */
    public function for(Company $company, ?Carbon $now = null): array
    {
        $cid = (int) $company->id;
        $now = ($now ?? now())->copy()->setTimezone(LocalTime::timezone($company));
        $today = $now->toDateString();
        $from = $now->copy()->subDays(self::WINDOW_DAYS - 1)->toDateString();
        $monthFrom = $now->copy()->startOfMonth()->toDateString();
        $monthTo = $now->copy()->endOfMonth()->toDateString();

        $out = ['has_batches' => false, 'batches_total' => 0, 'batches_active' => 0, 'birds_alive' => 0, 'layer_birds' => 0,
            'eggs_today' => 0, 'eggs_7d' => 0, 'lay_rate_today' => null, 'lay_rate_7d' => null, 'deaths_7d' => 0,
            'feed_kg' => 0.0, 'feed_days_left' => null, 'feed_low' => false, 'sales_month' => 0.0, 'expenses_month' => 0.0,
            'batches' => [], 'today' => $today, 'window_from' => $from, 'month_from' => $monthFrom];

        $batches = DB::table('poultry_batches')->where('company_id', $cid)->where('is_deleted', 0)
            ->orderByDesc('acquired_date')->orderByDesc('id')
            ->get(['id', 'name', 'type', 'status', 'start_count', 'acquired_date']);
        if ($batches->isEmpty()) {
            return $out;
        }
        $active = $batches->filter(fn ($b) => strtolower((string) $b->status) !== 'closed');

        $eggs = 'eggs_trays * '.self::EGGS_PER_TRAY.' + eggs_loose';
        $daily = DB::table('poultry_daily_records')->where('company_id', $cid)->where('is_deleted', 0)
            ->groupBy('batch_id')
            ->selectRaw('batch_id, COALESCE(SUM(mortality), 0) AS deaths, COALESCE(SUM(feed_kg), 0) AS feed_kg,'
                ." COALESCE(SUM(CASE WHEN date = ? THEN {$eggs} ELSE 0 END), 0) AS eggs_today,"
                ." COALESCE(SUM(CASE WHEN date BETWEEN ? AND ? THEN {$eggs} ELSE 0 END), 0) AS eggs_7d,"
                .' COALESCE(SUM(CASE WHEN date BETWEEN ? AND ? THEN mortality ELSE 0 END), 0) AS deaths_7d', [$today, $from, $today, $from, $today])
            ->get()->keyBy(fn ($r) => (int) $r->batch_id);
        $events = DB::table('poultry_mortality_events')->where('company_id', $cid)->where('is_deleted', 0)
            ->groupBy('batch_id')
            ->selectRaw('batch_id, COALESCE(SUM(`count`), 0) AS deaths, COALESCE(SUM(CASE WHEN date BETWEEN ? AND ? THEN `count` ELSE 0 END), 0) AS deaths_7d', [$from, $today])
            ->get()->keyBy(fn ($r) => (int) $r->batch_id);
        $sales = DB::table('poultry_sales')->where('company_id', $cid)->where('is_deleted', 0)
            ->groupBy('batch_id')
            ->selectRaw('batch_id, COALESCE(SUM(CASE WHEN category IN ('.implode(',', array_fill(0, count(self::BIRD_SALES), '?')).') THEN qty ELSE 0 END), 0) AS birds_sold,'
                .' COALESCE(SUM(CASE WHEN date BETWEEN ? AND ? THEN total ELSE 0 END), 0) AS month_total', [...self::BIRD_SALES, $monthFrom, $monthTo])
            ->get();

        $layerIds = [];
        foreach ($active as $b) {
            $id = (int) $b->id;
            $d = $daily[$id] ?? null;
            $e = $events[$id] ?? null;
            $sold = (int) round((float) ($sales->firstWhere('batch_id', $id)->birds_sold ?? 0));
            $alive = max(0, (int) $b->start_count - (int) ($d->deaths ?? 0) - (int) ($e->deaths ?? 0) - $sold);
            $out['birds_alive'] += $alive;
            $out['eggs_today'] += (int) ($d->eggs_today ?? 0);
            $out['eggs_7d'] += (int) ($d->eggs_7d ?? 0);
            $out['deaths_7d'] += (int) ($d->deaths_7d ?? 0) + (int) ($e->deaths_7d ?? 0);
            if (in_array(strtolower((string) $b->type), self::LAYER_TYPES, true)) {
                $out['layer_birds'] += $alive;
                $layerIds[] = $id;
            }
            $acquired = $b->acquired_date ? Carbon::parse($b->acquired_date, $now->getTimezone()) : null;
            $out['batches'][] = ['id' => $id, 'name' => (string) $b->name, 'type' => (string) $b->type, 'birds' => $alive,
                'age_weeks' => $acquired ? intdiv(max(0, (int) $acquired->startOfDay()->diffInDays($now->copy()->startOfDay(), false)), 7) : 0];
        }

        if ($out['layer_birds'] > 0) {
            $layerToday = array_sum(array_map(fn ($id) => (int) ($daily[$id]->eggs_today ?? 0), $layerIds));
            $out['lay_rate_today'] = round($layerToday / $out['layer_birds'] * 100, 1);
            $days = (int) DB::table('poultry_daily_records')->where('company_id', $cid)->where('is_deleted', 0)
                ->whereIn('batch_id', $layerIds)->whereBetween('date', [$from, $today])->distinct()->count('date');
            if ($days > 0) {
                $layer7 = array_sum(array_map(fn ($id) => (int) ($daily[$id]->eggs_7d ?? 0), $layerIds));
                $out['lay_rate_7d'] = round($layer7 / $days / $out['layer_birds'] * 100, 1);
            }
        }

        // Feed: bought − taken out ± adjustments − eaten (every daily record, closed batches too, as on the phone);
        // this month's expenses in the same round trip.
        $money = DB::selectOne(<<<'SQL'
            SELECT
              (SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN qty_kg WHEN direction = 'out' THEN -qty_kg ELSE qty_kg END), 0)
                 FROM poultry_feed_stock WHERE company_id = ? AND is_deleted = 0) AS kg,
              (SELECT COALESCE(SUM(amount), 0) FROM poultry_expenses WHERE company_id = ? AND is_deleted = 0 AND date BETWEEN ? AND ?) AS expenses
            SQL, [$cid, $cid, $monthFrom, $monthTo]);
        $stock = (float) $money->kg;
        $eaten = (float) $daily->sum(fn ($r) => (float) $r->feed_kg);
        $out['feed_kg'] = round((float) $stock - $eaten, 2);
        $recent = DB::table('poultry_daily_records')->where('company_id', $cid)->where('is_deleted', 0)
            ->orderByDesc('date')->orderByDesc('id')->limit(self::FEED_RECORDS)->get(['date', 'feed_kg']);
        $recordedDays = $recent->pluck('date')->unique()->count();
        $avgDaily = $recordedDays > 0 ? (float) $recent->sum(fn ($r) => (float) $r->feed_kg) / $recordedDays : 0.0;
        if ($avgDaily > 0) {
            $out['feed_days_left'] = $out['feed_kg'] <= 0 ? 0 : (int) floor($out['feed_kg'] / $avgDaily);
            $out['feed_low'] = $out['feed_days_left'] <= self::LOW_FEED_DAYS;
        }

        $out['sales_month'] = round((float) $sales->sum(fn ($r) => (float) $r->month_total), 2);
        $out['expenses_month'] = round((float) $money->expenses, 2);

        $out['has_batches'] = true;
        $out['batches_total'] = $batches->count();
        $out['batches_active'] = $active->count();

        return $out;
    }
}
