<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Services\Shop\PaymentService;
use App\Services\Shop\SaleService;
use App\Traits\AuditLogger;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Sale header. All money/stock effects go through SaleService (checkout, void)
 * and PaymentService (payments -> ledger). `amount_paid`, `balance` and
 * `payment_status` are derived from payment rows; setting them directly on a
 * processed sale records the matching payment instead of faking "Paid".
 */
class SaleRecord extends Model
{
    use AuditLogger, HasFactory, Syncable;

    /** Runtime-only: SaleService assigns numbers itself inside finalize(). */
    public bool $skipNumbering = false;

    /** Runtime-only: extra payment to post after this save (set in updating). */
    protected float $pendingPaymentAmount = 0.0;

    /** Runtime-only: PaymentService/SaleService are writing derived totals — skip the intent hook. */
    public bool $writingDerived = false;

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'sale_date' => 'date',
        'processed_at' => 'datetime',
        'voided_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
        'change_given' => 'decimal:2',
    ];

    protected $fillable = [
        'client_uuid', 'company_id', 'financial_period_id', 'created_by_id', 'sale_date', 'customer_name', 'customer_phone',
        'customer_address', 'subtotal', 'discount_amount', 'discount_reason', 'total_amount', 'amount_paid', 'balance',
        'change_given', 'currency', 'payment_method', 'payment_status', 'status', 'receipt_number', 'receipt_pdf_url',
        'receipt_pdf_is_generated', 'invoice_number', 'invoice_pdf_url', 'invoice_pdf_is_generated', 'notes',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (SaleRecord $sale) {
            if (empty($sale->created_by_id)) {
                $sale->created_by_id = Auth::id();
            }
            if (empty($sale->company_id) && $sale->created_by_id) {
                $sale->company_id = User::withoutGlobalScopes()->find($sale->created_by_id)?->company_id;
            }
            if (empty($sale->company_id)) {
                throw BusinessRuleException::make('company_required', 'A sale must belong to a company.');
            }
            if (empty($sale->sale_date)) {
                $sale->sale_date = now();
            }
            if (empty($sale->status)) {
                $sale->status = 'Completed';
            }
            if (empty($sale->currency)) {
                $sale->currency = Company::withoutGlobalScopes()->find($sale->company_id)?->currency;
            }

            if (! empty($sale->financial_period_id)) {
                $period = FinancialPeriod::withoutGlobalScopes()->find($sale->financial_period_id);
                if ($period === null || (int) $period->company_id !== (int) $sale->company_id) {
                    throw BusinessRuleException::make('invalid_period', 'Invalid financial period.');
                }
                if ($period->status === 'Closed') {
                    throw BusinessRuleException::make('period_closed', 'The selected financial period is closed.', ['period_id' => $period->id]);
                }
            } else {
                $sale->financial_period_id = FinancialPeriod::resolveFor((int) $sale->company_id, Carbon::parse($sale->sale_date))->id;
            }

            if (! $sale->skipNumbering) {
                if (empty($sale->receipt_number)) {
                    $sale->receipt_number = \App\Services\Shop\NumberSequencer::next((int) $sale->company_id, 'receipt', Carbon::parse($sale->sale_date));
                }
                if (empty($sale->invoice_number)) {
                    $sale->invoice_number = \App\Services\Shop\NumberSequencer::next((int) $sale->company_id, 'invoice', Carbon::parse($sale->sale_date));
                }
            }
        });

        static::updating(function (SaleRecord $sale) {
            if ($sale->writingDerived) {
                return;
            }
            if ($sale->voided_at !== null && ! $sale->isDirty('voided_at') && $sale->isDirty(['amount_paid', 'payment_status', 'total_amount'])) {
                throw BusinessRuleException::make('sale_voided', 'A voided sale cannot be modified.');
            }
            if ($sale->processed_at === null) {
                return; // unprocessed header (admin form before items are saved) — SaleService will finalise it
            }
            foreach (['total_amount', 'subtotal', 'company_id', 'financial_period_id'] as $locked) {
                if ($sale->isDirty($locked)) {
                    throw BusinessRuleException::make('immutable_sale', 'Sale totals cannot be edited. Void the sale and record a new one.', ['field' => $locked]);
                }
            }

            $originalPaid = round((float) $sale->getOriginal('amount_paid'), 2);
            $target = null;
            if ($sale->isDirty('amount_paid')) {
                $target = round((float) $sale->amount_paid, 2);
            } elseif ($sale->isDirty('payment_status') && $sale->payment_status === 'Paid') {
                $target = round((float) $sale->total_amount, 2);
            }

            if ($target !== null) {
                $diff = round($target - $originalPaid, 2);
                if ($diff < 0) {
                    throw BusinessRuleException::make('use_payment_reversal', 'Amount paid cannot be reduced directly. Reverse the payment instead.');
                }
                $sale->pendingPaymentAmount = $diff;
            }
            // Derived columns always come from the payment rows.
            $sale->amount_paid = $sale->getOriginal('amount_paid');
            $sale->balance = $sale->getOriginal('balance');
            $sale->payment_status = $sale->getOriginal('payment_status');
        });

        // `saved` (not `updated`): after the derived columns are reset the row may have nothing left to
        // write, and Eloquent only fires `updated` when it actually issued an UPDATE.
        static::saved(function (SaleRecord $sale) {
            if ($sale->pendingPaymentAmount > 0) {
                $amount = $sale->pendingPaymentAmount;
                $sale->pendingPaymentAmount = 0.0;
                (new PaymentService())->record($sale, ['amount' => $amount, 'method' => $sale->payment_method, 'received_by_id' => Auth::id() ?? $sale->created_by_id]);
                $sale->refresh();
            }
        });

        static::deleting(function (SaleRecord $sale) {
            throw BusinessRuleException::make('delete_not_allowed', 'Sales cannot be deleted. Void the sale instead so stock and ledger stay consistent.');
        });
    }

    /**
     * Backward-compatible entry point used by the admin form and older code:
     * applies stock, totals, numbering and payments to a persisted header + lines.
     *
     * @return array{success: bool, message: string, data: array|null}
     */
    public function processAndCompute(): array
    {
        try {
            $sale = (new SaleService())->processExistingSale($this);
            $this->refresh();
            $sale->loadMissing('saleRecordItems');

            return [
                'success' => true,
                'message' => 'Sale record processed successfully',
                'data' => [
                    'sale_record_id' => $sale->id,
                    'receipt_number' => $sale->receipt_number,
                    'invoice_number' => $sale->invoice_number,
                    'total_amount' => (float) $sale->total_amount,
                    'amount_paid' => (float) $sale->amount_paid,
                    'balance' => (float) $sale->balance,
                    'payment_status' => $sale->payment_status,
                    'total_profit' => (float) $sale->saleRecordItems->sum('profit'),
                    'items_processed' => $sale->saleRecordItems->count(),
                    'items' => $sale->saleRecordItems->map(fn ($i) => [
                        'item_name' => $i->item_name, 'quantity' => (float) $i->quantity, 'unit_price' => (float) $i->unit_price,
                        'subtotal' => (float) $i->subtotal, 'profit' => (float) $i->profit, 'stock_record_id' => $i->stock_record_id,
                    ])->all(),
                ],
            ];
        } catch (BusinessRuleException $e) {
            Log::warning('SaleRecord processing rejected', ['sale_record_id' => $this->id, 'code' => $e->errorCode(), 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Failed to process sale: '.$e->getMessage(), 'data' => null, 'code' => $e->errorCode()];
        }
    }

    /** Pre-check used by the admin form before committing. */
    public function validateStockAvailability(): array
    {
        $errors = [];
        $this->loadMissing('saleRecordItems');
        if ($this->saleRecordItems->isEmpty()) {
            return ['valid' => false, 'errors' => ['No items added to the sale. Please add at least one item.']];
        }
        foreach ($this->saleRecordItems as $index => $item) {
            $stockItem = StockItem::withoutGlobalScopes()->find($item->stock_item_id);
            if ($stockItem === null) {
                $errors[] = 'Item #'.($index + 1).': Stock item not found.';

                continue;
            }
            if ((float) $item->quantity <= 0) {
                $errors[] = "{$stockItem->name}: Quantity must be greater than zero.";
            }
            if (! $stockItem->allow_negative_stock && (float) $stockItem->current_quantity < (float) $item->quantity) {
                $errors[] = "{$stockItem->name}: Insufficient stock. Available: ".number_format((float) $stockItem->current_quantity, 2).', Requested: '.number_format((float) $item->quantity, 2);
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function getTotalProfitAttribute(): float
    {
        return round((float) $this->saleRecordItems()->sum('profit'), 2);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function financialPeriod(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_id');
    }

    public function saleRecordItems(): HasMany
    {
        return $this->hasMany(SaleRecordItem::class);
    }

    public function stockRecords(): HasMany
    {
        return $this->hasMany(StockRecord::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'sale_record_id');
    }

    public function scopeVoided($query)
    {
        return $query->whereNotNull('voided_at');
    }

    public function scopeNotVoided($query)
    {
        return $query->whereNull('voided_at');
    }
}
