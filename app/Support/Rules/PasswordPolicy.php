<?php

namespace App\Support\Rules;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;

/**
 * The one password rule, and the words that describe it (roadmap 02 §2, pattern from True-Doctor).
 *
 * At least 8 characters with letters and numbers, and, where the server can ask, not a password
 * already published in a breach (Have I Been Pwned's k-anonymity range API: only the first five
 * characters of the SHA-1 hash leave the server). The breach check fails open: a server with no
 * internet still lets a person set a password. PASSWORD_BREACH_CHECK=false switches it off.
 *
 * It applies whenever a password is set: sign-up on every channel, change, reset, team members,
 * invites, the classic Employees form and the classic settings page. A password set before this
 * rule keeps working; the rule is checked only when a new one is chosen. Every screen that asks
 * for a new password shows {@see hint()}, so the words can never disagree with the rule.
 */
final class PasswordPolicy
{
    public const MIN = 8;

    /** The longest password the forms take (they always have). */
    public const MAX = 100;

    /** Seconds the breach check may take before it gives up and lets the password through. */
    public const BREACH_TIMEOUT = 5;

    public const LEAKED = 'This password is on a public list of leaked passwords. Choose a different one.';

    public static function rule(bool $breachCheck = true): Password
    {
        $rule = PasswordRule::min(self::MIN)->letters()->numbers();

        return $breachCheck && config('saas.password_breach_check', true) ? $rule->uncompromised() : $rule;
    }

    /**
     * The usual field rules for a new password.
     *
     * @return array<int, mixed>
     */
    public static function rules(bool $confirmed = false, bool $required = true, int $max = self::MAX): array
    {
        return array_merge([$required ? 'required' : 'nullable', 'string', 'max:'.$max, self::rule()], $confirmed ? ['confirmed'] : []);
    }

    /**
     * Rules for a laravel-admin password field. Its edit form sends the stored hash back when the
     * password is left as it is; that is not a new password, so the rule does not apply to it.
     *
     * @return array<int, mixed>
     */
    public static function formRules(mixed $submitted, ?string $currentHash, bool $required = true, bool $confirmed = true): array
    {
        if ($currentHash !== null && $currentHash !== '' && is_string($submitted) && hash_equals($currentHash, $submitted)) {
            return [$required ? 'required' : 'nullable'];
        }

        return self::rules($confirmed, $required);
    }

    /** What every new-password field says under it. */
    public static function hint(): string
    {
        return 'At least '.self::MIN.' characters, with letters and numbers.';
    }

    /** The refusal when a password is too short or lacks letters or numbers. */
    public static function error(): string
    {
        return 'Use at least '.self::MIN.' characters, with letters and numbers.';
    }

    /**
     * Why this password may not be set, or null when it may (for code that checks outside a form).
     * $breachCheck false checks the length, letters and numbers only: for a backstop behind a form
     * that already ran the whole rule, so the breach API is not asked twice.
     */
    public static function refusal(string $password, bool $breachCheck = true): ?string
    {
        $validator = Validator::make(['password' => $password], ['password' => ['required', 'string', self::rule($breachCheck)]], ['password.required' => self::error()]);

        return $validator->fails() ? (string) $validator->errors()->first('password') : null;
    }

    /**
     * The framework waits 30 seconds for the breach API; a sign-up must not hang that long when the
     * host has no internet. Called from each app's service provider (register).
     */
    public static function shortenBreachCheck(Container $app): void
    {
        $app->extend(UncompromisedVerifier::class, fn ($verifier, $app) => new NotPwnedVerifier($app->make(HttpFactory::class), self::BREACH_TIMEOUT));
    }
}
