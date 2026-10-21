<?php

namespace App\Services\Sacco\Loans;

use App\Models\SaccoLoan;
use App\Models\SaccoLoanChange;
use App\Models\SaccoLoanScheduleLine;
use App\Services\Sacco\Ledger\LoanPostingRules;
use App\Services\Sacco\Ledger\PostingService;
use App\Support\Sacco\Amount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Interest and penalty charged on loans (04 §5.3, AK-28, AK-39, AK-40; SI 108 reg. 4(5), 7, 9).
 *
 * - Interest falls due on each due date: on the reducing balance, the principal still owed at the start of the
 *   period (by value date) × the periodic rate, half-up once; flat, the scheduled figure. Key
 *   `int:{loan}:{version}:{n}`. No interest after the last due date (AK-87): overdue principal then carries the penalty.
 * - Penalty is charged monthly for the month before, on overdue principal only (past grace_days), pro rata by day:
 *   Σ overdue principal each day × the monthly penalty rate ÷ the days in the month, rounded once; never compounded.
 *   Key `pen:{loan}:{yyyymm}`. Office mode only (a group has fines).
 * - A non-performing loan's interest and penalty go to suspense (memorandum, no ledger line), and everything charged
 *   after it became non-performing stops at the principal owed then (the NPL cap).
 *
 * Each charge is idempotent by its key and by the line's charged mark, so a catch-up after missed nights charges each
 * period once. A closed or written-off loan is never charged again.
 */
final class Accruals
{
    /** Charge every instalment due on or before $on not yet charged. @return int interest charged (ledger or suspense) */
    public function interest(SaccoLoan $loan, Carbon $on): int
    {
        if ($loan->status !== 'active') {
            return 0;
        }
        $cid = (int) $loan->company_id;
        $terms = $loan->loanTerms();
        $lines = SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $loan->schedule_version)
            ->whereNull('interest_charged_minor')->where('due_on', '<=', $on->toDateString())->orderBy('installment_no')->lockForUpdate()->get();
        $total = 0;
        foreach ($lines as $line) {
            $start = $this->periodStart($loan, $line);
            $amount = $terms->isReducing()
                ? Amount::of($this->principalAt($loan, $start), (string) $loan->currency)->times($terms->periodicRatio())->minor
                : (int) $line->interest_due_minor;
            $amount = $this->capped($loan, $amount);
            $suspended = $loan->isNpl();
            $entryId = null;
            if ($amount > 0 && ! $suspended) {
                [$entryDate, $valueDate] = LoanBooks::dates($cid, $line->due_on);
                $entry = (new PostingService())->post((new LoanPostingRules($cid, LoanBooks::settings($cid)->mode))
                    ->interestDue('int:'.$loan->id.':'.$loan->schedule_version.':'.$line->installment_no, $loan, Amount::of($amount, (string) $loan->currency))
                    ->dated($entryDate, $valueDate)->source('sacco_loans', (int) $loan->id)->memo('Interest due, loan '.$loan->loan_no.' #'.$line->installment_no));
                $entryId = (int) $entry->id;
            } elseif ($amount > 0) {
                DB::table('sacco_loans')->where('id', $loan->id)->update(['interest_in_suspense_minor' => DB::raw('interest_in_suspense_minor + '.$amount), 'updated_at' => now()]);
            }
            $line->fill(['interest_charged_minor' => $amount, 'interest_suspended' => $suspended && $amount > 0, 'charge_entry_id' => $entryId])->save();
            $total += $amount;
            $loan->refresh();
        }
        if ($lines->isNotEmpty()) {
            Repayments::refreshLines($loan, $on->toDateString());
        }

