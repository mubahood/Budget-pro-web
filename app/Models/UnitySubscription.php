<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Services\Unity\Memberships;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UnityDesk: one period of a member's membership and what was paid for it (minor units). ends_on null: for life. Paid and
 * waived periods are in force; pending ones wait for money; a mistake is cancelled, never deleted. Every save, from the API or
 * a phone, works the member's membership_ends_on and standing out again (Memberships::recompute).
 */
class UnitySubscription extends Model
{
    use Syncable;

    protected $table = 'unity_subscriptions';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'paid_on' => 'date:Y-m-d',
        'amount_minor' => 'integer', 'paid_minor' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::saving(fn (self $s) => Memberships::guard($s));
        static::saved(fn (self $s) => Memberships::recompute((int) $s->company_id, (int) $s->member_id));
        static::deleting(function () {
            throw BusinessRuleException::make('subscription_history', 'A subscription is never deleted. Cancel it instead.');
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(UnityCategory::class, 'category_id');
    }
}
