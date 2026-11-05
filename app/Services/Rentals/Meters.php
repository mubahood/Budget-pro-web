<?php

namespace App\Services\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLease;
use App\Models\RentalMeterReading;
use App\Models\RentalProperty;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\Payments;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Str;

/**
 * Water and power meter readings (docs/estate-ledger/04-architecture.md §5.9): the units used since the last reading, at
 * the property's rate, become a utility bill for the tenant living in the unit on the reading day. The first reading
 * of a meter, or one for a vacant unit, only sets the starting point. A phone's retry (same uuid) is one reading.
 */
final class Meters
{
    public const UTILITIES = ['water' => 'Water', 'power' => 'Power'];

    /** $data: unit_id, utility, reading (decimal string), read_on, reset (meter replaced), photo_uuid, notes, uuid. */
    public function record(User $by, array $data): RentalMeterReading
    {
        RentalAccess::authorize($by, 'rentals_maintenance');
        $cid = (int) $by->company_id;
        $unit = RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->find((int) ($data['unit_id'] ?? 0))
            ?? throw BusinessRuleException::make('not_found', 'Choose the unit.');
        RentalAccess::assertInScope($by, (int) $unit->property_id, 'unit');
        $utility = (string) ($data['utility'] ?? 'water');
        if (! array_key_exists($utility, self::UTILITIES)) {
            throw BusinessRuleException::make('utility_invalid', 'Choose water or power.');
        }
        $raw = str_replace([',', ' '], '', trim((string) ($data['reading'] ?? '')));
        if (! preg_match('/^\d{1,10}(\.\d{1,3})?$/', $raw)) {
            throw BusinessRuleException::make('reading_invalid', 'Type the number on the meter, for example 1234.5.');
        }
        $milli = (int) round(((float) $raw) * 1000); // a meter value, not money: three decimals are exact here
        $readOn = LocalDate::date($cid, $data['read_on'] ?? null);
        if ($readOn->gt(LocalDate::today($cid))) {
            throw BusinessRuleException::make('date_in_future', 'A reading cannot be dated after today.');
        }
        if (! empty($data['photo_uuid']) && (! is_string($data['photo_uuid']) || ! Str::isUuid($data['photo_uuid']))) {
            throw BusinessRuleException::make('file_invalid', 'That photo was not found. Please send it again.');
        }
        $uuid = (string) ($data['uuid'] ?? Str::uuid());
        if (($same = RentalMeterReading::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first()) !== null) {
            return $same;
        }

        return RentalLedger::locked($cid, function () use ($by, $data, $cid, $unit, $utility, $milli, $readOn, $uuid) {
            $last = RentalMeterReading::withoutGlobalScopes()->where('company_id', $cid)->where('unit_id', $unit->id)->where('utility', $utility)
                ->orderByDesc('read_on')->orderByDesc('id')->lockForUpdate()->first();
            if (($same = RentalMeterReading::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first()) !== null) {
                return $same; // the same reading sent twice
            }
            // A replaced meter starts again from zero; the first reading of a meter only sets the starting point.
            $reset = ! empty($data['reset']);
            $prevMilli = $reset ? 0 : ($last !== null ? (int) round(((float) $last->reading) * 1000) : null);
            if ($last !== null && $readOn->lt($last->read_on)) {
                throw BusinessRuleException::make('reading_order', 'A later reading exists ('.$last->read_on->format('j M Y').'). Readings go in date order.');
            }
            if ($prevMilli !== null && $milli < $prevMilli) {
                throw BusinessRuleException::make('reading_lower', 'This is below the last reading ('.self::show($prevMilli).'). Tick "meter replaced" if it was changed.');
            }
            $usedMilli = $prevMilli === null ? 0 : $milli - $prevMilli;
            $property = RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->find($unit->property_id);
            $rate = (int) ($utility === 'water' ? $property?->water_rate_minor : $property?->power_rate_minor);
            $amount = $rate > 0 ? Minor::share($rate, $usedMilli, 1000) : 0;
            if ($amount > Minor::MAX) {
                throw BusinessRuleException::make('amount_invalid', 'That reading would make a bill too large. Check the figure and the rate.');
            }
            $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->where('unit_id', $unit->id)->where('start_date', '<=', $readOn->toDateString())
                ->where(fn ($q) => $q->whereIn('status', RentalLease::LIVE)->orWhere(fn ($q) => $q->where('status', 'ended')->where('ended_on', '>=', $readOn->toDateString())))
                ->orderByDesc('start_date')->first();
            $r = RentalMeterReading::create(['company_id' => $cid, 'uuid' => $uuid, 'unit_id' => $unit->id, 'lease_id' => $lease?->id, 'utility' => $utility,
                'reading' => self::show($milli), 'previous' => self::show($prevMilli ?? $milli), 'used' => self::show($usedMilli), 'rate_minor' => $rate,
                'amount_minor' => $lease !== null ? $amount : 0, 'read_on' => $readOn->toDateString(), 'reset' => ! empty($data['reset']),
                'photo_uuid' => ! empty($data['photo_uuid']) ? $data['photo_uuid'] : null, 'recorded_by_id' => $by->id, 'notes' => isset($data['notes']) ? mb_substr((string) $data['notes'], 0, 255) : null]);
            if ($lease !== null && $amount > 0) {
                $label = self::UTILITIES[$utility];
                $c = app(Billing::class)->makeFor($lease, ['kind' => 'utility', 'meter_reading_id' => $r->id,
                    'description' => "{$label}: ".self::show($usedMilli).' units ('.self::show($prevMilli).' → '.self::show($milli).')',
                    'due_date' => $readOn->toDateString(), 'amount_minor' => $amount, 'goes_to' => 'landlord', 'dedupe_key' => "meter:{$r->id}",
                    'created_by_id' => $by->id] + Billing::commission($lease, false));
                if ($c !== null) {
                    $r->forceFill(['charge_id' => $c->id])->save();
                    app(Payments::class)->applyCredit($lease, LocalDate::today($cid), $by->id);
                }
            }
            RentalAudit::record($cid, 'meter.read', $r, self::UTILITIES[$utility]." reading for {$unit->code}: ".self::show($milli), ['amount_minor' => $r->amount_minor], 'staff', $by->id);

            return $r->fresh();
        });
    }

