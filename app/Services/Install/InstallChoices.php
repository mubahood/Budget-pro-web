<?php

namespace App\Services\Install;

/**
 * The installer's two pick-lists, before Laravel exists (docs/STANDALONE_EDITION_PLAN.md §9.2 ③):
 * the countries (each sets the currency, time zone and VAT) and the 22 kinds of business, read
 * straight from config/onboarding.php — the same lists the setup wizard and sign-up use. That file
 * is plain arrays, so requiring it needs nothing from the framework (a test runs this class with
 * Composer's autoloader alone).
 */
final class InstallChoices
{
    /** @return array<string, array{name:string,currency:string,timezone:string,vat:int|float|null}> code => preset */
    public static function countries(): array
    {
        $countries = [];
        foreach ((array) (self::onboarding()['countries'] ?? []) as $code => $preset) {
            $countries[(string) $code] = [
                'name' => (string) $preset['name'], 'currency' => (string) $preset['currency'],
                'timezone' => (string) $preset['timezone'], 'vat' => $preset['vat'] ?? null,
            ];
        }

        return $countries;
    }

    /** @return array<string, array{label:string,group:string}> key => what the picker shows */
    public static function businessTypes(): array
    {
        $types = [];
        foreach ((array) (self::onboarding()['business_types'] ?? []) as $key => $type) {
            $types[(string) $key] = ['label' => (string) $type['label'], 'group' => (string) ($type['group'] ?? 'Other')];
        }

        return $types;
    }

    /** @return array{name:string,currency:string,timezone:string,vat:int|float|null}|null */
    public static function country(string $code): ?array
    {
        return self::countries()[strtoupper($code)] ?? null;
    }

    private static function onboarding(): array
    {
        static $onboarding = null;

        return $onboarding ??= (array) require dirname(__DIR__, 3).'/config/onboarding.php';
    }
}
