<?php

namespace App\Services\Sacco\Channels;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoLedgerAccount;
use App\Models\SaccoStatementImport;
use App\Models\SaccoStatementLine;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoAudit;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Statement import and matching (04 §3.5, P3.4, AK-103) for 1100 Bank, 1150 MoMo float and 1160 MoMo clearing, from
 * a bank's, MTN's, Airtel's or Flutterwave's CSV (or .xlsx) export.
 *
 * - import() reads the file (headers by name, amounts exact), refuses the same file twice for one account, stores
 *   each line signed (+ money in, which the books show as a debit on the account) and proposes matches.
 * - match() proposes, for each unmatched line, the one journal line on that account with the same amount on the
 *   same side within three days, preferring one whose receipt, reference or MoMo reference the statement names.
 *   Ties are left for a person. Proposing never posts and never confirms.
 * - review() is the person: confirm or reject a proposal, link a line by hand, ignore one (with a note), or ask for
 *   the missing entry as a manual journal, which posts only when a second officer approves it.
 */
final class StatementImport
{
    /** Which account each source settles to by default. */
    public const DEFAULT_ACCOUNT = ['bank' => 'bank.main', 'mtn' => 'momo.float', 'airtel' => 'momo.float', 'flutterwave' => 'momo.clearing'];

    /** The accounts a statement may be matched against. */
    public const ACCOUNTS = ['bank.main', 'momo.float', 'momo.clearing'];

    public const DAYS = 3;

    /** field => header names, per source (most specific first). */
    public const HEADERS = [
        'bank' => ['date' => ['transaction date', 'txn date', 'posting date', 'date', 'value date'], 'reference' => ['reference', 'ref', 'reference no', 'ref no', 'cheque no', 'transaction id'],
            'description' => ['description', 'narration', 'details', 'particulars', 'remarks'], 'debit' => ['debit', 'withdrawals', 'withdrawal', 'money out', 'dr'],
            'credit' => ['credit', 'deposits', 'deposit', 'money in', 'cr'], 'amount' => ['amount', 'transaction amount'], 'balance' => ['balance', 'running balance', 'closing balance']],
        'mtn' => ['date' => ['date', 'transaction date', 'date time', 'created at'], 'reference' => ['external transaction id', 'external id', 'financial transaction id', 'transaction id', 'id', 'reference'],
            'description' => ['description', 'type', 'transaction type', 'note', 'message', 'from'], 'amount' => ['amount', 'transaction amount'], 'direction' => ['dr cr', 'direction', 'debit credit'],
            'balance' => ['balance', 'balance after'], 'status' => ['status', 'transaction status']],
        'airtel' => ['date' => ['transaction date', 'date', 'date time'], 'reference' => ['reference', 'reference number', 'external reference', 'transaction id', 'txn id', 'id'],
            'description' => ['service name', 'description', 'transaction type', 'type', 'remarks'], 'amount' => ['amount', 'transaction amount'], 'direction' => ['dr cr', 'direction', 'debit credit'],
            'balance' => ['balance', 'post balance'], 'status' => ['status', 'transaction status']],
        'flutterwave' => ['date' => ['created at', 'date', 'transaction date', 'created'], 'reference' => ['tx ref', 'transaction reference', 'txref', 'reference', 'flutterwave reference', 'flw ref', 'id'],
            'description' => ['narration', 'payment type', 'description', 'customer', 'customer name'], 'amount' => ['amount', 'amount settled', 'charged amount'],
            'direction' => ['type', 'debit credit'], 'status' => ['status']],
    ];

    /** Statuses a provider's line may carry and still be money (others are skipped: pending, failed, reversed…). */
    public const GOOD_STATUSES = ['', 'successful', 'success', 'completed', 'complete', 'succeeded', 'paid', 'settled'];

