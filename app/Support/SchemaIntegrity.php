<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema integrity pass (plan Part D, P4-5): foreign keys (RESTRICT for
 * money-bearing links) and status checks. Per-company uniqueness of master data
 * (names, phones, SKUs, barcodes) is enforced on online writes, not in the
 * database: phones create those rows offline and two devices creating the same
 * one are kept and merged (plan Appendix E, DuplicateService, DECISIONS E40). A constraint is
 * only added when the data already satisfies it; anything else is reported by
 * `php artisan schema:integrity` so it can be cleaned first (plan B.3 "constraints
 * after backfill verified"). Safe to run repeatedly.
 */
class SchemaIntegrity
{
    /** [child table, column, parent table, on delete] */
    public const FOREIGN_KEYS = [
        ['stock_sub_categories', 'stock_category_id', 'stock_categories', 'restrict'],
        ['stock_items', 'stock_sub_category_id', 'stock_sub_categories', 'restrict'],
        ['stock_items', 'company_id', 'companies', 'restrict'],
        ['stock_records', 'stock_item_id', 'stock_items', 'restrict'],
        ['stock_records', 'sale_record_id', 'sale_records', 'restrict'],
        ['stock_records', 'location_id', 'locations', 'restrict'],
        ['sale_records', 'company_id', 'companies', 'restrict'],
        ['sale_record_items', 'sale_record_id', 'sale_records', 'restrict'],
        ['sale_record_items', 'stock_item_id', 'stock_items', 'restrict'],
        ['payments', 'sale_record_id', 'sale_records', 'restrict'],
        ['payments', 'customer_id', 'customers', 'restrict'],
        ['payments', 'shift_id', 'shifts', 'restrict'],
        ['sale_returns', 'sale_record_id', 'sale_records', 'restrict'],
        ['sale_return_items', 'sale_return_id', 'sale_returns', 'restrict'],
        ['goods_receipts', 'supplier_id', 'suppliers', 'restrict'],
        ['goods_receipts', 'purchase_order_id', 'purchase_orders', 'restrict'],
        ['goods_receipt_items', 'goods_receipt_id', 'goods_receipts', 'restrict'],
        ['goods_receipt_items', 'stock_item_id', 'stock_items', 'restrict'],
        ['purchase_orders', 'supplier_id', 'suppliers', 'restrict'],
        ['purchase_order_items', 'purchase_order_id', 'purchase_orders', 'cascade'],
        ['purchase_order_items', 'stock_item_id', 'stock_items', 'restrict'],
        ['purchase_returns', 'supplier_id', 'suppliers', 'restrict'],
        ['purchase_return_items', 'purchase_return_id', 'purchase_returns', 'restrict'],
        ['stock_take_items', 'stock_take_id', 'stock_takes', 'cascade'],
        ['stock_transfer_items', 'stock_transfer_id', 'stock_transfers', 'restrict'],
        ['stock_transfers', 'from_location_id', 'locations', 'restrict'],
        ['stock_transfers', 'to_location_id', 'locations', 'restrict'],
        ['stock_levels', 'location_id', 'locations', 'restrict'],
        ['stock_levels', 'stock_item_id', 'stock_items', 'restrict'],
        ['stock_batches', 'stock_item_id', 'stock_items', 'restrict'],
        ['stock_record_batches', 'stock_record_id', 'stock_records', 'restrict'],
        ['stock_record_batches', 'stock_batch_id', 'stock_batches', 'restrict'],
        ['product_barcodes', 'stock_item_id', 'stock_items', 'cascade'],
        ['product_stats', 'stock_item_id', 'stock_items', 'cascade'],
        ['financial_records', 'financial_category_id', 'financial_categories', 'restrict'],
        ['subscriptions', 'company_id', 'companies', 'restrict'],
        ['invites', 'company_id', 'companies', 'cascade'],
        ['company_role_permissions', 'company_id', 'companies', 'cascade'],
        ['locations', 'company_id', 'companies', 'restrict'],
        // Akiba Ledger (ADR 0009 §5): the books keep their company, and nothing a journal points to is ever deleted.
        ['sacco_ledger_accounts', 'company_id', 'companies', 'restrict'],
        ['sacco_ledger_accounts', 'parent_id', 'sacco_ledger_accounts', 'restrict'],
        ['sacco_ledger_heads', 'company_id', 'companies', 'restrict'],
        ['sacco_periods', 'company_id', 'companies', 'restrict'],
        ['sacco_period_balances', 'period_id', 'sacco_periods', 'restrict'],
        ['sacco_period_balances', 'ledger_account_id', 'sacco_ledger_accounts', 'restrict'],
        ['sacco_journal_entries', 'company_id', 'companies', 'restrict'],
        ['sacco_journal_entries', 'period_id', 'sacco_periods', 'restrict'],
        ['sacco_journal_entries', 'reverses_entry_id', 'sacco_journal_entries', 'restrict'],
        ['sacco_journal_lines', 'journal_entry_id', 'sacco_journal_entries', 'restrict'],
        ['sacco_journal_lines', 'ledger_account_id', 'sacco_ledger_accounts', 'restrict'],
        ['sacco_account_balances', 'ledger_account_id', 'sacco_ledger_accounts', 'restrict'],
        ['sacco_settings', 'company_id', 'companies', 'restrict'],
        ['sacco_officers', 'company_id', 'companies', 'restrict'],
        ['sacco_officers', 'member_id', 'sacco_members', 'restrict'],
        ['sacco_members', 'company_id', 'companies', 'restrict'],
        ['sacco_member_relations', 'member_id', 'sacco_members', 'restrict'],
        ['sacco_member_documents', 'member_id', 'sacco_members', 'restrict'],
        ['sacco_member_documents', 'file_id', 'files', 'restrict'],
        ['sacco_member_logins', 'member_id', 'sacco_members', 'restrict'],
        ['sacco_approvals', 'company_id', 'companies', 'restrict'],
        ['sacco_approval_votes', 'approval_id', 'sacco_approvals', 'restrict'],
        ['sacco_loan_products', 'company_id', 'companies', 'restrict'],
        ['sacco_loan_products', 'portfolio_account_id', 'sacco_ledger_accounts', 'restrict'],
    ];

    /** [table, column, allowed values] — enforced with CHECK constraints (MySQL 8.0.16+ / MariaDB 10.2+). */
    public const CHECKS = [
        ['sale_records', 'status', ['Completed', 'Voided', 'Partially Refunded', 'Refunded', 'Pending', 'Draft']],
        ['purchase_orders', 'status', ['draft', 'sent', 'partially_received', 'received', 'cancelled']],
        ['subscriptions', 'status', ['trialing', 'active', 'past_due', 'canceled', 'expired']],
        ['shifts', 'status', ['open', 'closed']],
        ['company_members', 'status', ['active', 'inactive', 'invited', 'revoked']],
        ['invites', 'status', ['pending', 'accepted', 'revoked']],
        ['sacco_ledger_accounts', 'type', ['asset', 'liability', 'equity', 'income', 'expense']],
        ['sacco_ledger_accounts', 'normal_side', ['debit', 'credit']],
        ['sacco_ledger_accounts', 'control_of', ['savings', 'shares', 'loan_principal', 'loan_interest', 'loan_penalty', 'loan_fees', 'fines', 'fund']],
        ['sacco_periods', 'status', ['open', 'closing', 'closed']],
        ['sacco_journal_lines', 'sub_type', ['savings', 'shares', 'loan', 'fine', 'fund_due']],
        ['sacco_settings', 'mode', ['office', 'group']],
        ['sacco_settings', 'receipts_channel', ['sms', 'whatsapp', 'app', 'none']],
        ['sacco_settings', 'employee_loans_policy', ['salary_advance_only', 'committee']],
        ['sacco_members', 'status', ['applicant', 'active', 'dormant', 'suspended', 'exited', 'deceased', 'rejected']],
        ['sacco_members', 'kyc_status', ['pending', 'verified', 'rejected']],
        ['sacco_members', 'kind', ['individual', 'group', 'institution']],
        ['sacco_member_relations', 'role', ['nominee', 'next_of_kin']],
        ['sacco_member_documents', 'type', ['national_id', 'passport', 'photo', 'signature', 'application', 'nomination', 'other']],
        ['sacco_approvals', 'status', ['pending', 'approved', 'rejected', 'cancelled', 'expired', 'executed']],
        ['sacco_approval_votes', 'decision', ['approve', 'reject']],
        ['sacco_loan_products', 'interest_method', ['reducing_annuity', 'reducing_equal_principal', 'flat']],
        ['sacco_loan_products', 'rate_period', ['month', 'year']],
        ['sacco_loan_products', 'repayment_frequency', ['weekly', 'fortnightly', 'monthly']],
        ['sacco_officers', 'position', ['chairperson', 'vice_chair', 'treasurer', 'secretary', 'manager', 'board_member', 'loans_committee', 'supervisory', 'key_holder', 'money_counter', 'teller', 'custom']],
    ];

    /**
     * [table, constraint name, condition every row must meet]: rules wider than one column's values (ADR 0009 §5).
     * Added like CHECKS: only when the data meets them, only where the database enforces CHECK.
     */
    public const EXPRESSIONS = [
        ['sacco_journal_lines', 'chk_sacco_journal_lines_one_side', '((`debit_minor` = 0) <> (`credit_minor` = 0))'],
        ['sacco_journal_lines', 'chk_sacco_journal_lines_sub_pair', '((`sub_type` IS NULL) = (`sub_id` IS NULL))'],
        ['sacco_journal_entries', 'chk_sacco_journal_entries_total', '(`total_minor` > 0)'],
        ['sacco_journal_entries', 'chk_sacco_journal_entries_dates', '(`value_date` <= `entry_date`)'],
    ];

    /** Models whose status is guarded on save on every database (CHECK needs MySQL 8.0.16+ / MariaDB 10.2+). */
    public const STATUS_MODELS = [
        'sale_records' => \App\Models\SaleRecord::class,
        'purchase_orders' => \App\Models\PurchaseOrder::class,
        'subscriptions' => \App\Models\Subscription::class,
        'shifts' => \App\Models\Shift::class,
        'company_members' => \App\Models\CompanyMember::class,
        'sacco_ledger_accounts' => \App\Models\SaccoLedgerAccount::class,
        'sacco_periods' => \App\Models\SaccoPeriod::class,
        'sacco_journal_lines' => \App\Models\SaccoJournalLine::class,
        'sacco_settings' => \App\Models\SaccoSetting::class,
        'sacco_officers' => \App\Models\SaccoOfficer::class,
        'sacco_members' => \App\Models\SaccoMember::class,
        'sacco_member_relations' => \App\Models\SaccoMemberRelation::class,
        'sacco_member_documents' => \App\Models\SaccoMemberDocument::class,
        'sacco_approvals' => \App\Models\SaccoApproval::class,
        'sacco_approval_votes' => \App\Models\SaccoApprovalVote::class,
        'sacco_loan_products' => \App\Models\SaccoLoanProduct::class,
    ];

    /** Refuse unknown statuses in the application too (plan Part D "enums for statuses"). */
    public static function guardModels(): void
    {
        foreach (self::CHECKS as [$table, $column, $values]) {
            $class = self::STATUS_MODELS[$table] ?? null;
            if ($class === null) {
                continue;
            }
            $class::saving(function ($model) use ($column, $values, $table) {
                $v = $model->getAttribute($column);
                if ($v !== null && ! in_array($v, $values, true)) {
                    throw \App\Exceptions\BusinessRuleException::make('invalid_status', "\"{$v}\" is not a valid {$column} for {$table}.", ['allowed' => $values]);
                }
            });
        }
    }

    public static function checksSupported(): bool
    {
        $v = (string) (DB::selectOne('select version() as v')->v ?? '');

        return str_contains(strtolower($v), 'mariadb') ? version_compare($v, '10.2.1', '>=') : version_compare($v, '8.0.16', '>=');
    }

    private static function fkName(string $table, string $column): string
    {
        return substr("fk_{$table}_{$column}", 0, 64);
    }

    private static function hasConstraint(string $table, string $name): bool
    {
        return DB::table('information_schema.table_constraints')->where('table_schema', DB::getDatabaseName())->where('table_name', $table)->where('constraint_name', $name)->exists();
    }

    private static function columnType(string $table, string $column): ?string
    {
        return DB::table('information_schema.columns')->where('table_schema', DB::getDatabaseName())->where('table_name', $table)->where('column_name', $column)->value('column_type');
    }

    /**
     * Check every constraint; with $apply, add the ones the data allows.
     *
     * @return array<int, array{kind: string, target: string, status: string, detail?: string}>
     */
    public static function run(bool $apply = false): array
    {
        if (DB::getDriverName() !== 'mysql') {
            return [];
        }
        $out = [];
        foreach (self::FOREIGN_KEYS as [$table, $column, $parent, $onDelete]) {
            $target = "{$table}.{$column} → {$parent}";
            if (! Schema::hasTable($table) || ! Schema::hasTable($parent) || ! Schema::hasColumn($table, $column)) {
                $out[] = ['kind' => 'fk', 'target' => $target, 'status' => 'missing_table'];

                continue;
            }
            $name = self::fkName($table, $column);
            if (self::hasConstraint($table, $name)) {
                $out[] = ['kind' => 'fk', 'target' => $target, 'status' => 'ok'];

                continue;
            }
            $orphans = DB::table("{$table} as c")->whereNotNull("c.{$column}")->where("c.{$column}", '!=', 0)
                ->whereNotExists(fn ($q) => $q->from("{$parent} as p")->whereColumn('p.id', "c.{$column}"))->count();
            if ($orphans > 0) {
                $out[] = ['kind' => 'fk', 'target' => $target, 'status' => 'skipped', 'detail' => "{$orphans} row(s) point to a missing {$parent} row"];

                continue;
            }
            $childType = (string) self::columnType($table, $column);
            $parentType = (string) self::columnType($parent, 'id');
            if ($apply && $childType !== $parentType) {
                $negatives = DB::table($table)->where($column, '<', 0)->count();
                if ($negatives === 0 && str_starts_with($parentType, 'bigint') && str_starts_with($childType, 'bigint')) {
                    DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$parentType} NULL");
                } else {
                    $out[] = ['kind' => 'fk', 'target' => $target, 'status' => 'skipped', 'detail' => "type {$childType} ≠ {$parentType}"];

                    continue;
                }
            }
            if ($apply) {
                DB::table($table)->where($column, 0)->update([$column => null]); // 0 meant "none" in legacy rows
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) REFERENCES `{$parent}` (`id`) ON DELETE ".strtoupper($onDelete));
            }
            $out[] = ['kind' => 'fk', 'target' => $target, 'status' => $apply ? 'added' : 'ready'];
        }

        $checks = self::checksSupported();
        foreach (self::CHECKS as [$table, $column, $values]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            if (! $checks) {
                $out[] = ['kind' => 'check', 'target' => "{$table}.{$column}", 'status' => 'app_only', 'detail' => 'this database version does not enforce CHECK; the application guards it'];

                continue;
            }
            $name = substr("chk_{$table}_{$column}", 0, 64);
            if (self::hasConstraint($table, $name)) {
                $out[] = ['kind' => 'check', 'target' => "{$table}.{$column}", 'status' => 'ok'];

                continue;
            }
            $bad = DB::table($table)->whereNotNull($column)->whereNotIn($column, $values)->distinct()->pluck($column)->all();
            if ($bad !== []) {
                $out[] = ['kind' => 'check', 'target' => "{$table}.{$column}", 'status' => 'skipped', 'detail' => 'unexpected values: '.implode(', ', array_slice($bad, 0, 8))];

                continue;
            }
            if ($apply) {
                $list = implode(', ', array_map(fn ($v) => DB::getPdo()->quote($v), $values));
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK (`{$column}` IS NULL OR `{$column}` IN ({$list}))");
            }
            $out[] = ['kind' => 'check', 'target' => "{$table}.{$column}", 'status' => $apply ? 'added' : 'ready'];
        }

        foreach (self::EXPRESSIONS as [$table, $name, $condition]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! $checks) {
                $out[] = ['kind' => 'check', 'target' => $name, 'status' => 'app_only', 'detail' => 'this database version does not enforce CHECK; the application guards it'];

                continue;
            }
            if (self::hasConstraint($table, $name)) {
                $out[] = ['kind' => 'check', 'target' => $name, 'status' => 'ok'];

                continue;
            }
            $bad = (int) DB::table($table)->whereRaw("NOT {$condition}")->count();
            if ($bad > 0) {
                $out[] = ['kind' => 'check', 'target' => $name, 'status' => 'skipped', 'detail' => "{$bad} row(s) break it"];

                continue;
            }
            if ($apply) {
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK {$condition}");
            }
            $out[] = ['kind' => 'check', 'target' => $name, 'status' => $apply ? 'added' : 'ready'];
        }

        return $out;
    }
}
