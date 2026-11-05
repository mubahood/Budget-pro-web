<?php

namespace App\Services\Rentals\Reports;

use App\Exceptions\BusinessRuleException;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\UnitSheet;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The landlord work reports: one landlord's rent sheet for a month (LC-06, UnitSheet as of the month's last day, so it reproduces) and the
 * landlords below zero (LC-11, read from balances, so it covers payouts older than the advance column as well). Both cover money across the
 * account, so RentalReports refuses them to a caretaker, and both are for agencies only.
 */
final class LandlordWorkReports
{
    use ReportTable;

    /** The months an average collection looks back over. */
    private const AVERAGE_MONTHS = 6;

    /**
     * @param  \stdClass  $x  the run's context (RentalReports::context)
     * @return array<string, mixed>
     */
    public function landlordRentSheet(object $x): array
    {
        $landlord = $x->landlord ?? throw BusinessRuleException::make('landlord_required', 'Choose the landlord.');
        $rows = [];
        foreach (UnitSheet::rows($landlord, $x->to) as $r) {
            $tenant = $r['tenant'] ?? ($r['in_use_by'] !== null ? 'Used by '.$r['in_use_by'] : 'Vacant, since '.Carbon::parse((string) $r['vacant_since'])->format('j M Y'));
            $rows[] = ['unit' => $r['unit'], 'property' => $r['property'], 'tenant' => $tenant.($r['origin'] === 'sitting' ? ' (already living here)' : '').($r['moved_out_on'] !== null ? ' (moved out '.Carbon::parse($r['moved_out_on'])->format('j M Y').')' : ''),
                'rent_minor' => $r['rent_minor'], 'billed_minor' => $r['billed_minor'], 'paid_minor' => $r['paid_minor'], 'direct_minor' => $r['direct_minor'], 'owed_minor' => $r['owed_minor'],
                'behind' => UnitSheet::behindWords($r['months_behind'], $r['behind_rest_minor'], $x->cid), 'paid_up_to' => $r['paid_up_to'], 'last_paid_on' => $r['last_paid_on'], 'last_paid_minor' => $r['last_paid_minor']];
        }
        $sum = fn (string $k) => array_sum(array_column($rows, $k));
        $direct = $sum('direct_minor') !== 0 ? [self::c('direct_minor', 'Paid to the landlord', 'money')] : []; // said only when a tenant did

        return ['subtitle' => $landlord->name.' · '.Carbon::parse($x->from)->format('F Y').' · as of '.Carbon::parse($x->to)->format('j M Y'),
            'columns' => [self::c('unit', 'Unit'), self::c('property', 'Property'), self::c('tenant', 'Tenant'), self::c('rent_minor', 'Rent', 'money'), self::c('billed_minor', 'Billed this month', 'money'),
                self::c('paid_minor', 'Paid this month', 'money'), ...$direct, self::c('owed_minor', 'Owed at the end', 'money'), self::c('behind', 'Months behind'), self::c('paid_up_to', 'Paid up to', 'date'),
                self::c('last_paid_on', 'Last paid', 'date'), self::c('last_paid_minor', 'Last amount', 'money')],
            'rows' => $rows, 'totals' => ['rent_minor' => $sum('rent_minor'), 'billed_minor' => $sum('billed_minor'), 'paid_minor' => $sum('paid_minor'), 'direct_minor' => $sum('direct_minor'), 'owed_minor' => $sum('owed_minor')],
            'landlord' => ['id' => (int) $landlord->id, 'name' => (string) $landlord->name], 'paper' => ['hide' => ['property'], 'group' => 'property', 'sum' => ['rent_minor', 'billed_minor', 'paid_minor', 'owed_minor']]];
    }

    /**
     * @param  \stdClass  $x  the run's context (RentalReports::context)
     * @return array<string, mixed>
     */
    public function landlordAdvances(object $x): array
    {
        $rows = [];
        foreach (self::advances($x->cid) as $a) {
            if (! self::matches($x, [$a['name']])) {
                continue;
            }
            $rows[] = ['landlord' => $a['name'], 'owes_minor' => $a['owes_minor'], 'since' => $a['since'], 'last_collected' => $a['last_collected_on'], 'average_minor' => $a['average_minor'],
                'clears' => self::clearsWords($a['months_to_clear'])];
        }

        return ['subtitle' => $this->asAt($x),
            'columns' => [self::c('landlord', 'Landlord'), self::c('owes_minor', 'Owes the agency', 'money'), self::c('since', 'Below zero since', 'date'), self::c('last_collected', 'Last collected', 'date'),
                self::c('average_minor', 'Collected a month', 'money'), self::c('clears', 'Rough time to clear')],
            'rows' => $rows, 'totals' => ['owes_minor' => array_sum(array_column($rows, 'owes_minor'))], 'paper' => ['portrait' => true]];
    }

