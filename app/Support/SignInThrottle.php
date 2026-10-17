<?php

namespace App\Support;

use App\Models\User;
use App\Services\Auth\AccountLookup;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sign-in attempt limits, with one set of keys for every door: the classic sign-in, the web portal's
 * sign-in (budget-pro-new) and the API's `login` limiter (roadmap 02 §2; DECISIONS.md 2026-10-17).
 *
 *  - **Per account:** ACCOUNT_TRIES a minute. The account is the one the login opens (its id), so one
 *    person's email, phone in any spelling and username share one count. A login that opens nothing is
 *    counted by its normalised form: an email in lower case, a phone in E.164 (Phone::e164, as OtpService
 *    and AccountLookup read it), anything else in lower case. So "a0772…", "0772-…" and "+256 772…" are
 *    one count too.
 *  - **Per address:** IP_TRIES a minute, whatever the login. Enough for many people behind one carrier
 *    address, too few for spraying guesses across accounts. The address is ClientAddress::of(), never a
 *    forwarded header the app cannot trust.
 *  - **Counted first:** the web forms count each try before the password is checked ({@see hit()}), in
 *    the database limiter store (`cache.limiter`), so requests sent at the same moment cannot all slip
 *    under the limit. A right password clears that account's count; the address keeps its count, so a
 *    known password cannot buy more guesses at other accounts.
 *
 * Each app counts in its own store, so the classic and the web sign-in each allow ACCOUNT_TRIES.
 */
final class SignInThrottle
{
    public const ACCOUNT_TRIES = 6;

    public const IP_TRIES = 30;

    public const DECAY_SECONDS = 60;

    /** The classic (Encore) sign-in form. */
    public const CLASSIC = 'classic';

    /** The web portal's sign-in form (budget-pro-new). */
    public const WEB = 'web';

    /** What a person typed to sign in, normalised: an email in lower case, a phone in E.164, else lower case. */
    public static function identifier(string $raw, string $country = 'UG'): string
    {
        $raw = trim($raw);
        if (str_contains($raw, '@')) {
            return mb_strtolower($raw);
        }

        return Phone::e164($raw, $country) ?? mb_strtolower($raw);
    }

    /** The account part of a key: the account's id when the login opens one, else the normalised login. */
    public static function account(?User $user, string $raw, string $country = 'UG'): string
    {
        return $user !== null ? 'user:'.$user->id : 'login:'.self::identifier($raw, $country);
    }

    /** Hashed: the limiter's table never holds an email or a phone number. */
    public static function accountKey(string $door, ?User $user, string $raw, string $country = 'UG'): string
    {
        return 'signin:'.$door.':account:'.hash('sha256', self::account($user, $raw, $country));
    }

    public static function addressKey(string $door, string $address): string
    {
        return 'signin:'.$door.':ip:'.$address;
    }

    /**
     * Count one try on a sign-in form, before any password work. Null when it may go on; otherwise the
     * seconds until the account (or the address) may try again.
     */
    public static function hit(string $door, ?User $user, string $raw, Request $request): ?int
    {
        $account = self::accountKey($door, $user, $raw);
        $address = self::addressKey($door, ClientAddress::of($request));
        $overAccount = RateLimiter::hit($account, self::DECAY_SECONDS) > self::ACCOUNT_TRIES;
        $overAddress = RateLimiter::hit($address, self::DECAY_SECONDS) > self::IP_TRIES;
        if (! $overAccount && ! $overAddress) {
            return null;
        }

        return max(1, $overAccount ? RateLimiter::availableIn($account) : 0, $overAddress ? RateLimiter::availableIn($address) : 0);
    }

    /** The right password: that account starts again. The address keeps its count. */
    public static function clear(string $door, ?User $user, string $raw): void
    {
        RateLimiter::clear(self::accountKey($door, $user, $raw));
    }

    /**
     * The API's `login` limiter (sign-in, sign-in codes and password reset, routes/api.php):
     * ACCOUNT_TRIES a minute per account and endpoint, and IP_TRIES a minute per address across them.
     * The account is looked up the way the endpoint will (AccountLookup). A reset is counted by its token.
     *
     * @return array<int, Limit>
     */
    public static function apiLimits(Request $request): array
    {
        return [
            Limit::perMinute(self::ACCOUNT_TRIES)->by('account|'.$request->path().'|'.self::apiAccount($request)),
            Limit::perMinute(self::IP_TRIES)->by('ip|'.ClientAddress::of($request)),
        ];
    }

    /** The account an API sign-in, code or reset request is about, as {@see account()} names it. */
    public static function apiAccount(Request $request): string
    {
        foreach (['identifier', 'email', 'login'] as $field) {
            $value = $request->input($field);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $raw = mb_substr(trim((string) $value), 0, 191);
                $country = is_string($request->input('country')) && strlen((string) $request->input('country')) === 2 ? strtoupper((string) $request->input('country')) : 'UG';

                return self::account(AccountLookup::find($raw), $raw, $country);
            }
        }
        $token = $request->input('reset_token');

        return is_scalar($token) && (string) $token !== '' ? 'reset:'.hash('sha256', (string) $token) : '';
    }

    /**
     * A web sign-in form's route limiter (budget-pro-new's `throttle:login`): a backstop for requests the
     * form never counts (an empty field, a flood). It allows more than the form does, so a person sees the
     * form's own message, with the minutes to wait, rather than a bare 429 page. No account lookup here.
     *
     * @return array<int, Limit>
     */
    public static function formRouteLimits(Request $request, string $field = 'login'): array
    {
        $raw = $request->input($field);

        return [
            Limit::perMinute(self::ACCOUNT_TRIES + 4)->by('account|'.self::identifier(is_scalar($raw) ? mb_substr((string) $raw, 0, 191) : '')),
            Limit::perMinute(self::IP_TRIES * 2)->by('ip|'.ClientAddress::of($request)),
        ];
    }
}
