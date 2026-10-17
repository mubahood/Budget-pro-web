<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\User;
use App\Services\Messaging\Messenger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The owner's master password (roadmap 02 §2; DECISIONS.md 2026-10-17, which replaces the plaintext
 * one of 2026-10-03). It opens a shop account for support work, under these rules:
 *
 *  - SaaS only: always off on a standalone copy, whatever .env says (STANDALONE_EDITION_PLAN.md §5.4).
 *  - Only its bcrypt hash is configured (MASTER_PASSWORD_HASH, made by `master-password:set`).
 *  - It works only from MASTER_PASSWORD_ALLOWED_IPS (IPs or CIDR ranges; empty = nowhere), on the
 *    classic and web sign-in forms and on "Sign in as" (a platform admin, with a reason).
 *  - It never opens a platform-admin account, a switched-off account, an account removed from its
 *    shop or one without a shop.
 *  - Every attempt with the right master password, allowed or refused, is a row in
 *    master_password_uses and a message to the owner (OWNER_ALERT_EMAIL / OWNER_ALERT_WHATSAPP, or
 *    the support contact). A wrong master password is just a failed sign-in.
 *  - The session it opens is marked (session key SESSION_KEY) and ends after
 *    MASTER_PASSWORD_SESSION_MINUTES; both interfaces show a banner until then.
 *
 * Used by the classic AuthController, the web portal's AuthenticatedSessionController and the
 * classic SignInAsController.
 */
final class MasterPassword
{
    public const CLASSIC = 'classic';

    public const WEB = 'web';

    public const SIGN_IN_AS = 'sign_in_as';

    public const TABLE = 'master_password_uses';

    /** Session key: the Unix time the master session ends. */
    public const SESSION_KEY = 'master_session_until';

    /** Session key: the master_password_uses row that opened it. */
    public const SESSION_USE_KEY = 'master_session_use';

    public const MIN_LENGTH = 20;

    public const MIN_REASON = 10;

    /** Refused uses alert the owner at most once per address in this many seconds. */
    private const REFUSED_ALERT_EVERY = 600;

    private const REFUSALS = [
        'ip_not_allowed' => 'The master password does not work from this address.',
        'platform_admin' => 'The master password never opens a platform administrator’s account.',
        'inactive' => 'This account is switched off, so the master password cannot open it.',
        'removed' => 'This person was removed from their shop, so the master password cannot open the account.',
        'no_company' => 'This account is not linked to a shop, so the master password cannot open it.',
    ];

    /** A master password is set and this copy may use it. */
    public static function configured(): bool
    {
        return ! Edition::isStandalone() && self::hash() !== '';
    }

    /** True when $password is the master password (SaaS only, against the configured hash). */
    public static function matches(?string $password): bool
    {
        if (! self::configured() || $password === null || $password === '') {
            return false;
        }
        try {
            return Hash::check($password, self::hash());
        } catch (\RuntimeException) {
            return false; // not a bcrypt hash: treated as unset
        }
    }

    /** The address is on MASTER_PASSWORD_ALLOWED_IPS. An empty list allows nobody. */
    public static function ipAllowed(?string $ip): bool
    {
        $list = self::allowedIps();

        return $ip !== null && $ip !== '' && $list !== [] && IpUtils::checkIp($ip, $list);
    }

    /** @return list<string> */
    public static function allowedIps(): array
    {
        $list = config('saas.master_password_allowed_ips', []);
        if (is_string($list)) {
            $list = explode(',', $list);
        }

        return array_values(array_filter(array_map('trim', (array) $list), fn ($ip) => $ip !== ''));
    }

    /** The account belongs to a platform administrator (Encore role admin/administrator). Read from the tables, so it works in both apps. */
    public static function isPlatformAdmin(User $user): bool
    {
        return DB::table('admin_role_users')->join('admin_roles', 'admin_roles.id', '=', 'admin_role_users.role_id')
            ->where('admin_role_users.user_id', $user->id)
            ->whereIn('admin_roles.slug', \App\Http\Middleware\PlatformAdminOnly::PLATFORM_ROLES)->exists();
    }

