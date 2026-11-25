<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** UnityDesk: a chapter (branch) of the association, perhaps with the member who leads it. */
class UnityChapter extends Model
{
    use Syncable;

    protected $table = 'unity_chapters';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::creating(function (self $c) {
            $company = Company::withoutGlobalScopes()->find($c->company_id);
            if ($company !== null && ! (new \App\Services\Billing\Quotas())->featureOn($company, 'unity_chapters')) {
                throw BusinessRuleException::make('feature_not_in_plan', 'Chapters are on the Organisation plan.', ['feature' => 'unity_chapters']);
            }
        });
        static::saving(function (self $c) {
            if (trim((string) $c->name) === '') {
                throw BusinessRuleException::make('name_required', 'Name the chapter.', ['name' => ['Name the chapter.']]);
            }
            if ($c->lead_member_id !== null && ! SaccoMember::withoutGlobalScopes()->where('company_id', $c->company_id)->whereKey($c->lead_member_id)->exists()) {
                throw BusinessRuleException::make('not_a_member', 'That person is not a member of this association.');
            }
        });
        static::deleting(function (self $c) {
            $n = SaccoMember::withoutGlobalScopes()->where('company_id', $c->company_id)->where('chapter_id', $c->id)->where('is_deleted', 0)->count();
            if ($n > 0) {
                throw BusinessRuleException::make('chapter_in_use', "This chapter has {$n} ".($n === 1 ? 'member' : 'members').'. Move them to another chapter first.', ['members' => $n]);
            }
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'lead_member_id');
    }
}
