<?php

namespace App\Support;

/**
 * What a phone (or any client) is told about this server's edition (docs/STANDALONE_EDITION_PLAN.md
 * §5.6), on the public GET /api/v1/app/version and on auth/me. A standalone copy has no sign-up and
 * no billing, so the app hides "Create your shop", "Try demo" and the plan screens.
 */
final class EditionMeta
{
    /** @return array{edition: string, registration_open: bool, name: string, version: string} */
    public static function forApp(): array
    {
        return [
            'edition' => Edition::name(),
            'registration_open' => Edition::isSaas(),
            'name' => (string) config('app.name'),
            'version' => Edition::version(),
        ];
    }
}
