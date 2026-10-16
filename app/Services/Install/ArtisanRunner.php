<?php

namespace App\Services\Install;

use Symfony\Component\Process\Process;

/**
 * How the updater runs artisan in the two apps of a standalone copy (docs/STANDALONE_EDITION_PLAN.md
 * §12.2): always in a FRESH process, in the app's own folder, with a clean environment
 * (InstallCaches::cleanEnvironment), so each app reads its own .env and the code now on disk, never
 * the code this process loaded before the files were swapped.
 *
 * A separate class so a test can stand in for it and never take a real application down, touch its
 * database or write its caches.
 */
class ArtisanRunner
{
    /** Whether this host lets PHP start a process at all; an update cannot run without it. */
    public function available(): bool
    {
        return InstallCaches::cliPhp() !== null;
    }

    /** Maintenance mode with the self-contained page; --refresh makes the page reload itself (a Refresh header). */
    public function down(string $appRoot): void
    {
        $this->fresh($appRoot, ['down', '--render=errors::503', '--retry=30', '--refresh=30']);
    }

    /** Out of maintenance mode. If even artisan cannot run, the two files that hold the site down are removed. */
    public function up(string $appRoot): void
    {
        try {
            $this->fresh($appRoot, ['up']);
        } catch (\Throwable $e) {
            @unlink($appRoot.'/storage/framework/maintenance.php');
            @unlink($appRoot.'/storage/framework/down');
            if (is_file($appRoot.'/storage/framework/down')) {
                throw $e;
            }
        }
    }

    /** Compile configuration, routes and views, as the installer does (InstallCaches::COMMANDS). */
    public function cache(string $appRoot, string $side): void
    {
        foreach (InstallCaches::COMMANDS[$side] as $arguments) {
            $this->fresh($appRoot, $arguments);
        }
    }

    /** @param list<string> $arguments */
    public function fresh(string $appRoot, array $arguments): void
    {
        $php = InstallCaches::cliPhp() ?? throw new \RuntimeException('This server does not let PHP start a process, so artisan cannot run.');
        $process = new Process([$php, $appRoot.'/artisan', ...$arguments, '--no-ansi', '--no-interaction'], $appRoot, InstallCaches::cleanEnvironment());
        $process->setTimeout(1800);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('`'.$arguments[0].'` failed in '.basename($appRoot).': '
                .mb_substr(trim($process->getErrorOutput()."\n".$process->getOutput()), 0, 1500));
        }
    }
}
