<?php

namespace App\Services\Rentals\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLandlord;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Payments\CashGuard;
use App\Services\Rentals\RentalsModule;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Whose money the cash is (`money_position`, LC-12), a landlord's year (`landlord_year`, LC-18) and the accountant's journal and trial balance
 * (`journal_export`, `trial_balance`, LC-19, in JournalExport). All read the journal, nothing is stored. The year is a summary of money handled: it
 * states no tax rule and works out no tax, and says an accountant should check it.
 */
final class LandlordReports
{
    use ReportTable;

    /** Rows of the journal that are money received or put against a bill (LandlordAccounts::statement counts them the same way). */
    private const COLLECTION_KINDS = ['collected', 'credit_applied', 'deposit_applied', 'unapplied'];

    private const MONTHS = ['01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr', '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Aug', '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec'];

    /** @return array<string, mixed> */
    public function moneyPosition(object $x): array
    {
        $on = $x->asOf->toDateString();
        $p = MoneyPosition::asOf($x->cid, $on);
        $rows = [['line' => 'Owed to landlords', 'amount_minor' => $p['owed_to_landlords_minor']], ['line' => 'Landlords owe you (advances)', 'amount_minor' => -$p['owed_back_by_landlords_minor']],
            ['line' => 'Deposits you hold', 'amount_minor' => $p['deposits_minor']], ['line' => 'Tenants\' rent paid ahead', 'amount_minor' => $p['credit_minor']],
            ['line' => 'The agency\'s own money', 'amount_minor' => $p['agency_own_minor']]];
        $byMethod = DB::table('rental_entries')->where('company_id', $x->cid)->where('occurred_on', '<=', $on)->whereNotNull('method')->groupBy('method')
            ->selectRaw('method, SUM(cash_minor) AS total')->pluck('total', 'method');
        $where = [];
        foreach (CashGuard::LABELS as $method => $label) {
            if ((int) ($byMethod[$method] ?? 0) !== 0) {
                $where[] = ['account' => $label, 'amount_minor' => (int) $byMethod[$method]];
            }
        }
        $notes = ['Deposits a landlord holds never came into your cash, so they are in no figure here.'];
        if ($p['agency_own_minor'] < 0) {
            array_unshift($notes, 'You have spent '.Minor::format(-$p['agency_own_minor'], $x->cid).' of money held for others.');
        }

        return ['subtitle' => 'As of '.Carbon::parse($on)->format('j M Y'), 'from' => $on, 'to' => $on, 'total_label' => 'Cash in all accounts',
            'columns' => [self::c('line', 'Line'), self::c('amount_minor', 'Amount', 'money')], 'rows' => $rows,
            'totals' => ['amount_minor' => $p['cash_minor'], 'held_for_others_minor' => $p['held_for_others_minor']],
            'sections' => [['title' => 'Where the cash is', 'columns' => [self::c('account', 'Account'), self::c('amount_minor', 'Amount', 'money')], 'rows' => $where, 'totals' => ['amount_minor' => $p['cash_minor']]], self::notes($notes)]];
    }

    /** @return array<string, mixed> */
    public function landlordYear(object $x): array
    {
        $landlord = $x->landlord ?? ($x->owner ? RentalsModule::selfLandlord($x->cid) : null)
            ?? throw BusinessRuleException::make('landlord_required', 'Choose the landlord.');
        $year = substr((string) $x->from, 0, 4);
        $thisMonth = $x->today->format('Y-m');
        $opening = LandlordAccounts::balance($landlord, Carbon::parse((string) $x->from)->subDay()->toDateString());
        ['months' => $byMonth, 'properties' => $byProperty] = $this->yearLines($landlord, (string) $x->from, (string) $x->to);
        $closing = $opening;
        $rows = [];
        $sum = array_fill_keys(['collected_minor', 'commission_minor', 'deposits_minor', 'expenses_minor', 'payouts_minor', 'other_minor'], 0);
        foreach (array_keys(self::MONTHS) as $mm) {
            if ($year.'-'.$mm > $thisMonth) {
                break;
            }
            $line = array_replace(array_fill_keys(array_keys($sum), 0), $byMonth[$mm] ?? []);
            $change = (int) ($line['change_minor'] ?? 0);
            unset($line['change_minor']);
            foreach ($line as $k => $v) {
                $sum[$k] += $v;
            }
            $closing += $change;
            $rows[] = ['month' => Carbon::parse($year.'-'.$mm.'-01')->format('F Y')] + $line + ['closing_minor' => $closing];
        }

        return [
            'subtitle' => ($x->owner ? '' : $landlord->name.' · ').'Year '.$year.($year === $x->today->format('Y') ? ', to '.$x->today->format('j M Y') : ''),
            'total_label' => 'The year',
            'columns' => [self::c('month', 'Month'), self::c('collected_minor', 'Rent and fees collected', 'money'), self::c('commission_minor', 'Commission', 'money'),
                self::c('deposits_minor', $x->owner ? 'Deposits' : 'Deposits passed to the landlord', 'money'), self::c('expenses_minor', 'Expenses', 'money'),
                self::c('payouts_minor', $x->owner ? 'Owner drawings' : 'Paid to the landlord', 'money'), self::c('other_minor', 'Other', 'money'), self::c('closing_minor', 'Balance at the end', 'money')],
            'rows' => $rows,
            'totals' => $sum + ['closing_minor' => $closing, 'opening_minor' => $opening],
            'sections' => [$this->byProperty($landlord, $byProperty), $this->byCategory($landlord, (string) $x->from, (string) $x->to), self::notes([
                ...($x->owner ? [] : [$this->newLets($x, (int) $landlord->id)]),
                ...$this->directNote($landlord, (string) $x->from, (string) $x->to),
                'A summary of money handled, not tax advice. It states no tax rule and works out no tax. Ask an accountant to check it before you rely on it.',
                'Cash basis: rent and fees count when the money was received, or when money already held was put against a bill. Expenses count when they were paid.',
                'An expense under "Rental income tax" is only what was paid under that name in the year. It is not a tax worked out by this system.',
            ])],
            'landlord' => ['id' => (int) $landlord->id, 'name' => (string) $landlord->name],
        ];
    }

