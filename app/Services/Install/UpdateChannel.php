<?php

namespace App\Services\Install;

use App\Support\Edition;
use App\Support\Licensing\InstalledLicence;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Asks the update server (config edition.update_server, the SaaS) whether a newer release is available
 * to this licence, and fetches it (docs/STANDALONE_EDITION_PLAN.md §12.1). After True-Doctor's.
 *
 *   GET {server}/standalone/updates?licence=<id>&version=<v>
 *   → {"latest": null | {version, notes, published_at, size, sha256, download_url}, "updates_until": "Y-m-d"}
 *
 * Optional by design: a server with no internet uploads the package instead, and nothing about
 * selling depends on this ever succeeding. Only the licence id and the version are sent: no sales,
 * no customers, no usage figures. The last answer is kept for two days in a file beside the system
 * state (the two apps keep separate caches), so the daily check and the page agree.
 */
final class UpdateChannel
{
    public const KEEP_DAYS = 2;

    public function enabled(): bool
    {
        return filled(config('edition.update_server')) && $this->licence()->isValid();
    }

    /**
     * The newest release this licence may install, or null when this copy already runs it.
     *
     * @return array{version:string,notes:?string,published_at:?string,size:?int,sha256:string,download_url:string}|null
     */
    public function check(): ?array
    {
        if (! $this->enabled()) {
            throw new \DomainException('Checking online needs a valid licence and an update server. You can upload an update file instead.');
        }

        try {
            $response = Http::timeout(15)->acceptJson()->get($this->server().'/standalone/updates', [
                'licence' => $this->licence()->id(),
                'version' => Edition::version(),
            ]);
        } catch (ConnectionException) {
            throw new \DomainException('The update server could not be reached. Check this server\'s internet, or upload an update file instead.');
        }
        if (! $response->successful()) {
            throw new \DomainException('The update server answered '.$response->status().'. Try again later, or upload an update file instead.');
        }

        $latest = $response->json('latest');
        $valid = is_array($latest) && is_string($latest['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+(?:-[a-z0-9.-]+)?$/', $latest['version'])
            && is_string($latest['download_url'] ?? null) && is_string($latest['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/i', $latest['sha256']);
        $until = $response->json('updates_until');
        $this->remember(['checked_at' => now()->toIso8601String(), 'latest' => $valid ? $latest : null,
            'updates_until' => is_string($until) ? $until : null]);

        return $valid && self::newer($latest['version']) ? $latest : null;
    }

    /** The last answer, younger than two days, without asking again. @return array{checked_at:string,latest:?array,updates_until:?string}|null */
    public function lastCheck(): ?array
    {
        $file = $this->checkPath();
        $check = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (! is_array($check) || ! is_string($check['checked_at'] ?? null)) {
            return null;
        }

        return strtotime($check['checked_at']) > time() - self::KEEP_DAYS * 86400 ? $check : null;
    }

    /** Download a release into private storage (updates/incoming-*.zip), checked against its published sha256. */
    public function download(string $url, string $sha256): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $local = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        if ($host === '' || $host !== strtolower((string) parse_url($this->server(), PHP_URL_HOST)) || ! ($scheme === 'https' || ($scheme === 'http' && $local))) {
            throw new \DomainException('The download address is not the update server.');
        }
        if (! preg_match('/^[a-f0-9]{64}$/i', $sha256)) {
            throw new \DomainException('The update server sent no valid checksum for the download.');
        }

        File::ensureDirectoryExists(SystemState::updatesDirectory(), 0700);
        $target = SystemState::updatesDirectory().'/incoming-'.bin2hex(random_bytes(6)).'.zip';
        try {
            $response = Http::timeout(900)->withOptions(['sink' => $target, 'allow_redirects' => ['max' => 3, 'protocols' => $local ? ['http', 'https'] : ['https']]])->get($url);
        } catch (ConnectionException) {
            @unlink($target);
            throw new \DomainException('The update could not be downloaded: the connection was lost. Try again, or upload the file instead.');
        }
        if (! $response->successful() || ! is_file($target)) {
            @unlink($target);
            throw new \DomainException('The update could not be downloaded ('.$response->status().').');
        }
        if (! hash_equals(strtolower($sha256), (string) hash_file('sha256', $target))) {
            @unlink($target);
            throw new \DomainException('The downloaded file does not match its published checksum. Try again.');
        }

        return $target;
    }

    /** Whether $version is newer than the release installed here ("dev", a checkout, takes anything). */
    public static function newer(string $version): bool
    {
        return Edition::version() === 'dev' || version_compare($version, Edition::version(), '>');
    }

    private function licence(): InstalledLicence
    {
        return InstalledLicence::current();
    }

    private function server(): string
    {
        return rtrim((string) config('edition.update_server'), '/');
    }

    private function checkPath(): string
    {
        return (new SystemState)->directory().'/update-check.json';
    }

    /** @param array<string,mixed> $check */
    private function remember(array $check): void
    {
        $file = $this->checkPath();
        File::ensureDirectoryExists(dirname($file), 0700);
        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        File::put($temporary, json_encode($check, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($temporary, $file);
    }
}
