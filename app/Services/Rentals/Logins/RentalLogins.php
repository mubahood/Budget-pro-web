<?php

namespace App\Services\Rentals\Logins;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Plan;
use App\Models\RentalLandlord;
use App\Models\RentalLogin;
use App\Models\RentalTenant;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Billing\Entitlements;
use App\Services\Rentals\Demo\RentalsDemo;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalsModule;
use App\Support\Phone;
use App\Support\Rules\PasswordPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Tenants' and landlords' own sign-in (KR-16, docs/estate-ledger/04-architecture.md §8), beside the staff logins and never
 * one of them. Staff with rentals_settings open it for a tenant or a landlord (agency mode); the person signs in with
 * their email or phone and a password, or a code sent by email (OtpService, keyed `rentals:{login}`). One email may
 * open several accounts, or both a tenant and a landlord record: a sign-in answers every one it opens and the person
 * picks one with a short-lived ticket. Five wrong passwords lock a sign-in for 15 minutes; no refusal says whether a
 * sign-in exists. Tokens are Sanctum tokens with the `rentals-portal` ability only, which staff routes refuse
 * (AuthServiceProvider) and RentalPortalAuth reads.
 *
 * The own sign-in comes with a paid plan (EL-12): when an account lapses to its free plan, or has no plan with
 * `rentals_portal`, every sign-in of it stops working on the next request (resolve). Someone who proves who they are
 * (the right password or code) is told why in plain words; nobody else learns that a sign-in exists.
 */
final class RentalLogins
{
    public const MAX_FAILURES = 5;

    public const LOCK_MINUTES = 15;

    public const TICKET_MINUTES = 10;

    public const TOKEN_DAYS = 30;

    private const TICKET_KEY = 'rentals-portal-ticket:';

    /** What a person hears once they have proved who they are, and the account's plan has no own sign-in. */
    public const PLAN_OFF = 'Your own sign-in is closed for now, because the plan of the people who manage your home or property does not include it. Your records are safe. Ask them to turn it back on.';

    /**
     * Open (or update) the sign-in of a tenant or landlord (rentals_settings). Email and phone default to the
     * person's own; a person with neither cannot sign in. Landlords sign in only in agency mode.
     */
    public function enable(User $by, string $subjectType, int $subjectId, ?string $email = null, ?string $phone = null, ?string $password = null): RentalLogin
    {
        RentalAccess::authorize($by, 'rentals_settings');
        $cid = (int) $by->company_id;
        RentalsDemo::guard($cid, 'changing the tenant and landlord sign-ins');
        $subject = $this->subjectFor($by, $subjectType, $subjectId);
        if ($subjectType === 'landlord' && (RentalsModule::isOwnerMode($cid) || $subject->is_self)) {
            throw BusinessRuleException::make('owner_mode', 'Landlords sign in when the account manages property for landlords (agency).');
        }
        $company = Company::withoutGlobalScopes()->findOrFail($cid);
        if (! self::portalOnPlan($company)) {
            throw BusinessRuleException::make('plan_limit_reached', 'Tenants\' and landlords\' own sign-in comes with a paid plan. Choose one under My plan.',
                ['feature' => 'rentals_portal']);
        }
        $email = strtolower(trim((string) ($email ?? $subject->email)));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw BusinessRuleException::make('email_invalid', 'That email address does not look right.');
        }
        $phoneE164 = $phone !== null && trim($phone) !== '' ? Phone::e164($phone, (string) ($company->country ?: 'UG')) : $subject->phone_e164;
        if ($phone !== null && trim($phone) !== '' && $phoneE164 === null) {
            throw BusinessRuleException::make('phone_invalid', 'That phone number does not look right.');
        }
        if ($email === '' && $phoneE164 === null) {
            throw BusinessRuleException::make('contact_required', 'Add an email or a phone number first: the person signs in with one of them.');
        }
        if ($password !== null && $password !== '' && ($weak = PasswordPolicy::refusal($password, false)) !== null) {
            throw BusinessRuleException::make('weak_password', $weak);
        }
        $login = RentalLogin::withoutGlobalScopes()->updateOrCreate(['company_id' => $cid, 'subject_type' => $subjectType, 'subject_id' => $subjectId],
            ['email' => $email === '' ? null : $email, 'phone_e164' => $phoneE164, 'disabled_at' => null, 'failures' => 0, 'locked_until' => null, 'created_by_id' => $by->id]
            + ($password !== null && $password !== '' ? ['password' => Hash::make($password)] : []));
        RentalAudit::record($cid, 'login.enabled', $subject, "Opened the own sign-in of {$subject->name}",
            ['subject_type' => $subjectType, 'email' => $email === '' ? null : OtpService::mask($email), 'password_set' => $password !== null && $password !== ''], 'staff', (int) $by->id);

