<?php

namespace App\Services\Rentals\Directory;

use App\Exceptions\BusinessRuleException;
use App\Support\DataCrypt;
use App\Support\Phone;
use Illuminate\Support\Facades\Validator;

/**
 * Shared by the Estate Ledger directory services (landlords, properties, units, tenants): validation that refuses with the
 * first plain message, phone numbers in E.164, ID numbers encrypted at rest and shown masked.
 */
abstract class Directory
{
    /** @return array<string, mixed> the validated fields, limited to $writable */
    protected static function validated(array $data, array $writable, array $rules): array
    {
        $data = array_intersect_key($data, array_flip($writable));
        $v = Validator::make($data, $rules);
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }

        return $v->validated();
    }

    protected static function phone(?string $raw, string $country = 'UG'): ?string
    {
        $raw = trim((string) $raw);

        return $raw === '' ? null : Phone::e164($raw, $country);
    }

    protected static function encrypt(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : DataCrypt::encrypt($value);
    }

    /** "CM90…1234": enough to recognise, never the whole number. */
    public static function masked(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }
        try {
            $v = DataCrypt::decrypt($encrypted);
        } catch (\Throwable) {
            return null;
        }
        $n = mb_strlen($v);

        return $n <= 4 ? str_repeat('•', $n) : mb_substr($v, 0, 2).str_repeat('•', max(2, $n - 6)).mb_substr($v, -4);
    }

    public static function reveal(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }
        try {
            return DataCrypt::decrypt($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    protected static function country(int $companyId): string
    {
        return (string) (\App\Models\Company::withoutGlobalScopes()->find($companyId)?->country ?: 'UG');
    }
}
