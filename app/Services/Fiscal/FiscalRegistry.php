<?php

namespace App\Services\Fiscal;

use App\Services\Fiscal\Efris\EfrisAdapter;

/** The fiscal adapters Budget Pro knows (supermarket plan F2). Add a country = add a class here. */
class FiscalRegistry
{
    /** @var array<string, class-string<FiscalAdapter>> */
    public const ADAPTERS = [
        'manual' => ManualAdapter::class,
        'efris' => EfrisAdapter::class,
    ];

    /** @var array<string, FiscalAdapter> */
    private static array $bound = [];

    public static function get(?string $key): ?FiscalAdapter
    {
        if ($key === null || ! isset(self::ADAPTERS[$key])) {
            return null;
        }

        return self::$bound[$key] ?? app(self::ADAPTERS[$key]);
    }

    /** @return array<string, FiscalAdapter> */
    public static function all(): array
    {
        return collect(self::ADAPTERS)->mapWithKeys(fn ($class, $key) => [$key => self::get($key)])->all();
    }

    /** Tests: use this instance for a key. */
    public static function bind(string $key, ?FiscalAdapter $adapter): void
    {
        if ($adapter === null) {
            unset(self::$bound[$key]);
        } else {
            self::$bound[$key] = $adapter;
        }
    }
}
