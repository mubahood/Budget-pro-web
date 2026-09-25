<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Services\Shop\StockService;
use App\Traits\AuditLogger;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * One stock movement (append-only event). `quantity` is the absolute amount
 * the user typed; `quantity_delta` is the signed effect on stock. The product's
 * `current_quantity` is a cache updated atomically under a row lock when the
 * movement is inserted, so parallel sales can never oversell (P0-4/P0-5).
 *
 * Movements are never edited or deleted — corrections are reversal movements
 * (see StockService::reverse()).
 */
class StockRecord extends Model
{
    use AuditLogger, HasFactory, Syncable;

    /** Runtime-only: permit the movement to take stock below zero. */
    public bool $allowNegative = false;

    /** Runtime-only: set by StockService when an idempotent replay returned an existing row. */
    public bool $wasReplayed = false;

    /** Runtime-only: batches an inbound movement adds (batch_number, expiry_date, quantity) — P4-4. */
    public array $batchIn = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $with = ['stockItem', 'createdBy'];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'date' => 'datetime',
        'quantity' => 'decimal:3',
        'quantity_delta' => 'decimal:3',
        'selling_price' => 'decimal:2',
        'buying_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'profit' => 'decimal:2',
        'is_reversal' => 'boolean',
    ];

    protected $fillable = [
        'client_uuid', 'company_id', 'stock_item_id', 'stock_category_id', 'stock_sub_category_id', 'financial_period_id',
        'created_by_id', 'sku', 'name', 'measurement_unit', 'description', 'type', 'quantity', 'quantity_delta',
        'selling_price', 'buying_price', 'unit_cost', 'total_sales', 'profit', 'date', 'reference_type', 'reference_id',
        'is_reversal', 'reverses_id', 'sale_record_id', 'reason', 'image',
    ];

    /** Inserts always run in a transaction so the product lock covers the whole movement. */
    public function save(array $options = [])
    {
        if ($this->exists) {
            return parent::save($options);
        }

        return DB::transaction(fn () => parent::save($options));
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (StockRecord $model) {
            StockService::assertValidType($model->type);

            // Lock the product for the rest of the transaction.
            $item = StockService::lock((int) $model->stock_item_id);

            $quantity = round(abs((float) $model->quantity), 3);
            if ($quantity <= 0) {
                throw BusinessRuleException::make('invalid_quantity', 'Quantity must be greater than zero.');
            }

            $direction = StockService::isInbound($model->type) ? 1 : -1;
            if ($model->is_reversal) {
                $direction *= -1;
            }
            $delta = round($direction * $quantity, 3);

            $available = (float) $item->current_quantity;
            $allowNegative = $model->allowNegative || (bool) $item->allow_negative_stock;
            if ($delta < 0 && ! $allowNegative && ($available + $delta) < 0) {
                throw BusinessRuleException::make(
                    'insufficient_stock',
                    "Insufficient stock for {$item->name}. Available: ".rtrim(rtrim(number_format($available, 3, '.', ''), '0'), '.').', requested: '.rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.').'.',
                    ['stock_item_id' => $item->id, 'available' => $available, 'requested' => $quantity]
                );
            }

            $date = $model->date ? \Illuminate\Support\Carbon::parse($model->date) : now();
            $model->date = $date;
            if (empty($model->financial_period_id)) {
                $model->financial_period_id = FinancialPeriod::resolveFor((int) $item->company_id, $date)->id;
            }

            $model->company_id = $item->company_id;
            if (empty($model->location_id)) {
                $model->location_id = \App\Services\Shop\LocationStock::defaultLocation((int) $item->company_id);
            }
            $model->stock_category_id = $item->stock_category_id;
            $model->stock_sub_category_id = $item->stock_sub_category_id;
            $model->sku = $item->sku;
            $model->name = $item->name;
            $model->measurement_unit = $item->stockSubCategory?->measurement_unit ?? ($item->measurement_unit ?? 'pieces');
            if (empty($model->description)) {
                $model->description = $model->type;
            }
            if (empty($model->created_by_id)) {
                $model->created_by_id = auth()->id() ?? $item->created_by_id;
            }

            $unitPrice = $model->selling_price !== null ? (float) $model->selling_price : (float) $item->selling_price;
            $unitCost = $model->unit_cost !== null ? (float) $model->unit_cost : (float) ($model->buying_price ?? $item->buying_price);

            $model->quantity = $quantity;
            $model->quantity_delta = $delta;
            $model->selling_price = $unitPrice;
            $model->buying_price = $unitCost;
            $model->unit_cost = $unitCost;

            if ($model->type === 'Sale') {
                $sign = $model->is_reversal ? -1 : 1;
                $model->total_sales = $sign * round($unitPrice * $quantity, 2);
                $model->profit = $sign * round(($unitPrice - $unitCost) * $quantity, 2);
            } else {
                $model->total_sales = 0;
                $model->profit = 0;
            }
        });

        static::created(function (StockRecord $model) {
            // Atomic cache update; the row is still locked by the creating hook.
            DB::table('stock_items')->where('id', $model->stock_item_id)->update([
                'current_quantity' => DB::raw('current_quantity + ('.(float) $model->quantity_delta.')'),
                'server_seq' => \App\Support\Sync\SyncSequence::next(), // devices pull the new on-hand figure
                'version' => DB::raw('version + 1'),
                'client_updated_at' => \App\Support\Sync\SyncSequence::nowMs(),
                'updated_at' => now(),
            ]);

            \App\Services\Shop\LocationStock::applied($model);

            $item = StockItem::withoutGlobalScopes()->find($model->stock_item_id);
            if ($item?->stockSubCategory) {
                $item->stockSubCategory->update_self();
                $item->stockSubCategory->stockCategory?->update_self();
            }

            // Legacy direct sales (no SaleRecord/Payment) still post income so the ledger stays complete.
            if ($model->type === 'Sale' && empty($model->sale_record_id)) {
                $model->postLegacyLedger();
            }
        });

        static::updating(function (StockRecord $model) {
            $locked = ['stock_item_id', 'type', 'quantity', 'quantity_delta', 'selling_price', 'buying_price', 'unit_cost', 'total_sales', 'profit', 'company_id', 'is_reversal', 'reverses_id'];
            foreach ($locked as $field) {
                if ($model->isDirty($field)) {
                    throw BusinessRuleException::make('immutable_movement', 'Stock movements cannot be edited. Record a reversal or a new movement instead.', ['field' => $field]);
                }
            }
        });

        static::deleting(function (StockRecord $model) {
            throw BusinessRuleException::make('immutable_movement', 'Stock movements cannot be deleted. Reverse the movement instead.');
        });
    }

    /** Income row for a stand-alone Sale movement (legacy quick-sale paths). */
    protected function postLegacyLedger(): void
    {
        $category = (new \App\Services\Shop\PaymentService())->salesCategory((int) $this->company_id);
        $original = $this->reverses_id ? FinancialRecord::withoutGlobalScopes()->where('source_type', 'stock_record')->where('source_id', $this->reverses_id)->first() : null;

        $row = new FinancialRecord();
        $row->financial_category_id = $category->id;
        $row->company_id = $this->company_id;
        $row->user_id = $this->created_by_id;
        $row->created_by_id = $this->created_by_id;
        $row->amount = $this->total_sales;
        $row->quantity = $this->quantity;
        $row->type = 'Income';
        $row->payment_method = 'cash';
        $row->recipient = '';
        $row->receipt = '';
        $row->date = $this->date;
        $row->description = ($this->is_reversal ? 'Reversal of sale movement #'.$this->reverses_id : 'Sale movement #'.$this->id);
        $row->financial_period_id = $this->financial_period_id;
        $row->source_type = 'stock_record';
        $row->source_id = $this->id;
        $row->is_reversal = (bool) $this->is_reversal;
        $row->reverses_id = $original?->id;
        $row->save();
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function stockCategory(): BelongsTo
    {
        return $this->belongsTo(StockCategory::class, 'stock_category_id');
    }

    public function stockSubCategory(): BelongsTo
    {
        return $this->belongsTo(StockSubCategory::class, 'stock_sub_category_id');
    }

    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class, 'financial_period_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function saleRecord(): BelongsTo
    {
        return $this->belongsTo(SaleRecord::class, 'sale_record_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeStockIn($query)
    {
        return $query->whereIn('type', StockService::INBOUND);
    }

    public function scopeStockOut($query)
    {
        return $query->whereIn('type', StockService::OUTBOUND);
    }

    public function scopeSales($query)
    {
        return $query->where('type', 'Sale');
    }

    public function scopeEffective($query)
    {
        return $query->where('is_reversal', false)->whereDoesntHave('reversal');
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopeByPeriod($query, $periodId)
    {
        return $query->where('financial_period_id', $periodId);
    }

    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('stock_category_id', $categoryId);
    }

    public function scopeBySubCategory($query, $subCategoryId)
    {
        return $query->where('stock_sub_category_id', $subCategoryId);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('date', now()->month)->whereYear('date', now()->year);
    }

    public function scopeThisYear($query)
    {
        return $query->whereYear('date', now()->year);
    }
}
