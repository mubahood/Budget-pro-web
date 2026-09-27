<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\User;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Change a shop's currency, even after it has sales. Two ways:
 *
 *  - relabel: the figures were always in the new currency (the wrong one was picked at sign-up). Only the
 *    currency changes: the shop, and the currency label on its sales, payments and ledger rows.
 *  - convert: every amount the shop has stored is multiplied by one rate (1 old = $rate new): prices, sales and
 *    their lines, payments, the ledger, balances, stock values, deliveries, orders, returns, shifts, promotions,
 *    gift cards, budgets, poultry… so every report stays consistent. Quantities, percentages and tax rates are
 *    not money and are left alone.
 *
 * Left as they are in both modes: records of money that really moved in its own currency outside the shop's
 * books (platform subscription invoices, mobile money requests) and closed Z reports (kept as printed).
 * Owner only, confirmed with the owner's password, in one transaction, logged in `currency_changes`. Changed
 * synced rows get new server_seq values so phones pull them again.
 */
class CurrencyChangeService
{
    /** table => money columns (decimal/int), for the convert mode. */
    public const MONEY = [
        'stock_items' => ['buying_price', 'selling_price'],
        'stock_records' => ['selling_price', 'buying_price', 'unit_cost', 'total_sales', 'profit'],
        'stock_batches' => ['unit_cost'],
        'stock_categories' => ['selling_price', 'earned_profit', 'buying_price', 'expected_profit'],
        'stock_sub_categories' => ['selling_price', 'earned_profit', 'buying_price', 'expected_profit'],
        'sale_records' => ['subtotal', 'discount_amount', 'total_amount', 'amount_paid', 'balance', 'change_given', 'refunded_amount', 'rounding_amount'],
        'sale_record_items' => ['unit_price', 'subtotal', 'discount_amount', 'line_total', 'unit_cost', 'profit', 'tax_amount', 'promo_discount'],
        'sale_returns' => ['value', 'refund_amount'],
        'sale_return_items' => ['value'],
        'sale_promotions' => ['amount'],
        'payments' => ['amount'],
        'financial_records' => ['amount'],
        'financial_reports' => ['total_income', 'total_expense', 'profit', 'inventory_total_buying_price', 'inventory_total_selling_price', 'inventory_total_expected_profit', 'inventory_total_earned_profit'],
        'financial_categories' => ['total_expense', 'total_income'],
        'financial_periods' => ['total_sales', 'total_expenses', 'total_investment', 'total_profit'],
        'customers' => ['credit_limit', 'balance'],
        'suppliers' => ['balance', 'min_order_value'],
        'supplier_prices' => ['cost'],
        'goods_receipts' => ['total_cost', 'amount_paid', 'landed_cost_total', 'consignment_value'],
        'goods_receipt_items' => ['unit_cost', 'expected_unit_cost', 'landed_unit_cost'],
        'purchase_orders' => ['subtotal'],
        'purchase_order_items' => ['unit_cost'],
        'purchase_returns' => ['total_value', 'refund_amount', 'consignment_value'],
        'purchase_return_items' => ['unit_cost'],
        'consignment_settlements' => ['amount'],
        'shifts' => ['opening_float', 'expected_cash', 'counted_cash', 'variance', 'sales_total'],
        'cash_movements' => ['amount'],
        'approvals' => ['amount'],
        'product_prices' => ['price'],
        'location_prices' => ['price'],
        'price_changes' => ['old', 'new'],
        'batch_markdowns' => ['original_price', 'price', 'sold_value'],
        'product_stats' => ['revenue_30', 'profit_30'],
        'gift_cards' => ['balance'],
        'gift_card_ledger' => ['amount'],
        'held_carts' => ['total'],
        'budget_items' => ['target_amount', 'balance', 'invested_amount', 'unit_price'],
        'budget_item_categories' => ['invested_amount', 'target_amount', 'balance'],
        'budget_programs' => ['budget_total', 'budget_balance', 'total_collected', 'total_in_pledge', 'budget_spent', 'total_expected'],
        'contribution_records' => ['amount', 'not_paid_amount', 'paid_amount'],
        'handover_records' => ['amount'],
        'poultry_batches' => ['cost_per_chick'],
        'poultry_daily_records' => ['egg_unit_price', 'feed_price_per_kg'],
        'poultry_expenses' => ['amount'],
        'poultry_feed_stock' => ['cost'],
        'poultry_health_events' => ['cost'],
        'poultry_sales' => ['unit_price', 'total', 'amount_paid'],
    ];

