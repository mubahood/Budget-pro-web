<?php

namespace App\Services\Sacco\Ledger;

use App\Models\SaccoJournalEntry;
use App\Models\SaccoLedgerAccount;
use App\Models\SaccoLedgerHead;
use Illuminate\Support\Facades\DB;

/**
 * Proof that a SACCO's books are sound (ADR 0009 §7), run nightly by `sacco:ledger-verify`, before every month
 * closes and on demand. Each check is ok, warn or fail; any failure blocks a close and is reported to the owner
 * and the platform.
 *
 *   balanced        Σ debits = Σ credits over all lines, and per entry, each entry has 2+ lines and its total
 *   account_caches  each account's cached totals and balance = the sum of its lines
 *   sub_caches      each sub-ledger row's cached balance = the sum of its lines (per registered SubLedger)
 *   controls        each control account's balance = the sum of its sub-ledger rows
 *   chain           every entry's hash recomputes from the stored rows, in order, back to the head
 *   opening         3900 Opening balance equity nets to zero (warns while an opening import is unfinished)
 *   suspense_age    2150 Unallocated receipts holds nothing older than 30 days (warns)
 *
 * It also says whether the immutability triggers are installed (`triggers: present | absent`), for System health.
 */
final class LedgerVerifier
{
    public const CHECKS = ['balanced', 'account_caches', 'sub_caches', 'controls', 'chain', 'opening', 'suspense_age'];

    public const TRIGGERS = ['sacco_journal_entries_no_update', 'sacco_journal_entries_no_delete', 'sacco_journal_lines_no_update', 'sacco_journal_lines_no_delete'];

    /** An unallocated receipt older than this many days is a warning. */
    public const SUSPENSE_DAYS = 30;

    /**
     * @return array{company_id: int, ok: bool, status: string, checks: array<string, array{status: string, detail: string}>, first_broken_entry: ?string, triggers: string, at: string}
     */
    public function verify(int $companyId): array
    {
        $accounts = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->get()->keyBy('id');
        $checks = [];
        $checks['balanced'] = $this->balanced($companyId);
        $checks['account_caches'] = $this->accountCaches($companyId, $accounts->all());
        [$checks['sub_caches'], $checks['controls']] = $this->subLedgers($companyId, $accounts->all());
        [$checks['chain'], $broken] = $this->chain($companyId);
        $checks['opening'] = $this->opening($companyId, $accounts->all());
        $checks['suspense_age'] = $this->suspenseAge($companyId, $accounts->all());

        $statuses = array_column($checks, 'status');
        $status = in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok');

        return ['company_id' => $companyId, 'ok' => $status !== 'fail', 'status' => $status, 'checks' => $checks, 'first_broken_entry' => $broken,
            'triggers' => self::triggers(), 'at' => now()->toIso8601String()];
    }

    /** @param  array{checks: array<string, array{status: string}>}  $result @return list<string> the failing checks */
    public static function failed(array $result): array
    {
        return array_keys(array_filter($result['checks'], fn ($c) => $c['status'] === 'fail'));
    }

    public static function triggers(): string
    {
        if (DB::getDriverName() !== 'mysql') {
            return 'absent';
        }
        $n = DB::table('information_schema.triggers')->where('trigger_schema', DB::getDatabaseName())->whereIn('trigger_name', self::TRIGGERS)->count();

        return $n === count(self::TRIGGERS) ? 'present' : 'absent';
    }

    /** @return array{status: string, detail: string} */
    private static function ok(string $detail = ''): array
    {
        return ['status' => 'ok', 'detail' => $detail];
    }

    /** @return array{status: string, detail: string} */
    private static function fail(string $detail): array
    {
        return ['status' => 'fail', 'detail' => $detail];
    }

