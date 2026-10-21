<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** One officer's decision on a request (04 §3.3): one each, never changed. */
class SaccoApprovalVote extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['self_approved' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(fn () => throw BusinessRuleException::make('approval_frozen', 'A decision is final.'));
        static::deleting(fn () => throw BusinessRuleException::make('approval_history', 'A decision is never deleted.'));
    }
}