    /** Currency label columns that follow the shop's currency (both modes). */
    public const LABELS = ['sale_records', 'payments', 'financial_records', 'financial_reports'];

    /** Money inside JSON: table => [column => money keys]. */
    private const JSON_MONEY = [
        'promotions' => ['rules' => ['amount', 'price', 'min_spend']],
        'goods_receipts' => ['landed_costs' => ['amount']],
        'held_carts' => ['lines' => ['unit_price', 'discount_amount', 'price']],
    ];

    /** Money settings in companies.store_settings.settings. */
    private const MONEY_SETTINGS = ['cash_rounding', 'waste_limit', 'loyalty_spend_per_point', 'loyalty_point_value', 'loyalty_silver_spend', 'loyalty_gold_spend'];

    /** What a change would do: rows per table, and a few products before → after. */
    public function preview(Company $company, string $to, string $mode, ?float $rate): array
    {
        [$to, $mode, $rate] = $this->check($company, $to, $mode, $rate);
        $counts = [];
        if ($mode === 'convert') {
            foreach ($this->tables() as $table => $cols) {
                $n = DB::table($table)->where('company_id', $company->id)->count();
                if ($n > 0) {
                    $counts[$table] = $n;
                }
            }
        }
        $examples = DB::table('stock_items')->where('company_id', $company->id)->where('is_deleted', 0)->orderByDesc('id')->limit(3)
            ->get(['name', 'selling_price'])->map(fn ($p) => ['name' => (string) $p->name, 'before' => (float) $p->selling_price,
                'after' => $mode === 'convert' ? self::money((float) $p->selling_price * $rate) : (float) $p->selling_price])->all();

        return ['from' => (string) $company->currency, 'to' => $to, 'mode' => $mode, 'rate' => $rate, 'tables' => $counts, 'rows' => array_sum($counts), 'examples' => $examples];
    }

    /**
     * Change the currency. $mode 'relabel' | 'convert' ($rate: 1 old = $rate new). Returns the logged change.
     */
    public function change(Company $company, User $user, string $to, string $mode, ?float $rate, string $password): object
    {
        \App\Services\Onboarding\PublicDemo::guard($company, 'changing the currency');
        [$to, $mode, $rate] = $this->check($company, $to, $mode, $rate);
        if ((int) $company->owner_id !== (int) $user->id) {
            throw BusinessRuleException::make('owner_only', 'Only the owner can change the currency.');
        }
        if (! Hash::check($password, (string) $user->password)) {
            throw BusinessRuleException::make('wrong_password', 'That password is not right.');
        }
        $from = (string) $company->currency;

        return DB::transaction(function () use ($company, $user, $from, $to, $mode, $rate) {
            $rows = [];
            if ($mode === 'convert') {
                foreach ($this->tables() as $table => $cols) {
                    $set = [];
                    foreach ($cols as $col) {
                        $set[$col] = DB::raw("ROUND(`{$col}` * ".self::sqlRate($rate).', 2)');
                    }
                    $n = DB::table($table)->where('company_id', $company->id)->update($set);
                    if ($n > 0) {
                        $rows[$table] = $n;
                    }
                }
                foreach (self::JSON_MONEY as $table => $columns) {
                    if (Schema::hasTable($table)) {
                        $rows[$table.' (details)'] = $this->convertJson($table, $columns, (int) $company->id, $rate);
                    }
                }
                $this->convertSettings($company, $rate);
            }
            foreach (self::LABELS as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'currency')) {
                    DB::table($table)->where('company_id', $company->id)->update(['currency' => $to]);
                }
            }
            $company->forceFill(['currency' => $to])->save();
            $this->resync((int) $company->id, $mode === 'convert' ? array_keys($this->tables()) : self::LABELS);

            $id = DB::table('currency_changes')->insertGetId([
                'company_id' => $company->id, 'from_currency' => $from, 'to_currency' => $to, 'mode' => $mode,
                'rate' => $mode === 'convert' ? $rate : null, 'rows' => json_encode($rows), 'changed_by' => $user->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('currency_changes')->find($id);
        });
    }

