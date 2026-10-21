<?php

namespace App\Services\Sacco\Ledger;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoLoan;
use App\Services\Sacco\Loans\LoanLedger;
use App\Support\Sacco\Amount;

/**
 * Which accounts each loan event debits and credits (docs/akiba/04-architecture.md §5.1, the loan rows), beside the
 * P1 PostingRules and built the same way: each rule returns a JournalDraft for PostingService and nothing else
 * decides a loan posting's lines (AK-85). A loan's lines name it (sub_type `loan`) on 1300 (its product's portfolio
 * account), 1320, 1330 and 1340; a group's chart has no 1330, 1340, 1390, 4110, 4500 or 5200 (AK-58), so penalties,
 * provisions and write-offs are office mode only.
 */
final class LoanPostingRules
{
    /** @var array{principal: int, interest: int, penalty: ?int, fees: ?int} */
    private array $loanAccounts;

    public function __construct(private readonly int $companyId, private readonly string $mode)
    {
        $this->loanAccounts = LoanLedger::accounts($companyId);
    }

    public function account(string $systemKey): int
    {
        return ChartOfAccounts::id($this->companyId, $systemKey);
    }

    /** The account cash or bank money moves through (the office till or, in a group, the lockbox). */
    public function channelAccount(string $channel): int
    {
        return (new PostingRules($this->companyId, $this->mode))->channelAccount($channel);
    }

    /**
     * Paying a loan out: Dr 1300 the principal; Cr where the money goes (the member's savings, cash, bank, or 2160
     * Payouts in transit for MoMo) the principal less the charges; Cr 4200 the processing fee; Cr 2400 the insurance
     * premium held for the insurer.
     */
    public function disbursement(string $key, SaccoLoan $loan, Amount $principal, Amount $fee, Amount $insurance, ?SubRef $toSavings, ?int $toAccountId): JournalDraft
    {
        $net = $principal->minus($fee)->minus($insurance);
        if ($net->isNegative() || $net->isZero()) {
            throw BusinessRuleException::make('charges_exceed_loan', 'The charges would take the whole loan.');
        }
        $d = JournalDraft::make($this->companyId, 'loan_disbursement', $key)
            ->debit($this->portfolio($loan), $principal, 'loan', (int) $loan->id, (int) $loan->member_id);
        $d = $toSavings !== null ? $d->credit($toSavings->accountId, $net, $toSavings->type, $toSavings->id, $toSavings->memberId)
            : $d->credit((int) $toAccountId, $net, memberId: (int) $loan->member_id);
        if ($fee->isPositive()) {
            $d->credit($this->account('inc.loan_fees'), $fee, memberId: (int) $loan->member_id);
        }
        if ($insurance->isPositive()) {
            $d->credit($this->account('payables'), $insurance, memberId: (int) $loan->member_id, memo: 'Loan insurance premium');
        }

        return $d;
    }

    /** Interest falls due (performing loans): Dr 1320, Cr 4100. */
    public function interestDue(string $key, SaccoLoan $loan, Amount $amount): JournalDraft
    {
        return JournalDraft::make($this->companyId, 'interest_due', $key)->debit($this->loanAccounts['interest'], $amount, 'loan', (int) $loan->id, (int) $loan->member_id)
            ->credit($this->account('inc.interest'), $amount, memberId: (int) $loan->member_id);
    }

    /** Penalty on overdue principal (office mode): Dr 1330, Cr 4110. */
    public function penalty(string $key, SaccoLoan $loan, Amount $amount): JournalDraft
    {
        return JournalDraft::make($this->companyId, 'penalty', $key)->debit($this->penaltyAccount(), $amount, 'loan', (int) $loan->id, (int) $loan->member_id)
            ->credit($this->account('inc.penalty'), $amount, memberId: (int) $loan->member_id);
    }