    public function import(Company $company, User $by, string $contents, string $fileName, string $source, ?int $ledgerAccountId = null): SaccoStatementImport
    {
        SaccoAccess::authorize($by, 'sacco_accounts');
        $cid = (int) $company->id;
        if (! in_array($source, SaccoStatementImport::SOURCES, true)) {
            throw BusinessRuleException::make('invalid_source', 'Choose bank, mtn, airtel or flutterwave.');
        }
        $account = $this->account($cid, $ledgerAccountId ?? $this->defaultAccount($cid, $source));
        $hash = hash('sha256', $contents);
        $dup = SaccoStatementImport::withoutGlobalScopes()->where('company_id', $cid)->where('ledger_account_id', $account->id)->where('file_hash', $hash)->first();
        if ($dup !== null) {
            throw BusinessRuleException::make('already_imported', 'This statement was already imported for '.$account->code.'.', ['import_id' => (int) $dup->id]);
        }
        $lines = $this->parse($contents, $source, PostingService::currency($cid));
        if ($lines === []) {
            throw BusinessRuleException::make('empty_statement', 'The statement has no lines with a date and an amount.');
        }
        $dates = array_map(fn ($l) => $l['line_date'], $lines);
        $import = DB::transaction(function () use ($cid, $by, $source, $account, $fileName, $hash, $lines, $dates) {
            $last = end($lines);
            $import = SaccoStatementImport::create(['company_id' => $cid, 'source' => $source, 'ledger_account_id' => $account->id, 'file_name' => mb_substr($fileName, 0, 190),
                'file_hash' => $hash, 'period_from' => min($dates), 'period_to' => max($dates), 'closing_balance_minor' => $last['balance_minor'], 'lines_count' => count($lines),
                'status' => 'open', 'created_by_id' => $by->id]);
            foreach ($lines as $i => $l) {
                SaccoStatementLine::create(['company_id' => $cid, 'import_id' => $import->id, 'line_no' => $i + 1] + $l);
            }
            SaccoAudit::record('sacco.statement.imported', $import, $cid, ['source' => $source, 'account' => $account->code, 'lines' => count($lines)], (int) $by->id);

            return $import;
        });
        $this->match($company, (int) $import->id);

        return $import->fresh();
    }

    /** Propose matches for the unmatched lines. @return array{proposed: int, ambiguous: int, unmatched: int} */
    public function match(Company $company, int $importId, ?User $by = null): array
    {
        if ($by !== null) {
            SaccoAccess::authorize($by, 'sacco_accounts');
        }
        $cid = (int) $company->id;

        return DB::transaction(function () use ($cid, $importId) {
            $import = SaccoStatementImport::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->find($importId)
                ?? throw BusinessRuleException::make('import_not_found', 'That statement is not in this SACCO.');
            $out = ['proposed' => 0, 'ambiguous' => 0, 'unmatched' => 0];
            $taken = $this->taken($cid);
            foreach (SaccoStatementLine::withoutGlobalScopes()->where('import_id', $import->id)->where('match_status', 'unmatched')->orderBy('line_no')->get() as $line) {
                [$best, $score, $tie] = $this->best($cid, (int) $import->ledger_account_id, $line, $taken);
                if ($best !== null && ! $tie) {
                    $line->update(['match_status' => 'proposed', 'journal_line_id' => $best->id, 'journal_entry_id' => $best->journal_entry_id, 'match_score' => $score, 'note' => null]);
                    $taken[(int) $best->id] = true;
                    $out['proposed']++;
                } elseif ($tie) {
                    $line->update(['note' => 'Several entries could match: link one by hand.']);
                    $out['ambiguous']++;
                } else {
                    $out['unmatched']++;
                }
            }

            return $out;
        });
    }

