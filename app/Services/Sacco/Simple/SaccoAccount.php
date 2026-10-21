<?php

namespace App\Services\Sacco\Simple;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoAccountCategory;
use App\Models\SaccoAccountEntry;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoJournalLine;
use App\Models\SaccoLedgerAccount;
use App\Models\User;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Ledger\ReversalService;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoAudit;
use App\Support\Rules\SaccoRules;
use App\Support\Sacco\Amount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Flow 2, the SACCO's own account: money in (a grant, other income) or out (bank charges, office expenses), each with a
 * category from the SACCO's own list, an amount, a date, a method and notes. Each entry posts once to the ledger
 * (money in: Dr cash/bank/mobile money, Cr the category's income account; money out: the other way round) and so
 * moves the SACCO balance. A mistake is put right by reversing the entry (a mirror posting), never by editing it.
 *
 * Who: anyone with sacco_accounts (the treasurer, the manager, the owner).
 */
final class SaccoAccount
{
    /**
     * The categories every SACCO starts with: [direction, name, account code, account name, parent code]. A category
     * the SACCO adds itself posts to Other income (4900) or Other expenses (5790).
     */
    public const DEFAULTS = [
        ['in', 'Grant', '4950', 'Grants for operations', null],
        ['in', 'Other income', '4900', 'Other income', null],
        ['out', 'Bank charges', '5720', 'Bank charges', '5700'],
        ['out', 'Mobile money charges', '5710', 'Mobile money charges', '5700'],
        ['out', 'Office expenses', '5750', 'Office expenses', '5700'],
        ['out', 'Meetings', '5400', 'Governance (board, AGM)', null],
        ['out', 'Other expenses', '5790', 'Other expenses', '5700'],
    ];

    /** Where a SACCO's own new category posts. */
    public const GENERAL = ['in' => '4900', 'out' => '5790'];

    /** The SACCO's categories, made the first time they are asked for. @return list<SaccoAccountCategory> */
    public function categories(int $companyId, bool $activeOnly = false): array
    {
        $this->ensureCategories($companyId);

        return SaccoAccountCategory::withoutGlobalScopes()->where('company_id', $companyId)->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('direction')->orderBy('sort')->orderBy('name')->get()->all();
    }

    public function ensureCategories(int $companyId): void
    {
        if (SaccoAccountCategory::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            return;
        }
        DB::transaction(function () use ($companyId) {
            foreach (self::DEFAULTS as $i => [$direction, $name, $code, $accountName, $parent]) {
                SaccoAccountCategory::create(['company_id' => $companyId, 'direction' => $direction, 'name' => $name, 'sort' => $i * 10,
                    'ledger_account_id' => $this->ensureAccount($companyId, $code, $accountName, $direction === 'in' ? 'income' : 'expense', $parent)]);
            }
        });
    }

    /**
     * Add or change a category (sacco_settings or sacco_accounts): its name and whether it is offered. The account it posts
     * to is fixed when it is made.
     *
     * @param  array{name: string, direction?: string, is_active?: bool}  $data
     */
    public function saveCategory(Company $company, User $by, array $data, ?int $id = null): SaccoAccountCategory
    {
        $this->mayWrite($by);
        $cid = (int) $company->id;
        $this->ensureCategories($cid);
        $v = validator($data, ['name' => [$id === null ? 'required' : 'sometimes', 'string', 'max:80'], 'direction' => [$id === null ? 'required' : 'prohibited', Rule::in(SaccoAccountCategory::DIRECTIONS)],
            'is_active' => ['sometimes', 'boolean']]);
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_category', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }
        $d = $v->validated();
        if ($id !== null) {
            $cat = SaccoAccountCategory::withoutGlobalScopes()->where('company_id', $cid)->find($id) ?? throw BusinessRuleException::make('not_found', 'That category is not in this SACCO.');
            $cat->fill(array_intersect_key($d, array_flip(['name', 'is_active'])))->save();

            return $cat;
        }
        $direction = (string) $d['direction'];
        $account = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->where('code', self::GENERAL[$direction])->value('id');

        return SaccoAccountCategory::create(['company_id' => $cid, 'direction' => $direction, 'name' => trim((string) $d['name']), 'ledger_account_id' => (int) $account,
            'sort' => 1000]);
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return ['uuid' => ['nullable', 'uuid'], 'direction' => ['required', Rule::in(SaccoAccountCategory::DIRECTIONS)], 'category_id' => ['required', 'integer'],
            'amount_minor' => SaccoRules::minor(true, 1), 'value_date' => ['nullable', 'date'], 'method' => ['required', Rule::in(Money::METHODS)],
            'notes' => ['nullable', 'string', 'max:255']];
    }

    /**
     * Record money in or out. Idempotent on uuid (a double tap gives one entry); money out cannot take the account it
     * leaves below zero.
     *
     * @param  array<string, mixed>  $data  rules()
     * @return array{entry: SaccoAccountEntry, replayed: bool}
     */
    public function record(Company $company, User $by, array $data): array
    {
        $this->mayWrite($by);
        $cid = (int) $company->id;
        $v = validator($data, self::rules());
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_entry', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }
        $d = $v->validated();
        $uuid = strtolower((string) ($d['uuid'] ?? Str::uuid()));
        $this->ensureCategories($cid);
        $existing = SaccoAccountEntry::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first();
        if ($existing !== null) {
            if ((int) $existing->amount_minor !== (int) $d['amount_minor'] || $existing->direction !== $d['direction']) {
                throw BusinessRuleException::make('idempotency_conflict', 'That entry was already recorded with other details.');
            }

            return ['entry' => $existing, 'replayed' => true];
        }
        $cat = SaccoAccountCategory::withoutGlobalScopes()->where('company_id', $cid)->find((int) $d['category_id'])
            ?? throw BusinessRuleException::make('not_found', 'That category is not in this SACCO.');
        if ($cat->direction !== $d['direction']) {
            throw BusinessRuleException::make('invalid_category', $d['direction'] === 'in' ? 'Choose a money-in category.' : 'Choose a money-out category.');
        }
        if (! $cat->is_active) {
            throw BusinessRuleException::make('invalid_category', "{$cat->name} is switched off.");
        }
        $day = Carbon::parse($d['value_date'] ?? LoanBooks::today($cid))->startOfDay();
        [$entryDate, $valueDate] = LoanBooks::dates($cid, $day);
        $currency = PostingService::currency($cid);
        $amount = Amount::of((int) $d['amount_minor'], $currency);
        $money = Money::account($cid, (string) $d['method']);

        $entry = DB::transaction(function () use ($cid, $by, $d, $uuid, $cat, $entryDate, $valueDate, $currency, $amount, $money) {
            $row = SaccoAccountEntry::create(['company_id' => $cid, 'uuid' => $uuid, 'direction' => $d['direction'], 'category_id' => $cat->id, 'category_name' => $cat->name,
                'amount_minor' => $amount->minor, 'currency' => $currency, 'value_date' => $valueDate, 'method' => $d['method'], 'notes' => $d['notes'] ?? null,
                'recorded_by_id' => $by->id]);
            $memo = ($d['direction'] === 'in' ? 'Money in: ' : 'Money out: ').$cat->name.(filled($d['notes'] ?? null) ? ' ('.$d['notes'].')' : '');
            $draft = JournalDraft::make($cid, $d['direction'] === 'in' ? 'money_in' : 'money_out', 'acct:'.$uuid)->dated($entryDate, $valueDate)
                ->source('sacco_account_entries', (int) $row->id)->by((int) $by->id)->memo($memo);
            $d['direction'] === 'in' ? $draft->debit($money, $amount)->credit((int) $cat->ledger_account_id, $amount)
                : $draft->debit((int) $cat->ledger_account_id, $amount)->credit($money, $amount);
            if ($d['direction'] === 'out') {
                $draft->guard(function () use ($cid, $money, $amount) {
                    $left = (int) DB::table('sacco_account_balances')->where('company_id', $cid)->where('ledger_account_id', $money)->value('balance_minor');
                    if ($left < $amount->minor) {
                        throw BusinessRuleException::make('insufficient_funds', 'There is not enough money in that account: '.Amount::of(max(0, $left), $amount->currency)->toMajorString().' is there.',
                            ['available_minor' => max(0, $left)]);
                    }
                });
            }
            $je = (new PostingService())->post($draft);
            $row->forceFill(['journal_entry_id' => $je->id])->saveQuietly();
            SaccoAudit::record('sacco.account.'.($d['direction'] === 'in' ? 'money_in' : 'money_out'), $row, $cid, ['amount_minor' => $amount->minor, 'category' => $cat->name], (int) $by->id);

            return $row->fresh();
        });

        return ['entry' => $entry, 'replayed' => false];
    }

    /** Put a mistake right: a reversing entry on the ledger, the entry marked reversed. Never twice. */
    public function reverse(Company $company, User $by, int $entryId, string $reason): SaccoAccountEntry
    {
        $this->mayWrite($by);
        $cid = (int) $company->id;
        if (trim($reason) === '') {
            throw BusinessRuleException::make('reason_required', 'Say why the entry is reversed.');
        }

        return DB::transaction(function () use ($cid, $by, $entryId, $reason) {
            $row = SaccoAccountEntry::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->find($entryId)
                ?? throw BusinessRuleException::make('not_found', 'That entry is not in this SACCO.');
            if ($row->status === 'reversed') {
                throw BusinessRuleException::make('already_reversed', 'That entry was already reversed.');
            }
            $guards = [];
            if ($row->direction === 'in') {
                $money = Money::account($cid, (string) $row->method);
                $guards[] = function () use ($cid, $money, $row) {
                    $left = (int) DB::table('sacco_account_balances')->where('company_id', $cid)->where('ledger_account_id', $money)->value('balance_minor');
                    if ($left < (int) $row->amount_minor) {
                        throw BusinessRuleException::make('insufficient_funds', 'That money has already been spent: the account would go below zero.');
                    }
                };
            }
            $rev = (new ReversalService())->reverse($cid, (int) $row->journal_entry_id, $reason, null, (int) $by->id, $guards);
            $row->forceFill(['status' => 'reversed', 'reversal_entry_id' => $rev->id, 'reversal_reason' => mb_substr(trim($reason), 0, 255), 'reversed_by_id' => $by->id,
                'reversed_at' => now()])->save();
            SaccoAudit::record('sacco.account.reversed', $row, $cid, ['reason' => $reason], (int) $by->id);

            return $row->fresh();
        });
    }

    /**
     * The SACCO balance (cash, bank, mobile money) and its statement: every movement of the SACCO's money in the period
     * (members' deposits and loans included), with the running balance.
     *
     * @return array<string, mixed>
     */
    public function statement(int $companyId, ?string $from = null, ?string $to = null): array
    {
        $ids = Money::allIds($companyId);
        $today = LoanBooks::today($companyId);
        $to = Carbon::parse($to ?? $today)->toDateString();
        $from = Carbon::parse($from ?? Carbon::parse($to)->subDays(90))->toDateString();
        $base = SaccoJournalLine::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('ledger_account_id', $ids);
        $opening = (int) (clone $base)->where('entry_date', '<', $from)->sum(DB::raw('debit_minor - credit_minor'));
        $moves = (clone $base)->whereBetween('entry_date', [$from, $to])->groupBy('journal_entry_id')
            ->selectRaw('journal_entry_id, SUM(debit_minor) AS d, SUM(credit_minor) AS c')->get()->keyBy('journal_entry_id');
        $entries = SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $moves->keys())->orderBy('entry_date')->orderBy('id')->get();
        $running = $opening;
        $rows = [];
        foreach ($entries as $e) {
            $m = $moves[$e->id];
            $net = (int) $m->d - (int) $m->c;
            if ($net === 0) {
                continue; // money moved between the SACCO's own accounts (cash to bank)
            }
            $running += $net;
            $rows[] = ['entry_id' => (int) $e->id, 'entry_no' => $e->entry_no, 'date' => $e->entry_date->toDateString(), 'kind' => $e->kind,
                'description' => self::describe($e), 'in_minor' => max(0, $net), 'out_minor' => max(0, -$net), 'balance_minor' => $running];
        }

        return ['from' => $from, 'to' => $to, 'currency' => PostingService::currency($companyId), 'opening_minor' => $opening, 'closing_minor' => $running,
            'in_minor' => array_sum(array_column($rows, 'in_minor')), 'out_minor' => array_sum(array_column($rows, 'out_minor')),
            'balance' => Money::balances($companyId), 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    public static function present(SaccoAccountEntry $e): array
    {
        return ['id' => (int) $e->id, 'uuid' => $e->uuid, 'direction' => $e->direction, 'category_id' => (int) $e->category_id, 'category' => $e->category_name,
            'amount_minor' => (int) $e->amount_minor, 'currency' => $e->currency, 'value_date' => $e->value_date->toDateString(), 'method' => $e->method,
            'notes' => $e->notes, 'status' => $e->status, 'journal_entry_id' => $e->journal_entry_id, 'reversal_entry_id' => $e->reversal_entry_id,
            'reversal_reason' => $e->reversal_reason, 'reversed_at' => $e->reversed_at?->toIso8601String(), 'created_at' => $e->created_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public static function presentCategory(SaccoAccountCategory $c): array
    {
        return ['id' => (int) $c->id, 'direction' => $c->direction, 'name' => $c->name, 'is_active' => (bool) $c->is_active];
    }

    private static function describe(SaccoJournalEntry $e): string
    {
        if ($e->memo !== null && $e->memo !== '') {
            return (string) $e->memo;
        }

        return ['deposit' => 'Savings deposit', 'withdrawal' => 'Savings withdrawal', 'share_purchase' => 'Shares bought', 'entrance_fee' => 'Entrance fee',
            'loan_disbursement' => 'Loan paid out', 'loan_repayment' => 'Loan repayment', 'fee' => 'Fee', 'shareout' => 'Share-out', 'reversal' => 'Reversal',
            'opening_balance' => 'Opening balance'][$e->kind] ?? ucfirst(str_replace('_', ' ', (string) $e->kind));
    }

    private function mayWrite(User $by): void
    {
        SaccoAccess::authorize($by, 'sacco_accounts');
    }

    private function ensureAccount(int $companyId, string $code, string $name, string $type, ?string $parentCode): int
    {
        $id = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('code', $code)->value('id');
        if ($id !== null) {
            return (int) $id;
        }
        $parent = $parentCode === null ? null : SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('code', $parentCode)->value('id');

        return (int) (new \App\Services\Sacco\Ledger\ChartOfAccounts())->addAccount($companyId, ['code' => $code, 'name' => $name, 'type' => $type,
            'parent_id' => $parent === null ? null : (int) $parent], null)->id;
    }
}
