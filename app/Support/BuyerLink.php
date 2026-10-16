<?php

namespace App\Support;

/**
 * The private link into a buyer's page (/my-licence/claim/{licence}) — docs/STANDALONE_EDITION_PLAN.md §11.
 *
 * The page lives in the web app, but the link is made by whichever app sends the email or copies it for
 * staff (usually the kernel's queue worker or its classic licences screen). The two apps have different
 * APP_KEYs, so Laravel's own signed URLs would not verify across them. This signs with a key derived
 * from the release-signing secret (RELEASE_SIGNING_SECRET), which both apps must hold anyway to sell:
 * the buyer page signs licence.json with it. A link lasts HOURS and is made when the email is sent,
 * never when it is queued (True-Doctor lesson 9).
 */
final class BuyerLink
{
    public const HOURS = 24;

    public const PATH = '/my-licence/claim/';

    /** A signed link for $licenceId, valid for HOURS from now. $base defaults to the web app's address. */
    public static function make(string $licenceId, ?int $expires = null, ?string $base = null): string
    {
        $expires ??= now()->addHours(self::HOURS)->getTimestamp();
        $base = rtrim($base ?? (string) (config('saas.new_ui_url') ?: config('app.url')), '/');

        return $base.self::PATH.rawurlencode($licenceId).'?'.http_build_query([
            'expires' => $expires, 'signature' => self::signature($licenceId, $expires),
        ]);
    }

    /** Whether a claim for $licenceId carries a genuine, unexpired signature. */
    public static function valid(string $licenceId, mixed $expires, mixed $signature): bool
    {
        if (! is_scalar($expires) || ! ctype_digit((string) $expires) || ! is_string($signature) || $signature === '') {
            return false;
        }
        if ((int) $expires < now()->getTimestamp()) {
            return false;
        }

        try {
            return hash_equals(self::signature($licenceId, (int) $expires), $signature);
        } catch (\RuntimeException) {
            return false;
        }
    }

    private static function signature(string $licenceId, int $expires): string
    {
        return hash_hmac('sha256', $licenceId.'|'.$expires, self::key());
    }

    private static function key(): string
    {
        $secret = base64_decode((string) config('edition.signing_secret'), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Release signing is not configured (RELEASE_SIGNING_SECRET): buyer links cannot be made.');
        }

        return hash_hmac('sha256', 'budget-pro buyer link v1', $secret, true);
    }
}
