<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A gift card (supermarket plan C2): money the shop owes whoever holds the code. Only a hash of the
 * code is kept, with its last 4 digits for display. `balance` is a cache of the card's rows in
 * `gift_card_ledger` (the truth), written only by GiftCardService / TenderService.
 */
class GiftCard extends Model
{
    protected $table = 'gift_cards';

    protected $guarded = ['id'];

    protected $casts = ['balance' => 'decimal:2', 'expires_at' => 'datetime', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function label(): string
    {
        return 'Gift card •••• '.$this->last4;
    }
}
