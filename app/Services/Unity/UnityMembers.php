<?php

namespace App\Services\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\UnityCategory;
use App\Models\UnityChapter;
use App\Models\User;
use App\Support\LocalDate;
use App\Support\Phone;
use Illuminate\Validation\Rule;

/** An association's members (sacco_members rows): rules, add, change, find and how the API shows them. Never deleted. */
final class UnityMembers
{
    private const WRITABLE = ['first_name', 'last_name', 'other_names', 'gender', 'date_of_birth', 'email', 'address', 'district', 'subcounty', 'village', 'occupation',
        'education_level', 'status', 'member_since', 'category_id', 'chapter_id', 'phone', 'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relation'];

    /** Education levels (sacco_members.education_level), lowest first. Insights count anything else as unknown. */
    public const EDUCATION = ['none', 'primary', 'o_level', 'a_level', 'certificate', 'diploma', 'degree', 'postgraduate'];

    /** Current members (M10): the group's strength. Applicants, suspended, exited, deceased and rejected people are not counted. */
    public const CURRENT = ['active', 'dormant'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(int $companyId, bool $creating): array
    {
        return [
            'first_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'other_names' => ['sometimes', 'nullable', 'string', 'max:80'],
            'gender' => ['sometimes', 'nullable', Rule::in(SaccoMember::GENDERS)],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'district' => ['sometimes', 'nullable', 'string', 'max:80'],
            'subcounty' => ['sometimes', 'nullable', 'string', 'max:80'],
            'education_level' => ['sometimes', 'nullable', Rule::in(self::EDUCATION)],
            'next_of_kin_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'next_of_kin_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'next_of_kin_relation' => ['sometimes', 'nullable', 'string', 'max:40'],
            'village' => ['sometimes', 'nullable', 'string', 'max:80'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(config('unity.statuses'))],
            'member_since' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'chapter_id' => ['sometimes', 'nullable', 'integer'],
        ];
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
        $this->fill($company, $m, $data);
        if ($m->isDirty('status')) {
            $m->setAttribute('status_changed_on', LocalDate::today((int) $company->id)->toDateString());
        }
        $m->save();

        return $m->fresh();
    }

    /** @param array<string, mixed> $data */
    private function fill(Company $company, SaccoMember $m, array $data): void
    {
        $data = array_intersect_key($data, array_flip(self::WRITABLE));
        $cid = (int) $company->id;
        if (! empty($data['category_id']) && ! UnityCategory::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereKey($data['category_id'])->exists()) {
            throw BusinessRuleException::make('validation', 'That category is not this association\'s.', ['category_id' => ['Unknown category.']]);
        }
        if (! empty($data['chapter_id']) && ! UnityChapter::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->whereKey($data['chapter_id'])->exists()) {
            throw BusinessRuleException::make('validation', 'That chapter is not this association\'s.', ['chapter_id' => ['Unknown chapter.']]);
        }
        if (array_key_exists('phone', $data)) {
            $raw = $data['phone'];
            unset($data['phone']);
            $m->phone_e164 = blank($raw) ? null
                : (Phone::e164((string) $raw, (string) ($company->country ?: 'UG')) ?? throw BusinessRuleException::make('invalid_phone', "\"{$raw}\" is not a phone number.", ['phone' => ['Not a phone number.']]));
        }
        if (array_key_exists('next_of_kin_phone', $data)) {
            $raw = $data['next_of_kin_phone'];
            unset($data['next_of_kin_phone']);
            $m->next_of_kin_phone_e164 = blank($raw) ? null
                : (Phone::e164((string) $raw, (string) ($company->country ?: 'UG')) ?? throw BusinessRuleException::make('invalid_phone', "\"{$raw}\" is not a phone number.", ['next_of_kin_phone' => ['Not a phone number.']]));
        }
        if (! empty($data['chapter_id']) && UnityChapter::withoutGlobalScopes()->whereKey($data['chapter_id'])->value('kind') === 'region') {
            throw BusinessRuleException::make('validation', 'A member belongs to a chapter, not to a region.', ['chapter_id' => ['Choose a chapter.']]);
        }
        $m->fill($data);
    }

    public static function find(int $companyId, int $id): SaccoMember
    {
        return SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->whereNull('merged_into_id')->findOrFail($id);
    }

    /** The member record of a signed-in person: the one with their email or phone (there is no user link on a member). */
    public static function ofUser(int $companyId, User $user): ?SaccoMember
    {
        $email = trim((string) $user->email);
        $phone = trim((string) ($user->phone_e164 ?? ''));
        if ($email === '' && $phone === '') {
            return null;
        }

        return SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->whereNull('merged_into_id')
            ->where(fn ($q) => $q->when($email !== '', fn ($w) => $w->orWhere('email', $email))->when($phone !== '', fn ($w) => $w->orWhere('phone_e164', $phone)))
            ->orderBy('id')->first();
    }

    /** @return array<string, mixed> */
    public static function present(SaccoMember $m): array
    {
        return self::presentMany(collect([$m]))[0];
    }

    /**
     * Members as the API shows them, with their chapter, region, category, photo link and committee offices: a handful of
     * queries for the whole page, whatever its size.
     *
     * @param  \Illuminate\Support\Collection<int, SaccoMember>  $members
     * @return list<array<string, mixed>>
     */
    public static function presentMany(\Illuminate\Support\Collection $members): array
    {
        if ($members->isEmpty()) {
            return [];
        }
        $cid = (int) $members->first()->company_id;
        $places = UnityChapter::withoutGlobalScopes()->where('company_id', $cid)->get(['id', 'name', 'kind', 'parent_id', 'code'])->keyBy('id');
        $categories = UnityCategory::withoutGlobalScopes()->where('company_id', $cid)->pluck('name', 'id');
        $photos = UnityLinks::photos($cid, $members->pluck('photo_file_id')->filter()->all());
        $offices = UnityOffices::of($cid, $members->pluck('id')->all());

        return $members->map(function (SaccoMember $m) use ($places, $categories, $photos, $offices) {
            $chapter = $m->chapter_id ? $places->get($m->chapter_id) : null;
            $region = $chapter?->parent_id ? $places->get($chapter->parent_id) : null;

            return ['id' => (int) $m->id, 'uuid' => $m->uuid, 'member_no' => $m->member_no, 'name' => $m->full_name, 'first_name' => $m->first_name, 'last_name' => $m->last_name,
                'other_names' => $m->other_names, 'gender' => $m->gender, 'date_of_birth' => $m->date_of_birth?->toDateString(), 'phone' => $m->phone_e164, 'email' => $m->email,
                'address' => $m->address, 'district' => $m->district, 'subcounty' => $m->subcounty, 'village' => $m->village, 'occupation' => $m->occupation,
                'education_level' => $m->education_level, 'status' => $m->status,
                'member_since' => $m->member_since?->toDateString(), 'joined_on' => $m->joined_on?->toDateString(),
                'category_id' => $m->category_id ? (int) $m->category_id : null, 'category_name' => $m->category_id ? ($categories[$m->category_id] ?? null) : null,
                'chapter_id' => $m->chapter_id ? (int) $m->chapter_id : null, 'chapter_name' => $chapter?->name, 'chapter_code' => $chapter?->code,
                'region_id' => $region ? (int) $region->id : null, 'region_name' => $region?->name,
                'photo_url' => $m->photo_file_id ? ($photos[(int) $m->photo_file_id] ?? null) : null,
                'next_of_kin_name' => $m->next_of_kin_name, 'next_of_kin_phone' => $m->next_of_kin_phone_e164, 'next_of_kin_relation' => $m->next_of_kin_relation,
                'offices' => $offices[(int) $m->id] ?? [],
                'membership_ends_on' => $m->membership_ends_on?->toDateString(), 'standing' => Memberships::standingOf($m), 'version' => (int) $m->version];
        })->values()->all();
    }
}