        return $login->fresh();
    }

    /** Close a person's sign-in (rentals_settings): their tokens stop working at once. */
    public function disable(User $by, string $subjectType, int $subjectId): ?RentalLogin
    {
        RentalAccess::authorize($by, 'rentals_settings');
        RentalsDemo::guard((int) $by->company_id, 'changing the tenant and landlord sign-ins');
        $subject = $this->subjectFor($by, $subjectType, $subjectId);
        $login = self::forSubject((int) $by->company_id, $subjectType, $subjectId);
        if ($login === null) {
            return null;
        }
        $login->forceFill(['disabled_at' => now()])->save();
        $login->tokens()->delete();
        RentalAudit::record((int) $by->company_id, 'login.disabled', $subject, "Closed the own sign-in of {$subject->name}", ['subject_type' => $subjectType], 'staff', (int) $by->id);

        return $login->fresh();
    }

    /** The sign-in of one tenant or landlord, open or closed. */
    public static function forSubject(int $companyId, string $subjectType, int $subjectId): ?RentalLogin
    {
        return RentalLogin::withoutGlobalScopes()->where('company_id', $companyId)->where('subject_type', $subjectType)->where('subject_id', $subjectId)->first();
    }

    /**
     * Whether a sign-in code can reach anyone at all: codes go by email, and this server's email is on. Not about any
     * one sign-in, so a screen can say so to everyone without giving away who has one.
     */
    public static function codesOn(): bool
    {
        return config('messaging.mail.driver') === 'mail' && config('mail.default') !== 'log';
    }

    /**
     * Whether the account's plan includes the own sign-in now (Entitlements `rentals_portal`): off on a plan without it,
     * and off once a plan ran out past its grace days, even before the hourly lifecycle moves the account to free.
     */
    public static function portalOnPlan(Company $company): bool
    {
        $plan = $company->subscription?->plan;
        $features = array_merge(Entitlements::DEFAULT_FEATURES, $plan instanceof Plan ? (array) ($plan->features ?? []) : []);

        return (bool) ($features['rentals_portal'] ?? false) && $company->accessState() !== 'expired';
    }

    /**
     * Sign in with an email or phone and a password: the accounts it opens. Wrong passwords count per sign-in; five
     * lock it for 15 minutes. The refusal is the same whether or not the sign-in exists.
     *
     * @return list<array{login_id: int, company_id: int, company_name: string, subject_type: string, subject_id: int, name: string}>
     */
    public function attempt(string $login, string $password): array
    {
        $open = [];
        $planOff = false;
        foreach ($this->logins($login) as $l) {
            if ($l->locked_until !== null && $l->locked_until->isFuture()) {
                continue;
            }
            if ($l->password === null || $password === '' || ! Hash::check($password, (string) $l->password)) {
                $failures = (int) $l->failures + 1;
                $lock = $failures >= self::MAX_FAILURES;
                $l->forceFill(['failures' => $lock ? 0 : $failures, 'locked_until' => $lock ? now()->addMinutes(self::LOCK_MINUTES) : null])->save();
                if ($lock) {
                    RentalAudit::record((int) $l->company_id, 'login.locked', $l, 'A sign-in was locked for '.self::LOCK_MINUTES.' minutes after '.self::MAX_FAILURES.' wrong passwords',
                        ['subject_type' => $l->subject_type], 'system', null);
                }

                continue;
            }
            if (! $this->onPlan($l)) {
                $planOff = true; // the right password, on an account whose plan has no own sign-in

                continue;
            }
            $open[] = $l;
        }
        if ($open === []) {
            throw $planOff ? self::planRefusal() : self::refused();
        }

        return $this->signedIn($open, 'password');
    }

    /**
     * Send a sign-in code to the email of the sign-ins this login opens. Silent when there is none (the answer looks
     * the same), and when they have no email (codes go by email only).
     *
     * @return array{sent: bool, sent_to: ?string}
     */
    public function sendCode(string $login, ?string $ip = null): array
    {
        $key = self::normalise($login);
        $logins = $key === null ? collect() : $this->logins($login);
        $email = $logins->first(fn (RentalLogin $l) => $l->email !== null && $l->email !== '')?->email;
        $same = ['sent' => true, 'sent_to' => null]; // the answer is the same whether or not a sign-in exists (§8)
        if ($key === null || $email === null) {
            return $same;
        }
        $logins = $logins->filter(fn (RentalLogin $l) => $l->email === $email)->reject(fn (RentalLogin $l) => self::isDemoAccount((int) $l->company_id))->values();
        if ($logins->isEmpty()) {
            return $same; // a demo's people are made up: it never sends a message
        }
        $companies = $logins->pluck('company_id')->unique();
        if ($companies->contains(fn ($id) => RentalsDemo::isDemoCompany((int) $id))) {
            return ['sent' => true, 'sent_to' => null]; // nothing is ever sent from a demo account: its password is on the demo page
        }
        // The code goes out in the agency's own name when the login opens one account, else as Estate Ledger (KR-05).
        $sender = $companies->count() === 1 ? (string) (Company::withoutGlobalScopes()->whereKey($companies->first())->value('name') ?: '') : '';
        try {
            app(OtpService::class)->request(self::otpKey($login), 'login', $ip, (string) $email, $sender !== '' ? $sender : null);
        } catch (BusinessRuleException $e) {
            if ($e->errorCode() !== 'otp_rate_limited') {
                throw $e;
            }
        }

        return $same;
    }

    /**
     * Sign in with the code sent by sendCode(): the accounts it opens.
     *
     * @return list<array{login_id: int, company_id: int, company_name: string, subject_type: string, subject_id: int, name: string}>
     */
    public function verifyCode(string $login, string $code): array
    {
        $wrong = BusinessRuleException::make('otp_invalid', 'That code is not right, or it has expired. Request a new one.');
        if (self::normalise($login) === null) {
            throw $wrong;
        }
        try {
            app(OtpService::class)->verify(self::otpKey($login), 'login', $code);
        } catch (BusinessRuleException) {
            throw $wrong; // one answer for a wrong code, an old one or no sign-in at all (§8)
        }
        // A code proves the email it went to, nothing more: a phone number opens only the sign-ins with that email
        // (another agency may have typed this phone against a sign-in with its own email).
        $found = $this->logins($login);
        $email = $found->first(fn (RentalLogin $l) => $l->email !== null && $l->email !== '')?->email;
        $proved = $found->filter(fn (RentalLogin $l) => $l->email === $email && ! self::isDemoAccount((int) $l->company_id))->values();
        if ($proved->isEmpty()) {
            throw self::refused();
        }
        $logins = $proved->filter(fn (RentalLogin $l) => $this->onPlan($l))->values()->all();
        if ($logins === []) {
            throw self::planRefusal();
        }

        return $this->signedIn($logins, 'code');
    }

    /** The sign-in behind a token or a session, checked on every request: open, its person still there, the account still Estate Ledger's, its plan still including the own sign-in. */
    public function resolve(int $loginId): ?RentalLogin
    {
        $login = $this->openLogin($loginId);

        return $login !== null && $this->onPlan($login) ? $login : null;
    }

    /** Whether a sign-in is open in every way but the account's plan: the person is told why, not just signed out. */
    public function closedByPlan(int $loginId): bool
    {
        $login = $this->openLogin($loginId);

        return $login !== null && ! $this->onPlan($login);
    }

    private function onPlan(RentalLogin $login): bool
    {
        $company = Company::withoutGlobalScopes()->find($login->company_id);

        return $company !== null && self::portalOnPlan($company);
    }

    /** A sign-in that is open, its person still there, the account still running the rental books; the plan aside. */
    private function openLogin(int $loginId): ?RentalLogin
    {
        $login = RentalLogin::withoutGlobalScopes()->whereNull('disabled_at')->find($loginId);
        if ($login === null || $login->subject() === null) {
            return null;
        }
        $company = Company::withoutGlobalScopes()->find($login->company_id);
        if ($company === null || strtolower((string) $company->status) === 'inactive' || ! in_array('rentals', $company->modules(), true)) {
            return null;
        }
        if ($login->subject_type === 'landlord' && RentalsModule::isOwnerMode((int) $login->company_id)) {
            return null; // an owner-run account has no landlords to sign in
        }

        return $login;
    }

    /** The person sets (or changes) their own password, once signed in. */
    public function setPassword(RentalLogin $login, string $password): void
    {
        RentalsDemo::guard((int) $login->company_id, 'changing this password');
        if (($weak = PasswordPolicy::refusal($password, false)) !== null) {
            throw BusinessRuleException::make('weak_password', $weak);
        }
        $login->forceFill(['password' => Hash::make($password), 'failures' => 0, 'locked_until' => null])->save();
        RentalAudit::record((int) $login->company_id, 'login.password_set', $login, 'Set their own password', ['subject_type' => $login->subject_type], $login->subject_type, (int) $login->subject_id);
    }

    /** A 30-day token for one sign-in, with the portal ability only. */
    public function issueToken(RentalLogin $login, string $device): string
    {
        $name = mb_substr(trim($device) !== '' ? trim($device) : 'app', 0, 60);

        return $login->createToken($name, [RentalLogin::ABILITY], now()->addDays(self::TOKEN_DAYS))->plainTextToken;
    }

    /**
     * A 10-minute ticket to pick one of the accounts a sign-in opened (single use).
     *
     * @param  list<array{login_id: int, company_id: int, subject_type: string, subject_id: int}>  $choices
     * @return array{ticket: string, expires_in: int}
     */
    public function ticket(array $choices): array
    {
        $ticket = Str::random(40);
        Cache::put(self::TICKET_KEY.hash('sha256', $ticket), array_map(fn ($c) => [(int) $c['company_id'], (string) $c['subject_type'], (int) $c['subject_id'], (int) $c['login_id']], $choices),
            now()->addMinutes(self::TICKET_MINUTES));

        return ['ticket' => $ticket, 'expires_in' => self::TICKET_MINUTES * 60];
    }

    /** The sign-in a ticket opened for the chosen account (the ticket is used up either way). */
    public function redeem(string $ticket, int $companyId, string $subjectType, int $subjectId): RentalLogin
    {
        $pairs = (array) Cache::pull(self::TICKET_KEY.hash('sha256', $ticket), []);
        foreach ($pairs as [$cid, $type, $id, $loginId]) {
            if ((int) $cid === $companyId && $type === $subjectType && (int) $id === $subjectId) {
                $login = $this->resolve((int) $loginId);
                if ($login !== null) {
                    return $login;
                }
            }
        }
        throw BusinessRuleException::make('invalid_credentials', 'That choice was not opened by this sign-in. Sign in again.');
    }

    /** Portal codes are keyed apart from staff codes for the same address: `rentals:{email or phone}`. */
    public static function otpKey(string $login): string
    {
        return OtpService::scoped('rentals', self::normalise($login) ?? trim($login));
    }

    /** An email in lower case, or a phone in E.164; null when it is neither. */
    public static function normalise(string $login): ?string
    {
        $login = trim($login);
        if (str_contains($login, '@')) {
            return filter_var($login, FILTER_VALIDATE_EMAIL) ? strtolower($login) : null;
        }

        return Phone::e164($login, 'UG');
    }

    /** The public demo agency: its people are made up, and it sends nothing (KR-21). */
    private static function isDemoAccount(int $companyId): bool
    {
        $c = Company::withoutGlobalScopes()->find($companyId);

        return $c !== null && ((bool) $c->is_demo || ! empty(((array) ($c->onboarding_state ?? []))['rentals_demo']));
    }

    private static function planRefusal(): BusinessRuleException
    {
        return BusinessRuleException::make('portal_not_on_plan', self::PLAN_OFF);
    }

    private static function refused(): BusinessRuleException
    {
        return BusinessRuleException::make('invalid_credentials', 'That sign-in or password is not right.');
    }

    private function subjectFor(User $by, string $subjectType, int $subjectId): RentalTenant|RentalLandlord
    {
        if (! in_array($subjectType, RentalLogin::SUBJECTS, true)) {
            throw BusinessRuleException::make('invalid', 'Choose a tenant or a landlord.');
        }
        $cid = (int) $by->company_id;
        $subject = $subjectType === 'landlord'
            ? RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->find($subjectId)
            : RentalTenant::withoutGlobalScopes()->where('company_id', $cid)->find($subjectId);
        if ($subject === null) {
            throw BusinessRuleException::make('not_found', 'That '.$subjectType.' was not found.');
        }
        if ($subject instanceof RentalTenant && RentalAccess::propertyIds($by) !== null
            && ! \App\Models\RentalLease::withoutGlobalScopes()->where('company_id', $cid)->where('tenant_id', $subject->id)->whereIn('property_id', RentalAccess::propertyIds($by) ?: [0])->exists()) {
            throw BusinessRuleException::make('not_found', 'That tenant was not found.');
        }

        return $subject;
    }

    /** @return Collection<int, RentalLogin> the open sign-ins of an email or phone, across accounts, whose person is still there (the plan is asked once they prove who they are) */
    private function logins(string $login): Collection
    {
        $key = self::normalise($login);
        if ($key === null) {
            return collect();
        }
        $q = RentalLogin::withoutGlobalScopes()->whereNull('disabled_at');
        str_contains($key, '@') ? $q->where('email', $key) : $q->where('phone_e164', $key);

        return $q->orderBy('id')->get()->filter(fn (RentalLogin $l) => $this->openLogin((int) $l->id) !== null)->values();
    }

    /**
     * @param  array<int, RentalLogin>  $logins
     * @return list<array{login_id: int, company_id: int, company_name: string, subject_type: string, subject_id: int, name: string}>
     */
    private function signedIn(array $logins, string $how): array
    {
        $out = [];
        foreach ($logins as $l) {
            $l->forceFill(['failures' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();
            RentalAudit::record((int) $l->company_id, 'login.signed_in', $l, 'Signed in to their own records', ['how' => $how, 'subject_type' => $l->subject_type],
                $l->subject_type, (int) $l->subject_id);
            $out[] = ['login_id' => (int) $l->id, 'company_id' => (int) $l->company_id,
                'company_name' => (string) Company::withoutGlobalScopes()->whereKey($l->company_id)->value('name'),
                'subject_type' => (string) $l->subject_type, 'subject_id' => (int) $l->subject_id, 'name' => (string) $l->subject()?->name];
        }

        return $out;
    }
}
