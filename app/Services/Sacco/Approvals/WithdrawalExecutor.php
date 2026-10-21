<?php

namespace App\Services\Sacco\Approvals;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoApproval;
use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoTransaction;
use App\Models\User;
use App\Services\Sacco\SaccoTransactions;

/**
 * `withdrawal` (04 §5.2, §7): a withdrawal (or a transfer out) above the threshold, from a dormant account, on a
 * product with notice, or held back by another rule, waits in `pending_approval` until a second officer approves.
 * The frozen payload names the document and what it pays; execution posts it with the key txn:{uuid} after
 * re-checking the available balance under the lock (`insufficient_available` leaves the request approved).
 */
final class WithdrawalExecutor extends BaseExecutor implements ClosesSubject
{
    public function action(): string
    {
        return 'withdrawal';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_withdraw';
    }

    public function prepare(int $companyId, array $payload): array
    {
        $tx = SaccoTransaction::withoutGlobalScopes()->where('company_id', $companyId)->find($this->int($payload, 'transaction_id'))
            ?? throw BusinessRuleException::make('transaction_not_found', 'That withdrawal is not in this SACCO.');
        if ($tx->status !== 'pending_approval' || ! in_array($tx->kind, ['withdrawal', 'internal_transfer'], true)) {
            throw BusinessRuleException::make('transaction_closed', 'Only a waiting withdrawal is approved.');
        }
        $account = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $companyId)->find($tx->savings_account_id);
        $member = SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->find($tx->member_id);

        return ['payload' => ['transaction_id' => (int) $tx->id, 'uuid' => $tx->uuid, 'kind' => $tx->kind, 'channel' => $tx->channel, 'amount_minor' => (int) $tx->amount_minor,
            'fee_minor' => (int) $tx->fee_minor, 'savings_account_id' => (int) $tx->savings_account_id, 'account_no' => $account?->account_no,
            'member_id' => (int) $tx->member_id, 'member_no' => $member?->member_no, 'member_name' => $member?->full_name,
            'reasons' => array_values(array_map('strval', (array) ($payload['reasons'] ?? [])))],
            'subject_type' => 'sacco_transactions', 'subject_id' => (int) $tx->id, 'amount_minor' => (int) $tx->amount_minor + (int) $tx->fee_minor];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        $tx = (new SaccoTransactions())->postApproved($approval, $checker);

        return ['entry_id' => (int) $tx->journal_entry_id, 'transaction_id' => (int) $tx->id, 'receipt_no' => $tx->receipt_no];
    }

    public function closed(SaccoApproval $approval, string $status): void
    {
        SaccoTransactions::approvalClosed($approval, $status);
    }
}
