<?php

namespace App\Services\Install;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Compiles both apps' configuration, routes and views at the end of an installation, as the live
 * deploy does (docs/STANDALONE_EDITION_PLAN.md §9.2 ⑥). The two apps run different Laravel
 * versions, so each is cached by its own `php artisan` in a fresh process when the host lets PHP
 * start one; otherwise the kernel is cached in this process and the web app runs uncached (it works
 * the same, a little slower) until the first update caches it.
 *
 * Never fails an installation: a cache is a speed-up. A separate class so tests can stand in for it
 * and never write into a working copy's bootstrap/cache.
 */
class InstallCaches
{
    /** What compiles each app: the kernel (Laravel 10) piece by piece, the web app (Laravel 12) with optimize. The updater runs the same. */
    public const COMMANDS = [
        'kernel' => [['config:cache'], ['route:cache'], ['view:cache']],
        'web' => [['optimize'], ['view:cache']],
    ];

    /** @return list<string> what was done, in plain words */
    public function warm(string $kernelRoot, ?string $webRoot): array
    {
        $notes = [];
        $php = self::cliPhp();
        foreach (array_filter(['kernel' => $kernelRoot, 'web' => $webRoot]) as $side => $root) {
            $commands = self::COMMANDS[$side];
            try {
                if ($php !== null) {
                    foreach ($commands as $arguments) {
                        $this->fresh($php, $root, $arguments);
                    }
                    $notes[] = "{$side}: cached";
                } elseif ($side === 'kernel') {
                    foreach ($commands as [$command]) {
                        Artisan::call($command);
                    }
                    $notes[] = 'kernel: cached in this request';
                } else {
                    $notes[] = 'web: left uncached (this host does not let PHP start a process)';
                }
            } catch (\Throwable $e) {
                Log::warning('[standalone] caching after install failed', ['app' => $side, 'error' => $e->getMessage()]);
                $notes[] = "{$side}: not cached ({$e->getMessage()})";
            }
        }

        return $notes;
    }

    /** The command-line PHP: the one running now when this is the CLI, else the usual places beside it. */
    public static function cliPhp(): ?string
    {
        if (! function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return null;
        }
        if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
            return PHP_BINARY;
        }
        foreach ([PHP_BINDIR.'/php', '/usr/local/bin/php', '/usr/bin/php'] as $candidate) {
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The environment for a fresh artisan process: every variable this process holds unset, except the few a
     * shell needs. This process holds the KERNEL's .env values (Laravel puts them in getenv()), and inherited
     * they would win over the web app's own .env (SESSION_COOKIE, …) in its cached configuration.
     *
     * @return array<string,false>
     */
    public static function cleanEnvironment(): array
    {
        $environment = array_fill_keys(array_keys(getenv() + $_ENV), false);
        foreach (['PATH', 'HOME', 'TMPDIR', 'TEMP', 'TMP', 'LANG', 'USER'] as $keep) {
            unset($environment[$keep]);
        }

        return $environment;
    }

    /** @param list<string> $arguments */
    protected function fresh(string $php, string $root, array $arguments): void
    {
        $process = new Process([$php, $root.'/artisan', ...$arguments, '--no-ansi', '--no-interaction'], $root, self::cleanEnvironment());
        $process->setTimeout(600);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('`'.$arguments[0].'` failed: '.mb_substr(trim($process->getErrorOutput()."\n".$process->getOutput()), 0, 800));
        }
    }
}