    /**
     * A repayment (AK-27), split already: Dr the source (cash, bank, the member's or a guarantor's savings) the whole
     * amount; Cr 1330 penalty, 4110 suspended penalty, 1340 fees, 1320 interest, 4100 suspended interest and interest to
     * the day (recognised on receipt), 1300 principal; Cr the member's savings (or 2150 Unallocated receipts) whatever
     * is left over.
     *
     * @param  array{penalty: int, penalty_suspense: int, fees: int, interest: int, interest_suspense: int, interest_to_day: int, principal: int, overpayment: int}  $split
     */
    public function repayment(string $key, string $kind, SaccoLoan $loan, Amount $amount, ?SubRef $fromSavings, ?int $fromAccountId, array $split, ?SubRef $overpaymentTo): JournalDraft
    {
        $cur = $amount->currency;
        $m = fn (int $minor) => Amount::of($minor, $cur);
        $member = (int) $loan->member_id;
        $d = JournalDraft::make($this->companyId, $kind, $key);
        $d = $fromSavings !== null ? $d->debit($fromSavings->accountId, $amount, $fromSavings->type, $fromSavings->id, $fromSavings->memberId)
            : $d->debit((int) $fromAccountId, $amount, memberId: $member);
        $loanLine = fn (int $account, int $minor) => $minor > 0 ? $d->credit($account, $m($minor), 'loan', (int) $loan->id, $member) : $d;
        $plain = fn (int $account, int $minor, ?string $memo = null) => $minor > 0 ? $d->credit($account, $m($minor), memberId: $member, memo: $memo) : $d;
        if ($split['penalty'] > 0) {
            $loanLine($this->penaltyAccount(), $split['penalty']);
        }
        if ($split['penalty_suspense'] > 0) {
            $plain($this->account('inc.penalty'), $split['penalty_suspense'], 'Suspended penalty received');
        }
        if ($split['fees'] > 0) {
            $loanLine($this->loanAccounts['fees'] ?? throw BusinessRuleException::make('not_in_group_mode', 'A savings group has no loan fees receivable.'), $split['fees']);
        }
        $loanLine($this->loanAccounts['interest'], $split['interest']);
        $plain($this->account('inc.interest'), $split['interest_suspense'] + $split['interest_to_day'], 'Interest received');
        $loanLine($this->portfolio($loan), $split['principal']);
        if ($split['overpayment'] > 0) {
            $overpaymentTo !== null ? $d->credit($overpaymentTo->accountId, $m($split['overpayment']), $overpaymentTo->type, $overpaymentTo->id, $overpaymentTo->memberId)
                : $plain($this->account('suspense.receipts'), $split['overpayment'], 'Loan overpayment');
        }

        return $d;
    }

    /**
     * A loan cancelled in its cooling-off (SI 108 reg. 11): the member returns what they received plus the cancellation
     * fee; the principal comes off 1300, the processing fee and insurance are given back (Dr 4200, Dr 2400), any
     * interest already charged is taken back (Dr 4100, Cr 1320), and the cancellation fee is income (Cr 4200).
     */
    public function cancellation(string $key, SaccoLoan $loan, Amount $principal, Amount $feeRefund, Amount $insuranceRefund, Amount $cancellationFee, Amount $interestReversed,
        ?SubRef $fromSavings, ?int $fromAccountId): JournalDraft
    {
        $member = (int) $loan->member_id;
        $pays = $principal->minus($feeRefund)->minus($insuranceRefund)->plus($cancellationFee);
        $d = JournalDraft::make($this->companyId, 'loan_cancellation', $key);
        $d = $fromSavings !== null ? $d->debit($fromSavings->accountId, $pays, $fromSavings->type, $fromSavings->id, $fromSavings->memberId)
            : $d->debit((int) $fromAccountId, $pays, memberId: $member);
        $feeNet = $cancellationFee->minus($feeRefund);
        if ($feeNet->isNegative()) {
            $d->debit($this->account('inc.loan_fees'), $feeNet->negate(), memberId: $member, memo: 'Processing fee returned on cancellation');
        }
        if ($insuranceRefund->isPositive()) {
            $d->debit($this->account('payables'), $insuranceRefund, memberId: $member, memo: 'Insurance premium returned on cancellation');
        }
        if ($interestReversed->isPositive()) {
            $d->debit($this->account('inc.interest'), $interestReversed, memberId: $member)->credit($this->loanAccounts['interest'], $interestReversed, 'loan', (int) $loan->id, $member);
        }
        $d->credit($this->portfolio($loan), $principal, 'loan', (int) $loan->id, $member);
        if ($feeNet->isPositive()) {
            $d->credit($this->account('inc.loan_fees'), $feeNet, memberId: $member, memo: 'Cancellation fee');
        }

        return $d;
    }

