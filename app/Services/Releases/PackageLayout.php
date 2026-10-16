<?php

namespace App\Services\Releases;

/**
 * What a standalone ZIP may contain, and where each entry lands (docs/STANDALONE_EDITION_PLAN.md §8.1).
 *
 *   budget_pro/        the kernel: app, bootstrap, config, database, lang, resources, routes, vendor, artisan…
 *   budget_pro_web/    the web app: the same, plus compat/
 *   web_root_files/    what goes in the address's web folder: index.php, .htaccess, install.php, built
 *                      assets, the classic screens' assets, and an EMPTY storage/ (photos land there)
 *   PACKAGE.json       the signed list of every file
 *   INSTALL.md         the buyer's guide
 *
 * An allow-list decides what ships; a secret scan THROWS when anything selected looks like a secret
 * (S1: no .env, keys, dumps, logs, storage contents, live account notes ever ship). Pure PHP: the
 * pre-Laravel installer uses it through StandaloneArchive::verifyExtracted().
 */
final class PackageLayout
{
    public const KERNEL = 'budget_pro';

    public const WEB = 'budget_pro_web';

    public const WEB_ROOT = 'web_root_files';

    /** Folders and files of each app that ship (relative to the app root). */
    public const APP_DIRECTORIES = [
        self::KERNEL => ['app', 'bootstrap', 'config', 'database', 'lang', 'resources', 'routes', 'vendor'],
        self::WEB => ['app', 'bootstrap', 'compat', 'config', 'database', 'lang', 'resources', 'routes', 'vendor'],
    ];

    public const APP_FILES = [
        self::KERNEL => ['artisan', 'composer.json', 'composer.lock', 'RELEASE.json', 'RELEASE_KEYS.json'],
        self::WEB => ['artisan', 'composer.json', 'composer.lock'],
    ];

    /** The web folder: files and folders (relative to the web root). */
    public const WEB_ROOT_FILES = ['index.php', '.htaccess', 'install.php', 'robots.txt', 'favicon.ico'];

    public const WEB_ROOT_DIRECTORIES = ['build', 'fonts', 'images', 'css', 'js', 'vendor/fa', 'vendor/livewire', 'vendor/laravel-admin', 'vendor/laravel-admin-ext'];

    /** Where each web-folder entry is taken from when no web-root stage is given: [stage, path in that stage]. */
    public const WEB_ROOT_SOURCES = [
        'build' => ['web', 'public/build'], 'fonts' => ['web', 'public/fonts'], 'images' => ['web', 'public/images'],
        'vendor/fa' => ['web', 'public/vendor/fa'], 'vendor/livewire' => ['web', 'public/vendor/livewire'],
        'robots.txt' => ['web', 'public/robots.txt'],
        'index.php' => ['web', 'deploy/standalone-index.php'], '.htaccess' => ['web', 'deploy/standalone-htaccess'],
        'install.php' => ['web', 'deploy/install.php'],
        'css' => ['kernel', 'public/css'], 'js' => ['kernel', 'public/js'], 'favicon.ico' => ['kernel', 'public/favicon.ico'],
        'vendor/laravel-admin' => ['kernel', 'public/vendor/laravel-admin'],
        'vendor/laravel-admin-ext' => ['kernel', 'public/vendor/laravel-admin-ext'],
    ];

    /** Root-level documents beside the three folders. */
    public const ROOT_FILES = ['INSTALL.md'];

    /** The only directory entries, all 0777: what PHP must write. Everything else is a 0644 file. */
    public const DIRECTORIES = [
        'budget_pro/bootstrap/cache/', 'budget_pro/storage/', 'budget_pro/storage/app/', 'budget_pro/storage/app/private/',
        'budget_pro/storage/app/public/', 'budget_pro/storage/framework/', 'budget_pro/storage/framework/cache/',
        'budget_pro/storage/framework/cache/data/', 'budget_pro/storage/framework/sessions/', 'budget_pro/storage/framework/views/',
        'budget_pro/storage/logs/',
        'budget_pro_web/bootstrap/cache/', 'budget_pro_web/storage/', 'budget_pro_web/storage/app/', 'budget_pro_web/storage/app/private/',
        'budget_pro_web/storage/app/public/', 'budget_pro_web/storage/framework/', 'budget_pro_web/storage/framework/cache/',
        'budget_pro_web/storage/framework/cache/data/', 'budget_pro_web/storage/framework/sessions/', 'budget_pro_web/storage/framework/views/',
        'budget_pro_web/storage/logs/',
        'web_root_files/storage/',
    ];

