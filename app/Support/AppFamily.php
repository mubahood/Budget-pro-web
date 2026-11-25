<?php

namespace App\Support;

/**
 * The eight apps as the server sees them: which module each one opens, and whether its books stand alone.
 *
 * Family apps share one set of books: joining one adds its module to the books a person already has. Own-books apps (Akiba Ledger,
 * Estate Ledger, Family Roots, UnityDesk) never mix with anything: joining one opens a second account beside the person's (SiblingAccounts), on the same sign-in.
 * The web portal's config/apps.php carries the pages and words; this is only what the sign-in needs (docs/accounts in budget-pro-new).
 */
final class AppFamily
{
    /** slug => [name, module it opens, its books stand alone] */
    public const APPS = [
        'shelfie' => ['Shelfie', 'shop', false],
        'kuku-tally' => ['Kuku Tally', 'poultry', false],
        'pledge-and-budget' => ['Pledge & Budget', 'budget', false],
        'budget-pro' => ['Budget Pro', 'shop', false],
        'akiba-ledger' => ['Akiba Ledger', 'sacco', true],
        'estate-ledger' => ['Estate Ledger', 'rentals', true],
        'family-roots' => ['Family Roots', 'kin', true],
        // UnityDesk – Membership Manager (the unity module). The published phone package that was Budget Pro becomes UnityDesk;
        // 'budget-pro' above stays for the web.
        'unitydesk' => ['UnityDesk', 'unity', true],
    ];

    /** Older names the phones still send. */
    private const ALIASES = ['keyroll' => 'estate-ledger', 'akiba' => 'akiba-ledger', 'budgetpro' => 'budget-pro', 'pledgebudget' => 'pledge-and-budget', 'kukutally' => 'kuku-tally', 'kin' => 'family-roots', 'familyroots' => 'family-roots', 'unity' => 'unitydesk'];

    /** Modules whose books stand alone. */
    public const OWN_BOOKS_MODULES = ['sacco', 'rentals', 'kin', 'unity'];

    public static function slug(?string $app): ?string
    {
        $app = strtolower(trim((string) $app));
        $app = self::ALIASES[$app] ?? $app;

        return isset(self::APPS[$app]) ? $app : null;
    }

    public static function name(string $slug): string
    {
        return self::APPS[$slug][0];
    }

    public static function module(string $slug): string
    {
        return self::APPS[$slug][1];
    }

    public static function ownBooks(string $slug): bool
    {
        return self::APPS[$slug][2];
    }

    /** @param  array<int, string>  $modules  a company's modules */
    public static function booksStandAlone(array $modules): bool
    {
        return array_intersect($modules, self::OWN_BOOKS_MODULES) !== [];
    }

    /** Whether books with these modules already open [slug]. Budget Pro opens any family books. */
    public static function opens(array $modules, string $slug): bool
    {
        // A family's books (even with a purse, ['kin', 'sacco']) open in Family Roots only, never as a SACCO (doc 11 T1).
        if (in_array('kin', $modules, true)) {
            return $slug === 'family-roots';
        }
        // An association's books open in UnityDesk only, and UnityDesk opens nothing else.
        if (in_array('unity', $modules, true)) {
            return $slug === 'unitydesk';
        }
        if (self::ownBooks($slug)) {
            return in_array(self::module($slug), $modules, true);
        }
        if (self::booksStandAlone($modules)) {
            return false;
        }

        return $slug === 'budget-pro' || in_array(self::module($slug), $modules, true);
    }
}
