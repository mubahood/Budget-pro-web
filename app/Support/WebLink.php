<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Open on the web" from the phone (MOBILE_EXCELLENCE_PLAN.md D7): a two-minute, single-use link that
 * signs the person into the web portal (budget-pro-new, config saas.new_ui_url) at one of its pages.
 *
 * The kernel makes the link (POST /api/v1/web-link); the web portal takes it (GET /web-link?token=…).
 * The two apps have different APP_KEYs, so the token is signed with a key derived from the shared
 * SAAS_DATA_KEY (config saas.data_key, the one DataCrypt uses), and every link is recorded by the hash
 * of its nonce in `web_link_tokens`, which the portal marks used in one atomic update.
 */
final class WebLink
{
    public const TTL_SECONDS = 120;

    public const PATH = '/web-link';

    public const TABLE = 'web_link_tokens';

    /** Pages a link may not land on. */
    private const REFUSED = ['/web-link', '/logout', '/login'];

    /**
     * @param  int|null  $masterUntil  "Sign in as" (App\Support\MasterPassword): the Unix time the master session it opens ends.
     *                                 Carried inside the signed token, so only the kernel can mark a session.
     * @return array{url: string, expires_at: string, path: string}
     *
     * @throws BusinessRuleException invalid_path | web_link_unavailable
     */
    public static function issue(User $user, string $path, ?string $base = null, ?int $masterUntil = null): array
    {
        $clean = self::cleanPath($path);
        if ($clean === null) {
            throw BusinessRuleException::make('invalid_path', 'Choose a page of the web portal: a path that starts with "/".');
        }
        $base = rtrim($base ?? (string) config('saas.new_ui_url'), '/');
        if ($base === '') {
            throw BusinessRuleException::make('web_link_unavailable', 'This server has no web portal address set (SAAS_NEW_UI_URL), so it cannot open the web for you.');
        }
        $nonce = Str::random(40);
        $expires = now()->addSeconds(self::TTL_SECONDS);
        $claims = ['u' => (int) $user->id, 'c' => (int) $user->company_id, 'p' => $clean, 'e' => $expires->getTimestamp(), 'n' => $nonce];
        if ($masterUntil !== null) {
            $claims['m'] = $masterUntil;
        }
        $payload = self::encode((string) json_encode($claims));
        DB::table(self::TABLE)->insert(['nonce_hash' => hash('sha256', $nonce), 'company_id' => (int) $user->company_id, 'user_id' => (int) $user->id,
            'path' => $clean, 'expires_at' => $expires, 'created_at' => now()]);

        return ['url' => $base.self::PATH.'?'.http_build_query(['token' => $payload.'.'.self::encode(self::mac($payload))]), 'expires_at' => $expires->toIso8601String(), 'path' => $clean];
    }

    /**
     * Check a token and use it up. Valid once, within two minutes, with a genuine signature.
     *
     * @return array{user_id: int, company_id: int, path: string, master_until: int|null}
     *
     * @throws BusinessRuleException invalid_link | link_expired | link_used
     */
    public static function consume(string $token, ?string $ip = null): array
    {
        $parts = explode('.', $token);
        $claims = count($parts) === 2 && hash_equals(self::encode(self::mac($parts[0])), $parts[1]) ? json_decode(self::decode($parts[0]), true) : null;
        if (! is_array($claims) || ! isset($claims['u'], $claims['c'], $claims['p'], $claims['e'], $claims['n']) || self::cleanPath((string) $claims['p']) === null) {
            throw BusinessRuleException::make('invalid_link', 'This link is not valid. Open the page from the app again.');
        }
        if ((int) $claims['e'] < now()->getTimestamp()) {
            throw BusinessRuleException::make('link_expired', 'This link has expired: links from the app last two minutes. Open the page from the app again.');
        }
        $used = DB::table(self::TABLE)->where('nonce_hash', hash('sha256', (string) $claims['n']))->where('user_id', (int) $claims['u'])
            ->whereNull('used_at')->where('expires_at', '>=', now())->update(['used_at' => now(), 'used_ip' => $ip === null ? null : mb_substr($ip, 0, 45)]);
        if ($used !== 1) {
            throw BusinessRuleException::make('link_used', 'This link was already used. Open the page from the app again.');
        }

        return ['user_id' => (int) $claims['u'], 'company_id' => (int) $claims['c'], 'path' => (string) $claims['p'],
            'master_until' => isset($claims['m']) ? (int) $claims['m'] : null];
    }

    /** A path on the web portal ("/sales?scope=unpaid"), or null: no scheme, host, "//", backslash, spaces or control characters. */
    public static function cleanPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || strlen($path) > 500 || preg_match('/[\\\\\s\x00-\x1F\x7F]/', $path)) {
            return null;
        }
        $parts = parse_url($path);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) {
            return null;
        }
        $bare = rtrim((string) ($parts['path'] ?? '/'), '/');
        foreach (self::REFUSED as $refused) {
            if ($bare === $refused || str_starts_with($bare, $refused.'/')) {
                return null;
            }
        }

        return $path;
    }

    private static function mac(string $payload): string
    {
        $key = (string) (config('saas.data_key') ?: config('app.key'));
        if (Str::startsWith($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7));
        }

        return hash_hmac('sha256', $payload, hash_hmac('sha256', 'budget-pro web link v1', $key, true), true);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'), true);
    }
}
