<?php

namespace App\Services\Rentals\Leases;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalAllocation;
use App\Models\RentalCharge;
use App\Models\RentalEntry;
use App\Models\RentalLease;
use App\Models\RentalLandlord;
use App\Models\RentalLeaseItem;
use App\Models\RentalPayment;
use App\Models\RentalProperty;
use App\Models\RentalRentChange;
use App\Models\RentalTenant;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\Directory\Tenants;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Meters;
use App\Services\Rentals\Payments\CashGuard;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use App\Support\Rentals\Periods;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Leases (docs/estate-ledger/04-architecture.md §5.7, §5.8): moving a tenant in, notice, moving out with the deposit
 * settled, cancelling a lease made by mistake, rent changes and fees beside rent; and a lease's position, which every
 * screen shows: what is owed and overdue, credit, the deposit held, "paid up to" and the next bill.
 */
final class Leases
{
    public function __construct(private Billing $billing, private Payments $payments) {}

    /**
     * Move a tenant in (rentals_manage). $data: unit_id, tenant_id or tenant (new tenant fields), start_date, rent_minor,
     * cycle_months, anchor, end_date, deposit_minor, deposit_held_by, commission (type, bp, minor), late_fee, items
     * [name, amount_minor, goes_to, commissionable], notes, first_payment (amount_minor, method, reference, paid_on),
     * part_rent_minor (the part-month rent typed by hand when the first period is a part month, EL-04: 0 charges none;
     * left out, it is calculated, moveInPreview's suggestion), origin (new, the default, or sitting: already living there when the agency took over, LC-22).
     */
    public function moveIn(User $by, array $data): RentalLease
    {
        RentalAccess::authorize($by, 'rentals_manage');
        $cid = (int) $by->company_id;
        $unit = RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->find((int) ($data['unit_id'] ?? 0))
            ?? throw BusinessRuleException::make('not_found', 'Choose the unit the tenant moves into.');
        RentalAccess::assertInScope($by, (int) $unit->property_id, 'unit');
        if ($unit->status === 'in_use') {
            throw BusinessRuleException::make('unit_unavailable', 'This unit is used by '.$unit->in_use_by.'. Mark it available first.');
        }
        if (in_array($unit->status, ['inactive', 'maintenance'], true)) {
            throw BusinessRuleException::make('unit_unavailable', $unit->status === 'maintenance' ? 'This unit is marked under repair. Mark it available first.' : 'This unit is switched off.');
        }
        $property = RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($unit->property_id);
        $s = RentalsModule::settings($cid);
        $start = LocalDate::date($cid, $data['start_date'] ?? null);
        $rent = Minor::positive($data['rent_minor'] ?? $unit->rent_minor, 'rent');
        $cycle = (int) ($data['cycle_months'] ?? $unit->cycle_months ?: 1);
        if (! in_array($cycle, [1, 3, 6, 12], true)) {
            throw BusinessRuleException::make('cycle_invalid', 'Rent is billed every 1, 3, 6 or 12 months.');
        }
        $anchor = in_array($data['anchor'] ?? $s->anchor, ['start', 'first'], true) ? ($data['anchor'] ?? $s->anchor) : 'start';
        $end = ! empty($data['end_date']) ? LocalDate::date($cid, $data['end_date']) : null;
        if ($end !== null && $end->lte($start)) {
            throw BusinessRuleException::make('end_invalid', 'The end date must be after the start date.');
        }
        $deposit = array_key_exists('deposit_minor', $data) && $data['deposit_minor'] !== null && $data['deposit_minor'] !== ''
            ? Minor::nonNegative($data['deposit_minor'], 'deposit') : (int) ($unit->deposit_minor ?? $rent * (int) $s->deposit_months);
        $heldBy = in_array($data['deposit_held_by'] ?? null, ['agency', 'landlord'], true) ? $data['deposit_held_by'] : ($property->deposit_held_by ?? $s->deposit_held_by);
        if ($s->mode === 'owner') {
            $heldBy = 'agency'; // the owner holds it: one set of books
        }
        $commission = Billing::commissionFor($property, $property->landlord_id ? RentalLandlord::withoutGlobalScopes()->find($property->landlord_id) : null, $unit);
        if (isset($data['commission']['type']) && $s->mode !== 'owner') {
            $commission = ['commission_type' => (string) $data['commission']['type'], 'commission_bp' => Minor::nonNegative($data['commission']['bp'] ?? 0, 'commission rate'),
                'commission_minor' => Minor::nonNegative($data['commission']['minor'] ?? 0, 'commission')];
            if (! in_array($commission['commission_type'], ['percent', 'flat', 'none'], true) || $commission['commission_bp'] > 10000) {
                throw BusinessRuleException::make('commission_invalid', 'Commission is a percent up to 100, a flat amount, or none.');
            }
        }
        $origin = (string) ($data['origin'] ?? 'new');
        if (! in_array($origin, RentalLease::ORIGINS, true)) {
            throw BusinessRuleException::make('origin_invalid', 'A tenant is new, or was already living there.');
        }

        return RentalLedger::locked($cid, function () use ($by, $data, $cid, $unit, $property, $start, $rent, $cycle, $anchor, $end, $deposit, $heldBy, $commission, $origin) {
            $unit = RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($unit->id);
            if (RentalLease::withoutGlobalScopes()->where('company_id', $cid)->where('unit_id', $unit->id)->whereIn('status', RentalLease::LIVE)->exists()) {
                throw BusinessRuleException::make('unit_taken', "Unit {$unit->code} already has a tenant. Move them out first.");
            }
            $tenant = isset($data['tenant_id'])
                ? RentalTenant::withoutGlobalScopes()->where('company_id', $cid)->find((int) $data['tenant_id']) ?? throw BusinessRuleException::make('not_found', 'That tenant was not found.')
                : app(Tenants::class)->create($by, (array) ($data['tenant'] ?? []));
            $lease = RentalLease::create([
                'company_id' => $cid, 'number' => RentalsModule::nextNumber($cid, 'lease'), 'unit_id' => $unit->id, 'property_id' => $property->id,
                'landlord_id' => $property->landlord_id, 'tenant_id' => $tenant->id, 'start_date' => $start->toDateString(), 'end_date' => $end?->toDateString(),
                'cycle_months' => $cycle, 'anchor' => $anchor, 'rent_minor' => $rent, 'deposit_minor' => $deposit, 'deposit_held_by' => $heldBy,
                'late_fee' => ($data['late_fee'] ?? 'default') === 'off' ? 'off' : 'default', 'status' => 'active', 'origin' => $origin,
                'notes' => isset($data['notes']) ? mb_substr((string) $data['notes'], 0, 2000) : null, 'created_by_id' => $by->id,
            ] + $commission);
            RentalRentChange::create(['company_id' => $cid, 'lease_id' => $lease->id, 'rent_minor' => $rent, 'effective_from' => $start->toDateString(),
                'reason' => 'Rent at move-in', 'created_by_id' => $by->id]);
            foreach ((array) ($data['items'] ?? []) as $item) {
                $this->createItem($lease, (array) $item, $start, $by->id);
            }
            $import = isset($data['import']['as_of']) ? LocalDate::date($cid, $data['import']['as_of']) : null;
            if ($import !== null) {
                // A tenancy brought in from another system (KR-22): everything up to the period running on the import
                // day was billed there; Estate Ledger bills from the next period. Opening owed, credit and deposit come after.
                $p = Periods::containing($start, $cycle, $anchor, $import);
                $lease->forceFill(['billed_until' => ($p['end'] ?? $import->copy()->subDay())->toDateString()])->save();
            }
            if ($deposit > 0 && $import === null) {
                $this->billing->makeFor($lease, ['kind' => 'deposit', 'description' => 'Security deposit', 'due_date' => $start->toDateString(),
                    'amount_minor' => $deposit, 'goes_to' => 'deposit', 'dedupe_key' => "deposit:{$lease->id}", 'created_by_id' => $by->id]
                    + Billing::commission($lease, false));
            }
            if ($unit->status === 'reserved') {
                $unit->forceFill(['status' => 'available'])->save();
            }
            if ($tenant->status !== 'active') {
                $tenant->forceFill(['status' => 'active'])->save();
            }
            if ($import !== null) {
                $this->importOpening($by, $lease, (array) $data['import'], $import);
            }
            if ($import === null && isset($data['part_rent_minor']) && $data['part_rent_minor'] !== '') {
                $this->typedPartRent($by, $lease, $data['part_rent_minor']);
            }
            $this->billing->raise($lease);
            if (! empty($data['first_payment']['amount_minor']) && $import === null) {
                $this->payments->receive($by, $lease, (array) $data['first_payment']);
            }
            RentalAudit::record($cid, 'lease.moved_in', $lease, "Moved {$tenant->name} into {$unit->code}, {$property->name}", ['rent_minor' => $rent, 'deposit_minor' => $deposit], 'staff', $by->id);

            return $lease->fresh();
        });
    }