    /**
     * A person's decision on one line.
     *
     * @param  array<string, mixed>  $data  link: journal_line_id; ignore: note; journal: counter_account_id, memo
     */
    public function review(Company $company, int $importId, int $lineId, string $action, User $by, array $data = []): SaccoStatementLine
    {
        SaccoAccess::authorize($by, 'sacco_accounts');
        $cid = (int) $company->id;

        return DB::transaction(function () use ($company, $cid, $importId, $lineId, $action, $by, $data) {
            $import = SaccoStatementImport::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->find($importId)
                ?? throw BusinessRuleException::make('import_not_found', 'That statement is not in this SACCO.');
            $line = SaccoStatementLine::withoutGlobalScopes()->where('import_id', $import->id)->lockForUpdate()->find($lineId)
                ?? throw BusinessRuleException::make('line_not_found', 'That line is not on this statement.');
            $stamp = ['reviewed_by_id' => $by->id, 'reviewed_at' => now()];
            switch ($action) {
                case 'confirm':
                    if ($line->match_status !== 'proposed') {
                        throw BusinessRuleException::make('nothing_proposed', 'Only a proposed match is confirmed.');
                    }
                    $line->update(['match_status' => 'matched'] + $stamp);
                    break;
                case 'reject':
                case 'unignore':
                    if (! in_array($line->match_status, $action === 'reject' ? ['proposed', 'matched'] : ['ignored'], true)) {
                        throw BusinessRuleException::make('invalid_review', 'That line is '.$line->match_status.'.');
                    }
                    $line->update(['match_status' => 'unmatched', 'journal_line_id' => null, 'journal_entry_id' => null, 'match_score' => null, 'note' => null] + $stamp);
                    break;
                case 'ignore':
                    $note = trim((string) ($data['note'] ?? ''));
                    if ($note === '' || $line->match_status === 'matched') {
                        throw BusinessRuleException::make('note_required', $line->match_status === 'matched' ? 'Reject the match first.' : 'Say why the line needs no entry.');
                    }
                    $line->update(['match_status' => 'ignored', 'journal_line_id' => null, 'journal_entry_id' => null, 'note' => mb_substr($note, 0, 255)] + $stamp);
                    break;
                case 'link':
                    if (in_array($line->match_status, ['matched', 'ignored'], true)) {
                        throw BusinessRuleException::make('invalid_review', 'That line is '.$line->match_status.': reject or unignore it first.');
                    }
                    $jl = DB::table('sacco_journal_lines')->where('company_id', $cid)->where('id', (int) ($data['journal_line_id'] ?? 0))->first();
                    if ($jl === null || (int) $jl->ledger_account_id !== (int) $import->ledger_account_id || $this->signed($jl) !== (int) $line->amount_minor) {
                        throw BusinessRuleException::make('not_the_same', 'Link a line of the same account, the same amount and the same side.');
                    }
                    if (isset($this->taken($cid)[(int) $jl->id])) {
                        throw BusinessRuleException::make('already_matched', 'That entry is already matched to another statement line.');
                    }
                    $line->update(['match_status' => 'matched', 'journal_line_id' => $jl->id, 'journal_entry_id' => $jl->journal_entry_id, 'match_score' => null, 'note' => null] + $stamp);
                    break;
                case 'journal':
                    if ($line->match_status !== 'unmatched' || $line->approval_id !== null) {
                        throw BusinessRuleException::make('invalid_review', 'Only an unmatched line without a pending request becomes a journal.');
                    }
                    $counter = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->find((int) ($data['counter_account_id'] ?? 0))
                        ?? throw BusinessRuleException::make('account_not_found', 'Choose the account the other side goes to (bank charges, interest earned…).');
                    $amount = abs((int) $line->amount_minor);
                    $in = (int) $line->amount_minor > 0;
                    $memo = trim((string) ($data['memo'] ?? '')) ?: ('Statement '.$line->line_date->toDateString().' '.($line->reference ?? $line->description ?? ''));
                    $approval = app(SaccoApprovals::class)->request($company, 'manual_journal', ['memo' => mb_substr($memo.' ['.($line->reference ?? 'line '.$line->line_no).']', 0, 255),
                        'value_date' => $line->line_date->toDateString(), 'lines' => [
                            ['account_id' => $in ? (int) $import->ledger_account_id : (int) $counter->id, 'debit_minor' => $amount, 'credit_minor' => 0],
                            ['account_id' => $in ? (int) $counter->id : (int) $import->ledger_account_id, 'debit_minor' => 0, 'credit_minor' => $amount],
                        ]], $by, SyncSequence::childUuid('stl', (string) $line->id));
                    $line->update(['approval_id' => $approval->id, 'note' => 'A journal waits for a second officer.'] + $stamp);
                    break;
                default:
                    throw BusinessRuleException::make('invalid_review', 'Confirm, reject, link, ignore, unignore or journal.');
            }
            SaccoAudit::record('sacco.statement.'.$action, $line, $cid, ['import_id' => (int) $import->id, 'journal_line_id' => $line->journal_line_id], (int) $by->id);

            return $line->fresh();
        });
    }

