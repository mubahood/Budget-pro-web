<?php

namespace App\Services\Rentals\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalCharge;
use App\Models\RentalCommissionChange;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalLeaseItem;
use App\Models\RentalPayment;
use App\Models\RentalProperty;
use App\Models\RentalRentChange;
use App\Models\RentalSetting;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\Rentals\Landlords\Handover;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Payments\Splits;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use App\Support\Rentals\Periods;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Bills (docs/estate-ledger/04-architecture.md §5.1, §5.2, §5.8): rent and fees for each period, raised
 * `bill_days_before` days before the period starts (rent is paid in advance); one-off charges; voids; late fees.
 * Raising is idempotent: every automatic bill has a `dedupe_key`, so running it twice (or a missed night caught up)
 * never bills a period twice. A bill posts nothing to the journal: money moves when it is paid (ADR 0010 §4).
 */
final class Billing
{
    /** The most periods a payment may bill ahead (LC-07). */
    public const AHEAD_MAX = 12;

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
            $periods = Periods::between($lease->start_date, (int) $lease->cycle_months, (string) $lease->anchor, $lease->start_date, $horizon, $this->stopBefore($lease));

            return $this->raisePeriods($lease, $periods, $today);
        });
    }

    /**
     * Bill the next periods now, past the horizon, because the money for them is in hand (LC-07, rentals_bill): the same bills the night's run would
     * raise, one dedupe key each, so it never bills them again. Refused past a fixed term's end or a planned move-out.
     */
    public function raiseAhead(User $by, RentalLease $lease, int $periods, ?Carbon $today = null): int
    {
        RentalAccess::authorize($by, 'rentals_bill');
        self::assertOurs($by, $lease);
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        $cid = (int) $lease->company_id;
        if (RentalsModule::isOwnerMode($cid)) {
            throw BusinessRuleException::make('owner_mode', 'An owner-run account keeps rent paid ahead as paid ahead: there is no landlord to pay.');
        }
        if ($periods < 1 || $periods > self::AHEAD_MAX) {
            throw BusinessRuleException::make('ahead_invalid', 'Bill between 1 and '.self::AHEAD_MAX.' periods ahead.');
        }

        return RentalLedger::locked($cid, function () use ($by, $lease, $periods, $today, $cid) {
            $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($lease->id);
            if (! $lease->isLive()) {
                throw BusinessRuleException::make('lease_not_live', 'This lease has ended: there is nothing to bill ahead.');
            }
            $next = $this->nextPeriods($lease, $periods);
            if (count($next) < $periods) {
                throw BusinessRuleException::make('ahead_past_end', $this->stopWords($lease, count($next)));
            }
            $made = $this->raisePeriods($lease, $next, $today ?? LocalDate::today($cid));
            RentalAudit::record($cid, 'lease.billed_ahead', $lease, "Billed {$periods} ".($periods === 1 ? 'period' : 'periods')." ahead on lease {$lease->number}", ['periods' => $periods, 'bills' => $made], 'staff', $by->id);

            return $made;
        });
    }

    /**
     * Receive money and bill the next periods with it, as one write (LC-07: the second choice at the till, beside holding it as credit, KR-12). The
     * bills are real, with their commission; the payment settles them the usual way and what is left over stays as credit. A refusal anywhere, a closed
     * month's date among them, leaves no bill behind. The same uuid again is the same payment, and bills nothing more.
     */
    public function receiveAhead(User $by, RentalLease $lease, array $data, int $periods, string $channel = 'web'): RentalPayment
    {
        RentalAccess::authorize($by, 'rentals_collect');
        RentalAccess::authorize($by, 'rentals_bill');
        self::assertOurs($by, $lease);
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        if (($data['split'] ?? null) !== null) {
            throw BusinessRuleException::make('ahead_with_split', 'Billing ahead settles the bills oldest first. Use the suggested split, or hold the extra as paid ahead.');
        }
        $amount = Minor::positive($data['amount_minor'] ?? null);
        $cid = (int) $lease->company_id;
        $payments = app(Payments::class);

        return RentalLedger::locked($cid, function () use ($by, $lease, $data, $periods, $channel, $amount, $cid, $payments) {
            if (isset($data['uuid']) && RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', (string) $data['uuid'])->exists()) {
                return $payments->receive($by, $lease, $data, $channel);
            }
            $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($lease->id); // what is billed now, under the lock
            $plan = $this->aheadPlan($lease, $amount);
            if ($periods > $plan['max_periods']) {
                throw BusinessRuleException::make('ahead_not_covered', $plan['stop_on'] !== null && $periods > $plan['can_bill']
                    ? $this->stopWords($lease, $plan['can_bill'])
                    : 'This money covers '.($plan['max_periods'] === 0 ? 'no whole period' : $plan['max_periods'].' '.($plan['max_periods'] === 1 ? 'period' : 'periods')).' after what is owed. Bill fewer, or hold it as paid ahead.');
            }
            $this->raiseAhead($by, $lease, $periods);

            return $payments->receive($by, $lease->fresh(), $data, $channel);
        });
    }

    /**
     * What this money could bill ahead, for the choice at the till before anyone commits to it (no writes). `periods` are the whole periods that what is
     * left after the open bills covers, at most AHEAD_MAX, with what each bill will carry: the same bills raiseAhead makes. `can_bill` is how many periods
     * the lease's end allows, and `stop_on` the day it stops, when it has one.
     *
     * @return array{open_minor: int, credit_minor: int, over_minor: int, max_periods: int, can_bill: int, stop_on: ?string,
     *               periods: list<array{start: string, end: string, label: string, amount_minor: int, commission_minor: int, landlord_minor: int, agency_minor: int}>}
     */
    public function aheadPlan(RentalLease $lease, int $amount): array
    {
        $cid = (int) $lease->company_id;
        $open = (int) Payments::openCharges($lease, false)->sum(fn (RentalCharge $c) => $c->left());
        $credit = Payments::credit($lease);
        $out = ['open_minor' => $open, 'credit_minor' => $credit, 'over_minor' => max(0, $amount - $open), 'max_periods' => 0, 'can_bill' => 0, 'stop_on' => null, 'periods' => []];
        if (! $lease->isLive() || RentalsModule::isOwnerMode($cid)) {
            return $out;
        }
        $left = $amount + $credit - $open;
        $s = RentalsModule::settings($cid);
        $items = $this->activeItems($lease);
        $next = $this->nextPeriods($lease, self::AHEAD_MAX);
        $out['can_bill'] = count($next);
        $stop = $this->stopBefore($lease);
        $out['stop_on'] = $stop?->toDateString();
        foreach ($next as $p) {
            $row = ['start' => $p['start']->toDateString(), 'end' => $p['end']->toDateString(), 'label' => self::periodLabel($p['start'], $p['end']), 'amount_minor' => 0, 'commission_minor' => 0, 'landlord_minor' => 0, 'agency_minor' => 0];
            foreach ($this->billsOf($lease, $p, $items, $s) as $bill) {
                $row['amount_minor'] += (int) $bill['amount_minor'];
                if ($bill['goes_to'] === 'agency') {
                    $row['agency_minor'] += (int) $bill['amount_minor'];

                    continue;
                }
                $c = Splits::total((int) $bill['amount_minor'], (string) $bill['commission_type'], (int) $bill['commission_bp'], (int) $bill['commission_minor']);
                $row['commission_minor'] += $c;
                $row['landlord_minor'] += (int) $bill['amount_minor'] - $c;
            }
            if ($row['amount_minor'] <= 0 || $left < $row['amount_minor']) {
                break;
            }
            $left -= $row['amount_minor'];
            $out['periods'][] = $row;
        }
        $out['max_periods'] = count($out['periods']);

        return $out;
    }

    /** A lease of another account does not exist, for this person. */
    private static function assertOurs(User $by, RentalLease $lease): void
    {
        if ((int) $by->company_id !== (int) $lease->company_id) {
            throw BusinessRuleException::make('not_found', 'That lease was not found.');
        }
    }

    /** @return Collection<int, RentalLeaseItem> */
    private function activeItems(RentalLease $lease): Collection
    {
        return RentalLeaseItem::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('lease_id', $lease->id)->where('active', true)->get();
    }

    /**
     * The next $count periods not billed yet, in order, stopping at a fixed term's end or a planned move-out.
     *
     * @return list<array{start: Carbon, end: Carbon, part: bool, days: int, month_days: int}>
     */
    private function nextPeriods(RentalLease $lease, int $count): array
    {
        $from = $lease->billed_until?->copy()->addDay() ?? $lease->start_date->copy();
        $until = $from->copy()->addMonthsNoOverflow(($count + 1) * max(1, (int) $lease->cycle_months));
        $periods = array_filter(Periods::between($lease->start_date, (int) $lease->cycle_months, (string) $lease->anchor, $lease->start_date, $until, $this->stopBefore($lease)),
            fn (array $p) => $lease->billed_until === null || $p['end']->gt($lease->billed_until));

        return array_slice(array_values($periods), 0, $count);
    }

    private function stopWords(RentalLease $lease, int $canBill): string
    {
        $stop = $lease->status === 'notice' && $lease->move_out_on !== null ? 'The tenant moves out on '.$lease->move_out_on->format('j M Y') : 'The fixed term ends on '.$lease->end_date?->format('j M Y');

        return $stop.': '.($canBill === 0 ? 'no period can be billed ahead' : 'at most '.$canBill.' '.($canBill === 1 ? 'period' : 'periods').' can be billed ahead').'. Hold the rest as paid ahead.';
    }

    /**
     * Make the bills of these periods, skipping any already billed (by Estate Ledger, or by the system an imported tenancy came from), and move
     * `billed_until`; rent and commission follow their changes as their day comes, and credit settles what was made. Runs inside the lock.
     *
     * @param  list<array{start: Carbon, end: Carbon, part: bool, days: int, month_days: int}>  $periods
     */
    private function raisePeriods(RentalLease $lease, array $periods, Carbon $today): int
    {
        $s = RentalsModule::settings((int) $lease->company_id);
        $items = $this->activeItems($lease);
        $handover = null;
        $looked = false;
        $made = 0;
        $billedUntil = $lease->billed_until;
        foreach ($periods as $p) {
            if ($lease->billed_until !== null && $p['end']->lte($lease->billed_until)) {
                continue;
            }
            if (! $looked) {
                $handover = Handover::scheduled((int) $lease->company_id, (int) $lease->property_id);
                $looked = true;
            }
            $owner = $handover !== null && $p['start']->gte($handover->from_date) ? ['landlord_id' => (int) $handover->landlord_id] : []; // the period is the new owner's when its day comes
            foreach ($this->billsOf($lease, $p, $items, $s) as $bill) {
                if ($this->make($lease, $owner + $bill)) {
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
        $this->refreshCommission($lease, $today);
        if ($made > 0) {
            app(Payments::class)->applyCredit($lease, $today);
        }

        return $made;
    }

    /**
     * The bills of one period: its rent and the fees beside it, each with the commission rule in force on the period's first day (LC-15).
     *
     * @param  array{start: Carbon, end: Carbon, part: bool, days: int, month_days: int}  $p
     * @param  Collection<int, RentalLeaseItem>  $items
     * @return list<array<string, mixed>>
     */
    private function billsOf(RentalLease $lease, array $p, Collection $items, RentalSetting $s): array
    {
        $bills = [];
        $rent = self::rentAt($lease, $p['start']);
        $amount = $p['part'] ? ($s->prorate_first ? Minor::share($rent, $p['days'], $p['month_days']) : $rent) : $rent * (int) $lease->cycle_months;
        $label = self::periodLabel($p['start'], $p['end']);
        if ($amount > 0) {
            $bills[] = ['kind' => 'rent', 'description' => "Rent {$label}", 'period_start' => $p['start'], 'period_end' => $p['end'],
                'due_date' => $p['start'], 'amount_minor' => $amount, 'goes_to' => 'landlord', 'dedupe_key' => "rent:{$lease->id}:{$p['start']->toDateString()}"]
                + self::rentCommission($lease, $amount, $rent, $p['start']);
        }
        foreach ($items as $item) {
            if ($item->starts_on->gt($p['end']) || ($item->ends_on !== null && $item->ends_on->lt($p['start']))) {
                continue;
            }
            $amt = $p['part'] ? ($s->prorate_first ? Minor::share((int) $item->amount_minor, $p['days'], $p['month_days']) : (int) $item->amount_minor)
                : (int) $item->amount_minor * (int) $lease->cycle_months;
            if ($amt > 0) {
                $bills[] = ['kind' => 'item', 'item_id' => $item->id, 'description' => "{$item->name} {$label}",
                    'period_start' => $p['start'], 'period_end' => $p['end'], 'due_date' => $p['start'], 'amount_minor' => $amt,
                    'goes_to' => $item->goes_to, 'dedupe_key' => "item:{$item->id}:{$p['start']->toDateString()}"]
                    + self::commission($lease, $item->commissionable && $item->goes_to === 'landlord', $p['start']);
            }
        }

        return $bills;
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
     * The rule a lease is on, on a day (today by default): the latest change on or before it (LC-15), or the lease's own columns when it never changed.
     *
     * @return array{commission_type: string, commission_bp: int, commission_minor: int}
     */
    public static function ruleAt(RentalLease $lease, ?Carbon $on = null): array
    {
        $on ??= LocalDate::today((int) $lease->company_id);
        $change = RentalCommissionChange::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('lease_id', $lease->id)
            ->where('effective_from', '<=', $on->toDateString())->orderByDesc('effective_from')->orderByDesc('id')->first();
        $src = $change ?? $lease;

        return ['commission_type' => (string) ($src->commission_type ?? 'none'), 'commission_bp' => (int) $src->commission_bp, 'commission_minor' => (int) $src->commission_minor];
    }

    /**
     * The commission terms a new bill carries: the percent in force on $on for commissionable bills, none otherwise. A flat commission is a fee per month
     * of rent, so it rides on rent bills only (rentCommission).
     */
    public static function commission(RentalLease $lease, bool $commissionable, ?Carbon $on = null): array
    {
        $none = ['commission_type' => 'none', 'commission_bp' => 0, 'commission_minor' => 0];
        if (! $commissionable) {
            return $none;
        }
        $rule = self::ruleAt($lease, $on);

        return $rule['commission_type'] === 'percent' ? ['commission_type' => 'percent', 'commission_bp' => $rule['commission_bp'], 'commission_minor' => 0] : $none;
    }

    /**
     * The commission on a rent bill of $amount when the month's rent is $monthlyRent, on the rule in force on $on. Flat is "so much per month of rent"
     * (KR-09, as the earlier agency system charged it): a quarter's bill carries three months' fee and a part month its share, so the bill's total is
     * flat × amount ÷ monthly rent.
     */
    public static function rentCommission(RentalLease $lease, int $amount, int $monthlyRent, ?Carbon $on = null): array
    {
        $rule = self::ruleAt($lease, $on);
        if ($rule['commission_type'] === 'flat') {
            $flat = $rule['commission_minor'];

            return ['commission_type' => 'flat', 'commission_bp' => 0, 'commission_minor' => $monthlyRent > 0 ? Minor::share($flat, $amount, $monthlyRent) : $flat];
        }

        return $rule['commission_type'] === 'percent' ? ['commission_type' => 'percent', 'commission_bp' => $rule['commission_bp'], 'commission_minor' => 0]
            : ['commission_type' => 'none', 'commission_bp' => 0, 'commission_minor' => 0];
    }

    /**
     * The commission a new lease starts with: the unit's, else the property's, else the landlord's, else the account's (KR-09, LC-14); none in owner mode.
     *
     * @return array{commission_type: string, commission_bp: int, commission_minor: int}
     */
    public static function commissionFor(RentalProperty $property, ?RentalLandlord $landlord = null, ?RentalUnit $unit = null, ?RentalSetting $s = null): array
    {
        $chain = self::commissionChain($property, $landlord, $unit, $s);
        unset($chain['set_on']);

        return $chain;
    }

    /**
     * commissionFor() and where the rule was found, for "Usual: 12%, set on this unit". A caller going over many leases passes the settings it read.
     *
     * @return array{commission_type: string, commission_bp: int, commission_minor: int, set_on: string}
     */
    public static function commissionChain(RentalProperty $property, ?RentalLandlord $landlord = null, ?RentalUnit $unit = null, ?RentalSetting $s = null): array
    {
        $s ??= RentalsModule::settings((int) $property->company_id);
        if ($s->mode === 'owner') {
            return ['commission_type' => 'none', 'commission_bp' => 0, 'commission_minor' => 0, 'set_on' => 'owner'];
        }
        foreach (['unit' => $unit, 'property' => $property, 'landlord' => $landlord] as $on => $src) {
            if ($src !== null && $src->commission_type !== null) {
                return ['commission_type' => (string) $src->commission_type, 'commission_bp' => (int) ($src->commission_bp ?? 0), 'commission_minor' => (int) ($src->commission_minor ?? 0), 'set_on' => $on];
            }
        }

        return ['commission_type' => (string) $s->commission_type, 'commission_bp' => (int) $s->commission_bp, 'commission_minor' => (int) $s->commission_minor, 'set_on' => 'account'];
    }

    /** A commission rule in plain words: "12.5%", "UGX 15,000 a month", "None". */
    public static function ruleWords(int $companyId, string $type, int $bp, int $minor): string
    {
        return match ($type) {
            'percent' => rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.').'%',
            'flat' => Minor::format($minor, $companyId).' a month',
            default => 'None',
        };
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

    /** The lease's own rule follows its commission changes as their day comes (what lists and new one-off bills read). */
    private function refreshCommission(RentalLease $lease, Carbon $today): void
    {
        $rule = self::ruleAt($lease, $today);
        if ($rule['commission_type'] !== (string) $lease->commission_type || $rule['commission_bp'] !== (int) $lease->commission_bp || $rule['commission_minor'] !== (int) $lease->commission_minor) {
            $lease->forceFill($rule)->save();
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
