<?php

namespace App\Services\Sacco\Reports;

use App\Models\SaccoLedgerAccount;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\YearEnd\FiscalCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The books summed by statement line (P4.1, 04 §12 "UMRA mapping"): each account's balance from its journal lines by
 * entry date (never stored totals), put on a line by its `umra_line` (UmraLines). This is what the statement of
 * financial position, the income statement, the UMRA forms and the ratios read.
 *
 * The year-end close (kind `year_end`) moves income and expenses to 3410; the income statement leaves those entries
 * out, so a closed year still shows its income. On the position, "current year" is the financial year to date
 * (income less expenses since the year began, the close left out), and everything else that is surplus (3410 waiting
 * for the AGM, a year never closed) sits in retained earnings, so the position always balances.
 */
final class Figures
{
    /** @return array<int, SaccoLedgerAccount> */
    public static function chart(int $companyId): array
    {
        return SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('sort')->get()->keyBy('id')->all();
    }

    /**
     * Balances on the accounts' normal sides at the end of a day (every line up to it).
     *
     * @return array<int, int> account id => balance
     */
    public static function balances(int $companyId, CarbonInterface|string $asOf): array
    {
        $to = Carbon::parse($asOf)->toDateString();
        $chart = self::chart($companyId);
        $out = [];
        foreach (DB::table('sacco_journal_lines')->where('company_id', $companyId)->where('entry_date', '<=', $to)->groupBy('ledger_account_id')
            ->selectRaw('ledger_account_id, SUM(debit_minor) AS dr, SUM(credit_minor) AS cr')->get() as $r) {
            if (isset($chart[(int) $r->ledger_account_id])) {
                $out[(int) $r->ledger_account_id] = PostingService::signed($chart[(int) $r->ledger_account_id], (int) $r->dr, (int) $r->cr);
            }
        }

        return $out;
    }

    /**
     * Each income and expense account's movement between two days, the year-end close left out (positive = its normal side).
     *
     * @return array<int, int>
     */
    public static function movements(int $companyId, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $chart = self::chart($companyId);
        $out = [];
        foreach (DB::table('sacco_journal_lines as l')->join('sacco_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('l.company_id', $companyId)
            ->whereBetween('l.entry_date', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()])->where('e.kind', '!=', 'year_end')
            ->groupBy('l.ledger_account_id')->selectRaw('l.ledger_account_id, SUM(l.debit_minor) AS dr, SUM(l.credit_minor) AS cr')->get() as $r) {
            $a = $chart[(int) $r->ledger_account_id] ?? null;
            if ($a !== null && in_array($a->type, ['income', 'expense'], true)) {
                $out[(int) $a->id] = PostingService::signed($a, (int) $r->dr, (int) $r->cr);
            }
        }

        return $out;
    }

    /**
     * The statement of financial position at the end of a day: every Form 5 line (UmraLines::POSITION), with the
     * financial year to date as the current-year surplus.
     *
     * @return array{as_of: string, year_start: string, lines: array<string, int>, accounts: array<string, list<array{code: string, name: string, minor: int}>>, totals: array<string, int>}
     */
    public static function position(int $companyId, CarbonInterface|string $asOf): array
    {
        $day = Carbon::parse($asOf)->startOfDay();
        $yearStart = FiscalCalendar::startOf($companyId, $day);
        $chart = self::chart($companyId);
        $balances = self::balances($companyId, $day);
        $lines = array_fill_keys(array_keys(UmraLines::POSITION), 0);
        $accounts = [];
        $unclosed = 0; // income less expenses still in the income and expense accounts (all years)
        foreach ($balances as $id => $b) {
            $a = $chart[$id];
            if ($a->type === 'income') {
                $unclosed += $b;

                continue;
            }
            if ($a->type === 'expense') {
                $unclosed -= $b;

                continue;
            }
            $key = UmraLines::positionLine($a);
            // a contra account (the allowance, depreciation) is credit-normal under assets: it takes away
            $signed = $a->type === 'asset' && ! $a->isDebitNormal() ? -$b : $b;
            $lines[$key] += $signed;
            if ($b !== 0) {
                $accounts[$key][] = ['code' => (string) $a->code, 'name' => (string) $a->name, 'minor' => $signed];
            }
        }
        $current = 0;
        foreach (self::movements($companyId, $yearStart, $day) as $id => $m) {
            $current += $chart[$id]->type === 'income' ? $m : -$m;
        }
        $lines['current_surplus'] = $current;
        $lines['retained_prior'] += $unclosed - $current;
        $totals = ['assets' => 0, 'liabilities' => 0, 'equity' => 0];
        foreach (UmraLines::POSITION as $key => [$group]) {
            $totals[$group] += $lines[$key];
        }
        $totals['liabilities_and_equity'] = $totals['liabilities'] + $totals['equity'];

        return ['as_of' => $day->toDateString(), 'year_start' => $yearStart->toDateString(), 'lines' => $lines, 'accounts' => $accounts, 'totals' => $totals];
    }

