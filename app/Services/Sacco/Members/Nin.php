<?php

namespace App\Services\Sacco\Members;

use App\Exceptions\BusinessRuleException;
use App\Support\DataCrypt;
use App\Support\Rules\SaccoMemberRules;

/**
 * A national ID number at rest (AK-31): stored encrypted (DataCrypt, readable by the kernel and the portal), looked up
 * by an HMAC-SHA256 keyed with config('sacco.nin_pepper') (the app key stands in where no pepper is set, never in
 * production: config:doctor), and shown only as its last four characters.
 */
final class Nin
{
    /** "cm 9001 2345 6789 0x" → "CM9001234567890X", or `invalid_nin`. */
    public static function normalise(string $raw): string
    {
        $nin = strtoupper((string) preg_replace('/[\s\-.]+/', '', $raw));
        if (preg_match(SaccoMemberRules::NIN_PATTERN, $nin) !== 1) {
            throw BusinessRuleException::make('invalid_nin', 'That is not an ID number: use the letters and digits on the card.');
        }

        return $nin;
    }

    public static function hash(string $nin): string
    {
        return hash_hmac('sha256', self::normalise($nin), self::pepper());
    }

    /** @return array{nin_encrypted: string, nin_hash: string, nin_last4: string} */
    public static function columns(string $raw): array
    {
        $nin = self::normalise($raw);

        return ['nin_encrypted' => DataCrypt::encrypt($nin), 'nin_hash' => hash_hmac('sha256', $nin, self::pepper()), 'nin_last4' => substr($nin, -4)];
    }

    public static function reveal(?string $encrypted): ?string
    {
        return $encrypted === null ? null : DataCrypt::decrypt($encrypted);
    }

    private static function pepper(): string
    {
        return (string) (config('sacco.nin_pepper') ?: config('app.key'));
    }
}