    /** What this reading would bill today, at the property's rate: nothing for a first reading, a lower one or no rate. No writes. */
    public static function estimate(int $companyId, RentalUnit $unit, string $utility, mixed $reading): int
    {
        $raw = str_replace([',', ' '], '', trim((string) $reading));
        $last = self::last($companyId, (int) $unit->id, $utility);
        if ($last === null || ! preg_match('/^\d{1,10}(\.\d{1,3})?$/', $raw)) {
            return 0;
        }
        $used = (int) round(((float) $raw) * 1000) - (int) round(((float) $last->reading) * 1000);
        $property = RentalProperty::withoutGlobalScopes()->where('company_id', $companyId)->find($unit->property_id);
        $rate = (int) ($utility === 'power' ? $property?->power_rate_minor : $property?->water_rate_minor);

        return $used > 0 && $rate > 0 ? Minor::share($rate, $used, 1000) : 0;
    }

    /** The last reading of a unit's meter (the next reading's starting point), or null. */
    public static function last(int $companyId, int $unitId, string $utility): ?RentalMeterReading
    {
        return RentalMeterReading::withoutGlobalScopes()->where('company_id', $companyId)->where('unit_id', $unitId)->where('utility', $utility)
            ->orderByDesc('read_on')->orderByDesc('id')->first();
    }

    private static function show(?int $milli): string
    {
        if ($milli === null) {
            return '0';
        }

        return rtrim(rtrim(number_format($milli / 1000, 3, '.', ''), '0'), '.');
    }
}