    /** The tenant gave notice: billing stops after the planned move-out day. */
    public function notice(User $by, RentalLease $lease, ?string $noticeOn, string $moveOutOn): RentalLease
    {
        RentalAccess::authorize($by, 'rentals_manage');
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        $cid = (int) $lease->company_id;
        $out = LocalDate::date($cid, $moveOutOn);
        if (! $lease->isLive()) {
            throw BusinessRuleException::make('lease_not_live', 'This lease has already ended.');
        }
        if ($out->lt($lease->start_date)) {
            throw BusinessRuleException::make('date_invalid', 'The move-out day is before the lease started.');
        }

        return RentalLedger::locked($cid, function () use ($by, $lease, $noticeOn, $out, $cid) {
            $lease->forceFill(['status' => 'notice', 'notice_on' => LocalDate::date($cid, $noticeOn)->toDateString(), 'move_out_on' => $out->toDateString()])->save();
            RentalAudit::record($cid, 'lease.notice', $lease, "Notice on lease {$lease->number}: moving out on {$out->format('j M Y')}", [], 'staff', $by->id);

            return $lease->fresh();
        });
    }

    public function withdrawNotice(User $by, RentalLease $lease): RentalLease
    {
        RentalAccess::authorize($by, 'rentals_manage');
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        if ($lease->status !== 'notice') {
            throw BusinessRuleException::make('no_notice', 'This lease is not under notice.');
        }

        return RentalLedger::locked((int) $lease->company_id, function () use ($by, $lease) {
            $lease->forceFill(['status' => 'active', 'notice_on' => null, 'move_out_on' => null])->save();
            $this->billing->raise($lease);
            RentalAudit::record((int) $lease->company_id, 'lease.notice_withdrawn', $lease, "Notice withdrawn on lease {$lease->number}", [], 'staff', $by->id);

            return $lease->fresh();
        });
    }

    /**
     * Renew a fixed term (rentals_manage): a later end date, or none (month to month). A term that ran out while the
     * tenant stayed is shown as "term ended" until it is renewed or the tenant moves out; renewing bills what is due.
     */
    public function renew(User $by, RentalLease $lease, ?string $endDate): RentalLease
    {
        RentalAccess::authorize($by, 'rentals_manage');
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        $cid = (int) $lease->company_id;
        if (! $lease->isLive()) {
            throw BusinessRuleException::make('lease_not_live', 'This lease has ended.');
        }
        $end = $endDate ? LocalDate::date($cid, $endDate) : null;
        if ($end !== null && ($end->lte($lease->start_date) || ($lease->end_date !== null && $end->lt($lease->end_date)))) {
            throw BusinessRuleException::make('end_invalid', 'Choose an end date after the current one, or none for month to month.');
        }

        return RentalLedger::locked($cid, function () use ($by, $lease, $end, $cid) {
            $lease->forceFill(['end_date' => $end?->toDateString()])->save();
            $this->billing->raise($lease);
            RentalAudit::record($cid, 'lease.renewed', $lease, "Renewed lease {$lease->number}".($end ? ' to '.$end->format('j M Y') : ': month to month'), [], 'staff', $by->id);

            return $lease->fresh();
        });
    }

    /** A live lease whose fixed term has run out (the tenant stayed): renew it or move them out. */
    public static function termEnded(RentalLease $lease, ?Carbon $today = null): bool
    {
        return $lease->isLive() && $lease->end_date !== null && $lease->end_date->lt($today ?? LocalDate::today((int) $lease->company_id));
    }

