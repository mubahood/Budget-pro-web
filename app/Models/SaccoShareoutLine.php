<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** One member's part of a share-out: written with the share-out, never changed. */
class SaccoShareoutLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['shareout_id' => 'integer', 'member_id' => 'integer', 'savings_minor' => 'integer', 'shares' => 'integer',
        'savings_paid_minor' => 'integer', 'profit_minor' => 'integer', 'payout_minor' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function () {
            throw BusinessRuleException::make('shareout_locked', 'A share-out line never changes.');
        });
        static::deleting(function () {
            throw BusinessRuleException::make('shareout_locked', 'A share-out line is never deleted.');
        });
    }

    public function member()
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }
}
