<?php

namespace App\Services\Rentals\Leases;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalCommissionChange;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalProperty;
use App\Models\RentalSetting;
use App\Models\User;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A live lease's commission from a date (LC-15), the way rent changes (RentalRentChange): one lease, or every live lease of a property or landlord. The
 * date is after what is billed; bills already raised keep the rule they carry, past statements do not change, and no money moves. A new bill reads the rule
 * in force on its period's first day (Billing::ruleAt). A lease on a special rate stays on it unless someone ticks it.
 */
final class CommissionChanges
{
    /** A change to one lease (rentals_manage). $rule: commission_type (percent, flat, none), commission_bp, commission_minor. */
    public function change(User $by, RentalLease $lease, array $rule, string $from, string $reason): RentalCommissionChange
    {
        RentalAccess::authorize($by, 'rentals_manage');
        if ((int) $by->company_id !== (int) $lease->company_id) {
            throw BusinessRuleException::make('not_found', 'That lease was not found.');
        }
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        $cid = (int) $lease->company_id;
        $this->assertAgency($cid);
        $rule = self::rule($rule);
        $reason = self::reason($reason);
        $day = LocalDate::date($cid, $from);

        return RentalLedger::locked($cid, function () use ($by, $lease, $rule, $reason, $day, $cid) {
            $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($lease->id);
            if (! $lease->isLive()) {
                throw BusinessRuleException::make('lease_not_live', 'This lease has ended.');
            }
            if (($why = self::tooEarly($lease, $day)) !== null) {
                throw BusinessRuleException::make('period_billed', $why);
            }

            return $this->write($by, $lease, $rule, $day, $reason, null);
        });
    }

    /**
     * What a change would do across a property or a landlord, for the screen before anyone commits to it (no writes). Each live lease: its rule now, the
     * commission a month before and after at today's rent, whether it is on a special rate (not what its unit and property would give a new lease), and
     * whether it is already billed past the date, or already on the rule. `changes` says which it would change as things stand, with nothing ticked.
     *
     * @return array{rows: list<array<string, mixed>>, before_minor: int, after_minor: int, count: int}
     */
    public function plan(User $by, ?int $landlordId, ?int $propertyId, array $rule, string $from): array
    {
        RentalAccess::authorize($by, 'rentals_manage');
        $cid = (int) $by->company_id;
        $this->assertAgency($cid);
        $rule = self::rule($rule);
        $day = LocalDate::date($cid, $from);
        $rows = [];
        $before = 0;
        $after = 0;
        $leases = $this->leases($by, $landlordId, $propertyId);
        $rules = self::rulesOn($leases, $day);
        $s = RentalsModule::settings($cid);
        foreach ($leases as $l) {
            $now = $rules[(int) $l->id];
            $same = $now === $rule;
            $billed = self::tooEarly($l, $day);
            $special = self::isSpecial($l, $now, $s);
            $from = self::monthly($now, (int) $l->rent_minor);
            $to = self::monthly($rule, (int) $l->rent_minor);
            $changes = ! $same && $billed === null && ! $special;
            if ($changes) {
                $before += $from;
                $after += $to;
            }
            $rows[] = ['lease_id' => (int) $l->id, 'number' => (string) $l->number, 'tenant' => (string) $l->tenant?->name, 'unit' => (string) $l->unit?->code, 'property' => (string) $l->property?->name,
                'rent_minor' => (int) $l->rent_minor, 'rule' => Billing::ruleWords($cid, $now['commission_type'], $now['commission_bp'], $now['commission_minor']), 'before_minor' => $from, 'after_minor' => $to,
                'special' => $special, 'same' => $same, 'billed' => $billed, 'billed_until' => $l->billed_until?->toDateString(), 'changes' => $changes];
        }

        return ['rows' => $rows, 'before_minor' => $before, 'after_minor' => $after, 'count' => count(array_filter($rows, fn ($r) => $r['changes']))];
    }

