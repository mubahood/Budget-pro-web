<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A member's own sign-in for the web self-service (AK-61): one per membership, separate from the staff logins, so
 * the same person can be a member of several SACCOs and staff of a shop. It never carries a staff permission;
 * App\Services\Sacco\Members\MemberLogin signs it in and answers what the member may see (their own records only).
 */
class SaccoMemberLogin extends Model
{
    protected $hidden = ['password'];

    protected $guarded = ['id'];

    protected $casts = ['email_verified_at' => 'datetime', 'last_login_at' => 'datetime', 'locked_until' => 'datetime', 'disabled_at' => 'datetime', 'failed_attempts' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }
}