    /** Development-only files that look like secrets but are not; left out (relative to an app root). */
    public const LEFT_OUT = ['vendor/laravel/sail/database/', 'vendor/php-debugbar/php-debugbar/src/Storage/pdo_storage_schema.sql'];

    /** Noise that never ships and is harmless. */
    private const NOISE = ['.ds_store', '.gitignore', '.gitkeep', '.gitattributes', 'thumbs.db'];

    /** Whether a package path (or file name) is noise to leave out quietly. */
    public static function isNoise(string $path): bool
    {
        return in_array(strtolower(basename($path)), self::NOISE, true);
    }

    /** Whether a path inside an app is development-only and left out. */
    public static function isLeftOut(string $insideApp): bool
    {
        foreach (self::LEFT_OUT as $prefix) {
            if ($insideApp === $prefix || str_starts_with($insideApp, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** The reason a package path looks like a secret, or null. A build that meets one stops. */
    public static function secretReason(string $path): ?string
    {
        $name = strtolower(basename($path));
        $lower = strtolower($path);

        return match (true) {
            str_starts_with($name, '.env') => 'an environment file',
            (bool) preg_match('/ssh.*\.txt$/', $name) => 'SSH access notes',
            (bool) preg_match('/^id_(rsa|dsa|ecdsa|ed25519)/', $name) => 'a private SSH key',
            (bool) preg_match('/\.(pem|key|p12|pfx)$/', $name) => 'a key or certificate',
            (bool) preg_match('/\.sql(\.|$)|\.sqlite|\.dump$|\.bak$|\.log$/', $name) => 'a database dump, backup or log',
            $name === 'auth.json' => 'Composer credentials',
            str_starts_with($name, 'live_accounts') => 'live account notes',
            (bool) preg_match('~^(budget_pro|budget_pro_web|web_root_files)/storage/.~', $lower) => 'storage contents',
            (bool) preg_match('~^(budget_pro|budget_pro_web)/bootstrap/cache/.~', $lower) => 'a cached configuration',
            default => null,
        };
    }

    /** Whether a path may appear in PACKAGE.json at all (allow-list plus traversal checks). */
    public static function allowed(string $path): bool
    {
        if ($path === '' || strlen($path) > 500 || str_contains($path, '\\') || str_contains($path, "\0")
            || str_starts_with($path, '/') || str_contains($path, '//') || preg_match('~(^|/)\.\.?(/|$)~', $path)
            || self::secretReason($path) !== null) {
            return false;
        }
        if (in_array($path, self::ROOT_FILES, true)) {
            return true;
        }
        foreach ([self::KERNEL, self::WEB] as $app) {
            if (str_starts_with($path, $app.'/')) {
                $inside = substr($path, strlen($app) + 1);
                if (in_array($inside, self::APP_FILES[$app], true)) {
                    return true;
                }
                foreach (self::APP_DIRECTORIES[$app] as $directory) {
                    if (str_starts_with($inside, $directory.'/') && ! str_starts_with($inside, 'bootstrap/cache/')) {
                        return true;
                    }
                }

                return false;
            }
        }
        if (str_starts_with($path, self::WEB_ROOT.'/')) {
            $inside = substr($path, strlen(self::WEB_ROOT) + 1);
            if (in_array($inside, self::WEB_ROOT_FILES, true)) {
                return true;
            }
            foreach (self::WEB_ROOT_DIRECTORIES as $directory) {
                if (str_starts_with($inside, $directory.'/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Where a package path sits once extracted: the apps beside PACKAGE.json, the web folder wherever
     * the buyer moved web_root_files. Null for the root documents (the buyer may delete the guide).
     */
    public static function extractedPath(string $path, string $appsParent, string $webRoot): ?string
    {
        if (in_array($path, self::ROOT_FILES, true)) {
            return null;
        }
        if (str_starts_with($path, self::WEB_ROOT.'/')) {
            return rtrim($webRoot, '/').'/'.substr($path, strlen(self::WEB_ROOT) + 1);
        }

        return rtrim($appsParent, '/').'/'.$path;
    }
}
