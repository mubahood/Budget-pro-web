<?php

namespace App\Services\Install;

/**
 * The two `.env` files of a standalone copy (docs/STANDALONE_EDITION_PLAN.md §8.3), from the
 * installer's answers. Both apps share one database, one address, ONE APP_KEY and one
 * SAAS_DATA_KEY (encrypted settings written by either app must read in the other), the classic
 * screens' prefix, and the web folder (APP_PUBLIC_PATH, where /storage really is). Only the session
 * cookies differ. Never written: MASTER_PASSWORD (empty = off on a buyer's server).
 *
 * Pure PHP: runs inside the pre-Laravel installer. EnvironmentWriter writes and round-trips each.
 */
final class StandaloneEnvironment
{
    public const ADMIN_PREFIX = 'classic';

    public const UPDATE_SERVER = 'https://budget-dynamics.schooldynamics.ug';

    /** A new Laravel key (base64:…, 32 random bytes). */
    public static function newKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    /**
     * @param  array{app_url:string,web_root:string,kernel_path:string,web_path:string,secure:bool,
     *               db_host:string,db_port:string,db_name:string,db_user:string,db_password:string,
     *               business_name:string,app_key:string,data_key:string,
     *               mail_host?:string,mail_port?:string,mail_user?:string,mail_password?:string,mail_from?:string}  $a
     * @return array{kernel:array<string,string>,web:array<string,string>}
     */
    public static function settings(array $a): array
    {
        $url = rtrim($a['app_url'], '/');
        $webRoot = rtrim($a['web_root'], '/');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $common = [
            'APP_NAME' => 'Budget Pro', 'APP_EDITION' => 'standalone', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
            'APP_KEY' => $a['app_key'], 'APP_URL' => $url, 'APP_PUBLIC_PATH' => $webRoot, 'APP_TIMEZONE' => 'UTC',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $a['db_host'], 'DB_PORT' => $a['db_port'],
            'DB_DATABASE' => $a['db_name'], 'DB_USERNAME' => $a['db_user'], 'DB_PASSWORD' => $a['db_password'],
            'BUDGETPRO_PATH' => rtrim($a['kernel_path'], '/'), 'BUDGETPRO_WEB_PATH' => rtrim($a['web_path'], '/'), 'BUDGETPRO_URL' => $url,
            'BUDGETPRO_MEDIA_ROOT' => $webRoot.'/storage', 'BUDGETPRO_MEDIA_URL' => $url.'/storage',
            'SAAS_PUBLIC_URL' => $url, 'SAAS_NEW_UI_URL' => $url, 'SAAS_REDIRECT_TO_NEW_UI' => 'true',
            'SAAS_DATA_KEY' => $a['data_key'], 'ADMIN_ROUTE_PREFIX' => self::ADMIN_PREFIX,
            'SESSION_DRIVER' => 'file', 'SESSION_SECURE_COOKIE' => $a['secure'] ? 'true' : 'false',
            'CACHE_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'database', 'FILESYSTEM_DISK' => 'local',
            'LOG_CHANNEL' => 'daily', 'LOG_LEVEL' => 'warning',
            'DEMO_PUBLIC' => 'false', 'LEGACY_API_ENABLED' => 'false',
            'APP_UPDATE_SERVER' => self::UPDATE_SERVER,
        ] + self::mail($a, $host);

        return [
            'kernel' => $common + ['SESSION_COOKIE' => 'bp_classic_session'],
            'web' => $common + ['SESSION_COOKIE' => 'bp_shop_session', 'APP_LOCALE' => 'en', 'APP_FALLBACK_LOCALE' => 'en'],
        ];
    }

    /** SMTP when a host was given (port 465 = implicit TLS), otherwise mail goes to the log. Always signed as the business. */
    private static function mail(array $a, string $host): array
    {
        $name = trim($a['business_name']);
        $smtpHost = trim((string) ($a['mail_host'] ?? ''));
        if ($smtpHost === '') {
            return ['MAIL_MAILER' => 'log', 'MAIL_FROM_ADDRESS' => 'no-reply@'.($host !== '' ? $host : 'localhost'), 'MAIL_FROM_NAME' => $name];
        }
        $port = trim((string) ($a['mail_port'] ?? '')) ?: '465';

        return [
            'MAIL_MAILER' => 'smtp', 'MAIL_HOST' => $smtpHost, 'MAIL_PORT' => $port,
            'MAIL_USERNAME' => (string) ($a['mail_user'] ?? ''), 'MAIL_PASSWORD' => (string) ($a['mail_password'] ?? ''),
            // The kernel (Laravel 10) reads MAIL_ENCRYPTION, the web app (Laravel 12) MAIL_SCHEME.
            'MAIL_ENCRYPTION' => $port === '465' ? 'ssl' : 'tls', 'MAIL_SCHEME' => $port === '465' ? 'smtps' : 'smtp',
            'MAIL_FROM_ADDRESS' => (string) ($a['mail_from'] ?? ''), 'MAIL_FROM_NAME' => $name,
        ];
    }
}