    /**
     * Change every live lease of a property or landlord (rentals_manage). Leases on a special rate change only when listed in $alsoSpecial; a lease already
     * billed past the date or already on the rule is left alone. $newDefault also makes it the rule for new leases on that property or landlord.
     *
     * @param  list<int>  $alsoSpecial
     * @return array{changed: list<int>, batch: string}
     */
    public function changeMany(User $by, ?int $landlordId, ?int $propertyId, array $rule, string $from, string $reason, array $alsoSpecial = [], bool $newDefault = false): array
    {
        RentalAccess::authorize($by, 'rentals_manage');
        $cid = (int) $by->company_id;
        $this->assertAgency($cid);
        $rule = self::rule($rule);
        $reason = self::reason($reason);
        $day = LocalDate::date($cid, $from);
        $also = array_map('intval', $alsoSpecial);

        return RentalLedger::locked($cid, function () use ($by, $landlordId, $propertyId, $rule, $reason, $day, $also, $newDefault, $cid) {
            $batch = (string) Str::uuid();
            $changed = [];
            $leases = $this->leases($by, $landlordId, $propertyId);
            $rules = self::rulesOn($leases, $day);
            $s = RentalsModule::settings($cid);
            foreach ($leases as $l) {
                $on = $rules[(int) $l->id];
                if ($on === $rule || self::tooEarly($l, $day) !== null || (self::isSpecial($l, $on, $s) && ! in_array((int) $l->id, $also, true))) {
                    continue;
                }
                $this->write($by, $l, $rule, $day, $reason, $batch);
                $changed[] = (int) $l->id;
            }
            if ($newDefault) {
                $this->setDefault($by, $landlordId, $propertyId, $rule);
            }
            if ($changed === [] && ! $newDefault) {
                throw BusinessRuleException::make('nothing_to_change', 'No lease here can take this change from that day. Choose a later day, or tick a lease on a special rate.');
            }
            RentalAudit::record($cid, 'lease.commission_batch', null, 'Changed the commission on '.count($changed).' '.(count($changed) === 1 ? 'lease' : 'leases').' from '.$day->format('j M Y'),
                ['leases' => $changed, 'batch' => $batch, 'reason' => $reason, 'new_default' => $newDefault] + $rule, 'staff', $by->id);

            return ['changed' => $changed, 'batch' => $batch];
        });
    }

    /**
     * A lease's commission history, newest first: empty for a lease that never changed.
     *
     * @return Collection<int, RentalCommissionChange>
     */
    public static function history(RentalLease $lease): Collection
    {
        return RentalCommissionChange::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('lease_id', $lease->id)->orderByDesc('effective_from')->orderByDesc('id')->get();
    }

    /** Why a lease cannot change from this day, in words; null when it can. The same rule as rent: bills raised keep their terms. */
    public static function tooEarly(RentalLease $lease, Carbon $day): ?string
    {
        if ($lease->billed_until !== null && $day->lte($lease->billed_until)) {
            return 'Rent up to '.$lease->billed_until->format('j M Y').' is already billed. Choose a later date.';
        }
        if ($day->lt($lease->start_date)) {
            return 'The lease starts on '.$lease->start_date->format('j M Y').'. Choose that day or later.';
        }

        return null;
    }

    /**
     * Billing::ruleAt for many leases, in one read.
     *
     * @param  Collection<int, RentalLease>  $leases
     * @return array<int, array{commission_type: string, commission_bp: int, commission_minor: int}>
     */
    private static function rulesOn(Collection $leases, Carbon $day): array
    {
        $changes = $leases->isEmpty() ? collect() : RentalCommissionChange::withoutGlobalScopes()->where('company_id', $leases->first()->company_id)->whereIn('lease_id', $leases->map(fn (RentalLease $l) => (int) $l->id)->all())
            ->where('effective_from', '<=', $day->toDateString())->orderBy('effective_from')->orderBy('id')->get()->keyBy('lease_id'); // the latest of each lease wins
        $out = [];
        foreach ($leases as $l) {
            $src = $changes->get($l->id) ?? $l;
            $out[(int) $l->id] = ['commission_type' => (string) ($src->commission_type ?? 'none'), 'commission_bp' => (int) $src->commission_bp, 'commission_minor' => (int) $src->commission_minor];
        }

        return $out;
    }

    /**
     * Whether a lease is on a rule other than what its unit, property and landlord would give a new lease now.
     *
     * @param  array{commission_type: string, commission_bp: int, commission_minor: int}  $rule
     */
    private static function isSpecial(RentalLease $lease, array $rule, RentalSetting $s): bool
    {
        return $lease->property !== null && ! self::same(Billing::commissionFor($lease->property, $lease->landlord, $lease->unit, $s), $rule);
    }

    /** The commission on a month's rent under a rule (percent of rent, the flat fee, or none). */
    public static function monthly(array $rule, int $rent): int
    {
        return match ($rule['commission_type']) {
            'percent' => Minor::percent($rent, (int) $rule['commission_bp']),
            'flat' => min($rent, (int) $rule['commission_minor']),
            default => 0,
        };
    }

    /** @param array{commission_type: string, commission_bp: int, commission_minor: int} $a @param array{commission_type: string, commission_bp: int, commission_minor: int} $b */
    private static function same(array $a, array $b): bool
    {
        return $a['commission_type'] === $b['commission_type'] && match ($a['commission_type']) {
            'percent' => $a['commission_bp'] === $b['commission_bp'],
            'flat' => $a['commission_minor'] === $b['commission_minor'],
            default => true,
        };
    }