    /** @return array{status: string, detail: string} */
    private function balanced(int $cid): array
    {
        $t = DB::table('sacco_journal_lines')->where('company_id', $cid)->selectRaw('COALESCE(SUM(debit_minor),0) AS dr, COALESCE(SUM(credit_minor),0) AS cr')->first();
        if ((string) $t->dr !== (string) $t->cr) {
            return self::fail("All lines: debits {$t->dr} ≠ credits {$t->cr}.");
        }
        $bad = DB::table('sacco_journal_entries as e')->where('e.company_id', $cid)
            ->leftJoin('sacco_journal_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->groupBy('e.id', 'e.entry_no', 'e.total_minor')
            ->havingRaw('COALESCE(SUM(l.debit_minor),0) <> COALESCE(SUM(l.credit_minor),0) OR COALESCE(SUM(l.debit_minor),0) <> e.total_minor OR COUNT(l.id) < 2')
            ->limit(5)->pluck('e.entry_no')->all();

        return $bad === [] ? self::ok("Debits = credits = {$t->dr}.") : self::fail('Unbalanced entries: '.implode(', ', $bad).'.');
    }

    /**
     * @param  array<int, SaccoLedgerAccount>  $accounts
     * @return array{status: string, detail: string}
     */
    private function accountCaches(int $cid, array $accounts): array
    {
        $sums = DB::table('sacco_journal_lines')->where('company_id', $cid)->groupBy('ledger_account_id')
            ->selectRaw('ledger_account_id, SUM(debit_minor) AS dr, SUM(credit_minor) AS cr')->get()->keyBy('ledger_account_id');
        $bad = [];
        foreach (DB::table('sacco_account_balances')->where('company_id', $cid)->get() as $b) {
            $s = $sums[$b->ledger_account_id] ?? null;
            $dr = (int) ($s->dr ?? 0);
            $cr = (int) ($s->cr ?? 0);
            $account = $accounts[(int) $b->ledger_account_id] ?? null;
            if ($account === null || (int) $b->debit_total_minor !== $dr || (int) $b->credit_total_minor !== $cr || (int) $b->balance_minor !== PostingService::signed($account, $dr, $cr)) {
                $bad[] = $account?->code ?? '#'.$b->ledger_account_id;
            }
        }
        foreach ($sums as $accountId => $s) {
            if (! DB::table('sacco_account_balances')->where('company_id', $cid)->where('ledger_account_id', $accountId)->exists()) {
                $bad[] = ($accounts[(int) $accountId]->code ?? '#'.$accountId).' (no cache)';
            }
        }

        return $bad === [] ? self::ok() : self::fail('Cached balance ≠ its lines: '.implode(', ', array_slice($bad, 0, 10)).'.');
    }

    /**
     * Sub-ledger caches against their lines, then control accounts against their sub-ledgers.
     *
     * @param  array<int, SaccoLedgerAccount>  $accounts
     * @return array{0: array{status: string, detail: string}, 1: array{status: string, detail: string}}
     */
    private function subLedgers(int $cid, array $accounts): array
    {
        $lines = DB::table('sacco_journal_lines')->where('company_id', $cid)->whereNotNull('sub_type')
            ->groupBy('sub_type', 'sub_id', 'ledger_account_id')
            ->selectRaw('sub_type, sub_id, ledger_account_id, SUM(debit_minor) AS dr, SUM(credit_minor) AS cr')->get();
        $fromLines = [];
        $badSub = [];
        foreach ($lines as $l) {
            if (! SubLedgers::has((string) $l->sub_type)) {
                $badSub[] = "{$l->sub_type} #{$l->sub_id} (no such sub-ledger)";

                continue;
            }
            $account = $accounts[(int) $l->ledger_account_id] ?? null;
            $fromLines[$l->sub_type][(int) $l->sub_id][(int) $l->ledger_account_id] = $account ? PostingService::signed($account, (int) $l->dr, (int) $l->cr) : 0;
        }
        $perControl = [];
        foreach (SubLedgers::all() as $type => $ledger) {
            $cached = [];
            foreach ($ledger->balances($cid) as $row) {
                $cached[$row['sub_id']][$row['ledger_account_id']] = $row['balance_minor'];
                $perControl[$row['ledger_account_id']] = ($perControl[$row['ledger_account_id']] ?? 0) + $row['balance_minor'];
            }
            foreach ($fromLines[$type] ?? [] as $subId => $byAccount) {
                foreach ($byAccount as $accountId => $sum) {
                    if (($cached[$subId][$accountId] ?? 0) !== $sum) {
                        $badSub[] = "{$type} #{$subId}";
                    }
                }
            }
            foreach ($cached as $subId => $byAccount) {
                foreach ($byAccount as $accountId => $balance) {
                    if ($balance !== ($fromLines[$type][$subId][$accountId] ?? 0)) {
                        $badSub[] = "{$type} #{$subId}";
                    }
                }
            }
        }
        $badSub = array_values(array_unique($badSub));
        $balances = DB::table('sacco_account_balances')->where('company_id', $cid)->pluck('balance_minor', 'ledger_account_id');
        $badControl = [];
        foreach ($accounts as $id => $account) {
            if ($account->control_of === null) {
                continue;
            }
            if ((int) ($balances[$id] ?? 0) !== ($perControl[$id] ?? 0)) {
                $badControl[] = "{$account->code} ".((int) ($balances[$id] ?? 0)).' ≠ '.($perControl[$id] ?? 0);
            }
        }

        return [
            $badSub === [] ? self::ok() : self::fail('Sub-ledger cache ≠ its lines: '.implode(', ', array_slice($badSub, 0, 10)).'.'),
            $badControl === [] ? self::ok() : self::fail('Control account ≠ the sum of its member accounts: '.implode('; ', array_slice($badControl, 0, 10)).'.'),
        ];
    }

    /**
     * Recompute every hash in order. An edit made in the database breaks the entry it touched and every later one;
     * the first is named.
     *
     * @return array{0: array{status: string, detail: string}, 1: ?string}
     */
    private function chain(int $cid): array
    {
        $prev = PostingService::GENESIS;
        $broken = null;
        $count = 0;
        $last = null;
        SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $cid)->orderBy('id')->chunk(500, function ($entries) use (&$prev, &$broken, &$count, &$last) {
            $lines = DB::table('sacco_journal_lines')->whereIn('journal_entry_id', $entries->pluck('id'))->orderBy('line_no')->get()->groupBy('journal_entry_id');
            foreach ($entries as $e) {
                $count++;
                $raw = $e->getAttributes();
                $expected = PostingService::hash($prev, $raw, array_map(fn ($l) => (array) $l, ($lines[$e->id] ?? collect())->all()));
                if ($broken === null && ($raw['prev_hash'] !== $prev || $raw['hash'] !== $expected)) {
                    $broken = (string) $raw['entry_no'];
                }
                $prev = (string) $raw['hash'];
                $last = $e;
            }
        });
        if ($broken !== null) {
            return [self::fail("The hash chain breaks at {$broken}: a row was changed outside the books."), $broken];
        }
        $head = SaccoLedgerHead::withoutGlobalScopes()->where('company_id', $cid)->first();
        if ($head !== null && ((int) $head->entries_count !== $count || (string) ($head->last_hash ?: PostingService::GENESIS) !== $prev || ($last !== null && (int) $head->last_entry_id !== (int) $last->id))) {
            return [self::fail("The ledger head ({$head->entries_count} entries) does not match the {$count} entries in the books: an entry was removed or added outside them."), $last?->entry_no];
        }

        return [self::ok("{$count} entries chain to the head."), null];
    }

