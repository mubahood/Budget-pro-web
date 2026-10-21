<?php

namespace App\Services\Sacco\Loans;

use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\SubLedger;
use Illuminate\Support\Facades\DB;

/**
 * The loan sub-ledger (ADR 0009 §7, AK-55): one loan row under four control accounts. Lines on 1300 (its product's
 * portfolio account), 1320, 1330 and 1340 name the loan (sub_type `loan`), and PostingService keeps the loan's
 * principal_outstanding, interest_due, penalty_due and fees_due caches through project(). A group's chart has no
 * 1330 or 1340 (AK-58), so its loans sit under 1300 and 1320 only.
 */
final class LoanLedger implements SubLedger
{
    /** The system key of each control account and the loan column it keeps. */
    public const COLUMNS = ['loans.interest' => 'interest_due_minor', 'loans.penalty' => 'penalty_due_minor', 'loans.fees' => 'fees_due_minor'];

    /** @var array<int, array<int, string>> company => account id => column (portfolio accounts added per product) */
    private static array $columns = [];

    public static function forget(): void
    {
        self::$columns = [];
    }

    public function type(): string
    {
        return 'loan';
    }

    public function lock(int $companyId, array $ids): array
    {
        $rows = DB::table('sacco_loans as l')->join('sacco_loan_products as p', 'p.id', '=', 'l.product_id')
            ->where('l.company_id', $companyId)->whereIn('l.id', $ids)->orderBy('l.id')->lockForUpdate()
            ->get(['l.id', 'l.member_id', 'p.portfolio_account_id']);
        $map = $this->columns($companyId);
        $others = array_keys(array_filter($map, fn ($c) => $c !== 'principal_outstanding_minor'));
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] = ['accounts' => array_values(array_unique(array_merge([(int) $r->portfolio_account_id], $others))), 'member_id' => (int) $r->member_id];
        }

        return $out;
    }

    public function project(int $companyId, int $id, int $accountId, int $signedMinor): void
    {
        $column = $this->columns($companyId)[$accountId] ?? 'principal_outstanding_minor'; // a product's own portfolio account
        DB::table('sacco_loans')->where('company_id', $companyId)->where('id', $id)->update([$column => DB::raw($column.' + ('.$signedMinor.')'), 'updated_at' => now()]);
    }

    public function balances(int $companyId): array
    {
        $map = $this->columns($companyId);
        $byColumn = [];
        foreach ($map as $accountId => $column) {
            if ($column !== 'principal_outstanding_minor') {
                $byColumn[$column] = $accountId;
            }
        }
        $out = [];
        $rows = DB::table('sacco_loans as l')->join('sacco_loan_products as p', 'p.id', '=', 'l.product_id')->where('l.company_id', $companyId)
            ->get(['l.id', 'p.portfolio_account_id', 'l.principal_outstanding_minor', 'l.interest_due_minor', 'l.penalty_due_minor', 'l.fees_due_minor']);
        foreach ($rows as $r) {
            $out[] = ['sub_id' => (int) $r->id, 'ledger_account_id' => (int) $r->portfolio_account_id, 'balance_minor' => (int) $r->principal_outstanding_minor];
            foreach ($byColumn as $column => $accountId) {
                $out[] = ['sub_id' => (int) $r->id, 'ledger_account_id' => $accountId, 'balance_minor' => (int) $r->{$column}];
            }
        }

        return $out;
    }

    /** @return array<int, string> account id => loan column, for this company's chart */
    private function columns(int $companyId): array
    {
        if (! isset(self::$columns[$companyId])) {
            $map = [];
            $ids = DB::table('sacco_ledger_accounts')->where('company_id', $companyId)->whereIn('system_key', array_merge(['loans.principal'], array_keys(self::COLUMNS)))
                ->pluck('id', 'system_key');
            if (isset($ids['loans.principal'])) {
                $map[(int) $ids['loans.principal']] = 'principal_outstanding_minor';
            }
            foreach (self::COLUMNS as $key => $column) {
                if (isset($ids[$key])) {
                    $map[(int) $ids[$key]] = $column;
                }
            }
            if ($map === []) {
                return []; // no chart yet: do not cache an empty map
            }
            self::$columns[$companyId] = $map;
        }

        return self::$columns[$companyId];
    }

    /** The control account ids of a company's loans, by system key, for PostingRules. @return array{principal: int, interest: int, penalty: ?int, fees: ?int} */
    public static function accounts(int $companyId): array
    {
        $find = fn (string $key) => DB::table('sacco_ledger_accounts')->where('company_id', $companyId)->where('system_key', $key)->value('id');

        return ['principal' => ChartOfAccounts::id($companyId, 'loans.principal'), 'interest' => ChartOfAccounts::id($companyId, 'loans.interest'),
            'penalty' => ($p = $find('loans.penalty')) === null ? null : (int) $p, 'fees' => ($f = $find('loans.fees')) === null ? null : (int) $f];
    }
}
