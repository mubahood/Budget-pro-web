<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A member of a SACCO (docs/akiba/04-architecture.md §3.1), written by App\Services\Sacco\Members\MemberService.
 * Synced (a group registers members offline; twins are merged, never refused: ADR 0007). The NIN columns are
 * hidden from every array and JSON form and filtered out of the audit log (AK-31): only nin_masked ("•••• 4X2K")
 * is ever shown; revealing the number goes through MemberService::revealNin, which is audited.
 */
class SaccoMember extends Model
{
    use AuditLogger, Syncable;

    public const KINDS = ['individual', 'group', 'institution'];

    public const STATUSES = ['applicant', 'active', 'dormant', 'suspended', 'exited', 'deceased', 'rejected'];

    /** Statuses whose members are on the books (counted by Quotas `members`, AK-15). */
    public const CURRENT = ['applicant', 'active', 'dormant', 'suspended'];

    public const KYC = ['pending', 'verified', 'rejected'];

    public const GENDERS = ['female', 'male', 'other'];

    /** The NIN, its hash and the import fingerprint never leave the server (AK-31). */
    protected $hidden = ['nin_encrypted', 'nin_hash', 'import_key'];

    protected $appends = ['nin_masked', 'full_name'];

    protected $guarded = ['id'];

    protected $casts = ['date_of_birth' => 'date:Y-m-d', 'joined_on' => 'date:Y-m-d', 'status_changed_on' => 'date:Y-m-d', 'kyc_verified_at' => 'datetime',
        'consent_data_at' => 'datetime', 'consent_messages_at' => 'datetime', 'messages_opt_out_at' => 'datetime', 'consent_credit_bureau_at' => 'datetime',
        'is_insider' => 'boolean', 'is_deleted' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::deleting(function () {
            throw BusinessRuleException::make('member_history', 'A member is never deleted: their record stays for ten years (SI 51 reg. 33). Change their status instead.');
        });
    }

    protected function auditSensitive(): array
    {
        return ['nin_encrypted', 'nin_hash', 'nin_last4'];
    }

    public function getNinMaskedAttribute(): ?string
    {
        return $this->nin_last4 ? '•••• '.$this->nin_last4 : null;
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->other_names, $this->last_name])));
    }

    public function isMinor(?\Carbon\CarbonInterface $on = null): bool
    {
        return $this->date_of_birth !== null && $this->date_of_birth->diffInYears($on ?? now()) < 18;
    }

    public function relations(): HasMany
    {
        return $this->hasMany(SaccoMemberRelation::class, 'member_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SaccoMemberDocument::class, 'member_id');
    }

    public function login(): HasOne
    {
        return $this->hasOne(SaccoMemberLogin::class, 'member_id');
    }
}
