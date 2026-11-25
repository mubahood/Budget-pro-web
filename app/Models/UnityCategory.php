<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;

/** UnityDesk: a membership category and its fee for a period in months (period_months 0: for life). Amounts in minor units. */
class UnityCategory extends Model
{
    use Syncable;

    protected $table = 'unity_categories';

    protected $guarded = ['id'];

    protected $casts = ['is_deleted' => 'boolean', 'is_active' => 'boolean', 'fee_minor' => 'integer', 'period_months' => 'integer', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::saving(function (self $c) {
            if (trim((string) $c->name) === '') {
                throw BusinessRuleException::make('name_required', 'Name the category.', ['name' => ['Name the category.']]);
            }
            if ((int) $c->fee_minor < 0 || (int) $c->period_months < 0 || (int) $c->period_months > 120) {
                throw BusinessRuleException::make('invalid_category', 'A fee is never below zero, and a period is 0 (life) to 120 months.');
            }
        });
        static::deleting(function (self $c) {
            $used = SaccoMember::withoutGlobalScopes()->where('company_id', $c->company_id)->where('category_id', $c->id)->where('is_deleted', 0)->count()
                + UnitySubscription::withoutGlobalScopes()->where('company_id', $c->company_id)->where('category_id', $c->id)->where('is_deleted', 0)->count();
            if ($used > 0) {
                throw BusinessRuleException::make('category_in_use', 'Members and subscriptions use this category. Switch it off instead.', ['in_use' => $used]);
            }
        });
    }

    public function isLife(): bool
    {
        return (int) $this->period_months === 0;
    }
}
