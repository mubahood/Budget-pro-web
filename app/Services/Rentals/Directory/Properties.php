<?php

namespace App\Services\Rentals\Directory;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalEntry;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalProperty;
use App\Models\RentalPropertyOwner;
use App\Models\RentalPropertyStaff;
use App\Models\User;
use App\Services\Rentals\Demo\RentalsDemo;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalsModule;
use App\Support\Rentals\Minor;

/** Properties: buildings, estates and plots, each owned by one landlord ("You" in owner mode). */
final class Properties extends Directory
{
    public const WRITABLE = ['landlord_id', 'code', 'name', 'kind', 'address', 'area', 'district', 'lat', 'lng', 'photo_uuid', 'commission_type',
        'commission_bp', 'commission_minor', 'deposit_held_by', 'water_rate_minor', 'power_rate_minor', 'notes', 'status'];

    public static function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'landlord_id' => ['sometimes', 'nullable', 'integer'],
            'code' => ['nullable', 'string', 'max:20'],
            'name' => [$req, 'string', 'max:150'],
            'kind' => ['sometimes', 'in:residential,commercial,mixed,land'],
            'address' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'photo_uuid' => ['nullable', 'string', 'size:36'],
            'commission_type' => ['nullable', 'in:percent,flat,none'],
            'commission_bp' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'commission_minor' => ['nullable', 'integer', 'numeric', 'min:0', 'max:'.Minor::MAX],
            'deposit_held_by' => ['nullable', 'in:agency,landlord'],
            'water_rate_minor' => ['sometimes', 'integer', 'numeric', 'min:0', 'max:'.Minor::MAX],
            'power_rate_minor' => ['sometimes', 'integer', 'numeric', 'min:0', 'max:'.Minor::MAX],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'in:active,archived'],
        ];
    }

    public function create(User $by, array $data): RentalProperty
    {
        RentalAccess::authorize($by, 'rentals_manage');
        $cid = (int) $by->company_id;
        $d = self::validated($data, self::WRITABLE, self::rules());
        $d['landlord_id'] = $this->landlordFor($cid, $d['landlord_id'] ?? null);
        $p = RentalProperty::create($d + ['company_id' => $cid, 'kind' => $d['kind'] ?? 'residential', 'status' => 'active', 'created_by_id' => $by->id]);
        RentalAudit::record($cid, 'property.created', $p, "Added property {$p->name}", [], 'staff', $by->id);

        return $p;
    }

    public function update(User $by, RentalProperty $property, array $data): RentalProperty
    {
        RentalAccess::authorize($by, 'rentals_manage');
        RentalAccess::assertInScope($by, (int) $property->id, 'property');
        $cid = (int) $property->company_id;
        $d = self::validated($data, self::WRITABLE, self::rules(true));
        if (array_key_exists('landlord_id', $d)) {
            if ((int) $d['landlord_id'] === (int) $property->landlord_id) {
                unset($d['landlord_id']); // the same landlord, whatever their status now: nothing to check, nothing changes
            } else {
                $d['landlord_id'] = $this->landlordFor($cid, $d['landlord_id']);
                if ((int) $d['landlord_id'] === (int) $property->landlord_id) {
                    unset($d['landlord_id']);
                } elseif (self::landlordLocked($property)) {
                    throw BusinessRuleException::make('use_handover', 'Money or a tenant is already recorded here, so changing the landlord would split it. Use Hand over to another landlord.');
                }
            }
        }
        if (($d['status'] ?? null) === 'archived' && RentalLease::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $property->id)->whereIn('status', RentalLease::LIVE)->exists()) {
            throw BusinessRuleException::make('property_occupied', 'Tenants still live here. Move them out before archiving the property.');
        }
        $property->forceFill($d)->save();
        RentalAudit::record($cid, 'property.updated', $property, "Updated property {$property->name}", ['fields' => array_keys($d)], 'staff', $by->id);

        return $property->fresh();
    }

    /**
     * Whether the landlord now changes only through a handover (LC-01): the property has a lease, a journal row or a handover behind it. Before any of
     * those, a different landlord is only a typing fix.
     */
    public static function landlordLocked(RentalProperty $property): bool
    {
        $cid = (int) $property->company_id;

        return RentalLease::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $property->id)->exists()
            || RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $property->id)->exists()
            || RentalPropertyOwner::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $property->id)->exists();
    }

    /** The caretakers (or any staff) assigned to a property (rentals_settings). @param list<int> $userIds */
    public function assignStaff(User $by, RentalProperty $property, array $userIds): array
    {
        RentalAccess::authorize($by, 'rentals_settings');
        $cid = (int) $property->company_id;
        RentalsDemo::guard($cid, 'changing who looks after a property');
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        $valid = User::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $ids)->pluck('id')->map(fn ($x) => (int) $x)->all();
        if (count($valid) !== count($ids)) {
            throw BusinessRuleException::make('invalid', 'Choose people from your own team.');
        }
        RentalPropertyStaff::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $property->id)->whereNotIn('user_id', $valid ?: [0])->delete();
        foreach ($valid as $uid) {
            RentalPropertyStaff::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'property_id' => $property->id, 'user_id' => $uid]);
        }
        RentalAudit::record($cid, 'property.staff', $property, "Set who looks after {$property->name}", ['user_ids' => $valid], 'staff', $by->id);

        return $valid;
    }

    public function find(User $by, int $id): RentalProperty
    {
        RentalAccess::authorize($by, 'rentals_view');
        $p = RentalProperty::withoutGlobalScopes()->where('company_id', $by->company_id)->find($id);
        if ($p === null || ! RentalAccess::inScope($by, (int) $p->id)) {
            throw BusinessRuleException::make('not_found', 'That property was not found.');
        }

        return $p;
    }

    private function landlordFor(int $cid, mixed $landlordId): int
    {
        if (RentalsModule::isOwnerMode($cid)) {
            return (int) RentalsModule::selfLandlord($cid)->id;
        }
        $l = $landlordId ? RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'active')->find((int) $landlordId) : null;
        if ($l === null) {
            throw BusinessRuleException::make('landlord_required', 'Choose the landlord who owns this property (add them under Landlords first).');
        }

        return (int) $l->id;
    }
}
