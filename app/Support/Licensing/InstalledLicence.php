<?php

namespace App\Support\Licensing;

use Illuminate\Support\Carbon;

/**
 * The licence this standalone copy was installed with (docs/STANDALONE_EDITION_PLAN.md §7.2),
 * read from config edition.licence_path and verified offline.
 *
 * A missing, damaged or altered file is simply invalid: the owner sees a banner, and selling and
 * the records are never affected. Nothing here throws. `current()` is the memoised copy for the
 * request; call `forget()` after the file changes (install, upload, update).
 */
final class InstalledLicence
{
    public const PRODUCT = 'budget-pro-standalone';

    /** The only limits a licence may set. Products, sales and records are never limited. */
    public const LIMIT_KEYS = ['max_users', 'max_locations', 'max_devices'];

    private static ?self $current = null;

    /** @var array<string,mixed>|null the verified document, or null */
    private ?array $document = null;

    private string $status = 'missing';

    private bool $loaded = false;

    public function __construct(private readonly ?Verifier $verifier = null, private readonly ?string $path = null) {}

    public static function current(): self
    {
        $path = (string) config('edition.licence_path');
        if (self::$current === null || self::$current->path !== $path) {
            self::$current = new self(null, $path);
        }

        return self::$current;
    }

    public static function forget(): void
    {
        self::$current = null;
    }

    public function isValid(): bool
    {
        return $this->payload() !== null;
    }

    /** "valid", "missing" (no file) or "invalid" (damaged, altered, unknown key, another product). */
    public function status(): string
    {
        $this->load();

        return $this->status;
    }

    public function id(): ?string
    {
        return $this->string('id');
    }

    public function customer(): ?string
    {
        return $this->string('customer');
    }

    /** @return list<string> lower-case addresses this copy is licensed for */
    public function domains(): array
    {
        $domains = $this->payload()['domains'] ?? null;

        return is_array($domains)
            ? array_values(array_unique(array_map([self::class, 'normaliseHost'], array_filter($domains, 'is_string'))))
            : [];
    }

    public function updatesUntil(): ?Carbon
    {
        $date = $this->payload()['updates_until'] ?? null;
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date);

            return $parsed !== false && $parsed->format('Y-m-d') === $date ? $parsed : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Updates ended before today. The shop keeps working for ever; only newer releases refuse. */
    public function updatesEnded(?Carbon $today = null): bool
    {
        $until = $this->updatesUntil();

        return $until !== null && $until->lt(($today ?? Carbon::now())->copy()->startOfDay());
    }

    /** max_users, max_locations or max_devices; null = unlimited (or not one of those three). */
    public function limit(string $key): ?int
    {
        if (! in_array($key, self::LIMIT_KEYS, true)) {
            return null;
        }
        $limits = $this->payload()['limits'] ?? null;
        $value = is_array($limits) ? ($limits[$key] ?? null) : null;

        return is_int($value) && $value >= 0 ? $value : null;
    }

    public function matchesHost(string $host): bool
    {
        $host = self::normaliseHost($host);

        return $host !== '' && in_array($host, $this->domains(), true);
    }

    public function whiteLabel(): bool
    {
        return ($this->payload()['white_label'] ?? false) === true;
    }

    /** The whole verified document (licence, key_id, signature), or null when invalid. */
    public function document(): ?array
    {
        $this->load();

        return $this->document;
    }

    private function payload(): ?array
    {
        return $this->document()['licence'] ?? null;
    }

    private function string(string $key): ?string
    {
        $value = $this->payload()[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function normaliseHost(string $host): string
    {
        $host = rtrim(strtolower(trim($host)), '.');

        // "shop.example.com:8443" -> "shop.example.com"; a bracketed IPv6 address keeps its colons.
        return preg_match('/^([^:\[\]]+):\d+$/', $host, $m) ? $m[1] : $host;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $path = $this->path ?? config('edition.licence_path');
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return;
        }
        $this->status = 'invalid';

        try {
            $document = json_decode((string) @file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($document) || ! ($this->verifier ?? new Verifier)->verifiesDocument($document)) {
                return;
            }
            $payload = $document['licence'];
            if (($payload['format'] ?? null) !== 1 || ($payload['product'] ?? null) !== self::PRODUCT
                || ! is_string($payload['id'] ?? null) || $payload['id'] === '') {
                return;
            }
            $this->document = $document;
            $this->status = 'valid';
        } catch (\Throwable) {
            // A damaged file is an owner's banner, never an error page.
        }
    }
}