    /**
     * @param  array<int, SaccoLedgerAccount>  $accounts
     * @return array{status: string, detail: string}
     */
    private function opening(int $cid, array $accounts): array
    {
        $opening = collect($accounts)->firstWhere('system_key', 'opening');
        if ($opening === null) {
            return self::ok('No opening balance account.');
        }
        $balance = (int) DB::table('sacco_account_balances')->where('company_id', $cid)->where('ledger_account_id', $opening->id)->value('balance_minor');

        return $balance === 0 ? self::ok() : ['status' => 'warn', 'detail' => "{$opening->code} {$opening->name} holds {$balance}: finish the opening balance import so it nets to zero."];
    }

    /**
     * The oldest receipt still unallocated in 2150, by first-in first-out against the allocations made since.
     *
     * @param  array<int, SaccoLedgerAccount>  $accounts
     * @return array{status: string, detail: string}
     */
    private function suspenseAge(int $cid, array $accounts): array
    {
        $suspense = collect($accounts)->firstWhere('system_key', 'suspense.receipts');
        if ($suspense === null) {
            return self::ok();
        }
        $out = (int) DB::table('sacco_journal_lines')->where('company_id', $cid)->where('ledger_account_id', $suspense->id)->sum('debit_minor');
        foreach (DB::table('sacco_journal_lines')->where('company_id', $cid)->where('ledger_account_id', $suspense->id)->where('credit_minor', '>', 0)
            ->orderBy('value_date')->orderBy('id')->get(['credit_minor', 'value_date']) as $in) {
            if ($out >= (int) $in->credit_minor) {
                $out -= (int) $in->credit_minor;

                continue;
            }
            $days = (int) \Illuminate\Support\Carbon::parse($in->value_date)->diffInDays(now());

            return $days > self::SUSPENSE_DAYS
                ? ['status' => 'warn', 'detail' => "A receipt from {$in->value_date} has waited {$days} days in {$suspense->code} {$suspense->name}: allocate it."]
                : self::ok();
        }

        return self::ok();
    }
}
