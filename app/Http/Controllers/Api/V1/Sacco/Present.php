<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Models\SaccoApproval;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\SaccoShareAccount;
use App\Models\SaccoTransaction;
use App\Models\User;
use App\Services\Sacco\Reports\SaccoReportFormat;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Sacco\Savings\SavingsProducts;
use Illuminate\Support\Facades\DB;

/**
 * The API's shapes for Akiba records (04 §13): money as `*_minor` integers beside `currency`, dates as Y-m-d, never
 * a NIN (SaccoMember hides it; only `nin_masked` shows).
 */
final class Present
{
    /** @return array<string, mixed> */
    public static function member(SaccoMember $m): array
    {
        return $m->toArray();
    }

    /** @return array<string, mixed> */
    public static function product(SaccoSavingsProduct $p): array
    {
        return $p->toArray() + ['disclosure' => SavingsProducts::disclosure($p)];
    }

    /** @return array<string, mixed> */
    public static function savings(SaccoSavingsAccount $a, bool $available = true): array
    {
        return ['id' => (int) $a->id, 'uuid' => $a->uuid, 'account_no' => $a->account_no, 'member_id' => (int) $a->member_id, 'product_id' => (int) $a->product_id,
            'status' => $a->status, 'opened_on' => $a->opened_on->toDateString(), 'closed_on' => $a->closed_on?->toDateString(), 'maturity_on' => $a->maturity_on?->toDateString(),
            'dormant_since' => $a->dormant_since?->toDateString(), 'last_activity_on' => $a->last_activity_on?->toDateString(), 'frozen_reason' => $a->frozen_reason,
            'balance_minor' => (int) $a->balance_minor, 'held_minor' => (int) $a->held_minor,
            'available_minor' => $available ? SavingsAccounts::available((int) $a->company_id, (int) $a->id) : null, 'currency' => self::currency((int) $a->company_id)];
    }

    /** @return array<string, mixed> */
    public static function shares(SaccoShareAccount $a): array
    {
        return ['id' => (int) $a->id, 'uuid' => $a->uuid, 'account_no' => $a->account_no, 'member_id' => (int) $a->member_id, 'shares_count' => (int) $a->shares_count,
            'balance_minor' => (int) $a->balance_minor, 'status' => $a->status, 'opened_on' => $a->opened_on->toDateString(), 'currency' => self::currency((int) $a->company_id)];
    }

    /** @return array<string, mixed> */
    public static function transaction(SaccoTransaction $t): array
    {
        return ['id' => (int) $t->id, 'uuid' => $t->uuid, 'receipt_no' => $t->receipt_no, 'device_receipt_no' => $t->device_receipt_no, 'kind' => $t->kind, 'channel' => $t->channel,
            'member_id' => $t->member_id, 'savings_account_id' => $t->savings_account_id, 'share_account_id' => $t->share_account_id, 'loan_id' => $t->loan_id === null ? null : (int) $t->loan_id,
            'counter_account_id' => $t->counter_account_id === null ? null : (int) $t->counter_account_id, 'shares_count' => $t->shares_count, 'amount_minor' => (int) $t->amount_minor,
            'fee_minor' => (int) $t->fee_minor, 'currency' => $t->currency, 'value_date' => $t->value_date->toDateString(), 'status' => $t->status, 'hold_reason' => $t->hold_reason,
            'journal_entry_id' => $t->journal_entry_id, 'reverses_id' => $t->reverses_id === null ? null : (int) $t->reverses_id, 'reason' => $t->reason,
            'approval_id' => $t->approval_id === null ? null : (int) $t->approval_id, 'reference' => $t->reference, 'narration' => $t->narration,
            'received_by_id' => $t->received_by_id === null ? null : (int) $t->received_by_id, 'created_at' => $t->created_at?->toIso8601String(),
            'server_seq' => (int) $t->server_seq];
    }