    /**
     * The statement of comprehensive income between two days: every Form 6 line (UmraLines::INCOME).
     *
     * @return array{from: string, to: string, lines: array<string, int>, accounts: array<string, list<array{code: string, name: string, minor: int}>>, totals: array<string, int>}
     */
    public static function income(int $companyId, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $chart = self::chart($companyId);
        $lines = array_fill_keys(array_keys(UmraLines::INCOME), 0);
        $accounts = [];
        foreach (self::movements($companyId, $from, $to) as $id => $m) {
            $a = $chart[$id];
            $key = UmraLines::incomeLine($a);
            $lines[$key] += $m;
            if ($m !== 0) {
                $accounts[$key][] = ['code' => (string) $a->code, 'name' => (string) $a->name, 'minor' => $m];
            }
        }
        $t = ['income' => 0, 'expenses' => 0];
        foreach (UmraLines::INCOME as $key => [$group]) {
            $t[$group === 'income' ? 'income' : 'expenses'] += $lines[$key];
        }
        $t['net_surplus'] = $t['income'] - $t['expenses'];

        return ['from' => Carbon::parse($from)->toDateString(), 'to' => Carbon::parse($to)->toDateString(), 'lines' => $lines, 'accounts' => $accounts, 'totals' => $t];
    }

    /**
     * Form 3's capital (SI 51 reg. 27–28): core capital is paid-up shares, the reserves, retained earnings and half the
     * year's surplus to date (a loss counts in full), less investments in other institutions' equity; institutional
     * capital is core capital less the shares.
     *
     * @param  array{lines: array<string, int>}  $position
     * @return array{share_capital: int, statutory_reserve: int, retained: int, current_half: int, grants: int, general_reserves: int, other_reserves: int, subtotal: int, deductions: int, core: int, institutional: int}
     */
    public static function capital(array $position): array
    {
        $l = $position['lines'];
        $current = $l['current_surplus'] > 0 ? intdiv($l['current_surplus'], 2) : $l['current_surplus'];
        $sub = $l['share_capital'] + $l['statutory_reserve'] + $l['retained_prior'] + $current + $l['grants'] + $l['general_reserves'] + $l['other_reserves'];
        $deductions = 0; // investments in subsidiaries and other institutions' equity: no such account in the standard chart

        return ['share_capital' => $l['share_capital'], 'statutory_reserve' => $l['statutory_reserve'], 'retained' => $l['retained_prior'], 'current_half' => $current,
            'grants' => $l['grants'], 'general_reserves' => $l['general_reserves'], 'other_reserves' => $l['other_reserves'], 'subtotal' => $sub,
            'deductions' => $deductions, 'core' => $sub - $deductions, 'institutional' => $sub - $deductions - $l['share_capital']];
    }

    /** A ratio in basis points, rounded half-up (null when the denominator is not positive). */
    public static function bp(int $numerator, int $denominator): ?int
    {
        if ($denominator <= 0) {
            return null;
        }
        $neg = $numerator < 0;
        $x = bcdiv(bcmul((string) abs($numerator), '10000', 0), (string) $denominator, 6);

        return ($neg ? -1 : 1) * (int) bcadd($x, '0.5', 0);
    }
}
