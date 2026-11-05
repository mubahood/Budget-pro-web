<?php

namespace App\Services\Rentals\Landlords;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalCharge;
use App\Models\RentalEntry;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Models\RentalProperty;
use App\Models\RentalPropertyOwner;
use App\Models\User;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Giving a property to another landlord (LC-01). No money moves and no journal row is written: live leases and unpaid bills from the day on go to the new
 * landlord, and everything else stays. The old landlord keeps their balance, and keeps what is later collected on older or part-paid bills, because
 * allocations follow the bill's landlord. A day ahead waits, and the nightly run (RentalDaily) applies it when the day comes; until then bills raised for
 * periods from that day already carry the new landlord. Who owned what on a day comes from `rental_property_owners`; a property with no row there has
 * always been its current landlord's, so nothing needs a backfill.
 */
final class Handover
{
    /** The first owner's row starts here: "since the beginning". */
    public const BEGINNING = '2000-01-01';

    /**
     * What a handover would do, for the screen before anyone commits to it (no writes).
     *
     * @return array<string, mixed>
     */
    public function preview(User $by, RentalProperty $property, int $landlordId, ?string $from): array
    {
        RentalAccess::authorize($by, 'rentals_manage');
        self::assertOurs($by, $property);
        RentalAccess::assertInScope($by, (int) $property->id, 'property');
        $cid = (int) $property->company_id;
        $day = LocalDate::date($cid, $from);
        $to = $this->newLandlord($cid, $property, $landlordId);
        $old = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($property->landlord_id);
        $leases = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $property->id)->whereIn('status', RentalLease::LIVE)->with(['tenant:id,name', 'unit:id,code'])->orderBy('id')->get();
        $liveIds = $leases->map(fn (RentalLease $l) => (int) $l->id)->all();
        $open = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $property->id)->where('status', 'open')->where('kind', '!=', 'refund')->get();
        $moving = $open->filter(fn (RentalCharge $c) => self::billMoves($c, $liveIds, $day));
        $staying = $open->reject(fn (RentalCharge $c) => self::billMoves($c, $liveIds, $day));
        $held = Leases::depositsHeld($leases->all());
        $landlordHeld = [];
        $agencyHeld = 0;
        foreach ($leases as $l) {
            $h = (int) ($held[(int) $l->id] ?? 0);
            if ($h > 0 && $l->deposit_held_by === 'landlord') {
                $landlordHeld[] = ['lease_id' => (int) $l->id, 'number' => (string) $l->number, 'tenant' => (string) $l->tenant?->name, 'unit' => (string) $l->unit?->code, 'held_minor' => $h];
            } elseif ($h > 0) {
                $agencyHeld += $h;
            }
        }
        $credit = $liveIds === [] ? 0 : (int) RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $liveIds)->whereNull('reversed_at')->sum('unallocated_minor');
        $since = (int) RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $property->id)->where('landlord_id', $old->id)
            ->where('occurred_on', '>=', $day->toDateString())->sum('landlord_minor');

        return [
            'old' => ['id' => (int) $old->id, 'name' => (string) $old->name, 'balance_minor' => LandlordAccounts::balance($old)],
            'new' => ['id' => (int) $to->id, 'name' => (string) $to->name],
            'from' => $day->toDateString(), 'waits' => $day->gt(LocalDate::today($cid)),
            'leases' => $leases->map(fn (RentalLease $l) => ['lease_id' => (int) $l->id, 'number' => (string) $l->number, 'tenant' => (string) $l->tenant?->name, 'unit' => (string) $l->unit?->code])->values()->all(),
            'bills_move' => ['count' => $moving->count(), 'minor' => (int) $moving->sum(fn (RentalCharge $c) => $c->left())],
            'bills_stay' => ['count' => $staying->count(), 'minor' => (int) $staying->sum(fn (RentalCharge $c) => $c->left())],
            'landlord_deposits' => $landlordHeld, 'landlord_deposits_minor' => (int) array_sum(array_column($landlordHeld, 'held_minor')),
            'agency_deposits_minor' => $agencyHeld, 'credit_minor' => $credit, 'recorded_since_minor' => $since,
        ];
    }

    /**
     * Hand the property over (rentals_manage). $data: landlord_id, from (today by default; a day ahead waits for its day; a past day must be in an open
     * month, and after the current owner's own start), reason. Returns the new owner's row.
     */
    public function handOver(User $by, RentalProperty $property, array $data): RentalPropertyOwner
    {
        RentalAccess::authorize($by, 'rentals_manage');
        self::assertOurs($by, $property);
        RentalAccess::assertInScope($by, (int) $property->id, 'property');
        $cid = (int) $property->company_id;
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw BusinessRuleException::make('reason_required', 'Say why the property changes hands.');
        }
        $day = LocalDate::date($cid, $data['from'] ?? null);

        return RentalLedger::locked($cid, function () use ($by, $property, $data, $reason, $day, $cid) {
            $property = RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($property->id);
            $to = $this->newLandlord($cid, $property, (int) ($data['landlord_id'] ?? 0));
            if (($through = RentalLedger::closedThrough($cid)) !== null && $day->toDateString() <= $through) {
                $month = $day->format('F Y');
                throw BusinessRuleException::make('period_closed', "{$month} is closed. Choose a later day, or ask the account owner to reopen {$month}.");
            }
            if (self::scheduled($cid, (int) $property->id) !== null) {
                throw BusinessRuleException::make('handover_waiting', 'A handover of this property is already waiting for its day. Cancel it first.');
            }
            $current = RentalPropertyOwner::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $property->id)->whereNotNull('applied_at')->whereNull('to_date')->first();
            if ($current !== null && $day->lte($current->from_date)) {
                throw BusinessRuleException::make('from_too_early', 'This landlord has owned the property since '.$current->from_date->format('j M Y').'. Choose a later day.');
            }
            if ($current === null) {
                RentalPropertyOwner::create(['company_id' => $cid, 'property_id' => $property->id, 'landlord_id' => $property->landlord_id, 'from_date' => self::BEGINNING, 'applied_at' => now()]);
            }
            $row = RentalPropertyOwner::create(['company_id' => $cid, 'property_id' => $property->id, 'landlord_id' => $to->id, 'from_date' => $day->toDateString(),
                'reason' => mb_substr($reason, 0, 255), 'created_by_id' => $by->id]);
            if ($day->lte(LocalDate::today($cid))) {
                return $this->apply($row, $property, $by->id);
            }
            RentalAudit::record($cid, 'property.handover_waiting', $property, "{$property->name} goes to {$to->name} on {$day->format('j M Y')}", ['landlord_id' => (int) $to->id, 'reason' => $reason], 'staff', $by->id);

            return $row;
        });
    }

    /** Take back a handover still waiting for its day (rentals_manage). Nothing has moved. */
    public function cancelWaiting(User $by, RentalProperty $property): void
    {
        RentalAccess::authorize($by, 'rentals_manage');
        self::assertOurs($by, $property);
        RentalAccess::assertInScope($by, (int) $property->id, 'property');
        $cid = (int) $property->company_id;
        RentalLedger::locked($cid, function () use ($by, $property, $cid) {
            $row = self::scheduled($cid, (int) $property->id) ?? throw BusinessRuleException::make('nothing_waiting', 'No handover of this property is waiting.');
            $row->forceFill(['cancelled_at' => now(), 'cancelled_by_id' => $by->id])->save();
            RentalAudit::record($cid, 'property.handover_cancelled', $property, "Cancelled the handover of {$property->name} due on {$row->from_date->format('j M Y')}", [], 'staff', $by->id);
        });
    }

    /** The nightly run: apply the handovers whose day has come. Returns how many. */
    public function applyDue(int $companyId, ?Carbon $today = null): int
    {
        $today ??= LocalDate::today($companyId);
        $due = RentalPropertyOwner::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('applied_at')->whereNull('cancelled_at')->where('from_date', '<=', $today->toDateString())->orderBy('id')->get();
        foreach ($due as $row) {
            RentalLedger::locked($companyId, function () use ($row, $companyId) {
                $row = RentalPropertyOwner::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->findOrFail($row->id);
                if ($row->applied_at === null && $row->cancelled_at === null) {
                    $this->apply($row, RentalProperty::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->findOrFail($row->property_id), null);
                }
            });
        }

        return $due->count();
    }

    /** The handover of a property waiting for its day, if any. */
    public static function scheduled(int $companyId, int $propertyId): ?RentalPropertyOwner
    {
        return RentalPropertyOwner::withoutGlobalScopes()->where('company_id', $companyId)->where('property_id', $propertyId)->whereNull('applied_at')->whereNull('cancelled_at')->first();
    }

    /**
     * Who has owned a property, newest first. A property never handed over has no rows.
     *
     * @return Collection<int, RentalPropertyOwner>
     */
    public static function history(int $companyId, int $propertyId): Collection
    {
        return RentalPropertyOwner::withoutGlobalScopes()->where('company_id', $companyId)->where('property_id', $propertyId)->whereNotNull('applied_at')->with('landlord:id,name')->orderByDesc('from_date')->orderByDesc('id')->get();
    }

    /** Who owned a property on a day, from its history; its current landlord when it was never handed over. */
    public static function ownerOn(RentalProperty $property, string $day): int
    {
        $row = RentalPropertyOwner::withoutGlobalScopes()->where('company_id', $property->company_id)->where('property_id', $property->id)->whereNotNull('applied_at')
            ->where('from_date', '<=', $day)->orderByDesc('from_date')->orderByDesc('id')->first();

        return (int) ($row->landlord_id ?? $property->landlord_id);
    }

    /**
     * The properties a landlord owned on a day (a statement's occupancy reads this, so an old statement counts what they owned then).
     *
     * @return list<int>
     */
    public static function propertyIdsOn(int $companyId, int $landlordId, string $day): array
    {
        $handed = DB::table('rental_property_owners')->where('company_id', $companyId)->whereNotNull('applied_at')->distinct()->pluck('property_id');
        $owned = DB::table('rental_property_owners')->where('company_id', $companyId)->where('landlord_id', $landlordId)->whereNotNull('applied_at')
            ->where('from_date', '<=', $day)->where(fn ($q) => $q->whereNull('to_date')->orWhere('to_date', '>=', $day))->pluck('property_id');
        $never = DB::table('rental_properties')->where('company_id', $companyId)->where('landlord_id', $landlordId)->whereNotIn('id', $handed->all() ?: [0])->pluck('id');

        return array_values(array_unique(array_map('intval', $owned->concat($never)->all())));
    }

    /** A property of another account does not exist, for this person. */
    private static function assertOurs(User $by, RentalProperty $property): void
    {
        if ((int) $by->company_id !== (int) $property->company_id) {
            throw BusinessRuleException::make('not_found', 'That property was not found.');
        }
    }

    /** Whether the bill goes with the property: unpaid and open on a live lease, from the day on; and an unpaid deposit, which belongs to whoever owns the unit when it is paid. */
    private static function billMoves(RentalCharge $c, array $liveIds, Carbon $day): bool
    {
        if ($c->status !== 'open' || (int) $c->paid_minor !== 0 || ! in_array((int) $c->lease_id, $liveIds, true)) {
            return false;
        }

        return $c->kind === 'deposit' || ($c->period_start ?? $c->due_date)->gte($day);
    }

    /** Move what moves (inside the lock). The row's day has come. */
    private function apply(RentalPropertyOwner $row, RentalProperty $property, ?int $userId): RentalPropertyOwner
    {
        $cid = (int) $property->company_id;
        $to = (int) $row->landlord_id;
        $from = (int) $property->landlord_id;
        $leases = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $property->id)->whereIn('status', RentalLease::LIVE)->lockForUpdate()->get();
        $liveIds = $leases->map(fn (RentalLease $l) => (int) $l->id)->all();
        foreach ($leases as $l) {
            $l->forceFill(['landlord_id' => $to])->save();
        }
        $moved = 0;
        foreach (RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $property->id)->where('status', 'open')->where('paid_minor', 0)->where('kind', '!=', 'refund')->lockForUpdate()->get() as $c) {
            if ((int) $c->landlord_id !== $to && self::billMoves($c, $liveIds, $row->from_date)) {
                $c->forceFill(['landlord_id' => $to])->save();
                $moved++;
            }
        }
        RentalPropertyOwner::withoutGlobalScopes()->where('company_id', $cid)->where('property_id', $property->id)->whereNotNull('applied_at')->whereNull('to_date')->where('id', '!=', $row->id)
            ->update(['to_date' => $row->from_date->copy()->subDay()->toDateString(), 'updated_at' => now()]);
        $row->forceFill(['applied_at' => now()])->save();
        $property->forceFill(['landlord_id' => $to])->save();
        $names = DB::table('rental_landlords')->where('company_id', $cid)->whereIn('id', [$from, $to])->pluck('name', 'id');
        RentalAudit::record($cid, 'property.handed_over', $property, "Handed {$property->name} over from {$names[$from]} to {$names[$to]} from {$row->from_date->format('j M Y')}",
            ['from_landlord_id' => $from, 'to_landlord_id' => $to, 'from' => $row->from_date->toDateString(), 'leases' => count($liveIds), 'bills' => $moved, 'reason' => $row->reason], $userId ? 'staff' : 'system', $userId);

        return $row->fresh();
    }

    private function newLandlord(int $cid, RentalProperty $property, int $landlordId): RentalLandlord
    {
        if (RentalsModule::isOwnerMode($cid)) {
            throw BusinessRuleException::make('owner_mode', 'This account manages its own property: there is no other landlord to give it to.');
        }
        $to = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'active')->where('is_self', false)->find($landlordId)
            ?? throw BusinessRuleException::make('landlord_required', 'Choose the landlord who takes this property over.');
        if ((int) $to->id === (int) $property->landlord_id) {
            throw BusinessRuleException::make('same_landlord', 'This landlord already owns the property.');
        }

        return $to;
    }
}
