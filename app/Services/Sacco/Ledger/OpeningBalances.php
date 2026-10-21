<?php

namespace App\Services\Sacco\Ledger;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoApproval;
use App\Models\SaccoLedgerAccount;
use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\SaccoSetting;
use App\Models\User;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoTransactions;
use App\Services\Sacco\Savings\SavingsAccounts;
use App\Services\Sacco\Shares\ShareService;
use App\Support\LocalDate;
use App\Support\Sacco\Amount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Opening balances (04 §5.1, §13.2 `POST sacco/opening-balances/import`, P1.13): a SACCO moving to Akiba brings its
 * books over in two CSV files, each previewed first and posted only after a second officer approves
 * (`opening_balances`: maker sacco_accounts, checker sacco_approve). Every row posts against 3900 Opening balance
 * equity with the key `open:{import uuid}:{row}`, dated the import's day (`as_of`, in an open month):
 *
 *   members  member_no, shares (a count), savings_{PRODUCT CODE} (one column per product), loan_principal (P2)
 *            → Cr each savings account and the share account, Dr 3900. Accounts are opened when missing.
 *   ledger   account_code, debit, credit, memo: cash, bank, investments, reserves, payables… (not the member
 *            control accounts, which only member rows move) → the account against 3900.
 *
 * Amounts are in major units as people type them ("150000", "1250.50"), read exactly (never through a float). Once
 * both files are in, 3900 nets to zero; until then status() shows the gap and LedgerVerifier warns.
 */
final class OpeningBalances
{
    public const KINDS = ['members', 'ledger'];

    public const MAX_ROWS = 5000;

    /** @var array<string, callable(int, array<string, mixed>, SaccoMember, string): list<array{0: int, 1: int, 2?: ?SubRef}>> extra member columns (P2: loan_principal) */
    private static array $memberColumns = [];

    /**
     * A member column another module owns (P2 registers `loan_principal`): its handler opens what it needs and
     * returns the lines to post (signed minor: + debit, − credit) for the row.
     *
     * @param  callable(int, array<string, mixed>, SaccoMember, string): list<array{0: int, 1: int, 2?: ?SubRef}>  $handler
     */
    public static function memberColumn(string $column, callable $handler): void
    {
        self::$memberColumns[$column] = $handler;
    }