    /**
     * Where the statement and the books stand: counts and sums per status, the statement's closing balance beside the
     * account's balance on the same day, and the entries in the period no statement line matched.
     *
     * @return array<string, mixed>
     */
    public function summary(Company $company, int $importId): array
    {
        $cid = (int) $company->id;
        $import = SaccoStatementImport::withoutGlobalScopes()->where('company_id', $cid)->find($importId)
            ?? throw BusinessRuleException::make('import_not_found', 'That statement is not in this SACCO.');
        $counts = SaccoStatementLine::withoutGlobalScopes()->where('import_id', $import->id)->groupBy('match_status')
            ->selectRaw('match_status, COUNT(*) n, COALESCE(SUM(amount_minor),0) s')->get()->keyBy('match_status');
        $account = SaccoLedgerAccount::withoutGlobalScopes()->find($import->ledger_account_id);
        $to = $import->period_to?->toDateString();
        $book = $to === null || $account === null ? null : $this->bookBalance($cid, $account, $to);
        $taken = $this->taken($cid);
        $unmatchedBook = $import->period_from === null ? collect() : DB::table('sacco_journal_lines')->where('company_id', $cid)->where('ledger_account_id', $import->ledger_account_id)
            ->whereBetween('value_date', [$import->period_from->toDateString(), (string) $to])->orderBy('value_date')->orderBy('id')->limit(500)->get()
            ->reject(fn ($l) => isset($taken[(int) $l->id]))->values();

        return ['import' => self::present($import), 'account' => $account === null ? null : ['id' => (int) $account->id, 'code' => $account->code, 'name' => $account->name],
            'statuses' => collect(SaccoStatementLine::STATUSES)->mapWithKeys(fn ($s) => [$s => ['count' => (int) ($counts[$s]->n ?? 0), 'sum_minor' => (int) ($counts[$s]->s ?? 0)]])->all(),
            'statement_closing_minor' => $import->closing_balance_minor, 'book_balance_minor' => $book,
            'difference_minor' => $book === null || $import->closing_balance_minor === null ? null : (int) $import->closing_balance_minor - $book,
            'unmatched_entries' => $unmatchedBook->map(fn ($l) => ['journal_line_id' => (int) $l->id, 'journal_entry_id' => (int) $l->journal_entry_id, 'value_date' => (string) $l->value_date,
                'amount_minor' => $this->signed($l), 'memo' => $l->memo])->all()];
    }

