<?php

namespace App\Services\Install;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * The nightly backup of a standalone installation, and the list the owner downloads from
 * (docs/STANDALONE_EDITION_PLAN.md §12.4).
 *
 * A buyer has nobody running their server for them, so the copy keeps its own: one zip holding
 * the whole database, both apps' `.env` files (APP_KEY unlocks EFRIS credentials and every other
 * encrypted setting), the licence and install marker, and every uploaded file (product photos,
 * logos, receipt photos, chat pictures under the web root's storage folder). Seven are kept. They
 * live on the same server, which is why the page warns when the newest is old and the guide says
 * to download one regularly.
 */
final class InstallationBackups
{
    public const KEEP = 7;

    public const PREFIX = 'budget-pro-backup-';

    private const NAME = '/^budget-pro-backup-\d{8}-\d{6}\.zip$/';

    /**
     * @param  string|null  $webEnv  the web app's .env (installed: the sibling budget_pro_web/.env)
     * @param  string|null  $mediaRoot  uploads (installed: the web root's storage folder, the admin disk)
     * @param  string|null  $directory  where the zips go (default storage/app/private/backups)
     * @param  string|null  $kernelEnv  this app's .env (default base_path('.env'))
     */
    public function __construct(
        private readonly DatabaseBackup $database,
        private readonly ?string $webEnv = null,
        private readonly ?string $mediaRoot = null,
        private readonly ?string $directory = null,
        private readonly ?string $kernelEnv = null,
    ) {
    }

    public function directory(): string
    {
        return $this->directory ?? storage_path('app/private/backups');
    }

    public function webEnvPath(): string
    {
        if ($this->webEnv !== null) {
            return $this->webEnv;
        }
        $configured = config('edition.web_env_path');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $sibling = dirname(base_path()).'/budget_pro_web/.env';

        return is_file($sibling) ? $sibling : rtrim((string) config('edition.release_sources.web'), '/').'/.env';
    }

    public function mediaRoot(): string
    {
        return $this->mediaRoot ?? (string) (config('filesystems.disks.admin.root') ?: public_path('storage'));
    }

    /** @return array{path:string,size:int} */
    public function create(): array
    {
        $stamp = gmdate('Ymd-His');
        File::ensureDirectoryExists($this->directory(), 0700);
        $work = $this->directory().'/work-'.$stamp.'-'.bin2hex(random_bytes(3));
        File::ensureDirectoryExists($work, 0700);
        $target = $this->directory().'/'.self::PREFIX.$stamp.'.zip';

        try {
            $dump = $this->database->dump($work.'/database.sql.gz');

            $zip = new ZipArchive;
            if ($zip->open($target.'.tmp', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('The backup file could not be created.');
            }
            $zip->addFile($dump, 'database.sql.gz');
            $settings = [
                'kernel.env' => $this->kernelEnv ?? base_path('.env'),
                'web.env' => $this->webEnvPath(),
                'licence.json' => (string) config('edition.licence_path'),
                'installed.json' => (string) config('edition.installed_path'),
            ];
            foreach ($settings as $name => $file) {
                if ($file !== '' && is_file($file)) {
                    $zip->addFile($file, 'settings/'.$name);
                }
            }
            $zip->addFromString('RESTORE.txt', $this->instructions());
            $this->addMedia($zip);

            if (! $zip->close()) {
                throw new \RuntimeException('The backup file could not be written.');
            }
            rename($target.'.tmp', $target);
            chmod($target, 0600);
        } finally {
            File::deleteDirectory($work);
            @unlink($target.'.tmp');
        }

        $this->prune();

        return ['path' => $target, 'size' => (int) filesize($target)];
    }

    /** @return list<array{name:string,size:int,created_at:int}> newest first */
    public function list(): array
    {
        $files = array_filter(
            glob($this->directory().'/'.self::PREFIX.'*.zip') ?: [],
            fn (string $file) => preg_match(self::NAME, basename($file)) === 1,
        );
        $out = array_map(fn (string $f) => ['name' => basename($f), 'size' => (int) filesize($f), 'created_at' => (int) filemtime($f)], $files);
        // The name carries the UTC time it was made, so it orders reliably even after a copy.
        usort($out, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return array_values($out);
    }

    /** A backup's path by its name, or null — never a path somebody typed. */
    public function path(string $name): ?string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            return null;
        }
        $path = $this->directory().'/'.$name;

        return is_file($path) ? $path : null;
    }

    /** Seconds since the newest backup was made, or null when there is none. */
    public function newestAge(): ?int
    {
        $newest = $this->list()[0] ?? null;

        return $newest === null ? null : max(0, time() - $newest['created_at']);
    }

    private function addMedia(ZipArchive $zip): void
    {
        $root = $this->mediaRoot();
        if ($root === '' || ! is_dir($root)) {
            return;
        }
        $backups = realpath($this->directory()) ?: $this->directory();
        foreach (File::allFiles($root, true) as $file) {
            $real = $file->getRealPath() ?: $file->getPathname();
            if (str_starts_with($real, $backups.DIRECTORY_SEPARATOR)) {
                continue; // never a backup inside a backup
            }
            $zip->addFile($file->getPathname(), 'files/'.str_replace('\\', '/', $file->getRelativePathname()));
        }
    }

    private function prune(): void
    {
        foreach (array_slice($this->list(), self::KEEP) as $old) {
            @unlink($this->directory().'/'.$old['name']);
        }
    }

    private function instructions(): string
    {
        return <<<'TXT'
Budget Pro backup

  database.sql.gz         the whole database (tables, rows and triggers). Restore it into an
                          EMPTY database with phpMyAdmin (Import), or:
                            gunzip < database.sql.gz | mysql -u USER -p DATABASE
  settings/kernel.env     budget_pro/.env
  settings/web.env        budget_pro_web/.env
                          KEEP APP_KEY. EFRIS credentials and other encrypted settings cannot
                          be read without it. Both files must carry the same APP_KEY.
  settings/licence.json, settings/installed.json
                          copy into budget_pro/storage/app/private/
  files/                  product photos, logos, receipt and chat pictures; copy into the
                          web folder's storage/ (beside index.php)

Keep this file somewhere other than the server it came from.
TXT;
    }
}