    /**
     * Read and check a file without writing anything.
     *
     * @return array{kind: string, as_of: string, rows: list<array<string, mixed>>, errors: list<array{row: int, message: string}>, totals: array<string, int>}
     */
    public function preview(Company $company, string $kind, string $csv, ?string $asOf = null): array
    {
        $cid = (int) $company->id;
        $settings = SaccoSetting::for($cid) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
        if (! in_array($kind, self::KINDS, true)) {
            throw BusinessRuleException::make('invalid_import', 'Import members or ledger balances.');
        }
        $date = Carbon::parse($asOf ?? LocalDate::today($cid)->toDateString())->startOfDay();
        if ($date->gt(LocalDate::today($cid))) {
            throw BusinessRuleException::make('invalid_date', 'Opening balances are dated today or earlier.');
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', trim($csv)) ?: []), fn ($l) => $l !== ''));
        if (count($lines) < 2) {
            throw BusinessRuleException::make('invalid_import', 'The file needs a header row and at least one row.');
        }
        if (count($lines) - 1 > self::MAX_ROWS) {
            throw BusinessRuleException::make('invalid_import', 'At most '.self::MAX_ROWS.' rows per file: split it.');
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv(array_shift($lines)));
        $rows = [];
        $errors = [];
        $totals = ['debit_minor' => 0, 'credit_minor' => 0];
        foreach ($lines as $i => $line) {
            $n = $i + 2;
            $values = str_getcsv($line);
            $raw = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
            try {
                $row = $kind === 'members' ? $this->memberRow($cid, $settings, $raw) : $this->ledgerRow($cid, $settings, $raw);
                $row['row'] = $n;
                foreach ($row['lines'] as [, $signed]) {
                    $signed > 0 ? $totals['debit_minor'] += $signed : $totals['credit_minor'] -= $signed;
                }
                $rows[] = $row;
            } catch (BusinessRuleException $e) {
                $errors[] = ['row' => $n, 'message' => $e->getMessage()];
            }
        }
        $totals['opening_effect_minor'] = $totals['credit_minor'] - $totals['debit_minor']; // what 3900 is debited (+) by this file

        return ['kind' => $kind, 'as_of' => $date->toDateString(), 'rows' => $rows, 'errors' => $errors, 'totals' => $totals];
    }

    /** Ask for a file to be posted (OpeningBalancesExecutor::prepare checks it again and freezes its parsed rows). */
    public function request(Company $company, string $kind, string $csv, User $by, ?string $asOf = null, ?string $uuid = null): SaccoApproval
    {
        return app(SaccoApprovals::class)->request($company, 'opening_balances', ['kind' => $kind, 'csv' => $csv, 'as_of' => $asOf], $by, $uuid);
    }

    /**
     * The frozen payload of an import request: refused while any row has an error.
     *
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, subject_type: ?string, subject_id: ?int, amount_minor: ?int}
     */
    public function prepare(int $companyId, array $payload): array
    {
        $company = Company::withoutGlobalScopes()->findOrFail($companyId);
        $preview = $this->preview($company, (string) ($payload['kind'] ?? ''), (string) ($payload['csv'] ?? ''), isset($payload['as_of']) ? (string) $payload['as_of'] : null);
        if ($preview['errors'] !== []) {
            throw BusinessRuleException::make('invalid_import', count($preview['errors']).' row(s) have errors: fix them and import again.', ['errors' => $preview['errors']]);
        }
        if ($preview['rows'] === []) {
            throw BusinessRuleException::make('invalid_import', 'Nothing to import.');
        }

        return ['payload' => ['kind' => $preview['kind'], 'as_of' => $preview['as_of'], 'rows' => $preview['rows'], 'totals' => $preview['totals']],
            'subject_type' => null, 'subject_id' => null, 'amount_minor' => max($preview['totals']['debit_minor'], $preview['totals']['credit_minor'])];
    }

    /**
     * Post an approved import (OpeningBalancesExecutor), one entry per row. Rows already posted (the same key) are
     * replays, so an execution that stopped part way is finished by running it again.
     *
     * @return array{entries: int, first_entry_id: ?int}
     */
    public function post(SaccoApproval $approval): array
    {
        $cid = (int) $approval->company_id;
        $p = $approval->payload;
        $settings = SaccoSetting::for($cid) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
        $rules = new PostingRules($cid, $settings->mode);
        $posting = new PostingService();
        $first = null;
        $n = 0;
        SaccoTransactions::lockHead($cid);
        foreach ($p['rows'] as $row) {
            $lines = [];
            $memberId = null;
            if ($p['kind'] === 'members') {
                $member = SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->findOrFail((int) $row['member_id']);
                $memberId = (int) $member->id;
                $lines = $this->memberLines($cid, $settings, $row, $member, (string) $p['as_of']);
            } else {
                $lines = array_map(fn ($l) => [(int) $l[0], (int) $l[1]], $row['lines']);
            }
            if ($lines === []) {
                continue;
            }
            $draft = $rules->opening('open:'.$approval->uuid.':'.$row['row'], $settings->currency, $lines, $memberId)
                ->dated((string) $p['as_of'], (string) $p['as_of'])->source('sacco_approvals', (int) $approval->id)->approval((int) $approval->id)
                ->by($approval->requested_by_id === null ? null : (int) $approval->requested_by_id)->memo('Opening balance, row '.$row['row'].(isset($row['member_no']) ? ' ('.$row['member_no'].')' : ''));
            $entry = $posting->post($draft);
            $first ??= (int) $entry->id;
            $n++;
        }

        return ['entries' => $n, 'first_entry_id' => $first];
    }

    /**
     * How far the opening is: 3900's balance (zero when complete), and what the member and ledger imports put into it.
     *
     * @return array{opening_minor: int, complete: bool, members_minor: int, ledger_minor: int, imports: int}
     */
    public static function status(int $companyId): array
    {
        $opening = ChartOfAccounts::account($companyId, 'opening');
        $balance = (int) DB::table('sacco_account_balances')->where('ledger_account_id', $opening->id)->value('balance_minor');
        $byKind = ['members' => 0, 'ledger' => 0];
        $imports = 0;
        foreach (SaccoApproval::withoutGlobalScopes()->where('company_id', $companyId)->where('action', 'opening_balances')->where('status', 'executed')->get() as $a) {
            $imports++;
            $kind = (string) ($a->payload['kind'] ?? 'ledger');
            $entries = DB::table('sacco_journal_entries')->where('company_id', $companyId)->where('kind', 'opening_balance')->where('approval_id', $a->id)->pluck('id');
            $lines = DB::table('sacco_journal_lines')->whereIn('journal_entry_id', $entries)->where('ledger_account_id', $opening->id)
                ->selectRaw('COALESCE(SUM(credit_minor),0) - COALESCE(SUM(debit_minor),0) AS net')->value('net');
            $byKind[$kind] += (int) $lines;
        }

        return ['opening_minor' => $balance, 'complete' => $balance === 0 && $imports > 0, 'members_minor' => $byKind['members'], 'ledger_minor' => $byKind['ledger'], 'imports' => $imports];
    }

    // ---------------------------------------------------------------- rows

    /** @param array<string, string> $raw @return array<string, mixed> */
    private function memberRow(int $cid, SaccoSetting $settings, array $raw): array
    {
        $no = trim((string) ($raw['member_no'] ?? ''));
        $member = $no === '' ? null : SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->where('member_no', $no)->where('is_deleted', 0)->first();
        if ($member === null) {
            throw BusinessRuleException::make('member_not_found', "No member {$no} in this SACCO: import the members first.");
        }
        $row = ['member_id' => (int) $member->id, 'member_no' => $no, 'savings' => [], 'shares' => 0, 'extra' => []];
        foreach ($raw as $column => $value) {
            $value = trim((string) $value);
            if ($value === '' || in_array($column, ['member_no', 'name', 'member_name'], true)) {
                continue;
            }
            if ($column === 'shares') {
                if (! ctype_digit($value)) {
                    throw BusinessRuleException::make('invalid_import', "Shares are a whole count, not \"{$value}\".");
                }
                $row['shares'] = (int) $value;
            } elseif (str_starts_with($column, 'savings_')) {
                $code = strtoupper(substr($column, 8));
                $product = SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $cid)->where('code', $code)->first()
                    ?? throw BusinessRuleException::make('product_not_found', "No savings product {$code}.");
                $minor = Amount::fromMajor($value, $settings->currency)->minor;
                if ($minor < 0) {
                    throw BusinessRuleException::make('invalid_import', 'A savings balance is never below zero.');
                }
                if ($minor > 0) {
                    $row['savings'][(string) $product->id] = $minor;
                }
            } elseif (isset(self::$memberColumns[$column])) {
                $row['extra'][$column] = Amount::fromMajor($value, $settings->currency)->minor;
            } elseif ($column === 'loan_principal') {
                if (Amount::fromMajor($value, $settings->currency)->minor !== 0) {
                    throw BusinessRuleException::make('loans_later', 'Opening loans are imported with the loans module: leave loan_principal empty for now.');
                }
            } else {
                throw BusinessRuleException::make('invalid_import', "Unknown column \"{$column}\".");
            }
        }
        $row['lines'] = [];
        foreach ($row['savings'] as $minor) {
            $row['lines'][] = [0, -$minor];
        }
        if ($row['shares'] > 0) {
            $row['lines'][] = [0, -$row['shares'] * (int) $settings->share_par_minor];
        }

        return $row;
    }

