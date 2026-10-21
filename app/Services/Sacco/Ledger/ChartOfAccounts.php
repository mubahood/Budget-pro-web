<?php

namespace App\Services\Sacco\Ledger;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoAccountBalance;
use App\Models\SaccoLedgerAccount;
use App\Models\SaccoLedgerHead;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Each SACCO's chart of accounts (ADR 0009 §3): seeded from database/data/sacco_chart.php, the whole chart in office
 * mode and the rows marked `group` in group mode. Seeding also creates each account's balance row and the SACCO's
 * ledger head, so the books exist the moment their chart does. Seeding again only adds what is missing.
 *
 * Code finds the accounts it posts to by `system_key` (account()), never by code, so a SACCO may renumber nothing
 * the code relies on, and a new account comes through addAccount() behind an approval.
 */
final class ChartOfAccounts
{
    public const MODES = ['office', 'group'];

    /**
     * The chart's rows for a mode, keyed by code.
     *
     * @return array<string, array{code: string, name: string, type: string, parent: ?string, control_of: ?string, system_key: ?string, umra_line: ?string, group: bool, normal_side: string, sort: int}>
     */
    public static function rows(string $mode): array
    {
        if (! in_array($mode, self::MODES, true)) {
            throw BusinessRuleException::make('invalid_mode', 'Choose office mode or group mode.');
        }
        $out = [];
        foreach (require database_path('data/sacco_chart.php') as $i => [$code, $name, $type, $parent, $control, $key, $umra, $group, $normal]) {
            if ($mode === 'group' && ! $group) {
                continue;
            }
            $out[$code] = ['code' => $code, 'name' => $name, 'type' => $type, 'parent' => $parent, 'control_of' => $control, 'system_key' => $key,
                'umra_line' => $umra, 'group' => $group, 'normal_side' => $normal ?? (in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit'), 'sort' => ($i + 1) * 10];
        }

        return $out;
    }

    /** Create the chart, its balance rows and the ledger head; idempotent. @return int accounts created */
    public function seed(int $companyId, string $mode): int
    {
        $rows = self::rows($mode);
        $parents = array_filter(array_column($rows, 'parent'));

        return DB::transaction(function () use ($companyId, $rows, $parents) {
            $created = 0;
            $ids = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->pluck('id', 'code')->all();
            foreach ($rows as $r) {
                $code = $r['code']; // the array key of a numeric code is an int
                if (isset($ids[$code])) {
                    continue;
                }
                $account = SaccoLedgerAccount::create(['company_id' => $companyId, 'code' => $code, 'name' => $r['name'], 'type' => $r['type'],
                    'normal_side' => $r['normal_side'], 'parent_id' => $r['parent'] !== null ? ($ids[$r['parent']] ?? null) : null,
                    'is_postable' => ! in_array($code, $parents, true), 'control_of' => $r['control_of'], 'system_key' => $r['system_key'],
                    'umra_line' => $r['umra_line'], 'sort' => $r['sort']]);
                $ids[$code] = (int) $account->id;
                $created++;
            }
            $this->ensureBalances($companyId);
            if (! SaccoLedgerHead::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
                SaccoLedgerHead::create(['company_id' => $companyId, 'last_hash' => PostingService::GENESIS, 'entries_count' => 0]);
            }

            return $created;
        });
    }

    /** A balance row for every account that lacks one (the row PostingService locks). */
    public function ensureBalances(int $companyId): void
    {
        $have = SaccoAccountBalance::withoutGlobalScopes()->where('company_id', $companyId)->pluck('ledger_account_id')->all();
        foreach (SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->whereNotIn('id', $have)->pluck('id') as $id) {
            SaccoAccountBalance::create(['company_id' => $companyId, 'ledger_account_id' => $id]);
        }
    }

    /** The account the books post to for a system key (`cash.office`, `suspense.receipts`…), or `account_not_found`. */
    public static function account(int $companyId, string $systemKey): SaccoLedgerAccount
    {
        return SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('system_key', $systemKey)->first()
            ?? throw BusinessRuleException::make('account_not_found', "This SACCO's chart has no account for {$systemKey}.", ['system_key' => $systemKey]);
    }

    public static function id(int $companyId, string $systemKey): int
    {
        return (int) self::account($companyId, $systemKey)->id;
    }

    /**
     * The control accounts a sub-ledger row may be posted to (a savings account: its product's 21x0; a loan: 1300,
     * 1320, 1330, 1340), from the sub-ledger that owns it.
     *
     * @return list<int>
     */
    public static function controlFor(int $companyId, string $subType, int $subId): array
    {
        return DB::transaction(fn () => SubLedgers::for($subType)->lock($companyId, [$subId])[$subId]['accounts'] ?? []);
    }

    /** @return array<string, array<int, mixed>> */
    public static function accountRules(int $companyId): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^\d{4,6}$/', Rule::unique('sacco_ledger_accounts', 'code')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(SaccoLedgerAccount::TYPES)],
            'normal_side' => ['nullable', Rule::in(SaccoLedgerAccount::SIDES)],
            'parent_id' => ['nullable', 'integer'],
            'umra_line' => ['nullable', 'string', 'max:16'],
        ];
    }

    /**
     * A SACCO's own account (the executor of an approved `ledger_account_create`). It is never a control account
     * and has no system key: those come only with the chart. Under a parent, the parent becomes a header, which is
     * allowed only while the parent has no postings.
     *
     * @param  array{code: string, name: string, type: string, normal_side?: ?string, parent_id?: ?int, umra_line?: ?string}  $data
     */
    public function addAccount(int $companyId, array $data, ?int $userId): SaccoLedgerAccount
    {
        $validator = validator($data, self::accountRules($companyId));
        if ($validator->fails()) {
            throw BusinessRuleException::make('invalid_account', (string) $validator->errors()->first(), ['errors' => $validator->errors()->toArray()]);
        }

        return DB::transaction(function () use ($companyId, $data, $userId) {
            $parent = null;
            if (! empty($data['parent_id'])) {
                $parent = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->find((int) $data['parent_id'])
                    ?? throw BusinessRuleException::make('account_not_found', 'The parent account is not in this chart.');
                if ($parent->type !== $data['type']) {
                    throw BusinessRuleException::make('invalid_account', "An account under {$parent->code} must be of type {$parent->type}.");
                }
                if ($parent->is_postable && (SaccoLedgerAccount::hasLines((int) $parent->id) || $parent->system_key !== null || $parent->control_of !== null)) {
                    throw BusinessRuleException::make('account_in_use', "{$parent->code} is posted to, so it cannot become a header.");
                }
            }
            $account = SaccoLedgerAccount::create(['company_id' => $companyId, 'code' => $data['code'], 'name' => trim($data['name']), 'type' => $data['type'],
                'normal_side' => $data['normal_side'] ?? (in_array($data['type'], ['asset', 'expense'], true) ? 'debit' : 'credit'),
                'parent_id' => $parent?->id, 'umra_line' => $data['umra_line'] ?? $parent?->umra_line, 'created_by_id' => $userId, 'is_postable' => true, 'is_active' => true,
                'sort' => (int) SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->max('sort') + 10]);
            if ($parent !== null && $parent->is_postable) {
                $parent->update(['is_postable' => false]);
            }
            $this->ensureBalances($companyId);

            return $account;
        });
    }
}
