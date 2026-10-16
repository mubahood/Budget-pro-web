<?php

namespace App\Support;

/**
 * Which product this installation is (docs/STANDALONE_EDITION_PLAN.md, config/edition.php).
 *
 * The SaaS edition hosts many shops and sells subscriptions; the standalone edition is the same
 * application on a buyer's own server, with exactly one business and a licence instead of a
 * subscription. Selling, stock and money are identical — this class only ever decides what
 * SURROUNDS the shop: sign-up, plans, the platform screens, the public site, the demos.
 */
final class Edition
{
    public const SAAS = 'saas';

    public const STANDALONE = 'standalone';

    public static function name(): string
    {
        $name = (string) config('edition.name', self::SAAS);
        if (! in_array($name, [self::SAAS, self::STANDALONE], true)) {
            throw new \RuntimeException("APP_EDITION must be saas or standalone, not \"{$name}\".");
        }

        return $name;
    }

    public static function isSaas(): bool
    {
        return self::name() === self::SAAS;
    }

    public static function isStandalone(): bool
    {
        return self::name() === self::STANDALONE;
    }

    /** The release this code is, from the RELEASE.json a build writes beside artisan; "dev" in a checkout. */
    public static function version(): string
    {
        static $version = null;

        return $version ??= (static function (): string {
            $file = dirname(__DIR__, 2).'/RELEASE.json';
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

            return is_array($data) && isset($data['version']) ? (string) $data['version'] : 'dev';
        })();
    }
}
