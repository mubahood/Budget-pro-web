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
use App\Support\Rentals\Minor;

/**
 * Units: the rooms, flats, houses and shops of a property. A unit's state is derived (vacant, occupied, overdue, under
 * notice) from its live lease; only reserved, under repair and switched off are set by hand.
 */
final class Units extends Directory
{
    public const WRITABLE = ['property_id', 'code', 'kind', 'floor', 'block', 'bedrooms', 'bathrooms', 'size_sqm', 'rent_minor', 'deposit_minor',
        'cycle_months', 'status', 'water_meter', 'power_meter', 'notes', 'photo_uuid'];

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
            'size_sqm' => ['nullable', 'integer', 'min:0'],
            'rent_minor' => [$req, 'integer', 'min:0', 'max:'.Minor::MAX],
            'deposit_minor' => ['nullable', 'integer', 'min:0', 'max:'.Minor::MAX],
            'cycle_months' => ['sometimes', 'in:1,3,6,12'],
            'status' => ['sometimes', 'in:available,reserved,maintenance,inactive'],
            'water_meter' => ['nullable', 'string', 'max:40'],
            'power_meter' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo_uuid' => ['nullable', 'string', 'size:36'],
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
        if (in_array($d['status'] ?? null, ['inactive', 'reserved'], true) && $this->live($unit)) {
            throw BusinessRuleException::make('unit_occupied', 'A tenant lives in this unit. Move them out first.');
        }
        if ($unit->status === 'inactive' && isset($d['status']) && $d['status'] !== 'inactive') {
            app(Quotas::class)->assertCanAdd(Company::withoutGlobalScopes()->findOrFail($cid), 'units'); // a unit switched back on counts again
        }
        $unit->forceFill($d)->save();
        RentalAudit::record($cid, 'unit.updated', $unit, "Updated unit {$unit->code}", ['fields' => array_keys($d)], 'staff', $by->id);

        return $unit->fresh();
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
     * A unit's state for lists and colours: vacant, occupied, overdue, notice, reserved, maintenance or inactive.
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
            'reserved' => 'reserved', 'maintenance' => 'maintenance', 'inactive' => 'inactive', default => 'vacant',
        };
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