    /** @param array<string, string> $raw @return array<string, mixed> */
    private function ledgerRow(int $cid, SaccoSetting $settings, array $raw): array
    {
        $code = trim((string) ($raw['account_code'] ?? ''));
        $account = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $cid)->where('code', $code)->first()
            ?? throw BusinessRuleException::make('account_not_found', "No account {$code} in this chart.");
        if ($account->control_of !== null || $account->system_key === 'opening' || ! $account->is_postable || ! $account->is_active) {
            throw BusinessRuleException::make('invalid_import', "{$account->code} {$account->name} ".($account->control_of !== null ? 'is a member control account: import it with the member rows.' : 'cannot take an opening balance.'));
        }
        $dr = trim((string) ($raw['debit'] ?? '')) === '' ? 0 : Amount::fromMajor(trim((string) $raw['debit']), $settings->currency)->minor;
        $cr = trim((string) ($raw['credit'] ?? '')) === '' ? 0 : Amount::fromMajor(trim((string) $raw['credit']), $settings->currency)->minor;
        if ($dr < 0 || $cr < 0 || ($dr > 0) === ($cr > 0)) {
            throw BusinessRuleException::make('invalid_import', 'Each row has a debit or a credit, not both.');
        }

        return ['account_id' => (int) $account->id, 'account_code' => $account->code, 'memo' => mb_substr(trim((string) ($raw['memo'] ?? '')), 0, 120), 'lines' => [[(int) $account->id, $dr - $cr]]];
    }

    /**
     * The lines of a member row, opening the accounts it names when missing (under the ledger head lock).
     *
     * @param  array<string, mixed>  $row
     * @return list<array{0: int, 1: int, 2?: ?SubRef}>
     */
    private function memberLines(int $cid, SaccoSetting $settings, array $row, SaccoMember $member, string $asOf): array
    {
        $company = Company::withoutGlobalScopes()->findOrFail($cid);
        $lines = [];
        foreach ($row['savings'] as $productId => $minor) {
            $account = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $cid)->where('member_id', $member->id)->where('product_id', (int) $productId)
                ->where('status', '!=', 'closed')->first()
                ?? (new SavingsAccounts())->open($company, (int) $member->id, (int) $productId, null, ['opened_on' => min($asOf, $member->joined_on->toDateString())]);
            $lines[] = [(int) $account->ledger_account_id, -(int) $minor, SaccoTransactions::ref($account)];
        }
        if ((int) $row['shares'] > 0) {
            $shares = new ShareService();
            $account = $shares->account($cid, (int) $member->id);
            ShareService::addShares((int) $account->id, (int) $row['shares']);
            $lines[] = [(int) $account->ledger_account_id, -(int) $row['shares'] * (int) $settings->share_par_minor, ShareService::ref($account)];
        }
        foreach ($row['extra'] as $column => $minor) {
            array_push($lines, ...(self::$memberColumns[$column])($cid, ['minor' => $minor] + $row, $member, $asOf));
        }

        return $lines;
    }
}