    /** Why the master password may not open this account (a refusal code), or null when it may. */
    public static function targetRefusal(User $user): ?string
    {
        if (self::isPlatformAdmin($user)) {
            return 'platform_admin';
        }
        if (strtolower(trim((string) $user->status)) !== 'active') {
            return 'inactive';
        }
        $company = $user->company_id ? Company::withoutGlobalScopes()->find($user->company_id) : null;
        if ($company === null) {
            return 'no_company';
        }
        $member = DB::table('company_members')->where('company_id', $company->id)->where('user_id', $user->id)->first();
        if ($member && $member->status !== 'active' && (int) $company->owner_id !== (int) $user->id) {
            return 'removed';
        }

        return null;
    }

    public static function refusalMessage(string $code): string
    {
        return self::REFUSALS[$code] ?? 'The master password cannot open this account.';
    }

    /**
     * The sign-in forms (classic and web). Null when $password is not the master password (a plain
     * failed sign-in). Otherwise the attempt is recorded and the owner told, allowed or not.
     *
     * @return array{allowed: bool, refusal: string|null, use_id: int}|null
     */
    public static function attempt(?string $password, User $user, string $where, ?Request $request = null): ?array
    {
        if (! self::matches($password)) {
            return null;
        }
        $request ??= request();
        $refusal = self::ipAllowed($request->ip()) ? self::targetRefusal($user) : 'ip_not_allowed';
        $id = self::record($where, $user, $refusal === null, $refusal, $request);
        self::alert($id, $where, $user, null, $request->ip(), null, $refusal);

        return ['allowed' => $refusal === null, 'refusal' => $refusal, 'use_id' => $id];
    }

    /** Whether $password opens $user on a sign-in form ({@see attempt()}). */
    public static function allows(?string $password, User $user, string $where, ?Request $request = null): bool
    {
        return (self::attempt($password, $user, $where, $request)['allowed'] ?? false) === true;
    }

    /**
     * "Sign in as" from the classic Companies screen: a platform administrator opens $target in the
     * web portal with the master password and a reason. Returns the master_password_uses id.
     *
     * @throws BusinessRuleException master_off | not_platform_admin | reason_required | master_wrong | ip_not_allowed | platform_admin | inactive | removed | no_company
     */
    public static function signInAs(User $admin, User $target, string $reason, string $password, Request $request): int
    {
        if (! self::configured()) {
            throw BusinessRuleException::make('master_off', 'The master password is not set up on this server (MASTER_PASSWORD_HASH).');
        }
        if (! self::isPlatformAdmin($admin)) {
            throw BusinessRuleException::make('not_platform_admin', 'Only a platform administrator can do this.');
        }
        $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');
        if (mb_strlen($reason) < self::MIN_REASON) {
            throw BusinessRuleException::make('reason_required', 'Say why you are opening this account, in '.self::MIN_REASON.' characters or more.');
        }
        if (! self::matches($password)) {
            throw BusinessRuleException::make('master_wrong', 'That master password is not right.');
        }
        $refusal = self::ipAllowed($request->ip()) ? self::targetRefusal($target) : 'ip_not_allowed';
        $id = self::record(self::SIGN_IN_AS, $target, $refusal === null, $refusal, $request, $admin, $reason);
        self::alert($id, self::SIGN_IN_AS, $target, $admin, $request->ip(), $reason, $refusal);
        if ($refusal !== null) {
            throw BusinessRuleException::make($refusal, self::refusalMessage($refusal));
        }

        return $id;
    }

    public static function sessionMinutes(): int
    {
        return max(1, (int) config('saas.master_password_session_minutes', 60));
    }

    /** When a master session opened now ends (Unix time). */
    public static function sessionEndsAt(): int
    {
        return now()->addMinutes(self::sessionMinutes())->getTimestamp();
    }

