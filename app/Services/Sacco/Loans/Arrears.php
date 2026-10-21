<?php

namespace App\Services\Sacco\Loans;

use App\Models\SaccoLoan;
use App\Models\SaccoLoanChange;
use App\Models\SaccoLoanRepayment;
use App\Models\SaccoLoanScheduleLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Arrears, days past due and classification (04 §5.3, AK-40; SI 51 Tier 4 regs: the bands of
 * config('sacco.provisioning'), by days and by instalments, the worse wins).
 *
 * Everything is computed from the schedule and the repayments by their value date, so the figures for any day are
 * the same whether the job ran that day or caught up later. On entering substandard the loan is non-performing:
 * npl_since is set, the principal owing then becomes the cap on interest and fees charged afterwards (SI 108 reg. 7),
 * and later interest and penalty go to suspense. A non-performing or rescheduled loan is upgraded only after six
 * instalments paid on time, at most twice; until then it keeps its class.
 */
final class Arrears
{
    public const UPGRADE_AFTER = 6;

    public const MAX_UPGRADES = 2;

    /** The class for a number of days past due (config bands: performing 0, watch 1–60, substandard 61–90, doubtful 91–180, loss 181+). */
    public static function classFor(int $days): string
    {
        foreach ((array) config('sacco.provisioning') as [$class, $from, $to]) {
            if ($days >= $from && ($to === null || $days <= $to)) {
                return $class;
            }
        }

        return 'loss';
    }

    /** The provision rate of a class, in basis points. */
    public static function provisionBp(string $class): int
    {
        foreach ((array) config('sacco.provisioning') as [$c, , , $bp]) {
            if ($c === $class) {
                return (int) $bp;
            }
        }

        return 10000;
    }

    /** The first day of a band (61 for substandard), for dating the day a loan became non-performing. */
    public static function bandStart(string $class): int
    {
        foreach ((array) config('sacco.provisioning') as [$c, $from]) {
            if ($c === $class) {
                return (int) $from;
            }
        }

        return 0;
    }

    /**
     * A loan's position on a day, from the schedule and the repayments dated on or before it.
     *
     * @return array{arrears_principal: int, arrears_interest: int, dpd: int, instalments: int, oldest_due: ?string, class: string}
     */
    public static function position(SaccoLoan $loan, Carbon $on): array
    {
        $lines = self::lines($loan);
        [$paidP, $paidI] = self::paidBy($loan, $on);
        $dueP = 0;
        $dueI = 0;
        $cum = 0;
        $paid = $paidP + $paidI;
        $oldest = null;
        $count = 0;
        foreach ($lines as $l) {
            if ($l->due_on->gte($on)) {
                break;
            }
            $dueP += (int) $l->principal_due_minor;
            $dueI += $l->interestOwedMinor();
            $cum += (int) $l->principal_due_minor + $l->interestOwedMinor();
            if ($cum > $paid) {
                $oldest ??= $l->due_on->toDateString();
                $count++;
            }
        }
        $dpd = $oldest === null ? 0 : (int) Carbon::parse($oldest)->diffInDays($on);
        $byInstalments = $count * (LoanTerms::PERIOD_DAYS[$loan->loanTerms()->frequency] ?? 30);
        $worse = max($dpd, $count > 0 ? $byInstalments : 0);

        return ['arrears_principal' => max(0, $dueP - $paidP), 'arrears_interest' => max(0, $dueI - $paidI), 'dpd' => $dpd, 'instalments' => $count,
            'oldest_due' => $oldest, 'class' => self::classFor($worse)];
    }

    /** Overdue principal at the end of a day (principal due more than grace_days before it, less principal paid by then): the penalty's base. */
    public static function overduePrincipal(SaccoLoan $loan, \Carbon\CarbonInterface $on, int $graceDays, ?Collection $lines = null, ?Collection $payments = null): int
    {
        $due = 0;
        foreach ($lines ?? self::lines($loan) as $l) {
            if ($l->due_on->copy()->addDays($graceDays)->lt($on)) {
                $due += (int) $l->principal_due_minor;
            }
        }
        $paid = ($payments ?? self::payments($loan))->filter(fn ($p) => Carbon::parse($p->value_date)->lte($on))->sum('principal_minor');

        return max(0, $due - (int) $paid);
    }

