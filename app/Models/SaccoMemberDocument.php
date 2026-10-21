<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * A member's document (04 §3.1): an ID scan, photo, signature, application or nomination form, kept only with the
 * member's consent (AK-31). Downloading an ID document is audited (`sacco.sensitive_view`).
 */
class SaccoMemberDocument extends Model
{
    use AuditLogger;

    public const TYPES = ['national_id', 'passport', 'photo', 'signature', 'application', 'nomination', 'other'];

    /** Documents that identify the person: viewing one is a sensitive view. */
    public const SENSITIVE = ['national_id', 'passport'];

    protected $guarded = ['id'];

    protected $casts = ['verified_at' => 'datetime', 'removed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::deleting(function () {
            throw BusinessRuleException::make('member_history', 'A document is removed (unlinked), never deleted from the register.');
        });
    }
}
