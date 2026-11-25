<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use App\Services\Kin\TreeService;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * My Family Roots 360: a child's link to a parent, both sacco_members of the same family. role: father, mother or parent
 * (unsaid); kind: birth, adoptive, step or guardian. TreeService::guardLink checks every save, from the API and from a
 * phone's sync alike: no self-link, the same family, no cycle, at most one birth father and one birth mother.
 */
class KinLink extends Model
{
    use Syncable;

    protected $table = 'kin_links';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::saving(function (self $link) {
            if (! $link->is_deleted) {
                TreeService::guardLink($link);
            }
        });
        static::saved(fn (self $link) => TreeService::changed((int) $link->company_id));
        static::deleted(fn (self $link) => TreeService::changed((int) $link->company_id));
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'child_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'parent_id');
    }
}
