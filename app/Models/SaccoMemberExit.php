<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/** A member's exit (04 §3.4): written by App\Services\Sacco\Members\MemberExit only; never deleted. */
class SaccoMemberExit extends Model
{
    public const REASONS = ['voluntary', 'expelled', 'deceased'];

    public const STATUSES = ['requested', 'completed', 'rejected', 'cancelled'];

    protected $guarded = ['id'];

    protected $casts = ['settlement' => 'array', 'journal_entry_ids' => 'array', 'requested_on' => 'date:Y-m-d', 'completed_on' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::updating(function (self $e) {
            if ($e->isDirty(['settlement', 'member_id', 'company_id']) || in_array($e->getOriginal('status'), ['completed', 'rejected', 'cancelled'], true)) {
                throw BusinessRuleException::make('exit_final', 'An exit\'s settlement is fixed once asked, and a finished exit never changes.');
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('exit_final', 'An exit is never deleted.');
        });
    }
}
