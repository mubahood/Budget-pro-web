<?php

namespace App\Services\Install;

use App\Services\Releases\PackageLayout;
use App\Services\Releases\StandaloneArchive;
use App\Support\Edition;
use App\Support\Licensing\InstalledLicence;
use App\Support\Licensing\Verifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Moves a standalone copy to a newer release, and back again if anything goes wrong
 * (docs/STANDALONE_EDITION_PLAN.md §12.2). True-Doctor's updater, for Budget Pro's two apps:
 *
 *   verify     the signature against the keys THIS installation trusts (its own RELEASE_KEYS.json),
 *              every file's hash, that it is newer, and that the licence's updates cover it
 *   preflight  disk space (3× the package + 100 MB), writable folders, PHP can start processes
 *   down       maintenance mode in both apps (the self-contained 503)
 *   back up    the database, both .env files, the licence, installed.json and every code file the
 *              update touches in budget_pro/, budget_pro_web/ and the web folder
 *   swap       file by file (temp + rename) by the signed list; files the release dropped go. Never
 *              .env, storage/ or the web folder's storage/ (the buyer's photos)
 *   migrate    in FRESH processes on the new code: migrate, StandaloneReferenceSeeder, licence:apply,
 *              both apps' caches, then standalone:health (which runs the web app's shop:check)
 *   up         both apps
 *
 * Any failure after the swap began puts the code and the database back as they were and brings both
 * apps up on the old release. Command line only (the scheduler, or a technician): a web request may
 * be cut off half way through copying files.
 */
final class StandaloneUpdater
{
    /** @var list<string> */
    private array $log = [];

    private readonly string $kernelRoot;

    private readonly string $webAppRoot;

    private readonly string $webRoot;

    /** Update backups kept (each holds a copy of the code); nightly backups are InstallationBackups'. */
    public const KEEP_UPDATE_BACKUPS = 2;

    /** The roots default to this installation's own; a test passes temporary folders. */
    public function __construct(
        private readonly DatabaseBackup $backup,
        private readonly SystemState $state,
        private readonly ArtisanRunner $artisan,
        ?string $kernelRoot = null,
        ?string $webAppRoot = null,
        ?string $webRoot = null,
    ) {
        $this->kernelRoot = rtrim($kernelRoot ?? self::kernelPath(), '/');
        $this->webAppRoot = rtrim($webAppRoot ?? self::webAppPath(), '/');
        $this->webRoot = rtrim($webRoot ?? public_path(), '/');
    }

    /** The kernel's folder (budget_pro), from either app: this class always lives in it. */
    public static function kernelPath(): string
    {
        return dirname(__DIR__, 3);
    }

    /** The web app's folder (budget_pro_web): BUDGETPRO_WEB_PATH, else beside the kernel. */
    public static function webAppPath(): string
    {
        $configured = config('edition.web_path');

        return is_string($configured) && $configured !== '' ? rtrim($configured, '/') : dirname(self::kernelPath()).'/'.PackageLayout::WEB;
    }

    /**
     * Check a package without changing anything.
     *
     * @return array{version:string,built_at:string,files:int,size:int,mobile_min_version:?string}
     */
    public function inspect(string $archive): array
    {
        if (! Edition::isStandalone()) {
            throw new \DomainException('Only a standalone installation updates itself.');
        }
        if (! is_file($archive)) {
            throw new \DomainException('The update file is missing.');
        }
        $keys = config('edition.public_keys', []);
        if (! is_array($keys) || $keys === []) {
            throw new \DomainException('This installation has no trusted release keys, so it cannot verify an update.');
        }

        try {
            $manifest = (new StandaloneArchive)->verify($archive, new Verifier($keys));
        } catch (\RuntimeException $e) {
            throw new \DomainException('This is not a genuine Budget Pro update: '.$e->getMessage());
        }

        $version = (string) $manifest['version'];
        $builtAt = is_string($manifest['built_at'] ?? null) ? $manifest['built_at'] : null;
        $current = $this->installedVersion();
        if ($current !== 'dev' && version_compare($version, $current, '<=')) {
            throw new \DomainException("This installation already runs {$current}; the update is {$version}.");
        }

        $licence = new InstalledLicence(null, (string) config('edition.licence_path'));
        $until = $licence->updatesUntil();
        if (! $licence->isValid() || $until === null) {
            throw new \DomainException('A valid licence is needed to install updates. Selling is not affected.');
        }
        if ($builtAt === null || Carbon::parse($builtAt)->greaterThan($until->copy()->endOfDay())) {
            throw new \DomainException('This release was made after your updates ended on '.$until->format('d M Y').'. Renew updates on your buyer page.');
        }

        $release = $this->readFromArchive($archive, PackageLayout::KERNEL.'/RELEASE.json');

        return ['version' => $version, 'built_at' => $builtAt, 'files' => count($manifest['files']), 'size' => (int) filesize($archive),
            'mobile_min_version' => is_string($release['mobile_min_version'] ?? null) && $release['mobile_min_version'] !== '' ? $release['mobile_min_version'] : null];
    }