    /** A new rent from the start of a period not yet billed (rentals_bill). Bills already raised keep their amount. */
    public function changeRent(User $by, RentalLease $lease, int $rent, string $from, ?string $reason = null): RentalRentChange
    {
        RentalAccess::authorize($by, 'rentals_bill');
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        $cid = (int) $lease->company_id;
        Minor::positive($rent, 'rent');
        $date = LocalDate::date($cid, $from);
        if (! $lease->isLive()) {
            throw BusinessRuleException::make('lease_not_live', 'This lease has ended.');
        }
        if ($lease->billed_until !== null && $date->lte($lease->billed_until)) {
            throw BusinessRuleException::make('period_billed', 'Rent up to '.$lease->billed_until->format('j M Y').' is already billed. Choose a later date, or forgive part of a bill.');
        }

        return RentalLedger::locked($cid, function () use ($by, $lease, $rent, $date, $reason, $cid) {
            $change = RentalRentChange::create(['company_id' => $cid, 'lease_id' => $lease->id, 'rent_minor' => $rent, 'effective_from' => $date->toDateString(),
                'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null, 'created_by_id' => $by->id]);
            if ($date->lte(LocalDate::today($cid))) {
                $lease->forceFill(['rent_minor' => $rent])->save();
            }
            $this->billing->raise($lease);
            RentalAudit::record($cid, 'lease.rent_changed', $lease, "Rent on lease {$lease->number} changes to ".Minor::format($rent, $cid)." from {$date->format('j M Y')}", ['rent_minor' => $rent], 'staff', $by->id);

            return $change;
        });
    }

    /** A fee beside rent from a date (rentals_bill): billed with each period from then on. */
    public function addItem(User $by, RentalLease $lease, array $data): RentalLeaseItem
    {
        RentalAccess::authorize($by, 'rentals_bill');
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        if (! $lease->isLive()) {
            throw BusinessRuleException::make('lease_not_live', 'This lease has ended.');
        }
        $cid = (int) $lease->company_id;
        $from = isset($data['starts_on']) ? LocalDate::date($cid, $data['starts_on']) : ($lease->billed_until?->copy()->addDay() ?? $lease->start_date);

        return RentalLedger::locked($cid, function () use ($by, $lease, $data, $from, $cid) {
            $item = $this->createItem($lease, $data, $from, $by->id);
            $this->billing->raise($lease);
            RentalAudit::record($cid, 'lease.item_added', $lease, "Added {$item->name} to lease {$lease->number}", ['amount_minor' => $item->amount_minor], 'staff', $by->id);

            return $item;
        });
    }

    public function endItem(User $by, RentalLeaseItem $item, ?string $endsOn = null): RentalLeaseItem
    {
        RentalAccess::authorize($by, 'rentals_bill');
        $cid = (int) $item->company_id;
        $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($item->lease_id);
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        $end = isset($endsOn) ? LocalDate::date($cid, $endsOn) : ($lease->billed_until ?? LocalDate::today($cid));
        $item->forceFill(['ends_on' => $end->toDateString(), 'active' => $end->gte(LocalDate::today($cid))])->save();
        RentalAudit::record($cid, 'lease.item_ended', $lease, "Stopped {$item->name} on lease {$lease->number}", [], 'staff', $by->id);

        return $item->fresh();
    }

    /**
     * Move out (rentals_manage, §5.7). $data: date, readings [utility, reading], damages [description, amount_minor,
     * goes_to], unused_days (bool: forgive the rent for the days after the move-out in its period), unused_minor (the
     * amount to forgive typed by hand, EL-04: it replaces the calculation), refund_deposit (method or null to keep it),
     * refund_credit (method or null), reason.
     *
     * @return array{lease: RentalLease, settlement: array<string, int>}
     */
    public function moveOut(User $by, RentalLease $lease, array $data): array
    {
        RentalAccess::authorize($by, 'rentals_manage');
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        $cid = (int) $lease->company_id;
        $day = LocalDate::date($cid, $data['date'] ?? null);
        $today = LocalDate::today($cid);
        if (! $lease->isLive()) {
            throw BusinessRuleException::make('lease_not_live', 'This lease has already ended.');
        }
        if ($day->gt($today)) {
            throw BusinessRuleException::make('date_in_future', 'Record the move-out on the day it happens (or after).');
        }
        if ($day->lt($lease->start_date)) {
            throw BusinessRuleException::make('date_invalid', 'The move-out day is before the lease started.');
        }

        return RentalLedger::locked($cid, function () use ($by, $lease, $data, $cid, $day, $today) {
            RentalLedger::assertOpen($cid, $day->toDateString()); // its money posts today; the day the tenant left must not land in a closed month
            $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($lease->id);
            $this->assertRefundsCovered($lease, $data, $cid);
            $why = 'Moved out on '.$day->format('j M Y');
            // 1. Bills for periods that start on or after the move-out day: taken back (credit) and cancelled.
            foreach (RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $lease->id)->where('status', '!=', 'void')
                ->whereIn('kind', ['rent', 'item'])->where('period_start', '>=', $day->toDateString())->get() as $c) {
                if ((int) $c->paid_minor > 0) {
                    $this->payments->unapply($c, (int) $c->paid_minor, $today, $by->id, $why);
                }
                // What was forgiven on a bill that no longer stands is no longer forgiven: nothing moves, the bill just goes.
                foreach (RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->where('waived_charge_id', $c->id)->where('method', 'waiver')->whereNull('reversed_at')->get() as $w) {
                    $this->payments->reverseWaiver($w, $why, $by->id);
                }
                $this->billing->voidCharge($c->fresh(), $why, $by->id);
            }
            // 2. Unused days of the period the move-out falls in.
            $forgiven = 0;
            $typed = self::typedUnused($data);
            if (! empty($data['unused_days']) || $typed !== null) {
                foreach ($this->unusedPlan($lease, $day, $typed) as [$c, $unused]) {
                    $paidPart = max(0, $unused - $c->left());
                    if ($paidPart > 0) {
                        $this->payments->unapply($c, $paidPart, $today, $by->id, $why.': unused days');
                    }
                    $this->payments->waiveCharge($c->fresh(), $unused, $why.': unused days', $by->id, $today);
                    $forgiven += $unused;
                }
            }
            // 3. Final meter readings and damages.
            foreach ((array) ($data['readings'] ?? []) as $r) {
                if (isset($r['utility'], $r['reading']) && $r['reading'] !== '') {
                    app(Meters::class)->record($by, ['unit_id' => $lease->unit_id, 'utility' => $r['utility'], 'reading' => $r['reading'], 'read_on' => $day->toDateString(), 'notes' => 'Move-out reading']);
                }
            }
            foreach ((array) ($data['damages'] ?? []) as $d) {
                if (! empty($d['amount_minor'])) {
                    $this->billing->charge($by, $lease, ['description' => (string) ($d['description'] ?? 'Damage at move-out'), 'amount_minor' => $d['amount_minor'],
                        'goes_to' => $d['goes_to'] ?? 'landlord', 'due_date' => $day->toDateString()], 'move_out');
                }
            }
            $this->payments->applyCredit($lease, $today, $by->id);
            // 4. The deposit settles what is still owed; the rest is refunded or kept to refund later.
            $owed = Payments::openCharges($lease)->reject(fn ($c) => $c->kind === 'deposit')->sum(fn ($c) => $c->left());
            $held = Deposits::held($lease);
            $used = $this->payments->useDeposit($lease, min($held, (int) $owed), $today, $by->id);
            // An unpaid deposit bill is no longer owed once the tenant leaves.
            foreach (RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $lease->id)->where('kind', 'deposit')->where('status', 'open')->get() as $c) {
                if ((int) $c->paid_minor === 0) {
                    $this->billing->voidCharge($c, $why.': deposit not paid', $by->id);
                } else {
                    $this->payments->waiveCharge($c, $c->left(), $why.': rest of the deposit not paid', $by->id, $today);
                }
            }
            $depositBack = 0;
            if (! empty($data['refund_deposit']) && ($left = Deposits::held($lease)) > 0) {
                Deposits::refund($by, $lease, $left, (string) $data['refund_deposit'], $today);
                $depositBack = $left;
            }
            $creditBack = 0;
            if (! empty($data['refund_credit']) && ($credit = Payments::credit($lease)) > 0) {
                $this->payments->refundCredit($by, $lease, $credit, (string) $data['refund_credit'], $today, 'Credit refunded at move-out');
                $creditBack = $credit;
            }
            // 5. The lease ends; the tenant is a former tenant unless they rent another unit.
            $lease->forceFill(['status' => 'ended', 'ended_on' => $day->toDateString(), 'ended_by_id' => $by->id,
                'end_reason' => isset($data['reason']) ? mb_substr((string) $data['reason'], 0, 255) : null])->save();
            $still = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->where('tenant_id', $lease->tenant_id)->whereIn('status', RentalLease::LIVE)->exists();
            if (! $still) {
                RentalTenant::withoutGlobalScopes()->where('company_id', $cid)->whereKey($lease->tenant_id)->update(['status' => 'former', 'updated_at' => now()]);
            }
            $position = self::position($lease->fresh());
            $settlement = ['forgiven_minor' => $forgiven, 'deposit_used_minor' => (int) ($used?->amount_minor ?? 0), 'deposit_refunded_minor' => $depositBack,
                'deposit_kept_minor' => Deposits::held($lease), 'credit_refunded_minor' => $creditBack, 'credit_kept_minor' => $position['credit_minor'],
                'still_owed_minor' => $position['owed_minor']];
            RentalAudit::record($cid, 'lease.moved_out', $lease, "Lease {$lease->number} ended: moved out on {$day->format('j M Y')}", $settlement, 'staff', $by->id);

            return ['lease' => $lease->fresh(), 'settlement' => $settlement];
        });
    }

