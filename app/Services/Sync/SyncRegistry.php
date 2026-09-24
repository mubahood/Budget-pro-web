<?php

namespace App\Services\Sync;

use App\Models\BudgetItem;
use App\Models\BudgetItemCategory;
use App\Models\BudgetProgram;
use App\Models\ContributionRecord;
use App\Models\Customer;
use App\Models\FinancialCategory;
use App\Models\FinancialPeriod;
use App\Models\FinancialRecord;
use App\Models\GoodsReceipt;
use App\Models\Payment;
use App\Models\PoultryBatch;
use App\Models\PoultryCustomer;
use App\Models\PoultryDailyRecord;
use App\Models\PoultryEggTransaction;
use App\Models\PoultryExpense;
use App\Models\PoultryFarmType;
use App\Models\PoultryFeedStock;
use App\Models\PoultryFeedType;
use App\Models\PoultryHealthEvent;
use App\Models\PoultryMortalityEvent;
use App\Models\PoultryProductionGuideTask;
use App\Models\PoultrySale;
use App\Models\PoultryVaccinationEvent;
use App\Models\ProductBarcode;
use App\Models\SaleRecord;
use App\Models\SaleRecordItem;
use App\Models\SaleReturn;
use App\Models\Shift;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockRecord;
use App\Models\StockSubCategory;
use App\Models\StockTake;
use App\Models\Supplier;
use App\Models\Unit;

/**
 * Wire-key registry (plan A.7): which model a table name maps to, whether it is
 * event (append-only), master (LWW), poultry (legacy trait) or reference
 * (pull-only), which fields devices may write, and how `*_uuid` references map
 * to local foreign keys. Ordered parents-first.
 */
class SyncRegistry
{
    public const KIND_EVENT = 'event';

    public const KIND_MASTER = 'master';

    public const KIND_POULTRY = 'poultry';

    public const KIND_REFERENCE = 'reference';

