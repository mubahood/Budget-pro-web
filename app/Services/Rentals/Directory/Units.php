<?php

namespace App\Services\Rentals\Directory;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\RentalLease;
use App\Models\RentalProperty;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\Billing\Quotas;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalsModule;
use App\Support\Rentals\Minor;

/**
 * Units: the rooms, flats, houses and shops of a property. A unit's state is derived (vacant, occupied, overdue, under
 * notice) from its live lease; only reserved, under repair, switched off and used by the landlord or a caretaker (LC-21) are set by hand.
 */
final class Units extends Directory
{
    public const WRITABLE = ['property_id', 'code', 'kind', 'floor', 'block', 'bedrooms', 'bathrooms', 'size_sqm', 'rent_minor', 'deposit_minor',
        'cycle_months', 'status', 'in_use_by', 'water_meter', 'power_meter', 'notes', 'photo_uuid', 'commission_type', 'commission_bp', 'commission_minor'];

    public static function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'property_id' => [$req, 'integer'],
            'code' => [$req, 'string', 'max:40'],
            'kind' => ['sometimes', 'in:'.implode(',', RentalUnit::KINDS)],
            'floor' => ['nullable', 'string', 'max:20'],
            'block' => ['nullable', 'string', 'max:20'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'bathrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'size_sqm' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'rent_minor' => [$req, 'integer', 'numeric', 'min:0', 'max:'.Minor::MAX],
            'deposit_minor' => ['nullable', 'integer', 'numeric', 'min:0', 'max:'.Minor::MAX],
            'cycle_months' => ['sometimes', 'in:1,3,6,12'],
            'status' => ['sometimes', 'in:available,reserved,maintenance,inactive,in_use'],
            'in_use_by' => ['nullable', 'string', 'max:100'],
            'water_meter' => ['nullable', 'string', 'max:40'],
            'power_meter' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo_uuid' => ['nullable', 'string', 'size:36'],
            'commission_type' => ['nullable', 'in:percent,flat,none'],
            'commission_bp' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'commission_minor' => ['nullable', 'integer', 'numeric', 'min:0', 'max:'.Minor::MAX],
        ];
    }

    public function create(User $by, array $data): RentalUnit
    {
        RentalAccess::authorize($by, 'rentals_manage');
        $cid = (int) $by->company_id;
        $d = self::validated($data, self::WRITABLE, self::rules());
        $property = $this->property($by, (int) $d['property_id']);
        app(Quotas::class)->assertCanAdd(Company::withoutGlobalScopes()->findOrFail($cid), 'units');
        $this->assertCodeFree($cid, $property->id, (string) $d['code']);
        $d = self::commissionFields(self::inUseFields($d), $cid);
        $u = RentalUnit::create($d + ['company_id' => $cid, 'kind' => $d['kind'] ?? 'single_room', 'cycle_months' => (int) ($d['cycle_months'] ?? 1),
            'status' => $d['status'] ?? 'available', 'created_by_id' => $by->id]);
        RentalAudit::record($cid, 'unit.created', $u, "Added unit {$u->code} to {$property->name}", [], 'staff', $by->id);

        return $u;
    }

    /**
     * Add many units at once (setup): codes from a prefix and a range ("A", 1–12 → A1…A12) with one rent and kind.
     *
     * @return list<RentalUnit>
     */
    public function createMany(User $by, int $propertyId, string $prefix, int $from, int $to, array $shared): array
    {
        RentalAccess::authorize($by, 'rentals_manage');
        if ($to < $from || $to - $from > 199) {
            throw BusinessRuleException::make('range_invalid', 'Add between 1 and 200 units at a time.');
        }
        $cid = (int) $by->company_id;

        return RentalLedger::locked($cid, function () use ($by, $propertyId, $prefix, $from, $to, $shared) {
            $out = [];
            for ($n = $from; $n <= $to; $n++) {
                $out[] = $this->create($by, ['property_id' => $propertyId, 'code' => trim($prefix).$n] + $shared);
            }

            return $out;
        });
    }

    public function update(User $by, RentalUnit $unit, array $data): RentalUnit
    {
        RentalAccess::authorize($by, 'rentals_manage');
        $cid = (int) $unit->company_id;
        RentalAccess::assertInScope($by, (int) $unit->property_id, 'unit');
        $d = self::validated($data, self::WRITABLE, self::rules(true));
        if (isset($d['property_id']) && (int) $d['property_id'] !== (int) $unit->property_id) {
            if ($this->live($unit)) {
                throw BusinessRuleException::make('unit_occupied', 'A tenant lives in this unit: it cannot move to another property.');
            }
            $this->property($by, (int) $d['property_id']);
        }
        if (isset($d['code']) && $d['code'] !== $unit->code) {
            $this->assertCodeFree($cid, (int) ($d['property_id'] ?? $unit->property_id), (string) $d['code'], (int) $unit->id);
        }
        $d = self::inUseFields($d, $unit);
        if (in_array($d['status'] ?? null, ['inactive', 'reserved', 'in_use'], true) && $this->live($unit)) {
            throw BusinessRuleException::make('unit_occupied', 'A tenant lives in this unit. Move them out first.');
        }
        if ($unit->status === 'inactive' && isset($d['status']) && $d['status'] !== 'inactive') {
            app(Quotas::class)->assertCanAdd(Company::withoutGlobalScopes()->findOrFail($cid), 'units'); // a unit switched back on counts again
        }
        $unit->forceFill(self::commissionFields($d, $cid))->save();
        RentalAudit::record($cid, 'unit.updated', $unit, "Updated unit {$unit->code}", ['fields' => array_keys($d)], 'staff', $by->id);

        return $unit->fresh();
    }

    /**
     * Give ticked units of a property their own commission rule, or set them back to following the property (LC-14; rentals_manage). $rule: commission_type
     * (percent, flat, none, or null to follow), commission_bp, commission_minor. New leases read it; live leases and bills keep theirs. Returns how many changed.
     *
     * @param  list<int>  $unitIds
     */
    public function setCommission(User $by, RentalProperty $property, array $unitIds, array $rule): int
    {
        RentalAccess::authorize($by, 'rentals_manage');
        if ((int) $by->company_id !== (int) $property->company_id) {
            throw BusinessRuleException::make('not_found', 'That property was not found.');
        }
        RentalAccess::assertInScope($by, (int) $property->id, 'property');
        $cid = (int) $property->company_id;
        if (RentalsModule::isOwnerMode($cid)) {
            throw BusinessRuleException::make('owner_mode', 'This account manages its own property: there is no commission to set.');
        }
        $ids = array_values(array_unique(array_map('intval', $unitIds)));
        if ($ids === []) {
            throw BusinessRuleException::make('units_required', 'Tick at least one unit.');
        }
        $fields = self::validated($rule + ['commission_type' => null], ['commission_type', 'commission_bp', 'commission_minor'], array_intersect_key(self::rules(true), array_flip(['commission_type', 'commission_bp', 'commission_minor'])));
        $fields = self::commissionFields($fields + ['commission_type' => null], $cid);

        return RentalLedger::locked($cid, function () use ($by, $property, $ids, $fields, $cid) {
            $units = RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $property->id)->whereIn('id', $ids)->lockForUpdate()->get();
            if ($units->count() !== count($ids)) {
                throw BusinessRuleException::make('not_found', 'Tick units of this property only.');
            }
            foreach ($units as $u) {
                $u->forceFill($fields)->save();
            }
            RentalAudit::record($cid, 'unit.commission', $property, 'Set the commission on '.count($ids).' '.(count($ids) === 1 ? 'unit' : 'units')." of {$property->name}", $fields + ['unit_ids' => $ids], 'staff', $by->id);

            return $units->count();
        });
    }

    public function find(User $by, int $id): RentalUnit
    {
        RentalAccess::authorize($by, 'rentals_view');
        $u = RentalUnit::withoutGlobalScopes()->where('company_id', $by->company_id)->find($id);
        if ($u === null || ! RentalAccess::inScope($by, (int) $u->property_id)) {
            throw BusinessRuleException::make('not_found', 'That unit was not found.');
        }

        return $u;
    }

    /**
     * A unit's state for lists and colours: vacant, occupied, overdue, notice, reserved, maintenance, inactive or in_use.
     *
     * @param  array<string, mixed>|null  $position  the live lease's position, when the caller has it
     */
    public static function state(RentalUnit $unit, ?RentalLease $lease, ?array $position = null): string
    {
        if ($lease !== null && $lease->isLive()) {
            if ($lease->status === 'notice') {
                return 'notice';
            }

            return ($position['state'] ?? null) === 'overdue' ? 'overdue' : 'occupied';
        }

        return match ($unit->status) {
            'reserved' => 'reserved', 'maintenance' => 'maintenance', 'inactive' => 'inactive', 'in_use' => 'in_use', default => 'vacant',
        };
    }

    /**
     * Who uses a room (LC-21): needed while it is in use, and forgotten as soon as it is not. A write that names neither leaves it alone.
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private static function inUseFields(array $d, ?RentalUnit $unit = null): array
    {
        $status = $d['status'] ?? $unit?->status ?? 'available';
        if ($status !== 'in_use') {
            return array_key_exists('status', $d) || array_key_exists('in_use_by', $d) ? array_merge($d, ['in_use_by' => null]) : $d;
        }
        $who = trim((string) ($d['in_use_by'] ?? $unit?->in_use_by ?? ''));
        if ($who === '') {
            throw BusinessRuleException::make('in_use_by_required', 'Say who uses this room: the landlord, or the caretaker.');
        }

        return array_merge($d, ['in_use_by' => mb_substr($who, 0, 100)]);
    }

    /**
     * A unit's commission columns, whole: empty means it follows its property, and a rule keeps only what it needs. An owner-run account has none. A
     * write that does not name the rule leaves it alone.
     */
    private static function commissionFields(array $d, int $cid): array
    {
        if (! array_key_exists('commission_type', $d) && ! array_key_exists('commission_bp', $d) && ! array_key_exists('commission_minor', $d)) {
            return $d;
        }
        if (RentalsModule::isOwnerMode($cid)) {
            return array_diff_key($d, ['commission_type' => 1, 'commission_bp' => 1, 'commission_minor' => 1]);
        }
        $type = $d['commission_type'] ?? null;
        if ($type === 'percent' && ($d['commission_bp'] ?? null) === null) {
            throw BusinessRuleException::make('commission_invalid', 'Type the percent for this unit.');
        }
        if ($type === 'flat' && ($d['commission_minor'] ?? null) === null) {
            throw BusinessRuleException::make('commission_invalid', 'Type the flat amount for this unit.');
        }

        return ['commission_type' => $type, 'commission_bp' => $type === null ? null : ($type === 'percent' ? (int) $d['commission_bp'] : 0),
            'commission_minor' => $type === null ? null : ($type === 'flat' ? (int) $d['commission_minor'] : 0)] + $d;
    }

    private function live(RentalUnit $unit): bool
    {
        return RentalLease::withoutGlobalScopes()->where('company_id', $unit->company_id)->where('unit_id', $unit->id)->whereIn('status', RentalLease::LIVE)->exists();
    }

    private function property(User $by, int $id): RentalProperty
    {
        $p = RentalProperty::withoutGlobalScopes()->where('company_id', $by->company_id)->where('status', 'active')->find($id);
        if ($p === null || ! RentalAccess::inScope($by, (int) $p->id)) {
            throw BusinessRuleException::make('not_found', 'Choose one of your active properties.');
        }

        return $p;
    }

    private function assertCodeFree(int $cid, int $propertyId, string $code, ?int $except = null): void
    {
        $q = RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $propertyId)->where('code', $code);
        if ($except !== null) {
            $q->where('id', '!=', $except);
        }
        if ($q->exists()) {
            throw BusinessRuleException::make('code_taken', "This property already has a unit called {$code}.");
        }
    }
}
