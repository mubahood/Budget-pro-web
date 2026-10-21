<?php

namespace App\Services\Sacco\Ledger;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoLedgerAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Statements from the journal (04 §12, P1.13): a ledger account's lines (the cash book, a control account), or one
 * member account's (a savings account, the share account), each with the opening balance, every line in posting
 * order with its receipt and narration, the running balance on the account's normal side, and the closing balance.
 */
final class AccountStatement
{
    /** @return array{opening_minor: int, closing_minor: int, debits_minor: int, credits_minor: int, lines: list<array<string, mixed>>} */
    public function ledger(int $companyId, int $accountId, CarbonInterface|string|null $from, CarbonInterface|string $to): array
    {
        $account = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->find($accountId)
            ?? throw BusinessRuleException::make('account_not_found', 'That account is not in this SACCO\'s chart.');

        return $this->run($companyId, $account, fn ($q) => $q->where('l.ledger_account_id', $account->id), $from, $to);
    }

    /** A member account (sub-ledger row): savings or shares (loans add theirs). */
    public function member(int $companyId, string $subType, int $subId, CarbonInterface|string|null $from, CarbonInterface|string $to): array
    {
        $accountId = (int) (ChartOfAccounts::controlFor($companyId, $subType, $subId)[0] ?? 0);
        $account = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->find($accountId)
            ?? throw BusinessRuleException::make('account_not_found', 'That member account is not in this SACCO.');

        return $this->run($companyId, $account, fn ($q) => $q->where('l.sub_type', $subType)->where('l.sub_id', $subId), $from, $to);
    }

    /** @param callable(\Illuminate\Database\Query\Builder): mixed $scope */
    private function run(int $companyId, SaccoLedgerAccount $account, callable $scope, CarbonInterface|string|null $from, CarbonInterface|string $to): array
    {
        $to = Carbon::parse($to)->toDateString();
        $from = $from === null ? null : Carbon::parse($from)->toDateString();
        $base = fn () => tap(DB::table('sacco_journal_lines as l')->where('l.company_id', $companyId), $scope);
        $opening = 0;
        if ($from !== null) {
            $o = $base()->where('l.entry_date', '<', $from)->selectRaw('COALESCE(SUM(l.debit_minor),0) AS dr, COALESCE(SUM(l.credit_minor),0) AS cr')->first();
            $opening = PostingService::signed($account, (int) $o->dr, (int) $o->cr);
        }
        $rows = $base()->join('sacco_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->leftJoin('sacco_transactions as t', fn ($j) => $j->on('t.id', '=', 'e.source_id')->where('e.source_type', '=', 'sacco_transactions'))
            ->where('l.entry_date', '<=', $to)->when($from !== null, fn ($q) => $q->where('l.entry_date', '>=', $from))
            ->orderBy('l.entry_date')->orderBy('e.id')->orderBy('l.line_no')
            ->get(['l.entry_date', 'l.value_date', 'l.debit_minor', 'l.credit_minor', 'l.memo as line_memo', 'e.id as entry_id', 'e.entry_no', 'e.kind', 'e.memo',
                't.receipt_no', 't.narration', 't.id as transaction_id']);
        $running = $opening;
        $lines = [];
        $dr = 0;
        $cr = 0;
        foreach ($rows as $r) {
            $running += PostingService::signed($account, (int) $r->debit_minor, (int) $r->credit_minor);
            $dr += (int) $r->debit_minor;
            $cr += (int) $r->credit_minor;
            $lines[] = ['date' => substr((string) $r->entry_date, 0, 10), 'value_date' => substr((string) $r->value_date, 0, 10), 'entry_id' => (int) $r->entry_id,
                'entry_no' => $r->entry_no, 'kind' => $r->kind, 'receipt_no' => $r->receipt_no, 'transaction_id' => $r->transaction_id === null ? null : (int) $r->transaction_id,
                'narration' => $r->narration ?? $r->line_memo ?? $r->memo, 'debit_minor' => (int) $r->debit_minor, 'credit_minor' => (int) $r->credit_minor, 'balance_minor' => $running];
        }

        return ['account' => ['id' => (int) $account->id, 'code' => $account->code, 'name' => $account->name, 'normal_side' => $account->normal_side],
            'from' => $from, 'to' => $to, 'opening_minor' => $opening, 'debits_minor' => $dr, 'credits_minor' => $cr, 'closing_minor' => $running, 'lines' => $lines];
    }
}