        return $total;
    }

    /** Charge the penalty for one calendar month (YYYY-MM) that has ended before $on. @return int penalty charged */
    public function penalty(SaccoLoan $loan, string $month, Carbon $on): int
    {
        $cid = (int) $loan->company_id;
        $terms = $loan->loanTerms();
        $start = Carbon::parse($month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        if ($loan->penalty_through !== null && $loan->penalty_through >= $month) {
            return 0;
        }
        if ($end->gte($on) || ! in_array($loan->status, ['active'], true) || $terms->penaltyRateBp === 0 || LoanBooks::settings($cid)->isGroup()) {
            if ($end->lt($on)) {
                $loan->fill(['penalty_through' => $month])->save();
            }

            return 0;
        }
        $lines = Arrears::lines($loan);
        $payments = Arrears::payments($loan);
        $from = $loan->disbursed_on !== null && $loan->disbursed_on->gt($start) ? $loan->disbursed_on->copy() : $start->copy();
        $sum = '0';
        for ($d = $from->copy(); $d->lte($end); $d->addDay()) {
            $sum = bcadd($sum, (string) Arrears::overduePrincipal($loan, $d, $terms->graceDays, $lines, $payments), 0);
        }
        $ratio = bcdiv((string) $terms->penaltyRateBp, (string) (10000 * $start->daysInMonth), Schedule::SCALE);
        $amount = (int) bcadd(bcadd(bcmul($sum, $ratio, Schedule::SCALE), '0.5', Schedule::SCALE), '0', 0);
        $amount = $this->capped($loan, $amount);
        if ($amount > 0 && ! $loan->isNpl()) {
            [$entryDate, $valueDate] = LoanBooks::dates($cid, $end);
            (new PostingService())->post((new LoanPostingRules($cid, LoanBooks::settings($cid)->mode))
                ->penalty('pen:'.$loan->id.':'.str_replace('-', '', $month), $loan, Amount::of($amount, (string) $loan->currency))
                ->dated($entryDate, $valueDate)->source('sacco_loans', (int) $loan->id)->memo('Penalty for '.$month.', loan '.$loan->loan_no));
        } elseif ($amount > 0) {
            DB::table('sacco_loans')->where('id', $loan->id)->update(['penalty_in_suspense_minor' => DB::raw('penalty_in_suspense_minor + '.$amount), 'updated_at' => now()]);
        }
        $loan->refresh()->fill(['penalty_through' => $month])->save();

        return $amount;
    }

    /** The principal the loan owed at the end of a day, from its journal lines by value date. */
    public function principalAt(SaccoLoan $loan, Carbon $day): int
    {
        $account = (int) (\App\Models\SaccoLoanProduct::withoutGlobalScopes()->whereKey($loan->product_id)->value('portfolio_account_id'));
        $row = DB::table('sacco_journal_lines')->where('company_id', $loan->company_id)->where('sub_type', 'loan')->where('sub_id', $loan->id)
            ->where('ledger_account_id', $account)->where('value_date', '<=', $day->toDateString())
            ->selectRaw('COALESCE(SUM(debit_minor),0) dr, COALESCE(SUM(credit_minor),0) cr')->first();

        return max(0, (int) $row->dr - (int) $row->cr);
    }

    /** Where an instalment's period began: the previous due date, or the payout (or the reschedule) for the first. */
    private function periodStart(SaccoLoan $loan, SaccoLoanScheduleLine $line): Carbon
    {
        if ((int) $line->installment_no > 1) {
            $prev = SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $line->schedule_version)
                ->where('installment_no', (int) $line->installment_no - 1)->value('due_on');

            return Carbon::parse($prev)->startOfDay();
        }
        $resched = (int) $line->schedule_version > 1 ? SaccoLoanChange::withoutGlobalScopes()->where('loan_id', $loan->id)->where('kind', 'reschedule')
            ->where('to_version', $line->schedule_version)->value('effective_on') : null;

        return Carbon::parse($resched ?? $loan->disbursed_on)->startOfDay();
    }

    /** Within the NPL cap (SI 108 reg. 7): what may still be charged once non-performing, and count it. */
    private function capped(SaccoLoan $loan, int $amount): int
    {
        if ($amount <= 0 || $loan->npl_since === null || $loan->npl_cap_minor === null) {
            return max(0, $amount);
        }
        $room = max(0, (int) $loan->npl_cap_minor - (int) $loan->npl_charged_minor);
        $amount = min($amount, $room);
        if ($amount > 0) {
            DB::table('sacco_loans')->where('id', $loan->id)->update(['npl_charged_minor' => DB::raw('npl_charged_minor + '.$amount)]);
            $loan->npl_charged_minor = (int) $loan->npl_charged_minor + $amount;
            $loan->syncOriginalAttribute('npl_charged_minor');
        }

        return $amount;
    }
}