    /** What tenants paid the landlord directly in the year: no part of "collected", said once (LC-20). @return list<string> */
    private function directNote(RentalLandlord $landlord, string $from, string $to): array
    {
        $direct = LandlordAccounts::directBy((int) $landlord->company_id, $from, $to)[(int) $landlord->id] ?? 0;

        return $direct === 0 ? [] : ['Tenants paid the landlord directly: '.Minor::format($direct, (int) $landlord->company_id).'. That money never came through the agency, so it is not in "collected". Commission on it is.'];
    }

    /** What the agency did for the landlord this year (LC-22): tenants it found, and tenants already living there when it took over. */
    private function newLets(object $x, int $landlordId): string
    {
        $counts = DB::table('rental_leases')->where('company_id', $x->cid)->where('landlord_id', $landlordId)->where('status', '!=', 'cancelled')->whereBetween('start_date', [$x->from, $x->to])
            ->groupBy('origin')->selectRaw('origin, COUNT(*) AS n')->pluck('n', 'origin');

        return 'New tenants found this year: '.(int) ($counts['new'] ?? 0).'. Tenants already living there when the agency took over: '.(int) ($counts['sitting'] ?? 0).'.';
    }

    /** @return array<string, mixed> */
    public function journalExport(object $x): array
    {
        return (new JournalExport)->journal($x);
    }

    /** @return array<string, mixed> */
    public function trialBalance(object $x): array
    {
        return (new JournalExport)->trial($x);
    }

    /**
     * A landlord's year as a file, for their own page or a link sent to them. Run with no staff login: the caller has already decided whose it is.
     *
     * @return array{0: string, 1: string, 2: string} bytes, content type, file name
     */
    public static function yearFile(RentalLandlord $landlord, string $year, string $format): array
    {
        $cid = (int) $landlord->company_id;
        $report = (new RentalReports)->run('landlord_year', $cid, null, ['landlord_id' => (int) $landlord->id, 'year' => $year]);

        return RentalExport::render($report, $cid, $format);
    }