    /** The loan-loss allowance moved to its required level: Dr 5200, Cr 1390 (or back when it falls). */
    public function provision(string $key, Amount $delta): JournalDraft
    {
        $this->office('provision');
        $d = JournalDraft::make($this->companyId, 'provision', $key);

        return $delta->isPositive()
            ? $d->debit($this->account('exp.provision'), $delta)->credit($this->account('loans.allowance'), $delta)
            : $d->debit($this->account('loans.allowance'), $delta->negate())->credit($this->account('exp.provision'), $delta->negate());
    }

    /**
     * A write-off: Cr 1300, 1320, 1330 and 1340 whatever is left on the loan; Dr 1390 as far as the allowance holds it and
     * 5200 for the rest (the loss not yet provided for).
     *
     * @param  array{principal: int, interest: int, penalty: int, fees: int}  $left
     */
    public function writeOff(string $key, SaccoLoan $loan, array $left, Amount $fromAllowance, string $currency): JournalDraft
    {
        $this->office('write_off');
        $m = fn (int $minor) => Amount::of($minor, $currency);
        $total = $left['principal'] + $left['interest'] + $left['penalty'] + $left['fees'];
        $member = (int) $loan->member_id;
        $d = JournalDraft::make($this->companyId, 'write_off', $key);
        $allowance = min($total, max(0, $fromAllowance->minor));
        if ($allowance > 0) {
            $d->debit($this->account('loans.allowance'), $m($allowance), memberId: $member);
        }
        if ($total - $allowance > 0) {
            $d->debit($this->account('exp.provision'), $m($total - $allowance), memberId: $member, memo: 'Loss not yet provided for');
        }
        foreach (['principal' => $this->portfolio($loan), 'interest' => $this->loanAccounts['interest'], 'penalty' => $this->loanAccounts['penalty'], 'fees' => $this->loanAccounts['fees']] as $part => $account) {
            if ($left[$part] > 0) {
                $d->credit((int) $account, $m($left[$part]), 'loan', (int) $loan->id, $member);
            }
        }

        return $d;
    }

    /** Money recovered on a written-off loan: Dr the source (cash, bank, a guarantor's savings), Cr 4500 Recoveries. */
    public function recovery(string $key, SaccoLoan $loan, Amount $amount, ?SubRef $fromSavings, ?int $fromAccountId): JournalDraft
    {
        $this->office('recovery');
        $d = JournalDraft::make($this->companyId, 'recovery', $key);
        $d = $fromSavings !== null ? $d->debit($fromSavings->accountId, $amount, $fromSavings->type, $fromSavings->id, $fromSavings->memberId)
            : $d->debit((int) $fromAccountId, $amount, memberId: (int) $loan->member_id);

        return $d->credit($this->account('inc.recoveries'), $amount, memberId: (int) $loan->member_id, memo: 'Recovered on written-off loan '.$loan->loan_no);
    }

    /** A MoMo payout confirmed: Dr 2160 Payouts in transit, Cr 1150 MoMo float. */
    public function payoutSettled(string $key, Amount $amount, ?int $memberId): JournalDraft
    {
        return JournalDraft::make($this->companyId, 'payout', $key)->debit($this->account('payouts.in_transit'), $amount, memberId: $memberId)
            ->credit($this->account('momo.float'), $amount, memberId: $memberId);
    }

    private function portfolio(SaccoLoan $loan): int
    {
        return (int) (\App\Models\SaccoLoanProduct::withoutGlobalScopes()->whereKey($loan->product_id)->value('portfolio_account_id') ?: $this->loanAccounts['principal']);
    }

    private function penaltyAccount(): int
    {
        return $this->loanAccounts['penalty'] ?? throw BusinessRuleException::make('not_in_group_mode', 'A savings group charges fines, not penalty interest.');
    }

    private function office(string $kind): void
    {
        if ($this->mode !== 'office') {
            throw BusinessRuleException::make('not_in_group_mode', 'A savings group does not post '.str_replace('_', ' ', $kind).'s.');
        }
    }
}