    /** @return array<string, mixed> */
    public static function present(SaccoStatementImport $i): array
    {
        return ['id' => (int) $i->id, 'source' => $i->source, 'ledger_account_id' => (int) $i->ledger_account_id, 'file_name' => $i->file_name, 'period_from' => $i->period_from?->toDateString(),
            'period_to' => $i->period_to?->toDateString(), 'closing_balance_minor' => $i->closing_balance_minor, 'lines_count' => (int) $i->lines_count, 'status' => $i->status,
            'created_at' => $i->created_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public static function presentLine(SaccoStatementLine $l): array
    {
        return ['id' => (int) $l->id, 'line_no' => (int) $l->line_no, 'line_date' => $l->line_date->toDateString(), 'reference' => $l->reference, 'description' => $l->description,
            'amount_minor' => (int) $l->amount_minor, 'balance_minor' => $l->balance_minor, 'match_status' => $l->match_status, 'journal_line_id' => $l->journal_line_id === null ? null : (int) $l->journal_line_id,
            'journal_entry_id' => $l->journal_entry_id === null ? null : (int) $l->journal_entry_id, 'match_score' => $l->match_score, 'note' => $l->note,
            'approval_id' => $l->approval_id === null ? null : (int) $l->approval_id, 'reviewed_at' => $l->reviewed_at?->toIso8601String()];
    }

    /**
     * The statement's lines that are money, in order.
     *
     * @return list<array{line_date: string, reference: ?string, description: ?string, amount_minor: int, balance_minor: ?int}>
     */
    public function parse(string $contents, string $source, string $currency): array
    {
        $rows = SheetReader::rows($contents);
        [$head, $map] = SheetReader::header($rows, self::HEADERS[$source]);
        if (! isset($map['date']) || (! isset($map['amount']) && ! isset($map['debit']) && ! isset($map['credit']))) {
            throw BusinessRuleException::make('unknown_format', 'The statement needs a date column and an amount (or debit and credit) column.');
        }
        $out = [];
        foreach (array_slice($rows, $head + 1) as $n => $row) {
            $cell = fn (string $f) => isset($map[$f]) ? (string) ($row[$map[$f]] ?? '') : '';
            $date = SheetReader::date($cell('date'));
            if ($date === null) {
                continue; // a total, a blank or a footer row
            }
            if (! in_array(strtolower(trim($cell('status'))), self::GOOD_STATUSES, true)) {
                continue;
            }
            try {
                if (isset($map['debit']) || isset($map['credit'])) {
                    $in = SheetReader::minor($cell('credit'), $currency) ?? 0;
                    $outAmount = SheetReader::minor($cell('debit'), $currency) ?? 0;
                    $amount = abs($in) - abs($outAmount);
                    if ($amount === 0 && isset($map['amount'])) {
                        $amount = SheetReader::minor($cell('amount'), $currency) ?? 0;
                    }
                } else {
                    $amount = SheetReader::minor($cell('amount'), $currency) ?? 0;
                    $dir = strtolower($cell('direction'));
                    if ($amount > 0 && $dir !== '' && preg_match('/\b(dr|debit|out|withdraw|sent|payment|transfer|payout)/', $dir) === 1) {
                        $amount = -$amount;
                    }
                }
                $balance = isset($map['balance']) ? SheetReader::minor($cell('balance'), $currency) : null;
            } catch (BusinessRuleException $e) {
                throw BusinessRuleException::make('invalid_amount', 'Row '.($head + $n + 2).': '.$e->getMessage());
            }
            if ($amount === 0) {
                continue;
            }
            $out[] = ['line_date' => $date->toDateString(), 'reference' => $cell('reference') === '' ? null : mb_substr($cell('reference'), 0, 120),
                'description' => $cell('description') === '' ? null : mb_substr($cell('description'), 0, 255), 'amount_minor' => $amount, 'balance_minor' => $balance];
        }

        return $out;
    }

    private function defaultAccount(int $cid, string $source): int
    {
        $key = self::DEFAULT_ACCOUNT[$source];
        $id = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->where('system_key', $key)->value('id');

        return $id === null ? ChartOfAccounts::id($cid, 'bank.main') : (int) $id;
    }

    private function account(int $cid, int $id): SaccoLedgerAccount
    {
        $a = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->find($id);
        if ($a === null || ! in_array($a->system_key, self::ACCOUNTS, true)) {
            throw BusinessRuleException::make('account_not_found', 'Statements are matched against 1100 Bank, 1150 MoMo float or 1160 MoMo clearing.');
        }

        return $a;
    }

    /** @return array<int, true> journal line ids already proposed or matched by some statement line */
    private function taken(int $cid): array
    {
        return array_fill_keys(SaccoStatementLine::withoutGlobalScopes()->where('company_id', $cid)->whereIn('match_status', ['proposed', 'matched'])->whereNotNull('journal_line_id')
            ->pluck('journal_line_id')->map(fn ($v) => (int) $v)->all(), true);
    }

    private function signed(object $journalLine): int
    {
        return (int) $journalLine->debit_minor - (int) $journalLine->credit_minor;
    }

    /**
     * The best journal line for a statement line: [line, score, tie].
     *
     * @param  array<int, true>  $taken
     * @return array{0: ?object, 1: int, 2: bool}
     */
    private function best(int $cid, int $accountId, SaccoStatementLine $line, array $taken): array
    {
        $amount = (int) $line->amount_minor;
        $day = Carbon::parse($line->line_date);
        $candidates = DB::table('sacco_journal_lines')->where('company_id', $cid)->where('ledger_account_id', $accountId)
            ->where($amount > 0 ? 'debit_minor' : 'credit_minor', abs($amount))
            ->whereBetween('value_date', [$day->copy()->subDays(self::DAYS)->toDateString(), $day->copy()->addDays(self::DAYS)->toDateString()])
            ->orderBy('id')->get()->reject(fn ($l) => isset($taken[(int) $l->id]))->values();
        if ($candidates->isEmpty()) {
            return [null, 0, false];
        }
        $needle = strtolower(trim(($line->reference ?? '').' '.($line->description ?? '')));
        $scored = $candidates->map(function ($c) use ($day, $needle, $cid) {
            $score = 60 - 10 * (int) abs(Carbon::parse($c->value_date)->diffInDays($day, false));
            foreach ($this->references($cid, (int) $c->journal_entry_id) as $ref) {
                if ($needle !== '' && strlen($ref) >= 4 && str_contains($needle, strtolower($ref))) {
                    $score += 100;
                    break;
                }
            }

            return ['line' => $c, 'score' => max(1, $score)];
        })->sortByDesc('score')->values();
        $top = $scored[0];
        $tie = $scored->count() > 1 && $scored[1]['score'] === $top['score'];

        return [$top['line'], (int) $top['score'], $tie];
    }

    /** @return list<string> what an entry may be called on a statement: its number, its receipt, its references */
    private function references(int $cid, int $entryId): array
    {
        $e = DB::table('sacco_journal_entries')->where('id', $entryId)->first(['entry_no', 'memo', 'source_type', 'source_id']);
        if ($e === null) {
            return [];
        }
        $refs = [(string) $e->entry_no];
        if (preg_match_all('/\[([^\]]{4,})\]/', (string) $e->memo, $m)) {
            $refs = array_merge($refs, $m[1]);
        }
        if ($e->source_type === 'sacco_transactions') {
            $t = DB::table('sacco_transactions')->where('id', $e->source_id)->first(['id', 'receipt_no', 'reference']);
            if ($t !== null) {
                $refs = array_merge($refs, array_filter([(string) $t->receipt_no, (string) $t->reference]),
                    DB::table('momo_requests')->where('company_id', $cid)->where('sacco_transaction_id', $t->id)->pluck('tx_ref')->map(fn ($v) => (string) $v)->all());
            }
        } elseif ($e->source_type === 'sacco_payouts') {
            $p = DB::table('sacco_payouts')->where('id', $e->source_id)->first(['provider_ref', 'idempotency_key']);
            if ($p !== null) {
                $refs = array_merge($refs, array_filter([(string) $p->provider_ref, (string) $p->idempotency_key]));
            }
        }

        return array_values(array_filter($refs, fn ($r) => $r !== ''));
    }

    private function bookBalance(int $cid, SaccoLedgerAccount $account, string $on): int
    {
        $r = DB::table('sacco_journal_lines')->where('company_id', $cid)->where('ledger_account_id', $account->id)->where('value_date', '<=', $on)
            ->selectRaw('COALESCE(SUM(debit_minor),0) dr, COALESCE(SUM(credit_minor),0) cr')->first();

        return PostingService::signed($account, (int) $r->dr, (int) $r->cr);
    }
}
