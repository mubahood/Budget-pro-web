<?php

namespace App\Services\Sacco\Approvals;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoApproval;
use App\Models\SaccoJournalEntry;
use App\Models\User;
use App\Services\Sacco\Ledger\ReversalService;

/**
 * `reversal` (04 §7): reverse a posted journal entry, always with a second officer. Its mirror posts as rev:{entry_id}.
 * A member's receipt (`transaction_id`) is reversed as a document (SaccoTransactions: the mirror entry and a
 * `reversal` row beside the original); its entry cannot be reversed on its own, or the receipt would still read posted.
 */
final class ReversalExecutor extends BaseExecutor
{
    public function action(): string
    {
        return 'reversal';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_teller';
    }

    public function prepare(int $companyId, array $payload): array
    {
        if (isset($payload['transaction_id'])) {
            return \App\Services\Sacco\SaccoTransactions::prepareReversal($companyId, $payload);
        }
        $entryId = $this->int($payload, 'entry_id');
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            throw BusinessRuleException::make('reason_required', 'Say why the entry is reversed.');
        }
        $entry = SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $companyId)->find($entryId)
            ?? throw BusinessRuleException::make('entry_not_found', 'That entry is not in this SACCO\'s books.');
        if ($entry->source_type === 'sacco_transactions') {
            throw BusinessRuleException::make('reverse_the_receipt', "{$entry->entry_no} belongs to a member's receipt: reverse the receipt instead.", ['transaction_id' => $entry->source_id]);
        }
        if ($entry->kind === 'reversal') {
            throw BusinessRuleException::make('reversal_of_reversal', "{$entry->entry_no} is itself a reversal: post the right entry instead.");
        }
        if (SaccoJournalEntry::withoutGlobalScopes()->where('reverses_entry_id', $entry->id)->exists()) {
            throw BusinessRuleException::make('already_reversed', "{$entry->entry_no} is already reversed.");
        }

        return ['payload' => ['entry_id' => $entryId, 'entry_no' => $entry->entry_no, 'reason' => mb_substr($reason, 0, 200)],
            'subject_type' => 'sacco_journal_entries', 'subject_id' => $entryId, 'amount_minor' => (int) $entry->total_minor];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        $p = $approval->payload;
        if (isset($p['transaction_id'])) {
            $reversal = (new \App\Services\Sacco\SaccoTransactions())->executeReversal($approval, $checker);

            return ['entry_id' => (int) $reversal->journal_entry_id, 'transaction_id' => (int) $reversal->id, 'receipt_no' => $reversal->receipt_no];
        }
        $entry = (new ReversalService())->reverse((int) $approval->company_id, (int) $p['entry_id'], (string) $p['reason'], (int) $approval->id,
            $approval->requested_by_id === null ? null : (int) $approval->requested_by_id);

        return ['entry_id' => (int) $entry->id, 'entry_no' => $entry->entry_no];
    }
}
