<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The address that security decisions use: the master password's allow-list
 * (App\Support\MasterPassword) and the per-address sign-in limits (App\Support\SignInThrottle).
 *
 * It is the connecting address (REMOTE_ADDR), unless the app trusts an explicit list of proxy addresses;
 * then the forwarded address is taken, and only from those proxies (Symfony's own rule, through
 * Request::ip()). When the trusted proxies are `*`, X-Forwarded-For is never believed here: anyone who
 * reaches the server directly could write any address into it, and walk past the allow-list or start
 * a fresh attempt count with every request.
 *
 * Both apps call it: budget-pro-new reads TRUSTED_PROXIES into app.trusted_proxies; budget-pro's
 * TrustProxies middleware trusts no proxy (trustedproxy.proxies is the framework's own key).
 */
final class ClientAddress
{
    public static function of(?Request $request = null): string
    {
        $request ??= request();
        $remote = (string) $request->server('REMOTE_ADDR', '');
        if (! self::trustsAListOfProxies()) {
            return $remote;
        }

        return (string) ($request->ip() ?? $remote);
    }

    /** True only when the trusted proxies are named addresses (never `*`, `**` or nothing). */
    public static function trustsAListOfProxies(): bool
    {
        $proxies = config('app.trusted_proxies') ?? config('trustedproxy.proxies');
        if (is_string($proxies)) {
            $proxies = explode(',', $proxies);
        }
        $list = array_values(array_filter(array_map(fn ($p) => trim((string) $p), (array) $proxies), fn ($p) => $p !== ''));

        return $list !== [] && array_intersect($list, ['*', '**']) === [];
    }
}
