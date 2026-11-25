<?php

namespace App\Services\Kin;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Validation\Rule;

/**
 * A family's people through the API (My Family Roots 360). They are sacco_members rows: SaccoMember's hooks number them,
 * count them against the plan's people and keep the death rules (a date of death only with the status deceased, never
 * before the birth nor after today). Nobody is deleted: a person leaves by status. in_purse is written only while the
 * family keeps a purse (kin with sacco and kin.purse_enabled).
 */
final class KinPeople
{
    /** The fields an officer writes. */
    public const WRITABLE = ['first_name', 'last_name', 'other_names', 'gender', 'date_of_birth', 'birth_date_precision', 'status', 'date_of_death', 'place_of_death',
        'phone', 'email', 'address', 'district', 'village', 'occupation', 'education_level', 'country', 'heritage', 'privacy', 'branch_id', 'in_purse',
        'dues_duty', 'dues_personal_minor', 'dues_start_date'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId, bool $creating): array
    {
        $in = fn (string $key) => Rule::in(config('kin.'.$key));

        return [
            'first_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'other_names' => ['sometimes', 'nullable', 'string', 'max:80'],
            'gender' => ['sometimes', 'nullable', Rule::in(SaccoMember::GENDERS)],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before_or_equal:today', 'after:1700-01-01'],
            'birth_date_precision' => ['sometimes', 'nullable', $in('birth_date_precisions')],
            'status' => ['sometimes', $in('statuses')],
            'date_of_death' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'place_of_death' => ['sometimes', 'nullable', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'district' => ['sometimes', 'nullable', 'string', 'max:80'],
            'village' => ['sometimes', 'nullable', 'string', 'max:80'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:120'],
            'education_level' => ['sometimes', 'nullable', 'string', 'max:60'],
            'country' => ['sometimes', 'nullable', 'string', 'max:60'],
            'heritage' => ['sometimes', 'nullable', 'array:clan,totem,tribe,origin,village,notes'],
            'heritage.*' => ['nullable', 'string', 'max:150'],
            'privacy' => ['sometimes', 'nullable', 'array:contact,birth'],
            'privacy.*' => ['nullable', Rule::in(['family', 'officers', 'hidden'])],
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('kin_branches', 'id')->where('company_id', $companyId)->where('is_deleted', 0)],
            'in_purse' => ['sometimes', 'boolean'],
            'dues_duty' => ['sometimes', 'nullable', $in('dues_duties')],
            'dues_personal_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.\App\Support\Rules\SaccoRules::MAX_MINOR],
            'dues_start_date' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public static function find(int $companyId, int $id): SaccoMember
    {
        return SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->whereNull('merged_into_id')->find($id)
            ?? throw BusinessRuleException::make('not_found', 'That person was not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(Company $company, User $by, array $data): SaccoMember
    {
        $m = new SaccoMember(['company_id' => $company->id, 'kind' => 'individual', 'created_by_id' => $by->id]);
        $this->fill($company, $m, $data);
        $m->save();

        return $m->fresh();
    }

    /** @param array<string, mixed> $data */
    public function update(Company $company, SaccoMember $m, array $data): SaccoMember
    {
        // Marking someone alive again clears the death; recording a death needs the status too (SaccoMember's saving rule).
        if (isset($data['status']) && $data['status'] !== 'deceased' && ! array_key_exists('date_of_death', $data)) {
            $data['date_of_death'] = null;
            $data['place_of_death'] = null;
        }
        $this->fill($company, $m, $data);
        if ($m->isDirty('status')) {
            $m->setAttribute('status_changed_on', \App\Support\LocalDate::today((int) $company->id)->toDateString());
        }
        $m->save();

        return $m->fresh();
    }

    /** @param array<string, mixed> $data */
    private function fill(Company $company, SaccoMember $m, array $data): void
    {
        $data = array_intersect_key($data, array_flip(self::WRITABLE));
        if (array_key_exists('in_purse', $data) && ! TreeService::hasPurse($company)) {
            throw BusinessRuleException::make('purse_off', 'This family does not keep a purse.', ['in_purse' => ['This family does not keep a purse.']]);
        }
        if (array_key_exists('phone', $data)) {
            $raw = $data['phone'];
            unset($data['phone']);
            $m->phone_e164 = blank($raw) ? null
                : (Phone::e164((string) $raw, (string) ($company->country ?: 'UG')) ?? throw BusinessRuleException::make('invalid_phone', "\"{$raw}\" is not a phone number.", ['phone' => ['Not a phone number.']]));
        }
        $m->fill($data);
    }

    /**
     * A person as the API shows them. A viewer who cannot change people does not see a phone or email the person keeps
     * for officers (privacy.contact officers or hidden), nor a birth date kept hidden.
     *
     * @return array<string, mixed>
     */
    public static function present(SaccoMember $m, bool $officer): array
    {
        $privacy = (array) ($m->privacy ?? []);
        $contact = $officer || ($privacy['contact'] ?? 'family') === 'family';
        $birth = $officer || ($privacy['birth'] ?? 'family') === 'family';

        return ['id' => (int) $m->id, 'uuid' => $m->uuid, 'member_no' => $m->member_no, 'name' => $m->full_name, 'first_name' => $m->first_name, 'last_name' => $m->last_name,
            'other_names' => $m->other_names, 'gender' => $m->gender, 'status' => $m->status,
            'date_of_birth' => $birth ? $m->date_of_birth?->toDateString() : null, 'birth_date_precision' => $m->birth_date_precision,
            'date_of_death' => $m->date_of_death?->toDateString(), 'place_of_death' => $m->place_of_death,
            'phone' => $contact ? $m->phone_e164 : null, 'email' => $contact ? $m->email : null,
            'address' => $m->address, 'district' => $m->district, 'village' => $m->village, 'occupation' => $m->occupation, 'education_level' => $m->education_level,
            'country' => $m->country, 'heritage' => $m->heritage, 'privacy' => $officer ? $m->privacy : null,
            'branch_id' => $m->branch_id ? (int) $m->branch_id : null, 'generation' => $m->generation !== null ? (int) $m->generation : null,
            'root_id' => $m->root_id ? (int) $m->root_id : null, 'in_purse' => (bool) $m->in_purse,
            'dues_duty' => $m->dues_duty, 'dues_personal_minor' => $m->dues_personal_minor !== null ? (int) $m->dues_personal_minor : null,
            'dues_start_date' => $m->dues_start_date?->toDateString(), 'photo_file_id' => $m->photo_file_id, 'version' => (int) $m->version];
    }
}
