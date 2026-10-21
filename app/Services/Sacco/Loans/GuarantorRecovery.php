<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoLoanGuarantor;
use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\User;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoAudit;
use App\Services\Sacco\SaccoTransactions;
use Illuminate\Support\Str;

/**
 * Recovering a defaulted loan from a guarantor's held savings (Coop s.55AK first charge, 04 §5.1 "guarantor offset",
 * AK-88). A loans officer asks; a second officer with sacco_approve approves (`guarantor_recovery`); executing debits
 * the guarantor's savings account the lien sits on (never more than is still held from them, nor more than the loan
 * owes), takes the lien off by the same amount, pays the loan in the allocation order (or, written off, posts it to
 * 4500 Recoveries) and tells the guarantor. Only a loan in arrears (or written off) can be recovered from a guarantor.
 */
final class GuarantorRecovery
{
    public function request(Company $company, int $guarantorId, int $amountMinor, User $maker, string $reason, ?string $uuid = null): SaccoApproval
    {
        SaccoAccess::authorize($maker, 'sacco_loans');
        $cid = (int) $company->id;
        $g = SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $cid)->find($guarantorId)
            ?? throw BusinessRuleException::make('guarantee_not_found', 'That guarantee is not in this SACCO.');
        $this->assertRecoverable($g, $amountMinor);

        return (new SaccoApprovals())->request($company, 'guarantor_recovery', ['guarantor_id' => (int) $g->id, 'loan_id' => (int) $g->loan_id,
            'guarantor_member_id' => (int) $g->guarantor_member_id, 'amount_minor' => $amountMinor, 'reason' => mb_substr($reason, 0, 255), 'uuid' => (string) Str::uuid()],
            $maker, $uuid ?? (string) Str::uuid());
    }

    /** @return array<string, mixed> */
    public function execute(SaccoApproval $approval, User $checker): array
    {
        $p = $approval->payload;
        $cid = (int) $approval->company_id;
        $company = Company::withoutGlobalScopes()->findOrFail($cid);
        SaccoTransactions::lockHead($cid);
        $g = SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail((int) $p['guarantor_id']);
        $amount = (int) $p['amount_minor'];
        $this->assertRecoverable($g, $amount);
        $loan = LoanBooks::lock($cid, (int) $g->loan_id);
        if ($loan->status === 'active') {
            $amount = min($amount, $loan->owedMinor());
        }
        $account = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($g->hold_savings_account_id);
        $d = ['uuid' => (string) $p['uuid'], 'narration' => 'Recovered from guarantor'];
        MemberFunds::release($cid, (int) $account->id, $amount); // the lien comes off what is taken, so the debit is not blocked by it
        $result = $loan->status === 'written_off'
            ? (new WriteOffs())->recoverInto($company, $loan, $amount, $account, $d, $checker, $g)
            : (new Repayments())->apply($company, $loan, $amount, 'guarantor', $account, $d, $checker, [], 'guarantor_offset', $g);
        $g->update(['held_minor' => (int) $g->held_minor - $amount, 'recovered_minor' => (int) $g->recovered_minor + $amount]);
        $borrower = SaccoMember::withoutGlobalScopes()->find($loan->member_id);
        LoanNotices::toMember($cid, (int) $g->guarantor_member_id, 'guarantor_recovery', ['amount' => LoanBooks::fmt($cid, $amount),
            'borrower' => (string) ($borrower?->first_name ?? ''), 'loan' => (string) $loan->loan_no]);
        SaccoAudit::record('sacco.loan.guarantor_recovered', $g, $cid, ['loan_id' => $loan->id, 'amount_minor' => $amount], (int) $checker->id);

        return ['guarantor_id' => (int) $g->id, 'amount_minor' => $amount, 'entry_id' => (int) $result['transaction']->journal_entry_id];
    }

    private function assertRecoverable(SaccoLoanGuarantor $g, int $amount): void
    {
        if ($g->consent_status !== 'consented' || $g->hold_savings_account_id === null) {
            throw BusinessRuleException::make('guarantee_closed', 'Only a consented guarantee with held savings can be recovered from.');
        }
        if ($amount < 1 || $amount > (int) $g->held_minor) {
            throw BusinessRuleException::make('guarantee_capacity', 'At most the '.$g->held_minor.' still held from this guarantor.', ['held_minor' => (int) $g->held_minor]);
        }
        $loan = LoanBooks::find((int) $g->company_id, (int) $g->loan_id);
        if (! (($loan->status === 'active' && (int) $loan->days_past_due > 0) || $loan->status === 'written_off')) {
            throw BusinessRuleException::make('not_in_arrears', 'A guarantor pays only for a loan in arrears or written off.');
        }
    }
}
