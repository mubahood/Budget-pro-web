<?php

namespace App\Services\Sacco\Loans;

use App\Models\SaccoLoan;
use App\Models\SaccoLoanScheduleLine;
use App\Models\SaccoMember;
use App\Services\Sacco\SaccoTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The loan book's daily run (sacco:daily at 01:30, sacco:month-end on the 1st; 04 §16): for each open loan, every day
 * since it was last brought up to date that matters (a due date, the 1st of a month, today), in order: interest due,
 * the previous month's penalty on the 1st, arrears and class as of that day; then, on each 1st, the month's
 * provisioning (registered by Provisioning). A night missed catches up month by month and never charges twice (the
 * MobiSave catch-up). Reminders go out only for today: a due reminder 3 days before, an overdue notice on days 1, 7
 * and 30 (borrower only), and the guarantors' exposure notice on day 30.
 */
final class LoanJobs
{
    /** @var array<string, callable(int, string): void> company-level work for a month that has just ended (YYYY-MM) */
    private static array $monthEnd = [];

    /** @param  callable(int, string): void  $job */
    public static function onMonthEnd(string $key, callable $job): void
    {
        self::$monthEnd[$key] = $job;
    }

    /** Bring a SACCO's loans up to $upTo (today by default). @return array{days: int, loans: int, interest_minor: int, penalty_minor: int} */
    public function run(int $companyId, ?string $upTo = null): array
    {
        $today = LoanBooks::today($companyId);
        $end = $upTo !== null ? Carbon::parse($upTo)->startOfDay() : $today;
        if ($end->gt($today)) {
            $end = $today;
        }
        $loans = SaccoLoan::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'active')->where('is_deleted', 0)->orderBy('id')->get();
        $days = [];
        foreach ($loans as $loan) {
            $from = Carbon::parse($loan->arrears_on ?? $loan->disbursed_on)->startOfDay();
            foreach (SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $loan->schedule_version)
                ->where('due_on', '>', $from->toDateString())->where('due_on', '<=', $end->toDateString())->pluck('due_on') as $due) {
                $days[Carbon::parse($due)->toDateString()] = true;
            }
            for ($m = $from->copy()->startOfMonth()->addMonthNoOverflow(); $m->lte($end); $m->addMonthNoOverflow()) {
                $days[$m->toDateString()] = true;
            }
            if ($from->lt($end)) {
                $days[$end->toDateString()] = true;
            }
        }
        $last = SaccoLoan::withoutGlobalScopes()->where('company_id', $companyId)->max('arrears_on');
        for ($m = Carbon::parse($last ?? $end)->startOfMonth()->addMonthNoOverflow(); $m->lte($end); $m->addMonthNoOverflow()) {
            $days[$m->toDateString()] = true; // a month end with no open loan still gets its provisioning run
        }
        ksort($days);
        $out = ['days' => count($days), 'loans' => $loans->count(), 'interest_minor' => 0, 'penalty_minor' => 0];
        foreach (array_keys($days) as $day) {
            $d = Carbon::parse($day);
            foreach ($loans as $loan) {
                [$i, $p] = $this->day($companyId, (int) $loan->id, $d, $d->equalTo($today));
                $out['interest_minor'] += $i;
                $out['penalty_minor'] += $p;
            }
            if ($d->day === 1) {
                foreach (self::$monthEnd as $job) {
                    $job($companyId, $d->copy()->subMonthNoOverflow()->format('Y-m'));
                }
            }
        }

        return $out;
    }

    /** One loan, one day, in one transaction under the ledger lock. @return array{0: int, 1: int} interest and penalty charged */
    public function day(int $companyId, int $loanId, Carbon $day, bool $notify = false): array
    {
        return DB::transaction(function () use ($companyId, $loanId, $day, $notify) {
            SaccoTransactions::lockHead($companyId);
            $loan = LoanBooks::lock($companyId, $loanId);
            if ($loan->status !== 'active' || $loan->disbursed_on === null || $loan->disbursed_on->gt($day) || ($loan->arrears_on !== null && $loan->arrears_on->gte($day))) {
                return [0, 0];
            }
            $penalty = 0;
            if ($day->day === 1) {
                $penalty = (new Accruals())->penalty($loan, $day->copy()->subMonthNoOverflow()->format('Y-m'), $day);
                $loan->refresh();
            }
            $interest = (new Accruals())->interest($loan, $day);
            $loan->refresh();
            $pos = (new Arrears())->refresh($loan, $day);
            if ($notify) {
                $this->notices($loan->fresh(), $day, $pos);
            }

            return [$interest, $penalty];
        });
    }

    /** @param array<string, mixed> $pos */
    private function notices(SaccoLoan $loan, Carbon $day, array $pos): void
    {
        $cid = (int) $loan->company_id;
        $soon = SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $loan->schedule_version)
            ->where('due_on', $day->copy()->addDays(3)->toDateString())->where('status', '!=', 'paid')->first();
        if ($soon !== null) {
            LoanNotices::toMember($cid, (int) $loan->member_id, 'due_reminder', ['due' => LoanBooks::fmt($cid, (int) $soon->principal_due_minor + $soon->interestOwedMinor()),
                'loan' => (string) $loan->loan_no, 'date' => $soon->due_on->toDateString()]);
        }
        $dpd = (int) $pos['dpd'];
        if (in_array($dpd, [1, 7, 30], true)) {
            LoanNotices::toMember($cid, (int) $loan->member_id, 'overdue_notice', ['loan' => (string) $loan->loan_no, 'days' => $dpd,
                'arrears' => LoanBooks::fmt($cid, (int) $pos['arrears_principal'] + (int) $pos['arrears_interest'])]);
        }
        if ($dpd === 30) {
            $borrower = SaccoMember::withoutGlobalScopes()->find($loan->member_id);
            foreach ($loan->guarantors()->withoutGlobalScopes()->where('consent_status', 'consented')->get() as $g) {
                LoanNotices::toMember($cid, (int) $g->guarantor_member_id, 'guarantor_exposure', ['borrower' => (string) ($borrower?->first_name ?? ''),
                    'amount' => LoanBooks::fmt($cid, (int) $g->held_minor)]);
            }
        }
    }
}
