<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Services\Kin\TreeService;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * My Family Roots 360: a marriage of a person in the family, to another person in it (spouse_id) or to a name only
 * (spouse_name). A person may have several, at once or one after another (status current, ended, widowed).
 */
class KinSpouse extends Model
{
    use Syncable;

    protected $table = 'kin_spouses';

    protected $guarded = ['id'];

    protected $casts = ['married_on' => 'date:Y-m-d', 'ended_on' => 'date:Y-m-d', 'is_deleted' => 'boolean', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::saving(function (self $spouse) {
            if (! $spouse->is_deleted) {
                TreeService::guardSpouse($spouse);
            }
        });
        static::saved(fn (self $s) => TreeService::changed((int) $s->company_id));
        static::deleted(fn (self $s) => TreeService::changed((int) $s->company_id));
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'person_id');
    }

    public function spouse(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'spouse_id');
    }
}
