<?php

namespace App\Services\Sacco\Approvals;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoApproval;
use App\Models\SaccoJournalEntry;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\PostingRules;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Ledger\SubRef;
use App\Support\Rules\SaccoRules;
use App\Support\Sacco\Amount;
use Illuminate\Support\Facades\DB;

/**
 * `held_receipt_allocation` (04 §7, AK-24): a cash receipt held in 2150 Unallocated receipts (an unknown or closed
 * account, a phone over its offline limit) is moved to the member account it belongs to once an officer approves.
 * The system asks (sync) or a teller does; the allocation never exceeds what is still unallocated of that receipt,
 * checked under the ledger lock.
 */
final class HeldReceiptAllocationExecutor extends BaseExecutor
{
    public function action(): string
    {
        return 'held_receipt_allocation';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_teller';
    }

    public function prepare(int $companyId, array $payload): array
    {
        $v = validator($payload, ['held_entry_id' => ['required', 'integer'], 'amount_minor' => SaccoRules::minor(true, 1),
            'target.type' => ['required', 'string', 'max:16'], 'target.id' => ['required', 'integer'], 'target.account_id' => ['required', 'integer'], 'target.member_id' => ['nullable', 'integer']]);
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_request', (string) $v->errors()->first());
        }
        $held = SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $companyId)->where('kind', 'held_receipt')->find((int) $payload['held_entry_id'])
            ?? throw BusinessRuleException::make('entry_not_found', 'That held receipt is not in this SACCO\'s books.');
        $amount = (int) $payload['amount_minor'];
        if ($amount > self::unallocated($companyId, (int) $held->id)) {
            throw BusinessRuleException::make('over_allocation', 'That is more than is still unallocated of this receipt.');
        }
        $t = $payload['target'];

        return ['payload' => ['held_entry_id' => (int) $held->id, 'amount_minor' => $amount,
            'target' => ['type' => (string) $t['type'], 'id' => (int) $t['id'], 'account_id' => (int) $t['account_id'], 'member_id' => isset($t['member_id']) ? (int) $t['member_id'] : null]],
            'subject_type' => 'sacco_journal_entries', 'subject_id' => (int) $held->id, 'amount_minor' => $amount];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        $p = $approval->payload;
        $cid = (int) $approval->company_id;
        $t = $p['target'];
        $amount = (int) $p['amount_minor'];
        $draft = (new PostingRules($cid, $this->mode($cid)))->heldReceiptAllocation('apr:'.$approval->uuid, new SubRef($t['type'], (int) $t['id'], (int) $t['account_id'], $t['member_id']),
            Amount::of($amount, PostingService::currency($cid)))->source('sacco_journal_entries', (int) $p['held_entry_id'])->approval((int) $approval->id)->by((int) $checker->id)
            ->guard(function () use ($cid, $p, $amount) {
                if ($amount > self::unallocated($cid, (int) $p['held_entry_id'])) {
                    throw BusinessRuleException::make('over_allocation', 'That is more than is still unallocated of this receipt.');
                }
            });
        \App\Services\Sacco\SaccoTransactions::allocating($cid, new SubRef($t['type'], (int) $t['id'], (int) $t['account_id'], $t['member_id']), $amount);
        $entry = (new PostingService())->post($draft);
        \App\Services\Sacco\SaccoTransactions::allocated($cid, (int) $p['held_entry_id'], new SubRef($t['type'], (int) $t['id'], (int) $t['account_id'], $t['member_id']),
            self::unallocated($cid, (int) $p['held_entry_id']));

        return ['entry_id' => (int) $entry->id, 'entry_no' => $entry->entry_no];
    }

    /** What is left of a held receipt in 2150: its credit, less allocations, plus allocations that were reversed. */
    public static function unallocated(int $companyId, int $heldEntryId): int
    {
        $suspense = ChartOfAccounts::id($companyId, 'suspense.receipts');
        $credited = (int) DB::table('sacco_journal_lines')->where('journal_entry_id', $heldEntryId)->where('ledger_account_id', $suspense)->sum('credit_minor');
        $allocations = DB::table('sacco_journal_entries')->where('company_id', $companyId)->where('kind', 'held_receipt_allocation')
            ->where('source_type', 'sacco_journal_entries')->where('source_id', $heldEntryId)->pluck('id');
        $out = (int) DB::table('sacco_journal_lines')->whereIn('journal_entry_id', $allocations)->where('ledger_account_id', $suspense)->sum('debit_minor');
        $back = (int) DB::table('sacco_journal_lines')->whereIn('journal_entry_id', DB::table('sacco_journal_entries')->whereIn('reverses_entry_id', $allocations)->pluck('id'))
            ->where('ledger_account_id', $suspense)->sum('credit_minor');

        return $credited - $out + $back;
    }
}