    /** @return array<string, mixed> */
    public static function approval(SaccoApproval $a, ?User $viewer = null): array
    {
        $names = DB::table('admin_users')->whereIn('id', array_filter([$a->requested_by_id, $a->decided_by_id]))->pluck('name', 'id');

        return ['id' => (int) $a->id, 'uuid' => $a->uuid, 'action' => $a->action, 'summary' => self::summary($a), 'subject_type' => $a->subject_type,
            'subject_id' => $a->subject_id === null ? null : (int) $a->subject_id, 'payload' => $a->payload, 'payload_hash' => $a->payload_hash, 'amount_minor' => $a->amount_minor,
            'currency' => self::currency((int) $a->company_id), 'required_approvals' => (int) $a->required_approvals, 'status' => $a->isExpired() && in_array($a->status, ['pending', 'approved'], true) ? 'expired' : $a->status,
            'requested_by_id' => $a->requested_by_id === null ? null : (int) $a->requested_by_id, 'requested_by_name' => $a->requested_by_id === null ? 'System' : ($names[$a->requested_by_id] ?? null),
            'requested_at' => $a->requested_at->toIso8601String(), 'expires_at' => $a->expires_at->toIso8601String(), 'decided_by_name' => $a->decided_by_id === null ? null : ($names[$a->decided_by_id] ?? null),
            'decided_at' => $a->decided_at?->toIso8601String(), 'decision_note' => $a->decision_note, 'executed_at' => $a->executed_at?->toIso8601String(),
            'executed_entry_id' => $a->executed_entry_id === null ? null : (int) $a->executed_entry_id, 'last_error' => $a->last_error, 'self_approved' => (bool) $a->self_approved,
            'is_mine' => $viewer !== null && $a->requested_by_id !== null && (int) $a->requested_by_id === (int) $viewer->id];
    }

    /** One line a checker reads before opening the payload. */
    public static function summary(SaccoApproval $a): string
    {
        $p = (array) $a->payload;
        $cur = self::currency((int) $a->company_id);
        $money = fn ($minor) => $minor === null ? '' : $cur.' '.SaccoReportFormat::money((int) $minor, $cur);

        return match ($a->action) {
            'withdrawal' => 'Withdraw '.$money(($p['amount_minor'] ?? 0)).' from '.($p['account_no'] ?? 'savings').' ('.trim(($p['member_no'] ?? '').' '.($p['member_name'] ?? '')).')'
                .(empty($p['reasons']) ? '' : ': '.implode(', ', array_map(fn ($r) => str_replace('_', ' ', (string) $r), $p['reasons']))),
            'reversal' => 'Reverse '.($p['receipt_no'] ?? $p['entry_no'] ?? 'an entry').' '.$money($a->amount_minor).': '.($p['reason'] ?? ''),
            'manual_journal' => 'Journal '.$money($a->amount_minor).': '.($p['memo'] ?? ''),
            'share_transfer' => 'Move '.($p['shares_count'] ?? 0).' shares from '.($p['from_member_no'] ?? '').' to '.($p['to_member_no'] ?? '').': '.($p['reason'] ?? ''),
            'product_terms_change' => 'New terms for '.($p['name'] ?? 'a savings product').' from '.($p['effective_from'] ?? ''),
            'opening_balances' => 'Opening balances ('.($p['kind'] ?? '').', '.count((array) ($p['rows'] ?? [])).' rows) as at '.($p['as_of'] ?? ''),
            'held_receipt_allocation' => 'Allocate a held receipt of '.$money($a->amount_minor),
            'period_close' => 'Close the month', 'period_reopen' => 'Reopen the month: '.($p['reason'] ?? ''),
            'ledger_account_create' => 'New account '.($p['code'] ?? '').' '.($p['name'] ?? ''),
            'member_kyc' => 'Verify a member\'s identity',
            default => ucfirst(str_replace('_', ' ', $a->action)).($a->amount_minor ? ' '.$money($a->amount_minor) : ''),
        };
    }

    /** @return array<string, mixed> */
    public static function entry(SaccoJournalEntry $e, bool $lines = false): array
    {
        $out = $e->toArray();
        if ($lines) {
            $out['lines'] = DB::table('sacco_journal_lines as l')->join('sacco_ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('l.journal_entry_id', $e->id)
                ->orderBy('l.line_no')->get(['l.line_no', 'l.ledger_account_id', 'a.code', 'a.name', 'l.debit_minor', 'l.credit_minor', 'l.sub_type', 'l.sub_id', 'l.member_id', 'l.memo'])
                ->map(fn ($l) => (array) $l + ['debit_minor' => (int) $l->debit_minor, 'credit_minor' => (int) $l->credit_minor])->all();
        }

        return $out;
    }

    public static function currency(int $companyId): string
    {
        static $memo = [];

        return $memo[$companyId] ??= \App\Services\Sacco\PostingCurrency::of($companyId);
    }
}
