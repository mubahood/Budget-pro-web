<?php

namespace App\Services\Rentals\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalCharge;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalLeaseItem;
use App\Models\RentalProperty;
use App\Models\RentalRentChange;
use App\Models\User;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use App\Support\Rentals\Periods;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Bills (docs/estate-ledger/04-architecture.md §5.1, §5.2, §5.8): rent and fees for each period, raised
 * `bill_days_before` days before the period starts (rent is paid in advance); one-off charges; voids; late fees.
 * Raising is idempotent: every automatic bill has a `dedupe_key`, so running it twice (or a missed night caught up)
 * never bills a period twice. A bill posts nothing to the journal: money moves when it is paid (ADR 0010 §4).
 */
final class Billing
{
    /** Raise the bills of one lease that are due by the horizon (today + bill_days_before). Returns how many were made. */
    public function raise(RentalLease $lease, ?Carbon $today = null): int
    {
        $cid = (int) $lease->company_id;

        return RentalLedger::locked($cid, function () use ($lease, $today, $cid) {
            $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($lease->id);
            if (! $lease->isLive()) {
                return 0;
            }
            $s = RentalsModule::settings($cid);
            $today ??= LocalDate::today($cid);
            $horizon = $today->copy()->addDays((int) $s->bill_days_before);
            $stop = $this->stopBefore($lease);
            $periods = Periods::between($lease->start_date, (int) $lease->cycle_months, (string) $lease->anchor, $lease->start_date, $horizon, $stop);
            $items = RentalLeaseItem::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $lease->id)->where('active', true)->get();
            $made = 0;
            $billedUntil = $lease->billed_until;
            foreach ($periods as $p) {
                if ($lease->billed_until !== null && $p['end']->lte($lease->billed_until)) {
                    continue; // already billed (by Estate Ledger, or by the system an imported tenancy came from): never billed again
                }
                $rent = self::rentAt($lease, $p['start']);
                $amount = $p['part'] ? ($s->prorate_first ? Minor::share($rent, $p['days'], $p['month_days']) : $rent) : $rent * (int) $lease->cycle_months;
                $label = self::periodLabel($p['start'], $p['end']);
                if ($amount > 0 && $this->make($lease, ['kind' => 'rent', 'description' => "Rent {$label}", 'period_start' => $p['start'], 'period_end' => $p['end'],
                    'due_date' => $p['start'], 'amount_minor' => $amount, 'goes_to' => 'landlord', 'dedupe_key' => "rent:{$lease->id}:{$p['start']->toDateString()}"]
                    + self::rentCommission($lease, $amount, $rent))) {
                    $made++;
                }
                foreach ($items as $item) {
                    if ($item->starts_on->gt($p['end']) || ($item->ends_on !== null && $item->ends_on->lt($p['start']))) {
                        continue;
                    }
                    $amt = $p['part'] ? ($s->prorate_first ? Minor::share((int) $item->amount_minor, $p['days'], $p['month_days']) : (int) $item->amount_minor)
                        : (int) $item->amount_minor * (int) $lease->cycle_months;
                    if ($amt > 0 && $this->make($lease, ['kind' => 'item', 'item_id' => $item->id, 'description' => "{$item->name} {$label}",
                        'period_start' => $p['start'], 'period_end' => $p['end'], 'due_date' => $p['start'], 'amount_minor' => $amt,
                        'goes_to' => $item->goes_to, 'dedupe_key' => "item:{$item->id}:{$p['start']->toDateString()}"]
                        + ($item->commissionable && $item->goes_to === 'landlord' ? self::commission($lease, true) : self::commission($lease, false)))) {
                        $made++;
                    }
                }
                if ($billedUntil === null || $p['end']->gt($billedUntil)) {
                    $billedUntil = $p['end'];
                }
            }
            if ($billedUntil !== null && ($lease->billed_until === null || ! $billedUntil->equalTo($lease->billed_until))) {
                $lease->forceFill(['billed_until' => $billedUntil->toDateString()])->save();
            }
            $this->refreshRent($lease, $today);
            if ($made > 0) {
                app(Payments::class)->applyCredit($lease, $today);
            }

            return $made;
        });
    }

    /** Raise the bills of every live lease of an account (the daily job and "Raise bills now"). */
    public function raiseAll(int $companyId, ?Carbon $today = null): int
    {
        $made = 0;
        RentalLease::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('status', RentalLease::LIVE)->orderBy('id')
            ->chunkById(200, function ($leases) use (&$made, $today) {
                foreach ($leases as $lease) {
                    $made += $this->raise($lease, $today);
                }
            });

        return $made;
    }

    /** "Raise bills now" for a person (rentals_bill). */
    public function raiseNow(User $by, ?int $leaseId = null): int
    {
        RentalAccess::authorize($by, 'rentals_bill');
        $cid = (int) $by->company_id;
        if ($leaseId !== null) {
            $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($leaseId);
            RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');

            return $this->raise($lease);
        }

        return $this->raiseAll($cid);
    }

    /**
     * A one-off bill (rentals_bill): a repair, a damage, a key, an agreement fee, a utility bill typed in.
     * $data: description, amount_minor, due_date, goes_to (landlord|agency), commissionable, kind (one_off|utility).
     */
    public function charge(User $by, RentalLease $lease, array $data, string $source = 'manual'): RentalCharge
    {
        RentalAccess::authorize($by, $source === 'move_out' ? 'rentals_manage' : 'rentals_bill');
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        $cid = (int) $lease->company_id;
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw BusinessRuleException::make('description_required', 'Say what the bill is for.');
        }
        $amount = Minor::positive($data['amount_minor'] ?? null);
        $kind = in_array($data['kind'] ?? 'one_off', ['one_off', 'utility'], true) ? ($data['kind'] ?? 'one_off') : 'one_off';
        $goesTo = in_array($data['goes_to'] ?? 'landlord', ['landlord', 'agency'], true) ? ($data['goes_to'] ?? 'landlord') : 'landlord';
        if (RentalsModule::isOwnerMode($cid)) {
            $goesTo = 'landlord';
        }
        $due = LocalDate::date($cid, $data['due_date'] ?? null);
        if ($lease->status === 'cancelled') {
            throw BusinessRuleException::make('lease_not_live', 'This lease was cancelled.');
        }

        return RentalLedger::locked($cid, function () use ($by, $lease, $description, $amount, $kind, $goesTo, $due, $data, $source, $cid) {
            $c = $this->make($lease, ['kind' => $kind, 'description' => mb_substr($description, 0, 255), 'due_date' => $due, 'amount_minor' => $amount,
                'goes_to' => $goesTo, 'source' => $source, 'created_by_id' => $by->id]
                + self::commission($lease, ! empty($data['commissionable']) && $goesTo === 'landlord'), true);
            app(Payments::class)->applyCredit($lease, LocalDate::today($cid), $by->id);
            RentalAudit::record($cid, 'charge.added', $c, "Added a bill to lease {$lease->number}: {$description}", ['amount_minor' => $amount], 'staff', $by->id);

            return $c->fresh();
        });
    }

    /** Cancel a bill nothing has been paid on (rentals_bill); after a payment, reverse it or forgive the bill. */
    public function void(User $by, RentalCharge $charge, string $reason): RentalCharge
    {
        RentalAccess::authorize($by, 'rentals_bill');
        RentalAccess::assertInScope($by, (int) $charge->property_id, 'bill');

        return $this->voidCharge($charge, $reason, $by->id);
    }

    public function voidCharge(RentalCharge $charge, string $reason, ?int $userId): RentalCharge
    {
        $cid = (int) $charge->company_id;
        $reason = trim($reason);
        if ($reason === '') {
            throw BusinessRuleException::make('reason_required', 'Say why this bill is cancelled.');
        }

        return RentalLedger::locked($cid, function () use ($charge, $reason, $userId, $cid) {
            $c = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($charge->id);
            if ($c->status === 'void') {
                throw BusinessRuleException::make('charge_void', 'That bill was already cancelled.');
            }
            if ((int) $c->paid_minor > 0) {
                throw BusinessRuleException::make('charge_paid', 'Money was paid on this bill: reverse the payment or forgive the rest instead.');
            }
            $c->forceFill(['status' => 'void', 'voided_at' => now(), 'voided_by_id' => $userId, 'void_reason' => mb_substr($reason, 0, 255)])->save();
            RentalAudit::record($cid, 'charge.voided', $c, "Cancelled a bill: {$c->description}", ['reason' => $reason], $userId ? 'staff' : 'system', $userId);

            return $c->fresh();
        });
    }

    /**
     * Late fees (KR-14): with the account's late fee on, each rent bill still open after due date + grace days gets one
     * late fee, flat or a percent of what is left on it, due today. Never compounds. Returns how many were made.
     */
    public function lateFees(int $companyId, ?Carbon $today = null): int
    {
        $s = RentalsModule::settings($companyId);
        if ($s->late_fee_type === 'none') {
            return 0;
        }
        $today ??= LocalDate::today($companyId);
        $cutoff = $today->copy()->subDays((int) $s->grace_days)->toDateString();
        $made = 0;
        RentalCharge::withoutGlobalScopes()->where('company_id', $companyId)->where('kind', 'rent')->where('status', 'open')
            ->where('due_date', '<', $cutoff)->orderBy('id')
            ->chunkById(200, function ($charges) use (&$made, $s, $today, $companyId) {
                foreach ($charges as $c) {
                    $lease = RentalLease::withoutGlobalScopes()->where('company_id', $companyId)->find($c->lease_id);
                    if ($lease === null || $lease->late_fee === 'off' || $lease->status === 'cancelled') {
                        continue;
                    }
                    $amount = $s->late_fee_type === 'flat' ? (int) $s->late_fee_minor : Minor::percent($c->left(), (int) $s->late_fee_bp);
                    if ($amount <= 0) {
                        continue;
                    }
                    $goesTo = $s->late_fee_to === 'landlord' || $s->mode === 'owner' ? 'landlord' : 'agency';
                    $made += RentalLedger::locked($companyId, function () use ($lease, $c, $amount, $goesTo, $today) {
                        $ok = $this->make($lease, ['kind' => 'late_fee', 'parent_id' => $c->id, 'description' => 'Late fee: '.$c->description,
                            'due_date' => $today, 'amount_minor' => $amount, 'goes_to' => $goesTo, 'dedupe_key' => "late:{$c->id}"] + self::commission($lease, false));
                        if ($ok) {
                            app(Payments::class)->applyCredit($lease, $today);
                        }

                        return $ok ? 1 : 0;
                    });
                }
            });

        return $made;
    }

    /** The monthly rent in force on $date: the latest change on or before it (the first is written at move-in). */
    public static function rentAt(RentalLease $lease, Carbon $date): int
    {
        $r = RentalRentChange::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('lease_id', $lease->id)
            ->where('effective_from', '<=', $date->toDateString())->orderByDesc('effective_from')->orderByDesc('id')->value('rent_minor');

        return (int) ($r ?? $lease->rent_minor);
    }

    /**
     * The commission terms a new bill carries: the lease's percent for commissionable bills, none otherwise. A flat
     * commission is a fee per month of rent, so it rides on rent bills only (rentCommission).
     */
    public static function commission(RentalLease $lease, bool $commissionable): array
    {
        if (! $commissionable || $lease->commission_type === 'none' || $lease->commission_type === 'flat') {
            return ['commission_type' => 'none', 'commission_bp' => 0, 'commission_minor' => 0];
        }

        return ['commission_type' => (string) $lease->commission_type, 'commission_bp' => (int) $lease->commission_bp, 'commission_minor' => 0];
    }

    /**
     * The commission on a rent bill of $amount when the month's rent is $monthlyRent. Flat is "so much per month of
     * rent" (KR-09, as the earlier agency system charged it): a quarter's bill carries three months' fee and a part
     * month its share, so the bill's total is flat × amount ÷ monthly rent.
     */
    public static function rentCommission(RentalLease $lease, int $amount, int $monthlyRent): array
    {
        if ($lease->commission_type === 'flat') {
            $flat = (int) $lease->commission_minor;

            return ['commission_type' => 'flat', 'commission_bp' => 0, 'commission_minor' => $monthlyRent > 0 ? Minor::share($flat, $amount, $monthlyRent) : $flat];
        }

        return self::commission($lease, true);
    }

    /**
     * The commission a new lease starts with: the property's, else the landlord's, else the account's (KR-09);
     * none in owner mode. @return array{commission_type: string, commission_bp: int, commission_minor: int}
     */
    public static function commissionFor(RentalProperty $property, ?RentalLandlord $landlord = null): array
    {
        $cid = (int) $property->company_id;
        $s = RentalsModule::settings($cid);
        if ($s->mode === 'owner') {
            return ['commission_type' => 'none', 'commission_bp' => 0, 'commission_minor' => 0];
        }
        foreach ([$property, $landlord] as $src) {
            if ($src !== null && $src->commission_type !== null) {
                return ['commission_type' => (string) $src->commission_type, 'commission_bp' => (int) ($src->commission_bp ?? 0), 'commission_minor' => (int) ($src->commission_minor ?? 0)];
            }
        }

        return ['commission_type' => (string) $s->commission_type, 'commission_bp' => (int) $s->commission_bp, 'commission_minor' => (int) $s->commission_minor];
    }

    public static function periodLabel(Carbon $start, Carbon $end): string
    {
        if ($start->day === 1 && $end->equalTo($start->copy()->endOfMonth()->startOfDay())) {
            return $start->format('M Y');
        }

        return $start->format('j M').' – '.$end->format('j M Y');
    }

    /**
     * The first day no period may start on: the day after a fixed term's last day, or the planned move-out day (the
     * day the keys come back: a period starting that day is not the tenant's).
     */
    private function stopBefore(RentalLease $lease): ?Carbon
    {
        $stops = [];
        if ($lease->end_date !== null) {
            $stops[] = $lease->end_date->copy()->addDay()->toDateString();
        }
        if ($lease->status === 'notice' && $lease->move_out_on !== null) {
            $stops[] = $lease->move_out_on->toDateString();
        }

        return $stops === [] ? null : Carbon::parse(min($stops));
    }

    /** The lease's current rent follows its rent changes as their day comes. */
    private function refreshRent(RentalLease $lease, Carbon $today): void
    {
        $rent = self::rentAt($lease, $today);
        if ($rent !== (int) $lease->rent_minor) {
            $lease->forceFill(['rent_minor' => $rent])->save();
        }
    }

    /**
     * Create a bill for a lease (inside the lock). With a dedupe key, an existing bill of that key means "already
     * billed": returns false. Without one, $returnModel gives the new bill.
     */
    private function make(RentalLease $lease, array $data, bool $returnModel = false): RentalCharge|bool
    {
        $cid = (int) $lease->company_id;
        $key = $data['dedupe_key'] ?? null;
        if ($key !== null && RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('dedupe_key', $key)->exists()) {
            return false;
        }
        foreach (['period_start', 'period_end', 'due_date'] as $d) {
            if (isset($data[$d]) && $data[$d] instanceof Carbon) {
                $data[$d] = $data[$d]->toDateString();
            }
        }
        try {
            $c = RentalCharge::create($data + ['company_id' => $cid, 'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'unit_id' => $lease->unit_id,
                'property_id' => $lease->property_id, 'landlord_id' => $lease->landlord_id, 'source' => 'auto', 'status' => 'open', 'paid_minor' => 0]);
        } catch (QueryException $e) {
            if ($key !== null && str_contains($e->getMessage(), 'rental_charges_dedupe_unique')) {
                return false;
            }
            throw $e;
        }

        return $returnModel ? $c : true;
    }

    /** Make a bill for a service that settles it itself (a move-out deposit bill, an opening balance). */
    public function makeFor(RentalLease $lease, array $data): ?RentalCharge
    {
        return RentalLedger::locked((int) $lease->company_id, function () use ($lease, $data) {
            $c = $this->make($lease, $data, true);

            return $c instanceof RentalCharge ? $c : null;
        });
    }
}
