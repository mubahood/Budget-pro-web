<?php

namespace App\Services\Sacco\Members;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\SaccoMemberLogin;
use App\Models\SaccoSetting;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoAudit;
use App\Support\Phone;
use App\Support\Rules\PasswordPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * A member's own sign-in to the web self-service (AK-61), beside the staff logins and never one of them. An officer
 * opens it for a member (sacco_members); the member signs in with their email (or phone) and a password, or with
 * a code sent by email (OtpService; phone codes stay off unless OTP_PHONE_CODES is on). One person may be a member
 * of several SACCOs: a sign-in answers every membership it opens and the person picks one.
 *
 * The portal keeps the chosen membership in its session (company id and member id), and on every request calls
 * resolve() and owns(): a member sees their own records, in their own SACCO, and nothing else. No staff permission
 * is ever granted through it.
 */
final class MemberLogin
{
    public const MAX_FAILURES = 5;

    public const LOCK_MINUTES = 15;

    /** Members who may sign in: on the books and not stopped. */
    public const CAN_SIGN_IN = ['active', 'dormant'];

    /** Open (or update) a member's sign-in. @return SaccoMemberLogin */
    public function enable(Company $company, int $memberId, User $by, ?string $email = null, ?string $phone = null, ?string $password = null): SaccoMemberLogin
    {
        SaccoAccess::authorize($by, 'sacco_members');
        $member = (new MemberService())->find($company, $memberId);
        if (! in_array($member->status, self::CAN_SIGN_IN, true)) {
            throw BusinessRuleException::make('member_not_active', 'Only an active member can sign in to see their accounts.');
        }
        $email = strtolower(trim((string) ($email ?? $member->email)));
        $phoneE164 = $phone !== null ? Phone::e164($phone, (string) ($company->country ?: 'UG')) : $member->phone_e164;
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw BusinessRuleException::make('email_required', 'A member signs in with an email: sign-in codes go by email.');
        }
        if ($password !== null && ($weak = PasswordPolicy::refusal($password, false)) !== null) {
            throw BusinessRuleException::make('weak_password', $weak);
        }
        $login = SaccoMemberLogin::withoutGlobalScopes()->updateOrCreate(['company_id' => $company->id, 'member_id' => $member->id],
            ['email' => $email, 'phone_e164' => $phoneE164, 'disabled_at' => null, 'created_by_id' => $by->id] + ($password !== null ? ['password' => Hash::make($password)] : []));
        SaccoAudit::record('sacco.member_login.enabled', $member, (int) $company->id, ['email' => OtpService::mask($email)], (int) $by->id);

