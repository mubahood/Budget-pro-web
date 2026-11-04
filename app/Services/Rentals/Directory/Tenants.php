<?php

namespace App\Services\Rentals\Directory;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLease;
use App\Models\RentalTenant;
use App\Models\User;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalsModule;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenants: people and companies who rent. A caretaker sees the tenants of their properties only. Adding a second
 * tenant with a phone number already on the books is refused with the first one named, unless the caller says so.
 */
final class Tenants extends Directory
{
    public const WRITABLE = ['kind', 'name', 'phone', 'alt_phone', 'email', 'id_type', 'id_number', 'occupation', 'employer', 'next_of_kin_name',
        'next_of_kin_phone', 'photo_uuid', 'notes'];

    public static function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'kind' => ['sometimes', 'in:person,company'],
            'name' => [$req, 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'alt_phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'id_type' => ['nullable', 'in:national_id,passport,driving_permit,company_reg,other'],
            'id_number' => ['nullable', 'string', 'max:40'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'employer' => ['nullable', 'string', 'max:150'],
            'next_of_kin_name' => ['nullable', 'string', 'max:150'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:40'],
            'photo_uuid' => ['nullable', 'string', 'size:36'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function create(User $by, array $data): RentalTenant
    {
        RentalAccess::authorize($by, 'rentals_manage');
        $cid = (int) $by->company_id;
        $d = self::validated($data, self::WRITABLE, self::rules());
        $d = $this->fill($d, $cid);
        if (! empty($d['phone_e164']) && empty($data['allow_duplicate'])
            && ($same = RentalTenant::withoutGlobalScopes()->where('company_id', $cid)->where('phone_e164', $d['phone_e164'])->first()) !== null) {
            throw BusinessRuleException::make('tenant_exists', "{$same->name} already has this phone number. Choose them instead, or tick that this is a different person.", ['tenant_id' => $same->id]);
        }
        $t = RentalTenant::create($d + ['company_id' => $cid, 'code' => RentalsModule::nextNumber($cid, 'tenant'), 'kind' => $d['kind'] ?? 'person',
            'status' => 'active', 'created_by_id' => $by->id]);
        RentalAudit::record($cid, 'tenant.created', $t, "Added tenant {$t->name}", [], 'staff', $by->id);

        return $t;
    }

    public function update(User $by, RentalTenant $tenant, array $data): RentalTenant
    {
        RentalAccess::authorize($by, 'rentals_manage');
        $this->assertVisible($by, $tenant);
        $cid = (int) $tenant->company_id;
        $d = $this->fill(self::validated($data, self::WRITABLE, self::rules(true)), $cid);
        $tenant->forceFill($d)->save();
        RentalAudit::record($cid, 'tenant.updated', $tenant, "Updated tenant {$tenant->name}", ['fields' => array_keys($d)], 'staff', $by->id);

        return $tenant->fresh();
    }

    public function find(User $by, int $id): RentalTenant
    {
        RentalAccess::authorize($by, 'rentals_view');
        $t = RentalTenant::withoutGlobalScopes()->where('company_id', $by->company_id)->find($id)
            ?? throw BusinessRuleException::make('not_found', 'That tenant was not found.');
        $this->assertVisible($by, $t);

        return $t;
    }

    /**
     * Tenants a login may see: everyone, or for a caretaker those with a lease in their properties.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function visibleTo(Builder $query, User $by): Builder
    {
        $ids = RentalAccess::propertyIds($by);
        if ($ids === null) {
            return $query;
        }

        return $query->whereIn('id', RentalLease::withoutGlobalScopes()->where('company_id', $by->company_id)->whereIn('property_id', $ids ?: [0])->select('tenant_id'));
    }

    private function assertVisible(User $by, RentalTenant $t): void
    {
        $ids = RentalAccess::propertyIds($by);
        if ($ids !== null && ! RentalLease::withoutGlobalScopes()->where('company_id', $t->company_id)->where('tenant_id', $t->id)->whereIn('property_id', $ids ?: [0])->exists()) {
            throw BusinessRuleException::make('not_found', 'That tenant was not found.');
        }
    }

    private function fill(array $d, int $cid): array
    {
        $country = self::country($cid);
        if (array_key_exists('phone', $d)) {
            $d['phone_e164'] = self::phone($d['phone'], $country);
        }
        if (array_key_exists('id_number', $d)) {
            $d['id_number'] = self::encrypt($d['id_number']);
        }

        return $d;
    }
}
