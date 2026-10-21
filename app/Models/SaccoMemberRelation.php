<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/** A member's nominee or next of kin (04 §3.1): replaced by ending it (ended_on), never deleted. */
class SaccoMemberRelation extends Model
{
    use AuditLogger;

    public const ROLES = ['nominee', 'next_of_kin'];

    protected $hidden = ['nin_encrypted'];

    protected $guarded = ['id'];

    protected $casts = ['signed_on' => 'date:Y-m-d', 'ended_on' => 'date:Y-m-d', 'share_bp' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::deleting(function () {
            throw BusinessRuleException::make('member_history', 'A nomination is replaced, never deleted.');
        });
    }

    protected function auditSensitive(): array
    {
        return ['nin_encrypted'];
    }
}