    /** "14:05", in the shop's timezone. */
    public static function untilLabel(int $until, ?int $companyId = null): string
    {
        $company = $companyId ? Company::withoutGlobalScopes()->find($companyId) : null;

        return Carbon::createFromTimestamp($until)->setTimezone(LocalTime::timezone($company))->format('H:i');
    }

    /** The banner both interfaces show during a master session. */
    public static function bannerText(int $until, ?int $companyId = null): string
    {
        return 'Signed in with the master password until '.self::untilLabel($until, $companyId).'.';
    }

    private static function hash(): string
    {
        return trim((string) config('saas.master_password_hash', ''));
    }

    private static function record(string $where, User $target, bool $allowed, ?string $refusal, Request $request, ?User $by = null, ?string $reason = null): int
    {
        $id = (int) DB::table(self::TABLE)->insertGetId([
            'used_by_admin_id' => $by?->id, 'target_user_id' => $target->id, 'company_id' => $target->company_id ?: null,
            'where' => $where, 'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'ip' => $request->ip() === null ? null : mb_substr((string) $request->ip(), 0, 45),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'allowed' => $allowed, 'refusal_reason' => $refusal, 'created_at' => now(),
        ]);
        Log::warning($allowed ? '[auth] master password used' : '[auth] master password use refused', [
            'use_id' => $id, 'user_id' => $target->id, 'company_id' => $target->company_id, 'where' => $where,
            'by' => $by?->id, 'ip' => $request->ip(), 'refusal' => $refusal,
        ]);

        return $id;
    }

    /** One short message on email and one on WhatsApp (SMS if WhatsApp fails). Never the password. */
    private static function alert(int $useId, string $where, User $target, ?User $by, ?string $ip, ?string $reason, ?string $refusal): void
    {
        if ($refusal !== null && ! RateLimiter::attempt('master-refused-alert:'.$ip, 1, fn () => true, self::REFUSED_ALERT_EVERY)) {
            return; // refused again from the same address: recorded, not re-alerted
        }
        $company = $target->company_id ? Company::withoutGlobalScopes()->find($target->company_id) : null;
        $place = match ($where) {
            self::CLASSIC => 'the classic sign-in',
            self::WEB => 'the web sign-in',
            default => 'Sign in as'.($by ? ' by '.($by->name ?: $by->username) : ''),
        };
        $account = trim(($target->name ?: 'Account').' ('.($target->email ?: $target->phone_e164 ?: $target->username).')');
        $text = ($refusal === null ? 'Master password used' : 'Master password refused ('.str_replace('_', ' ', $refusal).')')
            .": {$account}".($company ? ", {$company->name} (#{$company->id})" : '')
            .", on {$place}, from ".($ip ?: 'an unknown address').' at '.now()->setTimezone((string) config('saas.display_timezone', 'Africa/Kampala'))->format('d M H:i')
            .($reason ? ". Reason: {$reason}" : '').". Record #{$useId}.";

        $meta = ['purpose' => 'master_password'];
        $email = trim((string) (config('saas.owner_alert_email') ?: config('support.email', '')));
        $phone = preg_replace('/[^\d+]/', '', (string) (config('saas.owner_alert_whatsapp') ?: config('support.whatsapp', ''))) ?? '';
        $messenger = app(Messenger::class);
        try {
            if ($email !== '') {
                $messenger->send($email, $text, ['mail'], $meta + ['options' => ['mail' => ['subject' => config('app.name').': '.($refusal === null ? 'master password used' : 'master password refused')]]]);
            }
            if ($phone !== '') {
                $messenger->send(str_starts_with($phone, '+') ? $phone : '+'.$phone, $text, ['whatsapp', 'sms'], $meta);
            }
        } catch (\Throwable $e) {
            Log::error('[auth] master password alert failed', ['use_id' => $useId, 'error' => $e->getMessage()]);
        }
        if ($email === '' && $phone === '') {
            Log::warning('[auth] master password alert has nowhere to go: set OWNER_ALERT_EMAIL or OWNER_ALERT_WHATSAPP', ['use_id' => $useId]);
        }
    }
}