    /** @return array<string, array{model: class-string, kind: string, fields?: string[], refs?: array<string, array{model: class-string, column: string}>, derived?: bool, handler?: string, user_fields?: string[]}> */
    public static function tables(): array
    {
        return [
            'categories' => ['model' => StockCategory::class, 'kind' => self::KIND_MASTER, 'fields' => ['name', 'description', 'status', 'image']],
            'sub_categories' => ['model' => StockSubCategory::class, 'kind' => self::KIND_MASTER, 'fields' => ['name', 'description', 'status', 'image', 'measurement_unit', 'reorder_level'],
                'refs' => ['category_uuid' => ['model' => StockCategory::class, 'column' => 'stock_category_id']]],
            'units' => ['model' => Unit::class, 'kind' => self::KIND_MASTER, 'fields' => ['name', 'abbreviation', 'factor'],
                'refs' => ['base_unit_uuid' => ['model' => Unit::class, 'column' => 'base_unit_id']], 'user_fields' => ['created_by_id']],
            'customers' => ['model' => Customer::class, 'kind' => self::KIND_MASTER, 'fields' => ['name', 'phone', 'email', 'address', 'credit_limit', 'notes', 'is_active'], 'user_fields' => ['created_by_id']],
            'suppliers' => ['model' => Supplier::class, 'kind' => self::KIND_MASTER, 'fields' => ['name', 'phone', 'email', 'address', 'payment_terms_days', 'notes', 'is_active'], 'user_fields' => ['created_by_id']],
            'financial_periods' => ['model' => FinancialPeriod::class, 'kind' => self::KIND_MASTER, 'fields' => ['name', 'start_date', 'end_date', 'status', 'description']],
            'financial_categories' => ['model' => FinancialCategory::class, 'kind' => self::KIND_MASTER, 'fields' => ['name', 'type', 'status', 'description']],
            'products' => ['model' => StockItem::class, 'kind' => self::KIND_MASTER, 'fields' => ['name', 'description', 'barcode', 'sku', 'image', 'buying_price', 'selling_price', 'original_quantity', 'min_stock', 'allow_negative_stock', 'track_stock', 'is_active'],
                'refs' => ['category_uuid' => ['model' => StockCategory::class, 'column' => 'stock_category_id'], 'sub_category_uuid' => ['model' => StockSubCategory::class, 'column' => 'stock_sub_category_id'], 'period_uuid' => ['model' => FinancialPeriod::class, 'column' => 'financial_period_id'], 'unit_uuid' => ['model' => Unit::class, 'column' => 'unit_id']]],
            'product_barcodes' => ['model' => ProductBarcode::class, 'kind' => self::KIND_MASTER, 'fields' => ['barcode'],
                'refs' => ['product_uuid' => ['model' => StockItem::class, 'column' => 'stock_item_id'], 'unit_uuid' => ['model' => Unit::class, 'column' => 'unit_id']], 'user_fields' => ['created_by_id']],
            'shifts' => ['model' => Shift::class, 'kind' => self::KIND_EVENT, 'handler' => 'shift'],
            'sales' => ['model' => SaleRecord::class, 'kind' => self::KIND_EVENT, 'handler' => 'sale',
                'refs' => ['period_uuid' => ['model' => FinancialPeriod::class, 'column' => 'financial_period_id'], 'customer_uuid' => ['model' => Customer::class, 'column' => 'customer_id'], 'shift_uuid' => ['model' => Shift::class, 'column' => 'shift_id']]],
            'sale_items' => ['model' => SaleRecordItem::class, 'kind' => self::KIND_EVENT, 'derived' => true,
                'refs' => ['sale_uuid' => ['model' => SaleRecord::class, 'column' => 'sale_record_id'], 'product_uuid' => ['model' => StockItem::class, 'column' => 'stock_item_id'], 'movement_uuid' => ['model' => StockRecord::class, 'column' => 'stock_record_id'], 'unit_uuid' => ['model' => Unit::class, 'column' => 'unit_id']]],
            'payments' => ['model' => Payment::class, 'kind' => self::KIND_EVENT, 'handler' => 'payment',
                'refs' => ['sale_uuid' => ['model' => SaleRecord::class, 'column' => 'sale_record_id'], 'ledger_uuid' => ['model' => FinancialRecord::class, 'column' => 'financial_record_id'], 'customer_uuid' => ['model' => Customer::class, 'column' => 'customer_id'], 'shift_uuid' => ['model' => Shift::class, 'column' => 'shift_id']]],
            'sale_returns' => ['model' => SaleReturn::class, 'kind' => self::KIND_EVENT, 'handler' => 'return',
                'refs' => ['sale_uuid' => ['model' => SaleRecord::class, 'column' => 'sale_record_id'], 'shift_uuid' => ['model' => Shift::class, 'column' => 'shift_id']]],
            'goods_receipts' => ['model' => GoodsReceipt::class, 'kind' => self::KIND_EVENT, 'handler' => 'grn',
                'refs' => ['supplier_uuid' => ['model' => Supplier::class, 'column' => 'supplier_id']]],
            'stock_takes' => ['model' => StockTake::class, 'kind' => self::KIND_EVENT, 'handler' => 'stock_take',
                'refs' => ['category_uuid' => ['model' => StockCategory::class, 'column' => 'stock_category_id']]],
            'stock_movements' => ['model' => StockRecord::class, 'kind' => self::KIND_EVENT, 'handler' => 'movement',
                'refs' => ['product_uuid' => ['model' => StockItem::class, 'column' => 'stock_item_id'], 'sale_uuid' => ['model' => SaleRecord::class, 'column' => 'sale_record_id'], 'category_uuid' => ['model' => StockCategory::class, 'column' => 'stock_category_id'], 'sub_category_uuid' => ['model' => StockSubCategory::class, 'column' => 'stock_sub_category_id'], 'period_uuid' => ['model' => FinancialPeriod::class, 'column' => 'financial_period_id']]],
            'financial_records' => ['model' => FinancialRecord::class, 'kind' => self::KIND_MASTER, 'fields' => ['amount', 'quantity', 'type', 'payment_method', 'recipient', 'description', 'receipt', 'date'],
                'user_fields' => ['created_by_id', 'user_id'],
                'refs' => ['category_uuid' => ['model' => FinancialCategory::class, 'column' => 'financial_category_id'], 'period_uuid' => ['model' => FinancialPeriod::class, 'column' => 'financial_period_id']]],
            'budget_programs' => ['model' => BudgetProgram::class, 'kind' => self::KIND_MASTER, 'fields' => ['name', 'status', 'deadline', 'rsvp', 'title', 'bottom', 'groups', 'is_active', 'is_default', 'logo']],
            'budget_item_categories' => ['model' => BudgetItemCategory::class, 'kind' => self::KIND_MASTER, 'fields' => ['name', 'target_amount'],
                'refs' => ['program_uuid' => ['model' => BudgetProgram::class, 'column' => 'budget_program_id']]],
            'budget_items' => ['model' => BudgetItem::class, 'kind' => self::KIND_MASTER, 'fields' => ['name', 'unit_price', 'quantity', 'approved', 'details', 'priority', 'invested_amount'],
                'user_fields' => ['created_by_id', 'changed_by_id'],
                'refs' => ['program_uuid' => ['model' => BudgetProgram::class, 'column' => 'budget_program_id'], 'category_uuid' => ['model' => BudgetItemCategory::class, 'column' => 'budget_item_category_id']]],
            'contribution_records' => ['model' => ContributionRecord::class, 'kind' => self::KIND_MASTER, 'fields' => ['name', 'amount', 'paid_amount', 'custom_amount', 'custom_paid_amount', 'fully_paid', 'treasurer_id'],
                'user_fields' => ['treasurer_id', 'chaned_by_id'],
                'refs' => ['program_uuid' => ['model' => BudgetProgram::class, 'column' => 'budget_program_id']]],
            // Poultry: same wire keys as the v1 endpoints; push semantics live in PoultrySyncable.
            'farm_types' => ['model' => PoultryFarmType::class, 'kind' => self::KIND_REFERENCE],
            'production_guide_tasks' => ['model' => PoultryProductionGuideTask::class, 'kind' => self::KIND_REFERENCE],
            'batches' => ['model' => PoultryBatch::class, 'kind' => self::KIND_POULTRY],
            'feed_types' => ['model' => PoultryFeedType::class, 'kind' => self::KIND_POULTRY],
            'poultry_customers' => ['model' => PoultryCustomer::class, 'kind' => self::KIND_POULTRY],
            'daily_records' => ['model' => PoultryDailyRecord::class, 'kind' => self::KIND_POULTRY],
            'feed_stock' => ['model' => PoultryFeedStock::class, 'kind' => self::KIND_POULTRY],
            'poultry_sales' => ['model' => PoultrySale::class, 'kind' => self::KIND_POULTRY],
            'expenses' => ['model' => PoultryExpense::class, 'kind' => self::KIND_POULTRY],
            'egg_tx' => ['model' => PoultryEggTransaction::class, 'kind' => self::KIND_POULTRY],
            'mortality_events' => ['model' => PoultryMortalityEvent::class, 'kind' => self::KIND_POULTRY],
            'health_events' => ['model' => PoultryHealthEvent::class, 'kind' => self::KIND_POULTRY],
            'vacc_events' => ['model' => PoultryVaccinationEvent::class, 'kind' => self::KIND_POULTRY],
        ];
    }

    public static function get(string $table): ?array
    {
        return self::tables()[$table] ?? null;
    }

    public static function keys(): array
    {
        return array_keys(self::tables());
    }

    /** Server-derived columns devices may never set directly. */
    public const DERIVED_COLUMNS = ['id', 'company_id', 'server_seq', 'version', 'is_deleted', 'created_at', 'updated_at', 'current_quantity', 'receipt_number', 'invoice_number', 'total_amount', 'amount_paid', 'balance', 'change_given', 'subtotal', 'payment_status', 'processed_at', 'voided_at', 'voided_by_id', 'total_income', 'total_expense', 'total_collected', 'total_expected', 'total_in_pledge', 'budget_total', 'budget_spent', 'budget_balance', 'balance', 'percentage_done', 'is_complete', 'not_paid_amount'];
}
