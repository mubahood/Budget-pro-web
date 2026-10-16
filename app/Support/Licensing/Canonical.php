<?php

namespace App\Support\Licensing;

/**
 * The one byte-exact encoding a licence is signed over (docs/STANDALONE_EDITION_PLAN.md §7.2).
 *
 * Object keys are sorted at every depth, lists keep their order, slashes and Unicode are left
 * unescaped. The SaaS signs this string and an installed copy verifies the same string, so the
 * order a licence file happens to be written in never matters.
 */
final class Canonical
{
    public static function json(array $value): string
    {
        return json_encode(self::sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function sort(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::sort($item);
            }
        }

        return $value;
    }
}