    /**
     * The live leases a change covers, within what this person may see.
     *
     * @return Collection<int, RentalLease>
     */
    private function leases(User $by, ?int $landlordId, ?int $propertyId): Collection
    {
        $cid = (int) $by->company_id;
        if ($landlordId === null && $propertyId === null) {
            throw BusinessRuleException::make('scope_required', 'Choose a property or a landlord first.');
        }
        if ($propertyId !== null) {
            RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->find($propertyId) ?? throw BusinessRuleException::make('not_found', 'That property was not found.');
            RentalAccess::assertInScope($by, $propertyId, 'property');
        }
        if ($landlordId !== null) {
            RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->where('is_self', false)->find($landlordId) ?? throw BusinessRuleException::make('not_found', 'That landlord was not found.');
        }

        return RentalAccess::scope(RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('status', RentalLease::LIVE), $by)
            ->when($propertyId !== null, fn ($q) => $q->where('property_id', $propertyId))
            ->when($landlordId !== null, fn ($q) => $q->where('landlord_id', $landlordId))
            ->with(['tenant:id,name', 'unit', 'property', 'landlord'])->orderBy('property_id')->orderBy('id')->get();
    }

    /** Append the change (inside the lock). The first one writes the lease's starting row, so its history reads from the beginning. */
    private function write(User $by, RentalLease $lease, array $rule, Carbon $day, string $reason, ?string $batch): RentalCommissionChange
    {
        $cid = (int) $lease->company_id;
        if (! RentalCommissionChange::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $lease->id)->exists()) {
            RentalCommissionChange::create(['company_id' => $cid, 'lease_id' => $lease->id, 'commission_type' => (string) $lease->commission_type, 'commission_bp' => (int) $lease->commission_bp,
                'commission_minor' => (int) $lease->commission_minor, 'effective_from' => $lease->start_date->toDateString(), 'reason' => 'Commission at move-in', 'created_by_id' => $by->id]);
        }
        $change = RentalCommissionChange::create(['company_id' => $cid, 'lease_id' => $lease->id, 'effective_from' => $day->toDateString(), 'reason' => $reason, 'batch_uuid' => $batch, 'created_by_id' => $by->id] + $rule);
        if ($day->lte(LocalDate::today($cid))) {
            $lease->forceFill($rule)->save();
        }
        RentalAudit::record($cid, 'lease.commission_changed', $lease, "Commission on lease {$lease->number} is ".Billing::ruleWords($cid, $rule['commission_type'], $rule['commission_bp'], $rule['commission_minor'])." from {$day->format('j M Y')}", $rule + ['reason' => $reason], 'staff', $by->id);

        return $change;
    }

    private function setDefault(User $by, ?int $landlordId, ?int $propertyId, array $rule): void
    {
        $cid = (int) $by->company_id;
        if ($propertyId !== null) {
            app(Properties::class)->update($by, RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($propertyId), $rule);
        } elseif ($landlordId !== null) {
            app(Landlords::class)->update($by, RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($landlordId), $rule);
        }
    }

    private function assertAgency(int $cid): void
    {
        if (RentalsModule::isOwnerMode($cid)) {
            throw BusinessRuleException::make('owner_mode', 'This account manages its own property: there is no commission to change.');
        }
    }

    /**
     * A rule, whole. @return array{commission_type: string, commission_bp: int, commission_minor: int}
     */
    private static function rule(array $r): array
    {
        $type = (string) ($r['commission_type'] ?? '');
        if ($type === 'percent') {
            $bp = Minor::nonNegative($r['commission_bp'] ?? null, 'commission rate');
            if ($bp > 10000) {
                throw BusinessRuleException::make('commission_invalid', 'A percent goes up to 100.');
            }

            return ['commission_type' => 'percent', 'commission_bp' => $bp, 'commission_minor' => 0];
        }
        if ($type === 'flat') {
            return ['commission_type' => 'flat', 'commission_bp' => 0, 'commission_minor' => Minor::nonNegative($r['commission_minor'] ?? null, 'commission')];
        }
        if ($type === 'none') {
            return ['commission_type' => 'none', 'commission_bp' => 0, 'commission_minor' => 0];
        }
        throw BusinessRuleException::make('commission_invalid', 'Commission is a percent up to 100, a flat amount, or none.');
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw BusinessRuleException::make('reason_required', 'Say why the commission changes.');
        }

        return mb_substr($reason, 0, 255);
    }
}