    /**
     * Landlords below zero now, biggest first: how much, since when (the day after their balance last stood at zero or above), when money was
     * last collected for them, their average share collected a month over the last six whole months (this month so far, when they are new), and about how many months that takes
     * to clear (null when nothing is coming in). Pass ids to read only those.
     *
     * @param  list<int>|null  $landlordIds
     * @return list<array{landlord_id: int, name: string, owes_minor: int, since: ?string, last_collected_on: ?string, average_minor: int, months_to_clear: ?int}>
     */
    public static function advances(int $companyId, ?array $landlordIds = null): array
    {
        $below = array_filter(LandlordAccounts::balances($companyId), fn (int $b) => $b < 0);
        if ($landlordIds !== null) {
            $below = array_intersect_key($below, array_flip($landlordIds));
        }
        $names = $below === [] ? collect() : DB::table('rental_landlords')->where('company_id', $companyId)->where('is_self', false)->whereIn('id', array_keys($below))->pluck('name', 'id');
        $ids = array_map('intval', $names->keys()->all());
        if ($ids === []) {
            return [];
        }
        $days = DB::table('rental_entries')->where('company_id', $companyId)->whereIn('landlord_id', $ids)->where('landlord_minor', '!=', 0)->groupBy('landlord_id', 'occurred_on')
            ->orderBy('occurred_on')->selectRaw('landlord_id, occurred_on, SUM(landlord_minor) AS moved')->get()->groupBy('landlord_id');
        $collected = ['collected', 'credit_applied', 'deposit_applied', 'unapplied'];
        $last = DB::table('rental_entries')->where('company_id', $companyId)->whereIn('landlord_id', $ids)->where('type', 'collected')->whereNull('reversed_at')->groupBy('landlord_id')
            ->selectRaw('landlord_id, MAX(occurred_on) AS d')->pluck('d', 'landlord_id');
        $today = LocalDate::today($companyId);
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $from = $today->copy()->startOfMonth()->subMonthsNoOverflow(self::AVERAGE_MONTHS)->toDateString();
        $shares = DB::table('rental_entries as e')->leftJoin('rental_entries as o', 'o.id', '=', 'e.reverses_id')->where('e.company_id', $companyId)->whereIn('e.landlord_id', $ids)
            ->whereBetween('e.occurred_on', [$from, $today->toDateString()])->where(fn ($q) => $q->whereIn('e.type', $collected)->orWhere(fn ($w) => $w->where('e.type', 'reversal')->whereIn('o.type', $collected)))
            ->groupBy('e.landlord_id')->selectRaw('e.landlord_id, SUM(CASE WHEN e.occurred_on < ? THEN e.landlord_minor ELSE 0 END) AS whole, SUM(CASE WHEN e.occurred_on >= ? THEN e.landlord_minor ELSE 0 END) AS now', [$monthStart, $monthStart])->get()->keyBy('landlord_id');
        $out = [];
        foreach ($ids as $id) {
            $run = 0;
            $since = null;
            foreach ($days->get($id, collect()) as $d) {
                $before = $run;
                $run += (int) $d->moved;
                if ($before >= 0 && $run < 0) {
                    $since = substr((string) $d->occurred_on, 0, 10);
                }
            }
            // Whole months on the books within the window, so a new landlord is not averaged over empty months; one with none yet goes by this month so far.
            $first = substr((string) ($days->get($id, collect())->first()->occurred_on ?? $today->toDateString()), 0, 10);
            $whole = (int) min(self::AVERAGE_MONTHS, Carbon::parse($first)->startOfMonth()->diffInMonths($today->copy()->startOfMonth()));
            $share = $shares->get($id);
            $average = max(0, $whole > 0 ? intdiv((int) ($share->whole ?? 0), $whole) : (int) ($share->now ?? 0));
            $owes = -(int) $below[$id];
            $out[] = ['landlord_id' => $id, 'name' => (string) $names[$id], 'owes_minor' => $owes, 'since' => $since, 'last_collected_on' => isset($last[$id]) ? substr((string) $last[$id], 0, 10) : null,
                'average_minor' => $average, 'months_to_clear' => $average > 0 ? (int) ceil($owes / $average) : null];
        }
        usort($out, fn ($a, $b) => [$b['owes_minor'], $a['name']] <=> [$a['owes_minor'], $b['name']]);

        return $out;
    }

    /** "About 3 months", or that nothing is coming in. */
    public static function clearsWords(?int $months): string
    {
        return match (true) {
            $months === null => 'Nothing collected lately',
            $months === 1 => 'About a month',
            default => 'About '.number_format($months).' months',
        };
    }
}
