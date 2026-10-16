<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A purchase or a renewal of a standalone copy, from checkout to verified payment
 * (docs/STANDALONE_EDITION_PLAN.md §11). The reference (BPL-…) is the Flutterwave tx_ref; the
 * amount, currency, update months and limits are what the buyer saw at checkout.
 *
 * @property int $id
 * @property string $reference
 * @property string $kind purchase | renewal
 * @property int $product_id
 * @property string|null $licence_id
 * @property array{business:string,name:?string,email:string,phone:?string,domain:string} $buyer
 * @property string $currency
 * @property string $amount
 * @property int $months
 * @property array<string,int|null>|null $limits
 * @property bool $white_label
 * @property string $status pending | paid | failed | mismatch
 * @property string|null $gateway_tx_id
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property-read StandaloneProduct|null $product
 * @property-read StandaloneLicence|null $licence
 */
class StandaloneOrder extends Model
{
    public const PREFIX = 'BPL-';

    protected $fillable = [
        'reference', 'kind', 'product_id', 'licence_id', 'buyer', 'currency', 'amount', 'months', 'limits',
        'white_label', 'status', 'gateway_tx_id', 'paid_at',
    ];

    protected $casts = [
        'buyer' => 'array', 'amount' => 'decimal:2', 'months' => 'integer', 'limits' => 'array',
        'white_label' => 'boolean', 'paid_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(StandaloneProduct::class, 'product_id');
    }

    public function licence(): BelongsTo
    {
        return $this->belongsTo(StandaloneLicence::class, 'licence_id');
    }

    public static function newReference(): string
    {
        return self::PREFIX.bin2hex(random_bytes(12));
    }
}
