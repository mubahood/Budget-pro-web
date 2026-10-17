<?php

namespace App\Support;

/**
 * What a phone (or any client) is told about this server before anyone signs in, on the public
 * GET /api/v1/app/version (docs/STANDALONE_EDITION_PLAN.md §5.6): which edition it is (a standalone
 * copy has no sign-up and no billing, so the app hides "Create your shop", "Try demo" and the plan
 * screens) and who to ask for help (SupportContact, docs/roadmap/06 §4), so the sign-in screen can
 * offer "Trouble signing in? WhatsApp us" on a phone that has never signed in.
 */
final class EditionMeta
{
    /** @return array{edition: string, registration_open: bool, name: string, version: string, support: array<string, mixed>} */
    public static function forApp(): array
    {
        return self::edition() + ['support' => SupportContact::current()->toArray()];
    }

    /**
     * The edition alone (GET /api/v1/meta carries `support` in its own block).
     *
     * @return array{edition: string, registration_open: bool, name: string, version: string}
     */
    public static function edition(): array
    {
        return [
            'edition' => Edition::name(),
            'registration_open' => Edition::isSaas(),
            'name' => (string) config('app.name'),
            'version' => Edition::version(),
        ];
    }
}
