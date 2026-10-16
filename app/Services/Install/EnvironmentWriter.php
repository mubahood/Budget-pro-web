<?php

namespace App\Services\Install;

use Dotenv\Dotenv;

/**
 * Writes an installation's first `.env` (docs/STANDALONE_EDITION_PLAN.md §9), for the kernel and
 * the web app alike.
 *
 * Runs inside the pre-Laravel installer, so it uses nothing from the framework. Every value is
 * double-quoted and escaped, then the whole file is parsed back with Dotenv and must give every
 * value back exactly (a database password with $, quotes or # in it is the usual victim). The file
 * is written to a temporary name, set to 0600 and renamed into place, and nothing is written once
 * the copy is installed.
 */
final class EnvironmentWriter
{
    /** @param string|null $installedMarker the kernel's installed.json; refuses once it exists */
    public function __construct(private readonly ?string $installedMarker = null) {}

    /** @param array<string,string> $settings */
    public function write(string $path, array $settings): void
    {
        foreach (array_filter([$this->installedMarker, dirname($path).'/storage/app/private/installed.json']) as $marker) {
            if (is_file($marker)) {
                throw new \DomainException('This installation is already complete.');
            }
        }

        $lines = [];
        foreach ($settings as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || ! is_string($value) || str_contains($value, "\0")) {
                throw new \InvalidArgumentException('An environment setting has an invalid name or value.');
            }
            $lines[] = $key.'='.$this->quote($value);
        }

        $contents = implode("\n", $lines)."\n";
        $parsed = Dotenv::parse($contents);
        foreach ($settings as $key => $value) {
            if (($parsed[$key] ?? null) !== $value) {
                throw new \InvalidArgumentException("The {$key} value cannot be safely written to the environment file.");
            }
        }

        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('The application directory is not writable.');
        }
        chmod($temporary, 0600);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('The environment file could not be saved.');
        }
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(
            ['\\', '"', '$', "\n", "\r"],
            ['\\\\', '\\"', '\\$', '\\n', '\\r'],
            $value,
        ).'"';
    }
}
