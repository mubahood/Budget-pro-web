<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** My Family Roots 360: a branch (house) of the family, perhaps with the person it comes from and a coordinator. */
class KinBranch extends Model
{
    use Syncable;

    protected $table = 'kin_branches';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::saving(function (self $b) {
            if ($b->founder_id !== null && ! SaccoMember::withoutGlobalScopes()->where('company_id', $b->company_id)->whereKey($b->founder_id)->exists()) {
                throw BusinessRuleException::make('not_in_family', 'That person is not in this family.');
            }
        });
    }

    public function founder(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'founder_id');
    }
}
