<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A share-out (Simple\ShareOut): saved with its lines and posted in one go, then locked. */
class SaccoShareout extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['share_on' => 'date:Y-m-d', 'amount_minor' => 'integer', 'savings_minor' => 'integer', 'profit_minor' => 'integer',
        'savings_total_minor' => 'integer', 'profit_available_minor' => 'integer', 'total_shares' => 'integer', 'journal_entry_id' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $s) {
            if ($s->getOriginal('journal_entry_id') !== null) {
                throw BusinessRuleException::make('shareout_locked', 'A share-out is locked once it is posted.');
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('shareout_locked', 'A share-out is never deleted.');
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaccoShareoutLine::class, 'shareout_id');
    }
}
