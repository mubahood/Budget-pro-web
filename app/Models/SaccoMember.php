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
        'is_insider' => 'boolean', 'is_deleted' => 'boolean',
        // My Family Roots 360 (the kin module).
        'date_of_death' => 'date:Y-m-d', 'dues_start_date' => 'date:Y-m-d', 'heritage' => 'array', 'privacy' => 'array', 'in_purse' => 'boolean',
        // UnityDesk (the unity module): membership_ends_on and standing are the server's caches (Memberships::recompute).
        'member_since' => 'date:Y-m-d', 'membership_ends_on' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::creating(function (self $m) {
            // A member pushed from a phone (SyncRegistry `sacco_members`): MemberService numbers its own members; a synced
            // row is numbered here, counted against the plan, starts as the mode says (a group: active; an office:
            // applicant) and needs the member's consent to keep their data (AK-47).
            if (filled($m->member_no)) {
                return;
            }
            $company = Company::withoutGlobalScopes()->findOrFail($m->company_id);
            if (\App\Services\Kin\TreeService::isFamily($company)) {
                // A family's person (My Family Roots 360): counted against the plan's people, numbered, active unless said otherwise.
                app(\App\Services\Billing\Quotas::class)->assertCanAdd($company, 'people');
                $m->member_no = \App\Services\Shop\NumberSequencer::next((int) $m->company_id, 'mem');
                $m->status = in_array($m->status, config('kin.statuses'), true) ? $m->status : 'active';
                $m->kyc_status = 'pending';
                $m->joined_on ??= \App\Support\LocalDate::today((int) $m->company_id)->toDateString();
                $m->setAttribute('status_changed_on', \App\Support\LocalDate::today((int) $m->company_id)->toDateString());

                return;
            }
            if (\App\Services\Unity\Memberships::isAssociation($company)) {
                // An association's member (UnityDesk): counted against the plan's members, numbered, active unless said otherwise.
                app(\App\Services\Billing\Quotas::class)->assertCanAdd($company, 'unity_members');
                $m->member_no = \App\Services\Shop\NumberSequencer::next((int) $m->company_id, 'mem');
                $m->status = in_array($m->status, config('unity.statuses'), true) ? $m->status : 'active';
                $m->kyc_status = 'pending';
                $m->joined_on ??= \App\Support\LocalDate::today((int) $m->company_id)->toDateString();
                $m->member_since ??= $m->joined_on;
                $m->setAttribute('status_changed_on', \App\Support\LocalDate::today((int) $m->company_id)->toDateString());

                return;
            }
            if ($m->consent_data_at === null && \App\Support\Rules\SaccoRules::strict((int) $m->company_id)) {
                throw BusinessRuleException::make('consent_required', 'Record the member\'s consent to keep their details first.');
            }
            app(\App\Services\Billing\Quotas::class)->assertCanAdd($company, 'members');
            $group = SaccoSetting::for((int) $m->company_id)?->isGroup() ?? false;
            $m->member_no = \App\Services\Shop\NumberSequencer::next((int) $m->company_id, 'mem');
            $m->status = $group ? 'active' : 'applicant';
            $m->kyc_status = 'pending';
            $m->joined_on ??= \App\Support\LocalDate::today((int) $m->company_id)->toDateString();
            $m->setAttribute('status_changed_on', \App\Support\LocalDate::today((int) $m->company_id)->toDateString());
            $m->consent_version ??= \App\Services\Sacco\Members\MemberService::CONSENT_VERSION;
        });
        static::saving(function (self $m) {
            // A death is recorded with the status deceased, and never before the birth or after today.
            if ($m->date_of_death === null) {
                return;
            }
            if ($m->status !== 'deceased') {
                throw BusinessRuleException::make('death_needs_status', 'A date of death is kept only for someone marked as departed.', ['date_of_death' => ['Set the status to deceased first.']]);
            }
            if ($m->date_of_birth !== null && $m->date_of_death->lt($m->date_of_birth)) {
                throw BusinessRuleException::make('death_before_birth', 'The date of death is before the date of birth.', ['date_of_death' => ['The date of death is before the date of birth.']]);
            }
            if ($m->date_of_death->gt(\App\Support\LocalDate::today((int) $m->company_id))) {
                throw BusinessRuleException::make('death_in_future', 'The date of death cannot be in the future.', ['date_of_death' => ['The date of death cannot be in the future.']]);
            }
        });
        static::created(function (self $m) {
            if (\App\Services\Kin\TreeService::isFamily((int) $m->company_id)) {
                \App\Services\Kin\TreeService::changed((int) $m->company_id); // a new person is a founder until linked
                \App\Services\Notifications\Triggers::kinPersonAdded($m); // the branch's coordinator hears it
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('member_history', 'A member is never deleted: their record stays. Change their status instead.');
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