    /**
     * Apply a verified package.
     *
     * @return array{from:string,to:string,backup:string}
     */
    public function apply(string $archive, ?int $by = null): array
    {
        if (! app()->runningInConsole()) {
            throw new \DomainException('Updates run from the scheduler or the command line, never inside a web request.');
        }

        $private = $this->kernelRoot.'/storage/app/private';
        File::ensureDirectoryExists($private, 0700);
        $lock = fopen($private.'/update.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \DomainException('Another update is already running.');
        }

        $this->log = [];
        $from = $this->installedVersion();
        $stamp = gmdate('Ymd-His');
        $backupDir = $private.'/backups/update-'.$stamp;
        $extract = $private.'/updates/extract-'.$stamp;
        $down = $swapped = $migrated = false;
        $dump = null;
        $snapshot = [];
        $newManifest = [];

        try {
            $this->step('Checking the update package');
            $checked = $this->inspect($archive);
            $to = $checked['version'];
            $this->state->running('update', "Updating {$from} → {$to}", $by);

            $this->step('Checking free disk space and folders');
            $this->preflight($checked['size']);

            $this->step('Extracting the package');
            File::ensureDirectoryExists($extract, 0700);
            $zip = new ZipArchive;
            if ($zip->open($archive, ZipArchive::RDONLY) !== true || ! $zip->extractTo($extract)) {
                throw new \RuntimeException('The package could not be extracted.');
            }
            $zip->close();
            $newManifest = $this->manifestAt($extract.'/PACKAGE.json');
            $oldManifest = $this->manifestAt($this->packagePath());

            $this->step('Switching both apps to maintenance mode');
            $down = true;
            $this->artisan->down($this->kernelRoot);
            $this->artisan->down($this->webAppRoot);

            $this->step('Backing up the database and settings');
            File::ensureDirectoryExists($backupDir, 0700);
            $dump = $this->backup->dump($backupDir.'/database');
            foreach (['kernel.env' => $this->kernelRoot.'/.env', 'web.env' => $this->webAppRoot.'/.env',
                'licence.json' => (string) config('edition.licence_path'), 'installed.json' => (string) config('edition.installed_path')] as $name => $file) {
                if ($file !== '' && is_file($file)) {
                    copy($file, $backupDir.'/'.$name);
                }
            }

            $this->step('Backing up the code the update replaces');
            $snapshot = $this->snapshot(array_unique([...array_keys($newManifest), ...array_keys($oldManifest)]), $backupDir);

            $this->step('Installing the new files');
            $swapped = true;
            $this->swap($extract, $newManifest, $oldManifest);

            $this->step('Updating the database (migrations and reference data)');
            $migrated = true;
            $this->artisan->fresh($this->kernelRoot, ['migrate', '--force']);
            $this->artisan->fresh($this->kernelRoot, ['db:seed', '--class=Database\\Seeders\\StandaloneReferenceSeeder', '--force']);
            $this->artisan->fresh($this->kernelRoot, ['licence:apply']);

            $this->step('Caching configuration, routes and views');
            $this->artisan->cache($this->kernelRoot, 'kernel');
            $this->artisan->cache($this->webAppRoot, 'web');

            $this->step('Checking the updated system');
            $this->artisan->fresh($this->kernelRoot, ['standalone:health']);

            $this->recordInstalledVersion($to, $from);
            $this->step('Bringing both apps back');
            $this->artisan->up($this->kernelRoot);
            $this->artisan->up($this->webAppRoot);
            $this->step("Updated to {$to}");
            $this->state->finished('update', "Updated {$from} → {$to}", $this->log);
            $this->pruneUpdateBackups($private.'/backups');

            return ['from' => $from, 'to' => $to, 'backup' => $backupDir];
        } catch (\Throwable $e) {
            $this->recover($e, $swapped, $migrated, $down, $snapshot, $newManifest, $backupDir, $dump);

            throw $e;
        } finally {
            File::deleteDirectory($extract);
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * After a failure: the old code, then the old database, then both apps up, then the log.
     *
     * @param  array<string,string>  $snapshot  package path => saved copy
     * @param  array<string,mixed>  $newManifest
     */
    private function recover(\Throwable $e, bool $swapped, bool $migrated, bool $down, array $snapshot, array $newManifest, string $backupDir, ?string $dump): void
    {
        $this->step('Stopped: '.$e->getMessage());
        if ($swapped) {
            $this->step('Putting the previous release back');
            $this->rollbackCode($snapshot, $newManifest, $backupDir);
        }
        if ($migrated && is_string($dump)) {
            $this->step('Restoring the database from the backup');
            try {
                $this->backup->restore($dump);
            } catch (\Throwable $restore) {
                $this->step('The database could not be restored automatically: '.$restore->getMessage().'. Restore '.$dump.' by hand.');
            }
        }
        if ($swapped) {
            foreach (['kernel' => $this->kernelRoot, 'web' => $this->webAppRoot] as $side => $root) {
                try {
                    $this->artisan->cache($root, $side); // the old release ran cached; a speed-up, never a reason to stop
                } catch (\Throwable) {
                }
            }
        }
        if ($down) {
            foreach ([$this->kernelRoot, $this->webAppRoot] as $root) {
                try {
                    $this->artisan->up($root);
                } catch (\Throwable $up) {
                    $this->step('Could not leave maintenance mode in '.basename($root).': run `php artisan up` there. '.$up->getMessage());
                }
            }
        }
        $this->state->failed('update', $e->getMessage(), $this->log);
    }

    private function preflight(int $size): void
    {
        $needed = 3 * $size + 100 * 1024 * 1024;
        $free = @disk_free_space($this->kernelRoot);
        if ($free !== false && $free < $needed) {
            throw new \DomainException('Not enough free disk space for a safe update: it needs about '.(int) ceil($needed / 1048576).' MB.');
        }
        if (! is_file($this->webAppRoot.'/artisan')) {
            throw new \DomainException("The web app was not found at {$this->webAppRoot}.");
        }
        foreach ([$this->kernelRoot, $this->webAppRoot, $this->webRoot, $this->kernelRoot.'/storage', $this->webAppRoot.'/storage'] as $folder) {
            if (! is_dir($folder) || ! is_writable($folder)) {
                throw new \DomainException("This folder is not writable: {$folder}");
            }
        }
        if (! $this->artisan->available()) {
            throw new \DomainException('This server does not let PHP start a process, which an update needs. Ask your host to allow proc_open.');
        }
    }

    /** @param list<string> $paths @return array<string,string> package path => saved copy */
    private function snapshot(array $paths, string $backupDir): array
    {
        $snapshot = [];
        foreach ($paths as $path) {
            $target = $this->target($path);
            if ($target !== null && is_file($target)) {
                $copy = $backupDir.'/code/'.$path;
                File::ensureDirectoryExists(dirname($copy), 0700);
                if (! copy($target, $copy)) {
                    throw new \RuntimeException("Could not back up {$path}.");
                }
                $snapshot[$path] = $copy;
            }
        }
        if (is_file($this->packagePath())) {
            copy($this->packagePath(), $backupDir.'/PACKAGE.json');
        }

        return $snapshot;
    }

    /** @param array<string,mixed> $new @param array<string,mixed> $old */
    private function swap(string $extract, array $new, array $old): void
    {
        foreach (array_keys($new) as $path) {
            $target = $this->target($path);
            if ($target === null) {
                continue;
            }
            File::ensureDirectoryExists(dirname($target));
            $temporary = $target.'.bp-update';
            $written = $path === PackageLayout::WEB_ROOT.'/.htaccess' && is_file($target)
                ? file_put_contents($temporary, $this->keepHostLines((string) file_get_contents($target), (string) file_get_contents($extract.'/'.$path))) !== false
                : copy($extract.'/'.$path, $temporary);
            if (! $written || ! rename($temporary, $target)) {
                @unlink($temporary);
                throw new \RuntimeException("Could not write {$path}.");
            }
        }
        foreach (array_diff(array_keys($old), array_keys($new)) as $gone) {
            $target = $this->target($gone);
            if ($target !== null && is_file($target)) {
                @unlink($target);
            }
        }
        copy($extract.'/PACKAGE.json', $this->packagePath()) || throw new \RuntimeException('Could not write PACKAGE.json.');
        $this->clearCompiledFiles();
    }

    /** cPanel's PHP-version lines in the web folder's .htaccess survive the update (without them the host may pick another PHP). */
    private function keepHostLines(string $old, string $new): string
    {
        if (preg_match('/\n?# php -- BEGIN cPanel-generated handler.*?# php -- END cPanel-generated handler[^\n]*\n?/s', $old, $m)
            && ! str_contains($new, '# php -- BEGIN cPanel-generated handler')) {
            return rtrim($new, "\n")."\n\n".trim($m[0])."\n";
        }

        return $new;
    }

    /** Where a package path lives on this server; null for anything the updater never touches. */
    private function target(string $path): ?string
    {
        if (! PackageLayout::allowed($path)) {
            return null;
        }
        $roots = [PackageLayout::KERNEL => $this->kernelRoot, PackageLayout::WEB => $this->webAppRoot, PackageLayout::WEB_ROOT => $this->webRoot];
        foreach ($roots as $prefix => $root) {
            if (str_starts_with($path, $prefix.'/')) {
                $inside = substr($path, strlen($prefix) + 1);
                // Never the buyer's own settings, data or photos, nor what the apps compile for themselves.
                if ($inside === '' || str_starts_with(basename($inside), '.env') || str_starts_with($inside, 'storage/') || str_starts_with($inside, 'bootstrap/cache/')) {
                    return null;
                }

                return $root.'/'.$inside;
            }
        }

        return null; // INSTALL.md: the buyer may have deleted the guide
    }

    /** @param array<string,string> $snapshot @param array<string,mixed> $newManifest */
    private function rollbackCode(array $snapshot, array $newManifest, string $backupDir): void
    {
        foreach (array_keys($newManifest) as $path) { // what the new release added goes
            $target = $this->target($path);
            if ($target !== null && ! isset($snapshot[$path]) && is_file($target)) {
                @unlink($target);
            }
        }
        foreach ($snapshot as $path => $copy) { // everything that was there comes back as it was
            $target = (string) $this->target($path);
            File::ensureDirectoryExists(dirname($target));
            if (! @copy($copy, $target.'.bp-rollback') || ! @rename($target.'.bp-rollback', $target)) {
                $this->step("Could not put back {$path}: copy it from {$copy}.");
            }
        }
        if (is_file($backupDir.'/PACKAGE.json')) {
            @copy($backupDir.'/PACKAGE.json', $this->packagePath());
        }
        $marker = (string) config('edition.installed_path');
        if ($marker !== '' && is_file($backupDir.'/installed.json')) {
            @copy($backupDir.'/installed.json', $marker); // the version is recorded just before the apps come back up
        }
        $this->clearCompiledFiles();
    }

    /** Cached config, routes, packages and views belong to the code that wrote them. */
    private function clearCompiledFiles(): void
    {
        foreach ([$this->kernelRoot, $this->webAppRoot] as $root) {
            foreach ([...(glob($root.'/bootstrap/cache/*.php') ?: []), ...(glob($root.'/storage/framework/views/*.php') ?: [])] as $file) {
                @unlink($file);
            }
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /** The installed (or extracted) PACKAGE.json's file list, unsafe paths left out. @return array<string,mixed> */
    private function manifestAt(string $file): array
    {
        $signed = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $files = is_array($signed['manifest']['files'] ?? null) ? $signed['manifest']['files'] : [];

        return array_filter($files, fn ($v, $path) => is_string($path) && PackageLayout::allowed($path), ARRAY_FILTER_USE_BOTH);
    }

    /** PACKAGE.json of the installed release, beside budget_pro/ and budget_pro_web/. */
    private function packagePath(): string
    {
        return dirname($this->kernelRoot).'/PACKAGE.json';
    }

    private function installedVersion(): string
    {
        $file = $this->kernelRoot.'/RELEASE.json';
        $release = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($release) && is_string($release['version'] ?? null) ? $release['version'] : 'dev';
    }

    /** @return array<string,mixed> */
    private function readFromArchive(string $archive, string $name): array
    {
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            return [];
        }
        $data = json_decode((string) $zip->getFromName($name), true);
        $zip->close();

        return is_array($data) ? $data : [];
    }

    private function recordInstalledVersion(string $version, string $from): void
    {
        $marker = (string) config('edition.installed_path');
        $state = is_file($marker) ? (json_decode((string) file_get_contents($marker), true) ?: []) : [];
        $state = array_merge($state, ['version' => $version, 'updated_from' => $from, 'updated_at' => now()->toIso8601String()]);
        File::put($marker.'.tmp', json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        @chmod($marker.'.tmp', 0600);
        rename($marker.'.tmp', $marker);
    }

    /** Each update backup holds a copy of the code; the newest two are kept. */
    private function pruneUpdateBackups(string $directory): void
    {
        $folders = glob($directory.'/update-*', GLOB_ONLYDIR) ?: [];
        rsort($folders, SORT_STRING);
        foreach (array_slice($folders, self::KEEP_UPDATE_BACKUPS) as $old) {
            File::deleteDirectory($old);
        }
    }

    private function step(string $message): void
    {
        $this->log[] = now()->format('H:i:s').'  '.$message;
        $this->state->progress($message, $this->log);
    }
}
