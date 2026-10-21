<?php

namespace App\Services\Sacco\YearEnd;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoFiscalYear;
use App\Models\SaccoPeriod;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\Reports\Figures;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoAudit;
use App\Services\Sacco\SaccoTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The year-end close (P4.7, ADR 0009 §8, 04 §5.1): every income and expense account's movement for the financial year
 * moves to 3410 Current-year surplus in one entry, key `close:{year}`, so 3410 holds the year's net surplus (or deficit)
 * until the AGM appropriates it (Appropriation).
 *
 * A year closes when it has ended, every month of it that exists is closed (the books are final: nothing can post
 * into it any more), any earlier year with income or expenses is closed, and the ledger verifies. An officer with
 * sacco_accounts asks; two officers holding the chair's, vice chair's or treasurer's office approve (`year_end_close`).
 * The request freezes the year's figures; if they differ at execution the close is refused.
 *
 * The close posts on the day given as `on` (default: the day it executes; a later day than the year's end, in an open
 * month), value date the year's last day. The income statement leaves the close out, so the closed year still shows
 * its income (Reports\Figures). Office mode only: a savings group closes its cycle with the share-out (P5).
 */
final class Closing
{
    /**
     * The year's figures as they stand.
     *
     * @return array{year: int, starts_on: string, ends_on: string, income_minor: int, expense_minor: int, surplus_minor: int, accounts: list<array{account_id: int, code: string, type: string, minor: int}>}
     */
    public function preview(int $companyId, int $year): array
    {
        [$from, $to] = FiscalCalendar::bounds($companyId, $year);
        $chart = Figures::chart($companyId);
        $income = 0;
        $expense = 0;
        $accounts = [];
        foreach (Figures::movements($companyId, $from, $to) as $id => $m) {
            if ($m === 0) {
                continue;
            }
            $a = $chart[$id];
            $a->type === 'income' ? $income += $m : $expense += $m;
            $accounts[] = ['account_id' => (int) $id, 'code' => (string) $a->code, 'type' => (string) $a->type, 'minor' => $m];
        }
        usort($accounts, fn ($x, $y) => strcmp($x['code'], $y['code']));

        return ['year' => $year, 'starts_on' => $from->toDateString(), 'ends_on' => $to->toDateString(), 'income_minor' => $income, 'expense_minor' => $expense,
            'surplus_minor' => $income - $expense, 'accounts' => $accounts];
    }

    /** Ask for the close. @param array{on?: ?string} $opts on: the day the close is posted (a backdated history) */
    public function request(Company $company, int $year, User $maker, array $opts = [], ?string $uuid = null): SaccoApproval
    {
        SaccoAccess::authorize($maker, 'sacco_accounts');
        $cid = (int) $company->id;
        $this->assertCanClose($cid, $year);
        $p = $this->preview($cid, $year);
        $on = $this->postingDay($cid, $year, $opts['on'] ?? null);

        return (new SaccoApprovals())->request($company, 'year_end_close', ['year' => $year, 'starts_on' => $p['starts_on'], 'ends_on' => $p['ends_on'],
            'income_minor' => $p['income_minor'], 'expense_minor' => $p['expense_minor'], 'surplus_minor' => $p['surplus_minor'], 'on' => $on], $maker, $uuid ?? (string) Str::uuid());
    }

    /** The approved close (inside the approval's transaction). @return array<string, mixed> */
    public function execute(SaccoApproval $approval, User $checker): array
    {
        $cid = (int) $approval->company_id;
        $pl = (array) $approval->payload;
        $year = (int) $pl['year'];
        SaccoTransactions::lockHead($cid);
        $fy = $this->fiscalYear($cid, $year, true);
        if ($fy->status === 'closed') {
            return ['fiscal_year_id' => (int) $fy->id, 'entry_id' => $fy->close_entry_id, 'surplus_minor' => (int) $fy->net_surplus_minor];
        }
        $this->assertCanClose($cid, $year);
        $p = $this->preview($cid, $year);
        if ($p['income_minor'] !== (int) $pl['income_minor'] || $p['expense_minor'] !== (int) $pl['expense_minor']) {
            throw BusinessRuleException::make('figures_changed', 'The year\'s figures changed since the close was asked: ask again.');
        }
        $entryId = null;
        if ($p['accounts'] !== []) {
            $draft = JournalDraft::make($cid, 'year_end', 'close:'.$year)->source('sacco_fiscal_years', (int) $fy->id)->approval((int) $approval->id)->by((int) $checker->id)
                ->memo('Year-end close '.$fy->label());
            foreach ($p['accounts'] as $a) {
                $amount = LoanBooks::money($cid, abs($a['minor']));
                // income (credit-normal) is debited by its balance, an expense credited; an account the wrong way round the other way
                ($a['type'] === 'income') === ($a['minor'] > 0) ? $draft->debit($a['account_id'], $amount) : $draft->credit($a['account_id'], $amount);
            }
            $surplus = $p['surplus_minor'];
            if ($surplus !== 0) {
                $current = ChartOfAccounts::id($cid, 're.current');
                $surplus > 0 ? $draft->credit($current, LoanBooks::money($cid, $surplus), memo: 'Net surplus') : $draft->debit($current, LoanBooks::money($cid, -$surplus), memo: 'Net deficit');
            }
            [$entry] = LoanBooks::dates($cid, (string) $pl['on']);
            $entryId = (int) (new PostingService())->post($draft->dated($entry, $p['ends_on']))->id;
        }
        $fy->fill(['status' => 'closed', 'income_minor' => $p['income_minor'], 'expense_minor' => $p['expense_minor'], 'net_surplus_minor' => $p['surplus_minor'],
            'close_entry_id' => $entryId, 'approval_id' => $approval->id, 'closed_on' => LoanBooks::today($cid)->toDateString(), 'closed_by_id' => $checker->id])->save();
        SaccoAudit::record('sacco.year.closed', $fy, $cid, ['year' => $year, 'surplus_minor' => $p['surplus_minor'], 'entry_id' => $entryId], (int) $checker->id);

        return ['fiscal_year_id' => (int) $fy->id, 'entry_id' => $entryId, 'surplus_minor' => $p['surplus_minor']];
    }

