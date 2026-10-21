<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/**
 * The SACCO's own money in or out (Simple\SaccoAccount). Posted once; its amount, date and category never change. A
 * mistake is corrected by a reversing entry: only the reversal fields are ever filled in afterwards.
 */
class SaccoAccountEntry extends Model
{
    public const METHODS = ['cash', 'bank', 'mobile_money'];

    /** The only fields a posted entry may still take, once: its reversal. */
    public const REVERSAL_FIELDS = ['status', 'reversal_entry_id', 'reversal_reason', 'reversed_by_id', 'reversed_at', 'updated_at'];

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer', 'category_id' => 'integer', 'journal_entry_id' => 'integer', 'reversal_entry_id' => 'integer',
        'value_date' => 'date:Y-m-d', 'reversed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $e) {
            $dirty = array_diff(array_keys($e->getDirty()), self::REVERSAL_FIELDS);
            if ($dirty !== [] && $e->getOriginal('journal_entry_id') !== null) {
                throw BusinessRuleException::make('entry_locked', 'A posted entry never changes: reverse it and record it again.');
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('entry_locked', 'A posted entry is never deleted: reverse it.');
        });
    }

    public function category()
    {
        return $this->belongsTo(SaccoAccountCategory::class, 'category_id');
    }
}
