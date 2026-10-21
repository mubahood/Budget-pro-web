<?php

namespace App\Support\Rules;

use App\Models\SaccoMember;
use Illuminate\Validation\Rule;

/**
 * What a member form or request may contain (the CustomerRules pattern): one rule set for the API, the web portal
 * and the CSV import. The business rules (the plan's member limit, KYC by a second officer, status changes,
 * nominations) are App\Services\Sacco\Members\MemberService's.
 */
final class SaccoMemberRules
{
    /** The fields a register or update writes (the NIN goes through its own handling, never stored as typed). */
    public const WRITABLE = ['kind', 'first_name', 'last_name', 'other_names', 'gender', 'date_of_birth', 'nin', 'phone', 'phone_alt', 'email', 'address',
        'district', 'subcounty', 'parish', 'village', 'occupation', 'employer_name', 'payroll_no', 'joined_on', 'consent_data', 'consent_messages',
        'consent_credit_bureau', 'guardian_name', 'guardian_phone'];

    /** A Ugandan NIN: 14 letters and digits (CM/CF + 12); other countries' IDs: 6 to 20. */
    public const NIN_PATTERN = '/^[A-Z0-9]{6,20}$/';

    /** @return array<string, array<int, mixed>> */
    public static function rules(bool $creating = true): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'kind' => ['sometimes', Rule::in(SaccoMember::KINDS)],
            'first_name' => [$req, 'string', 'max:80'],
            'last_name' => [$creating ? 'required_unless:kind,group,institution' : 'sometimes', 'nullable', 'string', 'max:80'],
            'other_names' => ['sometimes', 'nullable', 'string', 'max:80'],
            'gender' => ['sometimes', 'nullable', Rule::in(SaccoMember::GENDERS)],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today', 'after:1900-01-01'],
            'nin' => ['sometimes', 'nullable', 'string', 'max:40'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'phone_alt' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'district' => ['sometimes', 'nullable', 'string', 'max:80'],
            'subcounty' => ['sometimes', 'nullable', 'string', 'max:80'],
            'parish' => ['sometimes', 'nullable', 'string', 'max:80'],
            'village' => ['sometimes', 'nullable', 'string', 'max:80'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:120'],
            'employer_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'payroll_no' => ['sometimes', 'nullable', 'string', 'max:40'],
            'joined_on' => ['sometimes', 'nullable', 'date'],
            // DPPA s.7/s.9 (AK-47): registering needs the member's consent to keep their data, hosting clause included.
            'consent_data' => [$creating ? 'accepted' : 'sometimes', 'boolean'],
            'consent_messages' => ['sometimes', 'boolean'],
            'consent_credit_bureau' => ['sometimes', 'boolean'],
            'guardian_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'guardian_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }

    /** @return array<string, array<int, mixed>> one nominee of a nomination */
    public static function nominee(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'relationship' => ['nullable', 'string', 'max:60'], 'phone' => ['nullable', 'string', 'max:30'],
            'nin' => ['nullable', 'string', 'max:40'], 'share_bp' => ['required', 'integer', 'min:1', 'max:10000', SaccoRules::noFloat()]];
    }
}
