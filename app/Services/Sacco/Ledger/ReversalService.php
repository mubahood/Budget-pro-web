<?php

namespace App\Services\Sacco\Ledger;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoJournalLine;
use App\Support\Sacco\Amount;

/**
 * Corrections (ADR 0009 §5): an entry is put right by its mirror, never an edit. The reversal swaps every line's
 * debit and credit on the same accounts and sub-ledger rows, is dated today (with today's value date), names the
 * entry it reverses and why, and goes through PostingService like any posting, with the key `rev:{entry_id}`
 * and the database's UNIQUE(reverses_entry_id) behind it, so an entry is reversed at most once. A reversal is
 * never reversed: post the right entry instead. Reversing an entry needs an approval (SaccoApprovals `reversal`).
 */
final class ReversalService
{
    public function __construct(private readonly PostingService $posting = new PostingService())
    {
    }

    /** @param  list<callable(): void>  $guards  checks on the locked rows (a savings account must not go below what it holds) */
    public function reverse(int $companyId, int $entryId, string $reason, ?int $approvalId = null, ?int $userId = null, array $guards = []): SaccoJournalEntry
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw BusinessRuleException::make('reason_required', 'Say why the entry is reversed: the reason is kept with it.');
        }
        $entry = SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $companyId)->find($entryId)
            ?? throw BusinessRuleException::make('entry_not_found', 'That entry is not in this SACCO\'s books.');
        if ($entry->kind === 'reversal' || $entry->reverses_entry_id !== null) {
            throw BusinessRuleException::make('reversal_of_reversal', "{$entry->entry_no} is itself a reversal: post the right entry instead.");
        }
        $existing = SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $companyId)->where('reverses_entry_id', $entry->id)->first();
        if ($existing !== null && $existing->idempotency_key !== 'rev:'.$entry->id) {
            throw BusinessRuleException::make('already_reversed', "{$entry->entry_no} was already reversed by {$existing->entry_no}.");
        }

        $draft = JournalDraft::make($companyId, 'reversal', 'rev:'.$entry->id)
            ->source('sacco_journal_entries', (int) $entry->id)->reverses((int) $entry->id)->approval($approvalId)->by($userId)
            ->memo("Reversal of {$entry->entry_no}: {$reason}");
        foreach (SaccoJournalLine::withoutGlobalScopes()->where('journal_entry_id', $entry->id)->orderBy('line_no')->get() as $l) {
            $draft->line(new JournalLine((int) $l->ledger_account_id, Amount::of((int) $l->credit_minor, $entry->currency), Amount::of((int) $l->debit_minor, $entry->currency),
                $l->sub_type, $l->sub_id === null ? null : (int) $l->sub_id, $l->member_id === null ? null : (int) $l->member_id, $l->memo));
        }
        foreach ($guards as $guard) {
            $draft->guard($guard);
        }

        return $this->posting->post($draft);
    }
}
