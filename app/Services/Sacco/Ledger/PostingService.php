<?php

namespace App\Services\Sacco\Ledger;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoAccountBalance;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoJournalLine;
use App\Models\SaccoLedgerAccount;
use App\Models\SaccoLedgerHead;
use App\Services\Shop\NumberSequencer;
use App\Support\LocalDate;
use App\Support\Sacco\Amount;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the SACCO books (ADR 0009 §4.1): journal entries, their lines, the account balance caches
 * and, through each SubLedger, the member sub-ledger caches. tests/Feature/Sacco/LedgerWritersTest fails if any
 * other class in app/ writes those tables.
 *
 * post() refuses a bad draft before anything is written (§4.2), then, in one transaction (§4.3):
 *   1. a draft whose idempotency key was posted before is a replay (same payload) or `idempotency_conflict`;
 *   2. the SACCO's ledger head is locked, which serialises its postings and orders the hash chain;
 *   3. the balance rows, then the sub-ledger rows, are locked in ascending id order;
 *   4. the draft's guards run against the locked rows;
 *   5. the entry (JE-yyyy-nnnnnn) and its lines are inserted, hashed onto the chain;
 *   6. the caches move by exactly the lines' amounts, and the head moves to the new hash.
 */
final class PostingService
{
    /** The chain's first prev_hash. */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private readonly PeriodService $periods = new PeriodService())
    {
    }

    public function post(JournalDraft $draft): SaccoJournalEntry
    {
        $currency = $this->validate($draft);
        $cid = $draft->companyId;
        $entryDate = ($draft->entryDate ?? LocalDate::today($cid))->copy()->startOfDay();
        $valueDate = ($draft->valueDate ?? $entryDate)->copy()->startOfDay();
        if ($valueDate->gt($entryDate)) {
            throw BusinessRuleException::make('invalid_value_date', 'The money cannot move after the day it is posted: the value date must be on or before the entry date.');
        }
        $payloadHash = $draft->payloadHash();

        return DB::transaction(function () use ($draft, $cid, $currency, $entryDate, $valueDate, $payloadHash) {
            if (($replay = $this->replay($draft, $payloadHash)) !== null) {
                return $replay;
            }
            $head = SaccoLedgerHead::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->first()
                ?? throw BusinessRuleException::make('ledger_not_set_up', 'This SACCO\'s books are not set up yet.');
            if (($replay = $this->replay($draft, $payloadHash, true)) !== null) {
                return $replay; // a twin posted it while this one waited for the head (a locking read sees its commit)
            }

            $accounts = $this->accounts($draft);
            $period = $this->periods->forPosting($cid, $entryDate);
            $accountIds = array_values(array_unique(array_map(fn (JournalLine $l) => $l->accountId, $draft->lines)));
            sort($accountIds);
            $balances = SaccoAccountBalance::withoutGlobalScopes()->where('company_id', $cid)->whereIn('ledger_account_id', $accountIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('ledger_account_id');
            if ($balances->count() !== count($accountIds)) {
                (new ChartOfAccounts())->ensureBalances($cid);
                $balances = SaccoAccountBalance::withoutGlobalScopes()->where('company_id', $cid)->whereIn('ledger_account_id', $accountIds)
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('ledger_account_id');
            }
            $memberOf = $this->lockSubLedgers($draft, $accounts);
            foreach ($draft->guards as $guard) {
                $guard();
            }

            $total = 0;
            foreach ($draft->lines as $l) {
                $total += $l->debit->minor;
            }
            $entryNo = NumberSequencer::next($cid, 'je', $entryDate);
            $entry = [
                'company_id' => $cid, 'entry_no' => $entryNo, 'entry_date' => $entryDate->toDateString(), 'value_date' => $valueDate->toDateString(),
                'period_id' => (int) $period->id, 'cycle_id' => $draft->cycleId, 'kind' => $draft->kind, 'source_type' => $draft->sourceType, 'source_id' => $draft->sourceId,
                'idempotency_key' => $draft->idempotencyKey, 'payload_hash' => $payloadHash, 'memo' => $draft->memo, 'total_minor' => $total, 'currency' => $currency,
                'reverses_entry_id' => $draft->reversesEntryId, 'approval_id' => $draft->approvalId, 'posted_by_id' => $draft->postedById,
            ];
            $lines = [];
            foreach ($draft->lines as $i => $l) {
                $lines[] = ['company_id' => $cid, 'line_no' => $i + 1, 'ledger_account_id' => $l->accountId, 'debit_minor' => $l->debit->minor, 'credit_minor' => $l->credit->minor,
                    'sub_type' => $l->subType, 'sub_id' => $l->subId, 'member_id' => $l->memberId ?? ($l->subType !== null ? $memberOf[$l->subType][$l->subId] ?? null : null),
                    'entry_date' => $entry['entry_date'], 'value_date' => $entry['value_date'], 'memo' => $l->memo];
            }
            $prev = (string) ($head->last_hash ?: self::GENESIS);
            $entry['prev_hash'] = $prev;
            $entry['hash'] = self::hash($prev, $entry, $lines);

            $model = SaccoJournalEntry::create($entry);
            foreach ($lines as $line) {
                SaccoJournalLine::create(['journal_entry_id' => $model->id] + $line);
            }

            foreach ($draft->lines as $l) {
                $account = $accounts[$l->accountId];
                $signed = self::signed($account, $l->debit->minor, $l->credit->minor);
                $b = $balances[$l->accountId];
                SaccoAccountBalance::withoutGlobalScopes()->whereKey($b->id)->update([
                    'debit_total_minor' => DB::raw('debit_total_minor + '.$l->debit->minor),
                    'credit_total_minor' => DB::raw('credit_total_minor + '.$l->credit->minor),
                    'balance_minor' => DB::raw('balance_minor + ('.$signed.')'),
                    'last_entry_id' => $model->id, 'updated_at' => now(),
                ]);
                if ($l->subType !== null) {
                    SubLedgers::for($l->subType)->project($cid, (int) $l->subId, $l->accountId, $signed);
                }
            }
            SaccoLedgerHead::withoutGlobalScopes()->where('company_id', $cid)->update([
                'last_entry_id' => $model->id, 'last_hash' => $entry['hash'], 'entries_count' => DB::raw('entries_count + 1'), 'updated_at' => now(),
            ]);

            return $model->setRelation('lines', SaccoJournalLine::withoutGlobalScopes()->where('journal_entry_id', $model->id)->orderBy('line_no')->get());
        });
    }

    /** A line's amount on its account's normal side: debit − credit for a debit-normal account, the reverse otherwise. */
    public static function signed(SaccoLedgerAccount $account, int $debit, int $credit): int
    {
        return $account->isDebitNormal() ? $debit - $credit : $credit - $debit;
    }

    /**
     * The chain hash of an entry (ADR 0009 §6): SHA-256 of the previous hash and a canonical JSON of the entry and
     * its ordered lines. LedgerVerifier recomputes it from the stored rows with the same function.
     *
     * @param  array<string, mixed>  $entry
     * @param  list<array<string, mixed>>  $lines
     */
    public static function hash(string $prevHash, array $entry, array $lines): string
    {
        $int = fn ($v) => $v === null ? null : (int) $v;
        $str = fn ($v) => $v === null ? null : (string) $v;
        $canonical = [
            'company_id' => (int) $entry['company_id'], 'entry_no' => (string) $entry['entry_no'], 'entry_date' => substr((string) $entry['entry_date'], 0, 10),
            'value_date' => substr((string) $entry['value_date'], 0, 10), 'period_id' => (int) $entry['period_id'], 'cycle_id' => $int($entry['cycle_id'] ?? null),
            'kind' => (string) $entry['kind'], 'source_type' => $str($entry['source_type'] ?? null), 'source_id' => $int($entry['source_id'] ?? null),
            'idempotency_key' => (string) $entry['idempotency_key'], 'payload_hash' => (string) $entry['payload_hash'], 'memo' => $str($entry['memo'] ?? null),
            'total_minor' => (int) $entry['total_minor'], 'currency' => (string) $entry['currency'], 'reverses_entry_id' => $int($entry['reverses_entry_id'] ?? null),
            'approval_id' => $int($entry['approval_id'] ?? null), 'posted_by_id' => $int($entry['posted_by_id'] ?? null),
            'lines' => array_map(fn (array $l) => [
                (int) $l['line_no'], (int) $l['ledger_account_id'], (int) $l['debit_minor'], (int) $l['credit_minor'],
                $str($l['sub_type'] ?? null), $int($l['sub_id'] ?? null), $int($l['member_id'] ?? null), $str($l['memo'] ?? null),
            ], $lines),
        ];

        return hash('sha256', $prevHash.json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** The SACCO's currency: fixed in its settings at setup (the company's before that). */
    public static function currency(int $companyId): string
    {
        return \App\Services\Sacco\PostingCurrency::of($companyId);
    }

    /** §4.2, before any write. @return string the SACCO's currency */
    private function validate(JournalDraft $d): string
    {
        if ($d->idempotencyKey === '' || mb_strlen($d->idempotencyKey) > 120) {
            throw BusinessRuleException::make('idempotency_key_required', 'Every posting needs its business event\'s key (at most 120 characters).');
        }
        if (! in_array($d->kind, SaccoJournalEntry::KINDS, true)) {
            throw BusinessRuleException::make('invalid_entry_kind', "\"{$d->kind}\" is not a kind of entry the books know.");
        }
        if (count($d->lines) < 2) {
            throw BusinessRuleException::make('unbalanced_entry', 'An entry needs at least two lines.');
        }
        $currency = self::currency($d->companyId);
        $debits = Amount::zero($currency);
        $credits = Amount::zero($currency);
        foreach ($d->lines as $i => $l) {
            $n = $i + 1;
            if ($l->debit->currency !== $currency || $l->credit->currency !== $currency) {
                throw BusinessRuleException::make('currency_mismatch', "Line {$n} is not in {$currency}, the SACCO's currency.");
            }
            if ($l->debit->isNegative() || $l->credit->isNegative() || ($l->debit->isPositive() === $l->credit->isPositive())) {
                throw BusinessRuleException::make('invalid_line', "Line {$n} must have exactly one side above zero.");
            }
            if (($l->subType === null) !== ($l->subId === null)) {
                throw BusinessRuleException::make('invalid_line', "Line {$n} names half a sub-ledger account.");
            }
            $debits = $debits->plus($l->debit);
            $credits = $credits->plus($l->credit);
        }
        if (! $debits->equals($credits)) {
            throw BusinessRuleException::make('unbalanced_entry', "Debits ({$debits->toMajorString()}) and credits ({$credits->toMajorString()}) must be equal.",
                ['debit_minor' => $debits->minor, 'credit_minor' => $credits->minor]);
        }

        return $currency;
    }

    private function replay(JournalDraft $d, string $payloadHash, bool $current = false): ?SaccoJournalEntry
    {
        $q = SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $d->companyId)->where('idempotency_key', $d->idempotencyKey);
        // Under REPEATABLE READ a plain read keeps the snapshot taken before the head lock; a locking read sees the latest commit.
        $hit = ($current ? $q->sharedLock() : $q)->first();
        if ($hit === null) {
            return null;
        }
        if ($hit->payload_hash !== $payloadHash) {
            throw BusinessRuleException::make('idempotency_conflict', "{$d->idempotencyKey} was already posted with different lines ({$hit->entry_no}).", ['entry_id' => $hit->id]);
        }

        return $hit->setRelation('lines', SaccoJournalLine::withoutGlobalScopes()->where('journal_entry_id', $hit->id)->orderBy('line_no')->get());
    }

    /** Every account exists in this company, is active and postable, and control accounts carry their sub-ledger. @return array<int, SaccoLedgerAccount> */
    private function accounts(JournalDraft $d): array
    {
        $ids = array_values(array_unique(array_map(fn (JournalLine $l) => $l->accountId, $d->lines)));
        $accounts = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $d->companyId)->whereIn('id', $ids)->get()->keyBy('id')->all();
        foreach ($d->lines as $i => $l) {
            $n = $i + 1;
            $a = $accounts[$l->accountId] ?? throw BusinessRuleException::make('account_not_found', "Line {$n}: that account is not in this SACCO's chart.", ['line' => $n]);
            if (! $a->is_active || ! $a->is_postable) {
                throw BusinessRuleException::make('account_not_postable', "Line {$n}: {$a->code} {$a->name} ".(! $a->is_active ? 'is inactive.' : 'is a header: post to one of its accounts.'));
            }
            $expected = $a->subType();
            if ($expected !== $l->subType) {
                throw BusinessRuleException::make('sub_ledger_required', $expected === null
                    ? "Line {$n}: {$a->code} {$a->name} is not a member account, so it takes no sub-ledger reference."
                    : "Line {$n}: {$a->code} {$a->name} is a control account: name the {$expected} account it moves.", ['line' => $n]);
            }
        }

        return $accounts;
    }

    /**
     * Lock the sub-ledger rows (ascending id, per type) and check each line's row exists in this company and maps
     * to that control account.
     *
     * @param  array<int, SaccoLedgerAccount>  $accounts
     * @return array<string, array<int, ?int>> type => sub id => member id
     */
    private function lockSubLedgers(JournalDraft $d, array $accounts): array
    {
        $wanted = [];
        foreach ($d->lines as $l) {
            if ($l->subType !== null) {
                $wanted[$l->subType][] = (int) $l->subId;
            }
        }
        ksort($wanted);
        $found = [];
        foreach ($wanted as $type => $ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
            $found[$type] = SubLedgers::for($type)->lock($d->companyId, $ids);
        }
        $members = [];
        foreach ($d->lines as $i => $l) {
            if ($l->subType === null) {
                continue;
            }
            $row = $found[$l->subType][$l->subId] ?? throw BusinessRuleException::make('sub_account_not_found', 'Line '.($i + 1).": that {$l->subType} account is not in this SACCO.", ['line' => $i + 1]);
            if (! in_array($l->accountId, $row['accounts'], true)) {
                throw BusinessRuleException::make('wrong_control_account', 'Line '.($i + 1).": that {$l->subType} account belongs under another control account than {$accounts[$l->accountId]->code}.", ['line' => $i + 1]);
            }
            $members[$l->subType][(int) $l->subId] = $row['member_id'];
        }

        return $members;
    }
}