    /**
     * The refunds chosen must be in the account they go out of, or the move-out is refused whole, before anything is
     * written, in words that give the ways through (§5.10; the deposit or credit can be kept and refunded later).
     */
    private function assertRefundsCovered(RentalLease $lease, array $data, int $cid): void
    {
        if (empty($data['refund_deposit']) && empty($data['refund_credit'])) {
            return;
        }
        $will = $this->moveOutPreview($lease, $data);
        $need = [];
        foreach (['refund_deposit' => 'deposit_refunded_minor', 'refund_credit' => 'credit_refunded_minor'] as $choice => $figure) {
            if (! empty($data[$choice]) && (int) $will[$figure] > 0) {
                $need[(string) $data[$choice]] = ($need[(string) $data[$choice]] ?? 0) + (int) $will[$figure];
            }
        }
        foreach ($need as $method => $amount) {
            if (! in_array($method, RentalPayment::CASH_METHODS, true)) {
                continue; // the refund itself says which methods there are
            }
            try {
                CashGuard::assertCovers($cid, $method, $amount);
            } catch (BusinessRuleException $e) {
                $label = CashGuard::LABELS[$method];
                throw BusinessRuleException::make('insufficient_cash', 'The move-out was not recorded. Refunding '.Minor::format($amount, $cid)." needs that much in {$label}, and only "
                    .Minor::format(max(0, (int) ($e->meta()['available_minor'] ?? 0)), $cid).' is recorded there. Move money there first, choose another account, or move the tenant out without the refund and refund later.', $e->meta());
            }
        }
    }

    /**
     * What moveOut() would do with these choices, for the screen before anyone commits to it: the same steps on the
     * lease's bills in memory, no writes. The figures are the settlement's own names, plus what led to them.
     *
     * @return array<string, mixed>
     */
    public function moveOutPreview(RentalLease $lease, array $data): array
    {
        $cid = (int) $lease->company_id;
        $day = LocalDate::date($cid, $data['date'] ?? null);
        $charges = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $lease->id)->where('status', '!=', 'void')->where('kind', '!=', 'refund')->get();
        $credit = Payments::credit($lease);
        $held = Deposits::held($lease);
        // 1. Bills for periods from the move-out day on: what was paid goes back to credit, and the bills are cancelled.
        $cancelled = 0;
        $takenBack = 0;
        $bills = [];
        $back = ['landlords' => [], 'commission' => 0, 'agency' => 0, 'direct' => null];
        foreach ($charges as $c) {
            if (in_array($c->kind, ['rent', 'item'], true) && $c->period_start !== null && $c->period_start->gte($day)) {
                $cancelled += (int) $c->amount_minor;
                // A forgiven part of it just goes with the bill: only money comes back.
                $money = (int) $c->paid_minor - (int) RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->where('waived_charge_id', $c->id)->where('method', 'waiver')->whereNull('reversed_at')->sum('amount_minor');
                $takenBack += $money;
                $this->takesBack($c, $money, $back);

                continue;
            }
            $bills[(int) $c->id] = ['kind' => (string) $c->kind, 'due' => $c->due_date->toDateString(), 'pri' => RentalCharge::PRIORITY[$c->kind] ?? 9, 'left' => $c->left(), 'paid' => (int) $c->paid_minor];
        }
        $credit += $takenBack;
        // 2. The unused days of the period the move-out falls in.
        $suggested = (int) collect($this->unusedPlan($lease, $day, null))->sum(fn ($row) => $row[1]);
        $typed = self::typedUnused($data);
        $forgiven = 0;
        if (! empty($data['unused_days']) || $typed !== null) {
            foreach ($this->unusedPlan($lease, $day, $typed) as [$c, $unused]) {
                $b = &$bills[(int) $c->id];
                $paidPart = max(0, $unused - $b['left']);
                $credit += $paidPart;
                $this->takesBack($c, $paidPart, $back);
                $b['left'] = $b['left'] + $paidPart - $unused;
                $b['paid'] += $unused - $paidPart;
                $forgiven += $unused;
                unset($b);
            }
        }
        // 3. Final readings and damages become bills due on the day.
        $dueDay = $day->toDateString();
        $readings = [];
        $unit = RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->find($lease->unit_id);
        foreach ((array) ($data['readings'] ?? []) as $i => $r) {
            if (isset($r['utility'], $r['reading']) && $r['reading'] !== '' && $unit !== null) {
                $a = Meters::estimate($cid, $unit, (string) $r['utility'], $r['reading']);
                $readings[(string) $r['utility']] = $a;
                if ($a > 0) {
                    $bills['r'.$i] = ['kind' => 'utility', 'due' => $dueDay, 'pri' => RentalCharge::PRIORITY['utility'], 'left' => $a, 'paid' => 0];
                }
            }
        }
        $damages = 0;
        foreach ((array) ($data['damages'] ?? []) as $i => $d) {
            if (! empty($d['amount_minor'])) {
                $damages += (int) $d['amount_minor'];
                $bills['d'.$i] = ['kind' => 'one_off', 'due' => $dueDay, 'pri' => RentalCharge::PRIORITY['one_off'], 'left' => (int) $d['amount_minor'], 'paid' => 0];
            }
        }
        // Credit settles the open bills, oldest first (a deposit bill too, as applyCredit does).
        $settles = false;
        uasort($bills, fn ($a, $b) => [$a['due'], $a['pri']] <=> [$b['due'], $b['pri']]);
        foreach ($bills as $k => $b) {
            $a = min($credit, $b['left']);
            if ($a > 0) {
                $settles = $settles || $b['kind'] !== 'deposit';
                $bills[$k]['left'] -= $a;
                $bills[$k]['paid'] += $a;
                $credit -= $a;
                if ($b['kind'] === 'deposit') {
                    $held += $a;
                }
            }
        }
        // 4. The deposit settles what is owed; an unpaid deposit bill is no longer owed; the rest goes back or stays.
        $owed = (int) collect($bills)->reject(fn ($b) => $b['kind'] === 'deposit')->sum('left');
        $used = min($held, $owed);
        $left = $held - $used;
        $depositBack = ! empty($data['refund_deposit']) ? $left : 0;
        $creditBack = ! empty($data['refund_credit']) ? $credit : 0;
        $names = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', array_keys($back['landlords']) ?: [0])->get();
        $landlords = [];
        foreach ($names as $l) {
            $minor = (int) $back['landlords'][(int) $l->id];
            if ($minor !== 0) {
                $landlords[] = ['landlord_id' => (int) $l->id, 'name' => (string) $l->name, 'is_self' => (bool) $l->is_self, 'taken_back_minor' => $minor, 'balance_after_minor' => LandlordAccounts::balance($l) - $minor];
            }
        }