        return $login;
    }

    public function disable(Company $company, int $memberId, User $by): void
    {
        SaccoAccess::authorize($by, 'sacco_members');
        SaccoMemberLogin::withoutGlobalScopes()->where('company_id', $company->id)->where('member_id', $memberId)->update(['disabled_at' => now(), 'updated_at' => now()]);
        SaccoAudit::record('sacco.member_login.disabled', 'sacco_members', (int) $company->id, ['member_id' => $memberId], (int) $by->id, $memberId);
    }

    /** The member sets (or changes) their own password, once signed in. */
    public function setPassword(int $companyId, int $memberId, string $password): void
    {
        if (($weak = PasswordPolicy::refusal($password, false)) !== null) {
            throw BusinessRuleException::make('weak_password', $weak);
        }
        SaccoMemberLogin::withoutGlobalScopes()->where('company_id', $companyId)->where('member_id', $memberId)->update(['password' => Hash::make($password), 'updated_at' => now()]);
    }

    /**
     * Sign in with an email or phone and a password: the memberships it opens. Wrong passwords count per login;
     * five lock it for 15 minutes. The refusal never says whether the address is known.
     *
     * @return list<array{company_id: int, member_id: int, member_no: ?string, name: string, sacco: string}>
     */
    public function attempt(string $identifier, string $password): array
    {
        $open = [];
        foreach ($this->logins($identifier) as $login) {
            if ($login->locked_until !== null && $login->locked_until->isFuture()) {
                continue;
            }
            if ($login->password === null || ! Hash::check($password, (string) $login->password)) {
                $failures = $login->failed_attempts + 1;
                $login->update(['failed_attempts' => $failures, 'locked_until' => $failures >= self::MAX_FAILURES ? now()->addMinutes(self::LOCK_MINUTES) : null]);

                continue;
            }
            $open[] = $login;
        }
        if ($open === []) {
            throw BusinessRuleException::make('invalid_credentials', 'That email or password is not right.');
        }

        return $this->signedIn($open, 'password');
    }

    /** Send a sign-in code to the email of every membership this address opens (and say nothing if none). */
    public function requestCode(string $identifier, ?string $ip = null): array
    {
        $logins = $this->logins($identifier);
        $email = $logins->first()?->email;
        if ($email === null) {
            return ['sent' => true, 'sent_to' => null]; // no enumeration: the answer looks the same
        }
        $first = $logins->first();
        $sacco = $first === null ? null : (SaccoSetting::for((int) $first->company_id)?->short_name ?: null);
        // Codes go out in the SACCO's own name (one SACCO), or Akiba Ledger's when the address opens several.
        $sender = $logins->pluck('company_id')->unique()->count() > 1 ? 'Akiba Ledger' : ($sacco ?? 'Akiba Ledger');
        $r = app(OtpService::class)->request(self::otpKey($identifier), 'login', $ip, (string) $email, $sender);

        return ['sent' => $r['sent'], 'sent_to' => $r['sent_to']];
    }

    /** @return list<array{company_id: int, member_id: int, member_no: ?string, name: string, sacco: string}> */
    public function verifyCode(string $identifier, string $code): array
    {
        app(OtpService::class)->verify(self::otpKey($identifier), 'login', $code);
        $logins = $this->logins($identifier)->all();
        if ($logins === []) {
            throw BusinessRuleException::make('invalid_credentials', 'That code does not open any membership.');
        }
        foreach ($logins as $l) {
            $l->update(['email_verified_at' => $l->email_verified_at ?? now()]);
        }

        return $this->signedIn($logins, 'code');
    }

    /**
     * The member behind a session's (company, member) pair, checked on every request: the login still open and the
     * member still active. Anything else ends the session (`member_session_invalid`).
     */
    public function resolve(int $companyId, int $memberId): SaccoMember
    {
        $login = SaccoMemberLogin::withoutGlobalScopes()->where('company_id', $companyId)->where('member_id', $memberId)->whereNull('disabled_at')->first();
        $member = $login === null ? null : SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->whereNull('merged_into_id')->find($memberId);
        $company = $member === null ? null : Company::withoutGlobalScopes()->find($companyId);
        if ($member === null || ! in_array($member->status, self::CAN_SIGN_IN, true) || $company === null || ! in_array('sacco', $company->modules(), true)) {
            throw BusinessRuleException::make('member_session_invalid', 'Please sign in again.');
        }

        return $member;
    }

    /**
     * Whether a record is the member's own: in their SACCO and theirs (a member row is theirs when it is them; any
     * other row when its member_id is them). The portal checks this for every record it shows a member.
     *
     * @param  Model|array<string, mixed>  $record
     */
    public static function owns(SaccoMember $member, Model|array $record): bool
    {
        $r = $record instanceof Model ? $record->getAttributes() : $record;
        if ((int) ($r['company_id'] ?? 0) !== (int) $member->company_id) {
            return false;
        }

        return $record instanceof SaccoMember ? (int) $record->getKey() === (int) $member->id : (int) ($r['member_id'] ?? 0) === (int) $member->id;
    }

    /** @return \Illuminate\Support\Collection<int, SaccoMemberLogin> open logins for an email or phone, across SACCOs */
    private function logins(string $identifier)
    {
        $identifier = trim($identifier);
        $q = SaccoMemberLogin::withoutGlobalScopes()->whereNull('disabled_at');
        if (str_contains($identifier, '@')) {
            $q->where('email', strtolower($identifier));
        } else {
            $e164 = Phone::e164($identifier, 'UG');
            if ($e164 === null) {
                return collect();
            }
            $q->where('phone_e164', $e164);
        }

        return $q->orderBy('id')->get()->filter(function (SaccoMemberLogin $l) {
            $status = SaccoMember::withoutGlobalScopes()->whereKey($l->member_id)->where('is_deleted', 0)->value('status');

            return in_array($status, self::CAN_SIGN_IN, true);
        })->values();
    }

    /**
     * @param  array<int, SaccoMemberLogin>  $logins
     * @return list<array{company_id: int, member_id: int, member_no: ?string, name: string, sacco: string}>
     */
    private function signedIn(array $logins, string $how): array
    {
        $out = [];
        foreach ($logins as $l) {
            $l->update(['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()]);
            $m = SaccoMember::withoutGlobalScopes()->find($l->member_id);
            $sacco = SaccoSetting::for((int) $l->company_id)?->short_name ?? (string) DB::table('companies')->where('id', $l->company_id)->value('name');
            SaccoAudit::record('sacco.member_login', 'sacco_members', (int) $l->company_id, ['member_id' => (int) $l->member_id, 'how' => $how], null, (int) $l->member_id);
            $out[] = ['company_id' => (int) $l->company_id, 'member_id' => (int) $l->member_id, 'member_no' => $m?->member_no, 'name' => (string) $m?->full_name, 'sacco' => $sacco];
        }

        return $out;
    }

    /** Members' codes are keyed apart from staff codes for the same address. */
    public static function otpKey(string $identifier): string
    {
        return 'sacco:'.strtolower(trim($identifier));
    }
}