    /** The year's row, made on first use. */
    public function fiscalYear(int $companyId, int $year, bool $lock = false): SaccoFiscalYear
    {
        [$from, $to] = FiscalCalendar::bounds($companyId, $year);
        $q = SaccoFiscalYear::withoutGlobalScopes()->where('company_id', $companyId)->where('year', $year);
        $fy = ($lock ? $q->lockForUpdate() : $q)->first();
        if ($fy !== null) {
            return $fy;
        }
        try {
            SaccoFiscalYear::create(['company_id' => $companyId, 'year' => $year, 'starts_on' => $from->toDateString(), 'ends_on' => $to->toDateString(), 'status' => 'open']);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // a twin made it first
        }
        $q = SaccoFiscalYear::withoutGlobalScopes()->where('company_id', $companyId)->where('year', $year);

        return ($lock ? $q->lockForUpdate() : $q)->firstOrFail();
    }

    /** Whether a year is closed (its row says so). */
    public static function isClosed(int $companyId, int $year): bool
    {
        return SaccoFiscalYear::withoutGlobalScopes()->where('company_id', $companyId)->where('year', $year)->where('status', 'closed')->exists();
    }

    private function assertCanClose(int $cid, int $year): void
    {
        if (LoanBooks::settings($cid)->isGroup()) {
            throw BusinessRuleException::make('not_in_group_mode', 'A savings group closes its cycle with the share-out, not a year-end close.');
        }
        [$from, $to] = FiscalCalendar::bounds($cid, $year);
        if ($to->gte(LoanBooks::today($cid))) {
            throw BusinessRuleException::make('year_not_ended', 'The financial year '.$year.' has not ended yet.');
        }
        if (self::isClosed($cid, $year)) {
            throw BusinessRuleException::make('year_closed', 'The financial year '.$year.' is already closed.');
        }
        $open = SaccoPeriod::withoutGlobalScopes()->where('company_id', $cid)->whereBetween('starts_on', [$from->toDateString(), $to->toDateString()])
            ->where('status', '!=', 'closed')->orderBy('starts_on')->first();
        if ($open !== null) {
            throw BusinessRuleException::make('period_open', 'Close every month of the year first: '.$open->label().' is still open.');
        }
        if (! SaccoPeriod::withoutGlobalScopes()->where('company_id', $cid)->whereBetween('starts_on', [$from->toDateString(), $to->toDateString()])->exists()) {
            throw BusinessRuleException::make('nothing_to_close', 'The books have no month in the financial year '.$year.'.');
        }
        [$prevFrom, $prevTo] = FiscalCalendar::bounds($cid, $year - 1);
        if (! self::isClosed($cid, $year - 1) && Figures::movements($cid, $prevFrom, $prevTo) !== []) {
            throw BusinessRuleException::make('earlier_year_open', 'Close the financial year '.($year - 1).' first: years close in order.');
        }
        $verify = (new LedgerVerifier())->verify($cid);
        if (! $verify['ok']) {
            throw BusinessRuleException::make('ledger_not_verified', 'The ledger check failed, so the year cannot close: '.implode(', ', LedgerVerifier::failed($verify)).'.');
        }
    }

    /** The day the close posts: after the year's end, not in the future (today when not given). */
    private function postingDay(int $cid, int $year, ?string $on): string
    {
        [, $to] = FiscalCalendar::bounds($cid, $year);
        $day = $on !== null ? Carbon::parse($on)->startOfDay() : LoanBooks::today($cid);
        if ($day->lte($to) || $day->gt(LoanBooks::today($cid))) {
            throw BusinessRuleException::make('invalid_date', 'The close is posted after the year has ended, and not in the future.');
        }

        return $day->toDateString();
    }
}
