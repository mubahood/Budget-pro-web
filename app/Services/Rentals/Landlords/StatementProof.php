<?php

namespace App\Services\Rentals\Landlords;

/**
 * The proof code of an issued statement (LC-05): SHA-256 over its canonical body. A landlord or an auditor comparing the first 12 characters on
 * their paper with the portal sees whether it is the system's paper. Canonical means keys sorted at every level, so the database's own
 * ordering of a stored JSON document changes nothing; lists keep their order.
 */
final class StatementProof
{
    /** @param array<string, mixed> $body */
    public static function of(array $body): string
    {
        return hash('sha256', self::canonical($body));
    }

    /** @param array<string, mixed> $body */
    public static function canonical(array $body): string
    {
        return (string) json_encode(self::sorted($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** "7F3A 91C2 04DE", as it prints in the footer. */
    public static function short(string $proof): string
    {
        return strtoupper(implode(' ', str_split(substr($proof, 0, 12), 4)));
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::sorted(...), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