    /** @return array{0: string, 1: string, 2: ?float} */
    private function check(Company $company, string $to, string $mode, ?float $rate): array
    {
        $to = strtoupper(trim($to));
        if (! in_array($to, (array) config('saas.currencies', []), true)) {
            throw BusinessRuleException::make('invalid_currency', 'Choose a currency from the list.');
        }
        if ($to === strtoupper((string) $company->currency)) {
            throw BusinessRuleException::make('same_currency', 'The shop already uses '.$to.'.');
        }
        if (! in_array($mode, ['relabel', 'convert'], true)) {
            throw BusinessRuleException::make('invalid_mode', 'Choose whether to correct the currency or convert the amounts.');
        }
        if ($mode === 'convert' && ($rate === null || $rate <= 0 || $rate > 1_000_000)) {
            throw BusinessRuleException::make('invalid_rate', 'Enter how much 1 '.$company->currency.' is worth in '.$to.'.');
        }

        return [$to, $mode, $mode === 'convert' ? $rate : null];
    }

    /** The money tables and columns that exist in this database. @return array<string, list<string>> */
    private function tables(): array
    {
        $out = [];
        foreach (self::MONEY as $table => $cols) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            $cols = array_values(array_filter($cols, fn ($c) => Schema::hasColumn($table, $c)));
            if ($cols !== []) {
                $out[$table] = $cols;
            }
        }

        return $out;
    }

    private function convertJson(string $table, array $columns, int $companyId, float $rate): int
    {
        $n = 0;
        $cols = array_values(array_filter(array_keys($columns), fn ($c) => Schema::hasColumn($table, $c)));
        if ($cols === []) {
            return 0;
        }
        foreach (DB::table($table)->where('company_id', $companyId)->get(array_merge(['id'], $cols)) as $row) {
            $update = [];
            foreach ($cols as $col) {
                $data = json_decode((string) $row->{$col}, true);
                if (! is_array($data)) {
                    continue;
                }
                $update[$col] = json_encode(self::scaleKeys($data, $columns[$col], $rate));
            }
            if ($update !== []) {
                DB::table($table)->where('id', $row->id)->update($update);
                $n++;
            }
        }

        return $n;
    }

    /** Multiply the given keys wherever they appear (lists and nested arrays too). */
    private static function scaleKeys(array $data, array $keys, float $rate): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::scaleKeys($v, $keys, $rate);
            } elseif (is_string($k) && in_array($k, $keys, true) && is_numeric($v)) {
                $data[$k] = self::money((float) $v * $rate);
            }
        }

        return $data;
    }

    private function convertSettings(Company $company, float $rate): void
    {
        $data = is_array($company->store_settings) ? $company->store_settings : [];
        if (! isset($data['settings']) || ! is_array($data['settings'])) {
            return;
        }
        foreach (self::MONEY_SETTINGS as $key) {
            if (isset($data['settings'][$key]) && is_numeric($data['settings'][$key])) {
                $data['settings'][$key] = self::money((float) $data['settings'][$key] * $rate);
            }
        }
        unset($data['settings']['note_buttons']); // the new currency's own notes apply
        $company->forceFill(['store_settings' => $data]);
    }

    /** Give the changed synced rows new server_seq values, one each, so phones pull them again. */
    private function resync(int $companyId, array $tables): void
    {
        foreach (array_unique($tables) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'server_seq') || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            $ids = DB::table($table)->where('company_id', $companyId)->orderBy('id')->pluck('id');
            if ($ids->isEmpty()) {
                continue;
            }
            $seq = SyncSequence::reserve($ids->count());
            foreach ($ids->chunk(500) as $chunk) {
                $cases = [];
                foreach ($chunk as $id) {
                    $cases[] = 'WHEN '.(int) $id.' THEN '.$seq++;
                }
                DB::table($table)->whereIn('id', $chunk->all())->update(['server_seq' => DB::raw('CASE id '.implode(' ', $cases).' END')]);
            }
        }
    }

    private static function money(float $v): float
    {
        return round($v, 2);
    }

    private static function sqlRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 10, '.', ''), '0'), '.') ?: '0';
    }
}
