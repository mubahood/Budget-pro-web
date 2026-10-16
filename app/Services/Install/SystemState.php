<?php

namespace App\Services\Install;

use Illuminate\Support\Facades\File;

/**
 * What a standalone copy's housekeeping is doing, and what it was asked to do (Settings › Licence &
 * updates, docs/STANDALONE_EDITION_PLAN.md §12.2). After True-Doctor's.
 *
 * The web page never runs an update or a backup itself: it leaves ONE request, and the kernel's
 * scheduler (a command-line process with no time limit) claims it within a minute
 * (`standalone:system-requests`). Both apps read and write two small JSON files, so the page can
 * follow the progress even while both apps are in maintenance mode for the update itself.
 *
 * The files live beside the install marker (config edition.installed_path), which the kernel and
 * the web app both resolve to the kernel's storage/app/private: system/request.json, system/state.json.
 */
final class SystemState
{
    /** A "running" state older than this belongs to a process that died; a new request may start. */
    public const STALE_HOURS = 3;

    /** @param string|null $directory default: system/ beside the install marker */
    public function __construct(private readonly ?string $directory = null) {}

    /** The kernel's storage/app/private, as both apps resolve it (from the install marker's folder). */
    public static function privateDirectory(): string
    {
        return dirname((string) config('edition.installed_path'));
    }

    /** Where update packages wait: uploads, downloads and files put there with cPanel File Manager. */
    public static function updatesDirectory(): string
    {
        return self::privateDirectory().'/updates';
    }

    /** Where the nightly backups are (InstallationBackups) and each update keeps its own. */
    public static function backupsDirectory(): string
    {
        return self::privateDirectory().'/backups';
    }

    public function directory(): string
    {
        return $this->directory ?? self::privateDirectory().'/system';
    }

    /**
     * Leave a request for the scheduler. One at a time: refused while another waits or runs.
     *
     * @param  array<string,mixed>  $request  action (update|backup), source, archive, version, by
     */
    public function request(array $request): void
    {
        File::ensureDirectoryExists($this->directory(), 0700);
        $lock = fopen($this->directory().'/request.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new \DomainException('The request could not be recorded. Try again.');
        }
        try {
            if ($this->busy()) {
                throw new \DomainException('Something is already waiting or running. Wait for it to finish.');
            }
            $this->write($this->requestPath(), $request + ['requested_at' => now()->toIso8601String()]);
            $this->write($this->statePath(), [
                'action' => (string) ($request['action'] ?? ''), 'status' => 'queued',
                'message' => 'Waiting for the scheduler (up to a minute)…', 'log' => [],
                'by' => $request['by'] ?? null, 'updated_at' => now()->toIso8601String(),
            ]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Whether a request waits, or something runs (and is not stale). */
    public function busy(): bool
    {
        if ($this->pending() !== null) {
            return true;
        }
        $state = $this->current();
        if (($state['status'] ?? null) !== 'running') {
            return false;
        }
        $at = strtotime((string) ($state['updated_at'] ?? '')) ?: 0;

        return $at > time() - self::STALE_HOURS * 3600;
    }

    /** @return array<string,mixed>|null */
    public function pending(): ?array
    {
        return $this->read($this->requestPath());
    }

    /** Take the waiting request, so no other scheduler runs it too. @return array<string,mixed>|null */
    public function claim(): ?array
    {
        // rename() is atomic: of two schedulers that overlap, exactly one gets it.
        $claimed = $this->requestPath().'.'.getmypid().'.'.bin2hex(random_bytes(3)).'.claimed';
        if (! is_file($this->requestPath()) || ! @rename($this->requestPath(), $claimed)) {
            return null;
        }
        $request = $this->read($claimed);
        @unlink($claimed);

        return $request;
    }

    /** @return array<string,mixed> */
    public function current(): array
    {
        return $this->read($this->statePath()) ?? ['status' => 'idle'];
    }

    public function running(string $action, string $message, ?int $by = null): void
    {
        $this->write($this->statePath(), [
            'action' => $action, 'status' => 'running', 'message' => $message, 'by' => $by,
            'log' => [], 'started_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
        ]);
    }

    /** @param list<string> $log */
    public function progress(string $message, array $log): void
    {
        $state = $this->current();
        if (($state['status'] ?? null) !== 'running') {
            $state = ['status' => 'running', 'started_at' => now()->toIso8601String()] + $state;
        }
        $this->write($this->statePath(), array_merge($state, ['message' => $message, 'log' => $log, 'updated_at' => now()->toIso8601String()]));
    }

    /** @param list<string> $log */
    public function finished(string $action, string $message, array $log = []): void
    {
        $this->end($action, 'succeeded', $message, $log);
    }

    /** @param list<string> $log */
    public function failed(string $action, string $message, array $log = []): void
    {
        $this->end($action, 'failed', $message, $log);
    }

    /** @param list<string> $log */
    private function end(string $action, string $status, string $message, array $log): void
    {
        $this->write($this->statePath(), array_merge($this->current(), [
            'action' => $action, 'status' => $status, 'message' => $message, 'log' => $log ?: ($this->current()['log'] ?? []),
            'finished_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
        ]));
    }

    private function requestPath(): string
    {
        return $this->directory().'/request.json';
    }

    private function statePath(): string
    {
        return $this->directory().'/state.json';
    }

    /** @return array<string,mixed>|null */
    private function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $data */
    private function write(string $path, array $data): void
    {
        File::ensureDirectoryExists(dirname($path), 0700);
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        File::put($temporary, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @chmod($temporary, 0600);
        rename($temporary, $path);
    }
}