    /**
     * A landlord's journal for the year, read once and added up as LandlordAccounts::statement adds up a period (a test holds the two together, for each of the
     * twelve months): the statement's totals per month, what moved their balance, and the rent and fees collected per property and month.
     *
     * @return array{months: array<string, array<string, int>>, properties: array<int, array<string, int>>}
     */
    private function yearLines(RentalLandlord $landlord, string $from, string $to): array
    {
        $rows = DB::table('rental_entries as e')->leftJoin('rental_entries as o', 'o.id', '=', 'e.reverses_id')
            ->leftJoin('rental_allocations as a', 'a.id', '=', DB::raw('COALESCE(o.allocation_id, e.allocation_id)'))->leftJoin('rental_charges as c', 'c.id', '=', 'a.charge_id')
            ->where('e.company_id', $landlord->company_id)->where('e.landlord_id', $landlord->id)->whereBetween('e.occurred_on', [$from, $to])->where('e.landlord_minor', '!=', 0)
            ->get(['e.occurred_on', 'e.property_id', 'e.type', 'o.type as original_type', 'e.landlord_minor', 'a.amount_minor', 'a.commission_minor', 'a.landlord_minor as share_minor', 'c.kind']);
        $months = [];
        $properties = [];
        $add = function (string $month, string $key, int $amount) use (&$months) {
            $months[$month][$key] = ($months[$month][$key] ?? 0) + $amount;
        };
        foreach ($rows as $r) {
            $reversal = $r->type === 'reversal' && $r->original_type !== null;
            $kind = $reversal ? $r->original_type : $r->type;
            $sign = $reversal ? -1 : 1;
            $amount = (int) $r->landlord_minor;
            $month = substr((string) $r->occurred_on, 5, 2);
            $add($month, 'change_minor', $amount);
            if (in_array($kind, self::COLLECTION_KINDS, true)) {
                if ((int) $r->share_minor > 0 && (int) $r->commission_minor === 0 && $r->kind === 'deposit') {
                    $add($month, 'deposits_minor', $amount);

                    continue;
                }
                $commission = $sign * (int) $r->commission_minor;
                $collected = $kind === 'deposit_applied' ? $amount + $commission : $sign * (int) $r->amount_minor;
                $add($month, 'collected_minor', $collected);
                $add($month, 'commission_minor', $commission);
                $properties[(int) $r->property_id][$month] = ($properties[(int) $r->property_id][$month] ?? 0) + $collected;
            } elseif ($kind === 'collected_direct') {
                $commission = $sign * (int) $r->commission_minor; // the money is with the landlord: only what the agency earned moved (LandlordAccounts::addToTotals)
                $add($month, 'commission_minor', $commission);
                $add($month, 'other_minor', $amount + $commission);
            } elseif ($kind === 'payout') {
                $add($month, 'payouts_minor', -$amount);
            } elseif ($kind === 'expense_landlord') {
                $add($month, 'expenses_minor', -$amount);
            } else {
                $add($month, 'other_minor', $amount);
            }
        }

        return ['months' => $months, 'properties' => $properties];
    }

    /**
     * Rent and fees collected, a line per property and a column per month with money in it. Its total equals the statements'.
     *
     * @param  array<int, array<string, int>>  $cells  property id => month => amount
     * @return array<string, mixed>
     */
    private function byProperty(RentalLandlord $landlord, array $cells): array
    {
        $cid = (int) $landlord->company_id;
        $names = $this->names('rental_properties', 'name', array_keys($cells), $cid);
        $months = [];
        foreach ($cells as $line) {
            foreach ($line as $month => $amount) {
                if ($amount !== 0) {
                    $months[$month] = true;
                }
            }
        }
        ksort($months);
        $columns = [self::c('property', 'Property')];
        foreach (array_keys($months) as $month) {
            $columns[] = self::c('m'.$month, self::MONTHS[sprintf('%02d', $month)], 'money');
        }
        $columns[] = self::c('total_minor', 'Total', 'money');
        $list = [];
        $totals = [];
        foreach ($cells as $property => $line) {
            if (array_sum($line) === 0 && array_filter($line) === []) {
                continue;
            }
            $row = ['property' => (string) ($names[$property] ?? 'No property'), 'total_minor' => array_sum($line)];
            foreach (array_keys($months) as $month) {
                $row['m'.$month] = (int) ($line[$month] ?? 0);
            }
            foreach ($row as $k => $v) {
                if ($k !== 'property') {
                    $totals[$k] = ($totals[$k] ?? 0) + $v;
                }
            }
            $list[] = $row;
        }
        usort($list, fn ($a, $b) => strnatcasecmp($a['property'], $b['property']));

        return ['title' => 'Rent and fees collected, by property', 'columns' => $columns, 'rows' => $list, 'totals' => $totals];
    }

    /** What was charged to the landlord, by what it was for. @return array<string, mixed> */
    private function byCategory(RentalLandlord $landlord, string $from, string $to): array
    {
        $rows = DB::table('rental_entries as e')->join('rental_expenses as r', 'r.id', '=', 'e.expense_id')->where('e.company_id', $landlord->company_id)->where('e.landlord_id', $landlord->id)
            ->whereBetween('e.occurred_on', [$from, $to])->where('e.landlord_minor', '!=', 0)->groupBy('r.category')->orderBy('r.category')
            ->selectRaw('r.category, SUM(-e.landlord_minor) AS amount')->get();
        $list = [];
        foreach ($rows as $r) {
            if ((int) $r->amount !== 0) {
                $list[] = ['category' => (string) $r->category, 'amount_minor' => (int) $r->amount];
            }
        }

        return ['title' => 'Expenses by what they were for', 'columns' => [self::c('category', 'What for'), self::c('amount_minor', 'Amount', 'money')], 'rows' => $list,
            'totals' => ['amount_minor' => array_sum(array_column($list, 'amount_minor'))]];
    }

    /** @param list<string> $lines @return array<string, mixed> */
    private static function notes(array $lines): array
    {
        return ['title' => 'Notes', 'columns' => [self::c('note', 'Note')], 'rows' => array_map(fn ($line) => ['note' => $line], $lines), 'totals' => []];
    }
}
