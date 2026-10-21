<?php

namespace App\Services\Sacco\Approvals;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoApproval;
use App\Models\SaccoLedgerAccount;
use App\Models\User;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\JournalLine;
use App\Services\Sacco\Ledger\PostingService;
use App\Support\Rules\SaccoRules;
use App\Support\Sacco\Amount;

/**
 * `manual_journal` (04 §7): an accountant's journal (sacco_accounts), posted only after a second officer approves it,
 * with the key apr:{uuid}. The lines are checked before they are frozen (balanced, whole minor units, this SACCO's
 * accounts), and PostingService checks them again when they post.
 */
final class ManualJournalExecutor extends BaseExecutor
{
    public function action(): string
    {
        return 'manual_journal';
    }

    public function makerPermission(): ?string
    {
        return 'sacco_accounts';
    }

    public function prepare(int $companyId, array $payload): array
    {
        $v = validator($payload, [
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit_minor' => SaccoRules::minor(false),
            'lines.*.credit_minor' => SaccoRules::minor(false),
            'lines.*.sub_type' => ['nullable', 'string', 'max:16'],
            'lines.*.sub_id' => ['nullable', 'integer'],
            'lines.*.member_id' => ['nullable', 'integer'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
            'memo' => ['required', 'string', 'max:255'],
            'value_date' => ['nullable', 'date'],
        ]);
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_journal', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }
        $data = $v->validated();
        $lines = [];
        $dr = 0;
        $cr = 0;
        foreach ($data['lines'] as $i => $l) {
            $line = ['account_id' => (int) $l['account_id'], 'debit_minor' => (int) ($l['debit_minor'] ?? 0), 'credit_minor' => (int) ($l['credit_minor'] ?? 0),
                'sub_type' => $l['sub_type'] ?? null, 'sub_id' => isset($l['sub_id']) ? (int) $l['sub_id'] : null,
                'member_id' => isset($l['member_id']) ? (int) $l['member_id'] : null, 'memo' => $l['memo'] ?? null];
            if (($line['debit_minor'] > 0) === ($line['credit_minor'] > 0)) {
                throw BusinessRuleException::make('invalid_line', 'Line '.($i + 1).' must have exactly one side above zero.');
            }
            if (! SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($line['account_id'])->exists()) {
                throw BusinessRuleException::make('account_not_found', 'Line '.($i + 1).': that account is not in this SACCO\'s chart.');
            }
            $dr += $line['debit_minor'];
            $cr += $line['credit_minor'];
            $lines[] = $line;
        }
        if ($dr !== $cr) {
            throw BusinessRuleException::make('unbalanced_entry', "Debits ({$dr}) and credits ({$cr}) must be equal.");
        }

        return ['payload' => ['lines' => $lines, 'memo' => trim($data['memo']), 'value_date' => $data['value_date'] ?? null], 'subject_type' => null, 'subject_id' => null, 'amount_minor' => $dr];
    }

    public function execute(SaccoApproval $approval, User $checker): array
    {
        $p = $approval->payload;
        $currency = PostingService::currency((int) $approval->company_id);
        $draft = JournalDraft::make((int) $approval->company_id, 'manual', 'apr:'.$approval->uuid)->memo($p['memo'])->approval((int) $approval->id)
            ->by($approval->requested_by_id === null ? null : (int) $approval->requested_by_id)->source('sacco_approvals', (int) $approval->id)
            ->dated(null, $p['value_date'] ?? null);
        foreach ($p['lines'] as $l) {
            $draft->line(new JournalLine((int) $l['account_id'], Amount::of((int) $l['debit_minor'], $currency), Amount::of((int) $l['credit_minor'], $currency),
                $l['sub_type'], $l['sub_id'], $l['member_id'], $l['memo']));
        }
        $entry = (new PostingService())->post($draft);

        return ['entry_id' => (int) $entry->id, 'entry_no' => $entry->entry_no];
    }
}