        return [
            'landlords_back' => $landlords, 'commission_back_minor' => $back['commission'], 'agency_back_minor' => $back['agency'], 'settles_more' => $settles || $used > 0,
            'blocked' => $back['direct'] !== null ? Payments::directSettledWords((string) $back['direct']) : null,
            'cancelled_minor' => $cancelled, 'taken_back_minor' => $takenBack, 'unused_suggested_minor' => $suggested, 'readings' => $readings,
            'readings_minor' => array_sum($readings), 'damages_minor' => $damages, 'owed_minor' => $owed, 'deposit_held_minor' => $held,
            'forgiven_minor' => $forgiven, 'deposit_used_minor' => $used, 'deposit_refunded_minor' => $depositBack, 'deposit_kept_minor' => $left - $depositBack,
            'credit_refunded_minor' => $creditBack, 'credit_kept_minor' => $credit - $creditBack, 'still_owed_minor' => $owed - $used,
        ];
    }

    /**
     * What Payments::unapply would take back off a bill, without doing it: the same walk, newest allocation first, so the words at move-out are the
     * amounts the journal will show. Waivers and deposit uses are never turned into credit.
     *
     * @param  array{landlords: array<int, int>, commission: int, agency: int, direct: ?string}  $back
     */
    private function takesBack(RentalCharge $c, int $amount, array &$back): void
    {
        $todo = min($amount, (int) $c->paid_minor);
        if ($todo <= 0) {
            return;
        }
        $cid = (int) $c->company_id;
        $allocs = RentalAllocation::withoutGlobalScopes()->where('company_id', $cid)->where('charge_id', $c->id)->whereNull('reverses_id')->whereNull('reversed_at')->where('amount_minor', '>', 0)->orderByDesc('id')->get();
        $methods = RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $allocs->pluck('payment_id')->all() ?: [0])->pluck('method', 'id');
        $direct = null;
        foreach ($allocs as $a) {
            if ($todo <= 0) {
                break;
            }
            if (($methods[$a->payment_id] ?? null) === RentalPayment::DIRECT) {
                $direct = (int) $a->payment_id;

                continue;
            }
            if (in_array($methods[$a->payment_id] ?? 'waiver', ['waiver', 'deposit'], true)) {
                continue;
            }
            $x = min($todo, (int) $a->amount_minor);
            $part = fn (int $v) => $x === (int) $a->amount_minor ? $v : Minor::share($v, $x, (int) $a->amount_minor);
            $commission = $part((int) $a->commission_minor);
            $agency = $part((int) $a->agency_minor);
            $back['commission'] += $commission;
            $back['agency'] += $agency;
            $back['landlords'][(int) $c->landlord_id] = ($back['landlords'][(int) $c->landlord_id] ?? 0) + $x - $commission - $agency - $part((int) $a->deposit_minor);
            $todo -= $x;
        }
        if ($todo > 0 && $direct !== null) {
            $back['direct'] = (string) RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->whereKey($direct)->value('number'); // what moveOut() would refuse (Payments::unapply)
        }
    }

    /**
     * The bills a move-in would raise at once, for the screen before anyone commits to it (no writes): the deposit, then
     * rent and fees for each period up to the billing horizon. A first period that is a part month is `part`, with its
     * calculated amount as the suggestion a person may replace (part_rent_minor).
     *
     * @return array{lines: list<array{kind: string, description: string, due_date: string, amount_minor: int}>, total_minor: int,
     *               part: ?array{start: string, end: string, days: int, month_days: int, suggested_minor: int}}
     */
    public static function moveInPreview(int $companyId, array $data): array
    {
        $s = RentalsModule::settings($companyId);
        $start = LocalDate::date($companyId, $data['start_date'] ?? null);
        $rent = max(0, (int) ($data['rent_minor'] ?? 0));
        $cycle = in_array((int) ($data['cycle_months'] ?? 1), [1, 3, 6, 12], true) ? (int) ($data['cycle_months'] ?? 1) : 1;
        $anchor = in_array($data['anchor'] ?? null, ['start', 'first'], true) ? (string) $data['anchor'] : (string) $s->anchor;
        $stop = ! empty($data['end_date']) ? LocalDate::date($companyId, $data['end_date'])->addDay() : null;
        $typed = isset($data['part_rent_minor']) && $data['part_rent_minor'] !== '' ? max(0, (int) $data['part_rent_minor']) : null;
        $lines = [];
        if (($deposit = (int) ($data['deposit_minor'] ?? 0)) > 0) {
            $lines[] = ['kind' => 'deposit', 'description' => 'Security deposit', 'due_date' => $start->toDateString(), 'amount_minor' => $deposit];
        }
        $first = Periods::between($start, $cycle, $anchor, $start, $start)[0] ?? null;
        $part = null;
        if ($first !== null && $first['part']) {
            $part = ['start' => $first['start']->toDateString(), 'end' => $first['end']->toDateString(), 'days' => $first['days'], 'month_days' => $first['month_days'],
                'suggested_minor' => $s->prorate_first ? Minor::share($rent, $first['days'], $first['month_days']) : $rent];
        }
        $horizon = LocalDate::today($companyId)->addDays((int) $s->bill_days_before);
        $periods = Periods::between($start, $cycle, $anchor, $start, $horizon, $stop);
        if ($part !== null && $typed !== null && ($periods[0]['part'] ?? false) === false) {
            array_unshift($periods, $first); // a typed part-month bill is made at once, however far ahead the move-in is
        }
        foreach ($periods as $p) {
            $label = Billing::periodLabel($p['start'], $p['end']);
            $amount = $p['part'] ? ($typed ?? (int) $part['suggested_minor']) : $rent * $cycle;
            if ($amount > 0) {
                $lines[] = ['kind' => 'rent', 'description' => "Rent {$label}", 'due_date' => $p['start']->toDateString(), 'amount_minor' => $amount];
            }
            foreach ((array) ($data['items'] ?? []) as $item) {
                $base = (int) ($item['amount_minor'] ?? 0);
                $amt = $p['part'] ? ($s->prorate_first ? Minor::share($base, $p['days'], $p['month_days']) : $base) : $base * $cycle;
                if ($amt > 0) {
                    $lines[] = ['kind' => 'item', 'description' => trim((string) ($item['name'] ?? '')).' '.$label, 'due_date' => $p['start']->toDateString(), 'amount_minor' => $amt];
                }
            }
        }

        return ['lines' => $lines, 'total_minor' => (int) array_sum(array_column($lines, 'amount_minor')), 'part' => $part];
    }

    /** Cancel a lease made by mistake (no money recorded on it): its bills are cancelled and the unit is free. */
    public function cancel(User $by, RentalLease $lease, string $reason): RentalLease
    {
        RentalAccess::authorize($by, 'rentals_manage');
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        $cid = (int) $lease->company_id;

        return RentalLedger::locked($cid, function () use ($by, $lease, $reason, $cid) {
            $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($lease->id);
            if (! $lease->isLive()) {
                throw BusinessRuleException::make('lease_not_live', 'Only a current lease can be cancelled.');
            }
            if (RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $lease->id)->whereNull('reversed_at')->exists()) {
                throw BusinessRuleException::make('lease_has_payments', 'Money is recorded on this lease. Reverse the payments first, or move the tenant out.');
            }
            foreach (RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $lease->id)->where('status', '!=', 'void')->get() as $c) {
                $this->billing->voidCharge($c, 'Lease cancelled: '.$reason, $by->id);
            }
            $lease->forceFill(['status' => 'cancelled', 'ended_on' => LocalDate::today($cid)->toDateString(), 'ended_by_id' => $by->id, 'end_reason' => mb_substr($reason, 0, 255)])->save();
            RentalAudit::record($cid, 'lease.cancelled', $lease, "Cancelled lease {$lease->number}", ['reason' => $reason], 'staff', $by->id);

            return $lease->fresh();
        });
    }

    /**
     * Where a lease stands (04 §9.1 Position). For many leases, positions(): same rule, a few reads whatever their number.
     *
     * @return array{owed_minor: int, overdue_minor: int, overdue_days: int, credit_minor: int, deposit_held_minor: int, paid_up_to: ?string, next_due: ?array{date: string, amount_minor: int}, state: string}
     */
    public static function position(RentalLease $lease, ?Carbon $today = null): array
    {
        $cid = (int) $lease->company_id;
        $credit = Payments::credit($lease);

        return self::positionFrom($lease, Payments::openCharges($lease, false), $today ?? LocalDate::today($cid), (int) RentalsModule::settings($cid)->grace_days, $credit,
            Deposits::held($lease), self::paidUpTo($lease, $credit), fn () => self::nextBill($lease));
    }

    /**
     * position() for many leases, in a few reads whatever their number (a rent roll, the deposit reports, a page of a list).
     * Takes lease ids, or leases already read (they are not read again); the caller has already checked who may see them.
     *
     * @param  array<int|RentalLease>  $ids
     * @return array<int, array{owed_minor: int, overdue_minor: int, overdue_days: int, credit_minor: int, deposit_held_minor: int, paid_up_to: ?string, next_due: ?array{date: string, amount_minor: int}, state: string}> lease id => position
     */
    public static function positions(array $ids, ?Carbon $today = null): array
    {
        $leases = self::leasesFrom($ids);
        $out = [];
        foreach ($leases->groupBy(fn (RentalLease $l) => (int) $l->company_id) as $cid => $group) {
            $cid = (int) $cid;
            $day = $today ?? LocalDate::today($cid);
            $grace = (int) RentalsModule::settings($cid)->grace_days;
            $list = $group->map(fn (RentalLease $l) => (int) $l->id)->all();
            $open = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $list)->where('status', 'open')->where('kind', '!=', 'refund')->get()
                ->sort(fn (RentalCharge $a, RentalCharge $b) => [$a->due_date->toDateString(), RentalCharge::PRIORITY[$a->kind] ?? 9, $a->id] <=> [$b->due_date->toDateString(), RentalCharge::PRIORITY[$b->kind] ?? 9, $b->id])
                ->groupBy('lease_id');
            $credit = array_map('intval', RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $list)->whereNull('reversed_at')
                ->groupBy('lease_id')->selectRaw('lease_id, SUM(unallocated_minor) AS c')->pluck('c', 'lease_id')->all());
            $held = self::depositsHeld($group->all());
            $rents = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $list)->where('kind', 'rent')->where('status', '!=', 'void')
                ->orderBy('period_start')->get(['lease_id', 'period_start', 'period_end', 'status', 'amount_minor', 'paid_minor'])->groupBy('lease_id');
            $live = $group->filter(fn (RentalLease $l) => $l->isLive())->map(fn (RentalLease $l) => (int) $l->id)->values()->all();
            $items = $live === [] ? collect() : RentalLeaseItem::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $live)->where('active', true)
                ->groupBy('lease_id')->selectRaw('lease_id, SUM(amount_minor) AS a')->pluck('a', 'lease_id');
            $changes = $live === [] ? collect() : RentalRentChange::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $live)
                ->orderByDesc('effective_from')->orderByDesc('id')->get(['lease_id', 'effective_from', 'rent_minor'])->groupBy('lease_id');
            foreach ($group as $l) {
                $id = (int) $l->id;
                $itemsOf = (int) ($items[$id] ?? 0);
                $changesOf = $changes->get($id, collect());
                $out[$id] = self::positionFrom($l, $open->get($id, collect()), $day, $grace, (int) ($credit[$id] ?? 0), (int) ($held[$id] ?? 0),
                    self::paidUpToFrom($l, $rents->get($id, collect()), (int) ($credit[$id] ?? 0), $itemsOf, $changesOf), fn () => self::nextBillFrom($l, $itemsOf, $changesOf));
            }
        }

        return $out;
    }

    /**
     * Deposits::held() for many leases, in three reads whatever their number.
     *
     * @param  array<int|RentalLease>  $ids
     * @return array<int, int> lease id => deposit held (every lease is in it, 0 when nothing is held)
     */
    public static function depositsHeld(array $ids): array
    {
        $out = [];
        foreach (self::leasesFrom($ids)->groupBy(fn (RentalLease $l) => (int) $l->company_id) as $cid => $group) {
            $cid = (int) $cid;
            $list = $group->map(fn (RentalLease $l) => (int) $l->id)->all();
            $collected = RentalAllocation::withoutGlobalScopes()->from('rental_allocations as a')->join('rental_charges as c', 'c.id', '=', 'a.charge_id')
                ->where('a.company_id', $cid)->where('c.company_id', $cid)->where('c.kind', 'deposit')->whereIn('c.lease_id', $list)
                ->whereNotIn('a.payment_id', RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $list)->where('method', 'waiver')->select('id'))
                ->groupBy('c.lease_id')->selectRaw('c.lease_id, SUM(a.amount_minor) AS total')->pluck('total', 'lease_id')->all();
            $used = RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $list)->where('method', 'deposit')->whereNull('reversed_at')
                ->groupBy('lease_id')->selectRaw('lease_id, SUM(amount_minor) AS total')->pluck('total', 'lease_id')->all();
            $refunded = RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $list)->whereIn('type', Deposits::REFUND_TYPES)->whereNull('reversed_at')
                ->groupBy('lease_id')->selectRaw('lease_id, -SUM(cash_minor) AS total')->pluck('total', 'lease_id')->all();
            foreach ($list as $id) {
                $out[$id] = max(0, (int) ($collected[$id] ?? 0) - (int) ($used[$id] ?? 0) - (int) ($refunded[$id] ?? 0));
            }
        }

        return $out;
    }

    /**
     * The leases behind a list of ids (or leases already read).
     *
     * @param  array<int|RentalLease>  $ids
     * @return Collection<int, RentalLease>
     */
    private static function leasesFrom(array $ids): Collection
    {
        $given = collect($ids)->filter(fn ($v) => $v instanceof RentalLease);
        $wanted = collect($ids)->reject(fn ($v) => $v instanceof RentalLease)->map(fn ($v) => (int) $v)->unique()->values()->all();

        return $given->concat($wanted === [] ? [] : RentalLease::withoutGlobalScopes()->whereIn('id', $wanted)->get()->all())->values();
    }

    /**
     * The rule of a position, from what was read.
     *
     * @param  Collection  $open  the lease's open bills, oldest first
     * @param  callable(): ?array{date: string, amount_minor: int}  $nextBill  the next bill not raised yet, asked only when it is needed
     * @return array{owed_minor: int, overdue_minor: int, overdue_days: int, credit_minor: int, deposit_held_minor: int, paid_up_to: ?string, next_due: ?array{date: string, amount_minor: int}, state: string}
     */
    public static function positionFrom(RentalLease $lease, Collection $open, Carbon $today, int $grace, int $credit, int $held, ?string $paidUpTo, callable $nextBill): array
    {
        $owed = 0;
        $overdue = 0;
        $oldest = null;
        $next = null;
        foreach ($open as $c) {
            $owed += $c->left();
            if ($c->due_date->copy()->addDays($grace)->lt($today)) {
                $overdue += $c->left();
                $oldest ??= $c->due_date;
            } elseif ($c->due_date->gte($today) && ($next === null || $c->due_date->lt($next['date']))) {
                $next = ['date' => $c->due_date, 'amount_minor' => 0];
            }
        }
        if ($next !== null) {
            $next['amount_minor'] = (int) $open->filter(fn ($c) => $c->due_date->equalTo($next['date']))->sum(fn ($c) => $c->left());
            $next['date'] = $next['date']->toDateString();
        } elseif ($lease->isLive()) {
            $next = $nextBill();
        }
        $state = $overdue > 0 ? 'overdue' : ($owed > 0 ? 'due' : ($credit > 0 ? 'credit' : 'paid'));

        return [
            'owed_minor' => $owed, 'overdue_minor' => $overdue, 'overdue_days' => $oldest !== null ? max(0, Periods::days($oldest, $today)) : 0,
            'credit_minor' => $credit, 'deposit_held_minor' => $held, 'paid_up_to' => $paidUpTo,
            'next_due' => $next, 'state' => $state,
        ];
    }

    /**
     * The last day the rent is paid for: the end of the last fully paid rent period, carried forward over the coming
     * periods by any credit (KR-12). Null before the first period is paid.
     */
    public static function paidUpTo(RentalLease $lease, ?int $credit = null): ?string
    {
        $rents = RentalCharge::withoutGlobalScopes()->where('company_id', (int) $lease->company_id)->where('lease_id', $lease->id)->where('kind', 'rent')
            ->where('status', '!=', 'void')->orderBy('period_start')->get(['period_start', 'period_end', 'status', 'amount_minor', 'paid_minor']);

        return self::paidUpToFrom($lease, $rents, $credit);
    }

    /**
     * paidUpTo() for many leases of one account, in a few queries whatever their number (a grid of doors, a page of a list).
     *
     * @param  iterable<RentalLease>  $leases
     * @param  array<int, int>|null  $credit  credit per lease when known (a lease not in it has none)
     * @return array<int, ?string> lease id => paid up to
     */
    public static function paidUpToMany(iterable $leases, ?array $credit = null): array
    {
        $leases = collect($leases);
        if ($leases->isEmpty()) {
            return [];
        }
        $cid = (int) $leases->first()->company_id;
        $ids = $leases->map(fn ($l) => (int) $l->id)->all();
        $credit ??= array_map('intval', RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $ids)->whereNull('reversed_at')
            ->groupBy('lease_id')->selectRaw('lease_id, SUM(unallocated_minor) AS c')->pluck('c', 'lease_id')->all());
        $rents = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $ids)->where('kind', 'rent')->where('status', '!=', 'void')
            ->orderBy('period_start')->get(['lease_id', 'period_start', 'period_end', 'status', 'amount_minor', 'paid_minor'])->groupBy('lease_id');
        $ahead = array_values(array_filter($ids, fn ($id) => ($credit[$id] ?? 0) > 0));
        $items = $ahead === [] ? collect() : RentalLeaseItem::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $ahead)->where('active', true)
            ->groupBy('lease_id')->selectRaw('lease_id, SUM(amount_minor) AS a')->pluck('a', 'lease_id');
        $changes = $ahead === [] ? collect() : RentalRentChange::withoutGlobalScopes()->where('company_id', $cid)->whereIn('lease_id', $ahead)
            ->orderByDesc('effective_from')->orderByDesc('id')->get(['lease_id', 'effective_from', 'rent_minor'])->groupBy('lease_id');
        $out = [];
        foreach ($leases as $l) {
            $id = (int) $l->id;
            $out[$id] = self::paidUpToFrom($l, $rents->get($id, collect()), (int) ($credit[$id] ?? 0), (int) ($items[$id] ?? 0), $changes->get($id, collect()));
        }

        return $out;
    }

    /** The rule of paidUpTo(), from its rent bills; the rest is read when it is not given. */
    public static function paidUpToFrom(RentalLease $lease, Collection $rents, ?int $credit, ?int $items = null, ?Collection $changes = null): ?string
    {
        $cid = (int) $lease->company_id;
        $paid = null;
        foreach ($rents as $r) {
            if ($r->status !== 'paid') {
                // Part of this period is paid: the rent covers that share of its days.
                $days = Periods::days($r->period_start, $r->period_end) + 1;
                $covered = (int) $r->amount_minor > 0 ? intdiv((int) $r->paid_minor * $days, (int) $r->amount_minor) : 0;

                return $covered > 0 ? $r->period_start->copy()->addDays($covered - 1)->toDateString() : ($paid?->toDateString() ?? self::dayBefore($r->period_start));
            }
            $paid = $r->period_end;
        }
        $credit ??= Payments::credit($lease);
        if ($credit <= 0 || ! $lease->isLive()) {
            return $paid?->toDateString();
        }
        // Carry the credit over the periods not billed yet, at their rent plus fees.
        $from = $lease->billed_until?->copy()->addDay() ?? $lease->start_date->copy();
        $items ??= (int) RentalLeaseItem::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $lease->id)->where('active', true)->sum('amount_minor');
        foreach (Periods::between($lease->start_date, (int) $lease->cycle_months, (string) $lease->anchor, $from, $from->copy()->addYears(5)) as $p) {
            $rent = $changes === null ? Billing::rentAt($lease, $p['start'])
                : (int) ($changes->first(fn ($c) => $c->effective_from->toDateString() <= $p['start']->toDateString())->rent_minor ?? $lease->rent_minor);
            $cost = ($rent + $items) * ($p['part'] ? 1 : (int) $lease->cycle_months);
            if ($cost <= 0) {
                break;
            }
            if ($credit >= $cost) {
                $credit -= $cost;
                $paid = $p['end'];

                continue;
            }
            $days = intdiv($credit * $p['days'], $cost);
            if ($days > 0) {
                $paid = $p['start']->copy()->addDays($days - 1);
            }
            break;
        }

        return $paid?->toDateString();
    }

    /**
     * What paidUpTo() would say if this money came in now, for the screen as a cashier types: $lines is charge id => amount
     * put on that bill and $credit what stays paid ahead (Payments::suggestSplit). Reads only.
     *
     * @param  array<int, int>  $lines
     */
    public static function paidUpToIf(RentalLease $lease, array $lines, int $credit): ?string
    {
        $rents = RentalCharge::withoutGlobalScopes()->where('company_id', (int) $lease->company_id)->where('lease_id', $lease->id)->where('kind', 'rent')
            ->where('status', '!=', 'void')->orderBy('period_start')->get(['id', 'period_start', 'period_end', 'status', 'amount_minor', 'paid_minor']);
        foreach ($rents as $r) {
            $paid = (int) $r->paid_minor + (int) ($lines[(int) $r->id] ?? 0);
            $r->paid_minor = $paid;
            if ($paid >= (int) $r->amount_minor) {
                $r->status = 'paid';
            }
        }

        return self::paidUpToFrom($lease, $rents, Payments::credit($lease) + max(0, $credit));
    }

    /** The next bill of a live lease that has not been raised yet: its date and expected amount. */
    public static function nextBill(RentalLease $lease): ?array
    {
        return self::nextBillFrom($lease);
    }

    /** The rule of nextBill(); the fees and rent changes are read when they are not given (rent changes newest first). */
    public static function nextBillFrom(RentalLease $lease, ?int $items = null, ?Collection $changes = null): ?array
    {
        $from = $lease->billed_until?->copy()->addDay() ?? $lease->start_date->copy();
        $p = Periods::between($lease->start_date, (int) $lease->cycle_months, (string) $lease->anchor, $from, $from->copy()->addMonths(13))[0] ?? null;
        if ($p === null || ($lease->end_date !== null && $p['start']->gt($lease->end_date)) || ($lease->status === 'notice' && $lease->move_out_on !== null && $p['start']->gt($lease->move_out_on))) {
            return null;
        }
        $items ??= (int) RentalLeaseItem::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('lease_id', $lease->id)->where('active', true)->sum('amount_minor');
        $rent = $changes === null ? Billing::rentAt($lease, $p['start'])
            : (int) ($changes->first(fn ($c) => $c->effective_from->toDateString() <= $p['start']->toDateString())->rent_minor ?? $lease->rent_minor);

        return ['date' => $p['start']->toDateString(), 'amount_minor' => ($rent + $items) * ($p['part'] ? 1 : (int) $lease->cycle_months)];
    }

    /** The amount typed for the unused days (null: none typed, the calculation stands). */
    private static function typedUnused(array $data): ?int
    {
        if (! isset($data['unused_minor']) || $data['unused_minor'] === '') {
            return null;
        }
        if (filter_var($data['unused_minor'], FILTER_VALIDATE_INT) === false || (int) $data['unused_minor'] < 0) {
            throw BusinessRuleException::make('amount_invalid', 'Type the amount to forgive in figures.');
        }

        return (int) $data['unused_minor'];
    }

    /**
     * What to forgive on each bill of the period the move-out falls in: pro rata by the days left, or a typed amount
     * spread over the bills, rent first. Bills from the move-out day on are cancelled, not forgiven.
     *
     * @return list<array{0: RentalCharge, 1: int}>
     */
    private function unusedPlan(RentalLease $lease, Carbon $day, ?int $typed): array
    {
        $cid = (int) $lease->company_id;
        $p = Periods::containing($lease->start_date, (int) $lease->cycle_months, (string) $lease->anchor, $day);
        $bills = $p !== null && $p['start']->lt($day)
            ? RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $lease->id)->whereIn('kind', ['rent', 'item'])
                ->where('status', '!=', 'void')->where('period_start', $p['start']->toDateString())->orderByRaw("FIELD(kind, 'rent', 'item')")->orderBy('id')->get()
            : collect();
        $plan = [];
        if ($typed !== null) {
            $total = (int) $bills->sum(fn ($c) => (int) $c->amount_minor);
            if ($typed > $total) {
                throw BusinessRuleException::make('unused_too_much', 'You can forgive at most '.Minor::format($total, $cid).': the bills of the period the move-out falls in.');
            }
            foreach ($bills as $c) {
                $x = min($typed, (int) $c->amount_minor);
                if ($x > 0) {
                    $plan[] = [$c, $x];
                    $typed -= $x;
                }
            }

            return $plan;
        }
        if ($p === null || ! $p['end']->gt($day)) {
            return [];
        }
        foreach ($bills as $c) {
            $unused = Minor::share((int) $c->amount_minor, Periods::days($day, $p['end']), $p['days']);
            if ($unused > 0) {
                $plan[] = [$c, $unused];
            }
        }

        return $plan;
    }

    /** The first bill's part-month rent as typed (0: none, a cancelled bill, so billing never makes it): the first period must be a part month. */
    private function typedPartRent(User $by, RentalLease $lease, mixed $typed): void
    {
        if (filter_var($typed, FILTER_VALIDATE_INT) === false || (int) $typed < 0) {
            throw BusinessRuleException::make('amount_invalid', 'Type the part-month rent in figures.');
        }
        $amount = (int) $typed;
        $first = Periods::between($lease->start_date, (int) $lease->cycle_months, (string) $lease->anchor, $lease->start_date, $lease->start_date)[0] ?? null;
        if ($first === null || ! $first['part']) {
            return;
        }
        $rent = Billing::rentAt($lease, $first['start']);
        $this->billing->makeFor($lease, ['kind' => 'rent', 'description' => 'Rent '.Billing::periodLabel($first['start'], $first['end']), 'period_start' => $first['start'], 'period_end' => $first['end'],
            'due_date' => $first['start'], 'amount_minor' => $amount, 'goes_to' => 'landlord', 'source' => 'manual', 'dedupe_key' => "rent:{$lease->id}:{$first['start']->toDateString()}", 'created_by_id' => $by->id]
            + ($amount > 0 ? Billing::rentCommission($lease, $amount, $rent) : Billing::commission($lease, false) + ['status' => 'void', 'voided_at' => now(), 'voided_by_id' => $by->id, 'void_reason' => 'No part-month rent charged']));
    }

    /** Opening figures of an imported tenancy: what was owed, credit paid ahead, and the deposit already held. */
    private function importOpening(User $by, RentalLease $lease, array $import, Carbon $asOf): void
    {
        $owed = (int) ($import['owed_minor'] ?? 0);
        if ($owed > 0) {
            $this->billing->makeFor($lease, ['kind' => 'opening', 'description' => 'Balance brought forward', 'due_date' => $asOf->toDateString(),
                'amount_minor' => $owed, 'goes_to' => 'landlord', 'source' => 'import', 'dedupe_key' => "opening:{$lease->id}", 'created_by_id' => $by->id]
                + Billing::rentCommission($lease, $owed, (int) $lease->rent_minor));
        }
        if (($credit = (int) ($import['credit_minor'] ?? 0)) > 0) {
            $this->payments->openingCredit($lease, $credit, $asOf, $by->id);
        }
        if (($held = (int) ($import['deposit_held_minor'] ?? 0)) > 0) {
            $this->payments->openingDeposit($lease, $held, $asOf, $by->id);
        }
    }

    private static function dayBefore(?Carbon $d): ?string
    {
        return $d?->copy()->subDay()->toDateString();
    }

    private function createItem(RentalLease $lease, array $item, Carbon $from, ?int $userId): RentalLeaseItem
    {
        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '') {
            throw BusinessRuleException::make('item_invalid', 'Give the fee a name, for example Garbage.');
        }
        $amount = Minor::positive($item['amount_minor'] ?? null, "{$name} amount");
        $goesTo = ($item['goes_to'] ?? 'landlord') === 'agency' && ! RentalsModule::isOwnerMode((int) $lease->company_id) ? 'agency' : 'landlord';

        return RentalLeaseItem::create(['company_id' => $lease->company_id, 'lease_id' => $lease->id, 'name' => mb_substr($name, 0, 80), 'amount_minor' => $amount,
            'goes_to' => $goesTo, 'commissionable' => ! empty($item['commissionable']) && $goesTo === 'landlord', 'starts_on' => $from->toDateString(),
            'ends_on' => ! empty($item['ends_on']) ? LocalDate::date((int) $lease->company_id, $item['ends_on'])->toDateString() : null, 'active' => true, 'created_by_id' => $userId]);
    }
}