    /** Refresh the loan's arrears figures and class for a day, under its lock (the daily run). @return array<string, mixed> */
    public function refresh(SaccoLoan $loan, Carbon $on): array
    {
        $pos = self::position($loan, $on);
        $before = (string) $loan->classification;
        $class = $pos['class'];
        $fill = ['arrears_principal_minor' => $pos['arrears_principal'], 'arrears_interest_minor' => $pos['arrears_interest'], 'days_past_due' => $pos['dpd'],
            'instalments_in_arrears' => $pos['instalments'], 'arrears_on' => $on->toDateString()];
        $rank = array_flip(SaccoLoan::CLASSES);
        $sticky = $loan->isNpl() || ((int) $loan->reschedule_count > 0 && $rank[$before] > 0);
        if ($rank[$class] < $rank[$before] && $sticky) {
            $good = $this->goodInstalments($loan, $on);
            $fill['good_instalments'] = $good;
            if ($good >= self::UPGRADE_AFTER && (int) $loan->upgrade_count < self::MAX_UPGRADES) {
                $fill['upgrade_count'] = (int) $loan->upgrade_count + 1;
                $this->changed($loan, 'upgrade', $before, $class, $on);
            } else {
                $class = $before; // keeps its class until it has performed
            }
        } elseif ($rank[$class] > $rank[$before]) {
            $this->changed($loan, 'downgrade', $before, $class, $on);
        }
        $fill['classification'] = $class;
        $npl = in_array($class, SaccoLoan::NPL, true);
        if ($npl && $loan->npl_since === null) {
            $since = $pos['oldest_due'] !== null ? Carbon::parse($pos['oldest_due'])->addDays(self::bandStart('substandard')) : $on->copy();
            $fill['npl_since'] = ($since->gt($on) ? $on : $since)->toDateString();
            $fill['npl_cap_minor'] = (int) $loan->principal_outstanding_minor;
            $fill['npl_charged_minor'] = 0;
            $fill['good_instalments'] = 0;
        } elseif (! $npl && $loan->npl_since !== null) {
            $fill['npl_since'] = null;
            $fill['npl_cap_minor'] = null;
            $fill['npl_charged_minor'] = 0;
        }
        $loan->fill($fill)->save();

        return $pos + ['classification' => $class];
    }

    /** Instalments due since the loan became non-performing (or was last rescheduled) and paid on or before their due date. */
    public function goodInstalments(SaccoLoan $loan, Carbon $on): int
    {
        $from = $loan->npl_since?->toDateString();
        $resched = SaccoLoanChange::withoutGlobalScopes()->where('loan_id', $loan->id)->where('kind', 'reschedule')->max('effective_on');
        if ($resched !== null && ($from === null || $resched > $from)) {
            $from = Carbon::parse($resched)->toDateString();
        }

        return SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $loan->schedule_version)
            ->when($from !== null, fn ($q) => $q->where('due_on', '>', $from))->where('due_on', '<=', $on->toDateString())
            ->where('status', 'paid')->whereColumn('paid_on', '<=', 'due_on')->count();
    }

    /** @return Collection<int, SaccoLoanScheduleLine> */
    public static function lines(SaccoLoan $loan): Collection
    {
        return SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $loan->schedule_version)->orderBy('installment_no')->get();
    }

    /** @return Collection<int, \stdClass> the current version's posted allocations (value date, principal, interest) */
    public static function payments(SaccoLoan $loan): Collection
    {
        return DB::table('sacco_loan_repayments')->where('loan_id', $loan->id)->where('schedule_version', $loan->schedule_version)->where('status', 'posted')
            ->get(['value_date', 'principal_minor', 'interest_minor', 'interest_suspense_minor', 'interest_to_day_minor']);
    }

    /** @return array{0: int, 1: int} principal and interest paid on the current version by the end of a day */
    private static function paidBy(SaccoLoan $loan, Carbon $on): array
    {
        $row = SaccoLoanRepayment::withoutGlobalScopes()->where('loan_id', $loan->id)->where('schedule_version', $loan->schedule_version)->where('status', 'posted')
            ->where('value_date', '<=', $on->toDateString())
            ->selectRaw('COALESCE(SUM(principal_minor),0) p, COALESCE(SUM(interest_minor + interest_suspense_minor + interest_to_day_minor),0) i')->first();

        return [(int) $row->p, (int) $row->i];
    }

    private function changed(SaccoLoan $loan, string $kind, string $from, string $to, Carbon $on): void
    {
        SaccoLoanChange::create(['company_id' => $loan->company_id, 'loan_id' => $loan->id, 'kind' => $kind, 'before' => ['classification' => $from],
            'after' => ['classification' => $to, 'days_past_due' => $loan->days_past_due], 'effective_on' => $on->toDateString()]);
    }
}
