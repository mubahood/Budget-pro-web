<?php

namespace App\Services\Releases;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Lists what a standalone ZIP will hold: package path => source file, from the kernel stage, the
 * web stage and the web folder (PackageLayout). Anything selected that looks like a secret stops
 * the build, as does a symbolic link; development-only files and noise (.DS_Store, .gitignore)
 * are left out quietly.
 */
final class PackageCollector
{
    /** @var array<string,string> */
    private array $files = [];

    /** @return array<string,string> package path => absolute source, sorted */
    public function collect(string $kernelStage, string $webStage, ?string $webRootStage): array
    {
        $this->files = [];
        foreach ([PackageLayout::KERNEL => $kernelStage, PackageLayout::WEB => $webStage] as $app => $stage) {
            foreach (PackageLayout::APP_DIRECTORIES[$app] as $directory) {
                $this->directory($stage.'/'.$directory, $app.'/'.$directory, true);
            }
            foreach (PackageLayout::APP_FILES[$app] as $name) {
                $this->file($stage.'/'.$name, $app.'/'.$name);
            }
        }

        if ($webRootStage !== null) {
            $this->directory($webRootStage, PackageLayout::WEB_ROOT, false);
        } else {
            foreach (PackageLayout::WEB_ROOT_SOURCES as $target => [$side, $source]) {
                $from = ($side === 'kernel' ? $kernelStage : $webStage).'/'.$source;
                $to = PackageLayout::WEB_ROOT.'/'.$target;
                if (is_dir($from)) {
                    $this->directory($from, $to, false);
                } elseif (is_file($from) || in_array($target, ['index.php', '.htaccess', 'install.php'], true)) {
                    $this->file($from, $to);
                }
            }
        }
        foreach (['index.php', '.htaccess', 'install.php', 'build/manifest.json'] as $needed) {
            if (! isset($this->files[PackageLayout::WEB_ROOT.'/'.$needed])) {
                throw new \RuntimeException("The web folder needs {$needed}.");
            }
        }

        $this->file($kernelStage.'/resources/standalone/INSTALL.md', 'INSTALL.md');
        ksort($this->files, SORT_STRING);

        return $this->files;
    }

    private function directory(string $source, string $destination, bool $insideApp): void
    {
        if (! is_dir($source)) {
            if ($insideApp && ! in_array(basename($source), ['lang', 'compat', 'database'], true)) { // the web app owns no migrations
                throw new \RuntimeException("A required folder is missing from the stage: {$destination}.");
            }

            return;
        }
        if (is_link($source)) {
            throw new \RuntimeException("{$destination} is a symbolic link; a release holds real files only.");
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen($source) + 1);
            $path = $destination.'/'.$relative;
            if (PackageLayout::isNoise($path)) {
                continue;
            }
            if ($insideApp) {
                [$app] = explode('/', $destination, 2);
                if (PackageLayout::isLeftOut(substr($path, strlen($app) + 1))) {
                    continue;
                }
            }
            $this->file($entry->getPathname(), $path);
        }
    }

    private function file(string $source, string $path): void
    {
        if (($reason = PackageLayout::secretReason($path)) !== null) {
            throw new \RuntimeException("Refusing to package {$path}: it looks like {$reason}. Secrets never ship (S1).");
        }
        if (is_link($source)) {
            throw new \RuntimeException("{$path} is a symbolic link; a release holds real files only.");
        }
        if (! is_file($source)) {
            throw new \RuntimeException("A required file is missing from the stage: {$path}.");
        }
        if (! PackageLayout::allowed($path)) {
            throw new \RuntimeException("{$path} is not on the release allow-list.");
        }
        if (isset($this->files[$path])) {
            throw new \RuntimeException("{$path} would be packed twice.");
        }
        $this->files[$path] = $source;
    }
}
