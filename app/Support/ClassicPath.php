<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A request path as the classic screens name it: without their prefix (config admin.route.prefix,
 * ADMIN_ROUTE_PREFIX). Empty on the SaaS; "classic" on a standalone copy, where both apps share one
 * address (docs/STANDALONE_EDITION_PLAN.md §8.2) and /classic/stock-items is the classic
 * "stock-items". Path-based checks (permissions, platform-only areas, redirects to the new screens)
 * match on this, so they keep working whatever the prefix.
 */
final class ClassicPath
{
    public static function of(string $path): string
    {
        $path = trim($path, '/');
        $prefix = trim((string) config('admin.route.prefix', ''), '/');
        if ($prefix === '') {
            return $path;
        }
        if ($path === $prefix) {
            return '';
        }

        return str_starts_with($path, $prefix.'/') ? substr($path, strlen($prefix) + 1) : $path;
    }

    /** Request::is(), on the path without the prefix. */
    public static function is(Request $request, string ...$patterns): bool
    {
        $path = self::of(rawurldecode($request->path()));
        foreach ($patterns as $pattern) {
            if (Str::is(trim($pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }
}
