<?php

namespace App\Services\Rentals\Payments;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalAllocation;
use App\Models\RentalCharge;
use App\Models\RentalEntry;
use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Models\User;
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
 * Money from tenants (docs/estate-ledger/04-architecture.md §5.3–5.6): receiving a payment and splitting it over the
 * lease's open bills, oldest first; credit (money paid ahead) applying itself to later bills; waivers; taking money
 * back off a bill into credit (unapply); refunds of credit; reversals. Every write runs under the account's lock and
 * posts balanced journal rows (ADR 0010).
 */
final class Payments
{
    /** Funding of an allocation: what the money is, and so which journal row it makes. */
    private const ROW = ['cash' => 'collected', 'credit' => 'credit_applied', 'deposit' => 'deposit_applied'];

    /**
     * Receive money for a lease. $data: amount_minor, method, paid_on, reference, notes, uuid, device_id.
     * The same uuid twice returns the first payment (a phone's retry); with a different payload it is refused.
     *
     * By default the money settles the oldest bill first (KR-10). `split` lets the cashier say otherwise (EL-05): a list
     * of [charge_id, amount_minor] on this lease's open bills, each at most what is left on it, adding up to the amount.
     * Money may stay as credit only once every open bill is covered.
     */
    public function receive(?User $by, RentalLease $lease, array $data, string $channel = 'web'): RentalPayment
    {
        $cid = (int) $lease->company_id;
        if ($by !== null) {
            RentalAccess::authorize($by, 'rentals_collect');
            RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        }
        $amount = Minor::positive($data['amount_minor'] ?? null);
        $method = (string) ($data['method'] ?? 'cash');
        if (! in_array($method, RentalPayment::CASH_METHODS, true)) {
            throw BusinessRuleException::make('method_invalid', 'Choose cash, mobile money, bank or cheque.');
        }
        $today = LocalDate::today($cid);
        $paidOn = LocalDate::date($cid, $data['paid_on'] ?? null);
        if ($paidOn->gt($today)) {
            throw BusinessRuleException::make('date_in_future', 'A payment cannot be dated after today.');
        }
        $uuid = (string) ($data['uuid'] ?? Str::uuid());
        if (! Str::isUuid($uuid)) {
            throw BusinessRuleException::make('uuid_invalid', 'The payment id is not valid.');
        }
        $split = array_key_exists('split', $data) && $data['split'] !== null ? self::splitMap((array) $data['split']) : null;
        $payload = ['lease_id' => (int) $lease->id, 'amount_minor' => $amount, 'method' => $method, 'split' => $split];
        if (($same = $this->byUuid($cid, $uuid, $payload)) !== null) {
            return $same;
        }

        return RentalLedger::locked($cid, function () use ($by, $lease, $data, $channel, $cid, $amount, $method, $paidOn, $uuid, $payload, $split) {
            if (($same = $this->byUuid($cid, $uuid, $payload)) !== null) {
                return $same;
            }
            $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($lease->id);
            if ($lease->status === 'cancelled') {
                throw BusinessRuleException::make('lease_not_live', 'This lease was cancelled: record the payment on the tenant\'s current lease.');
            }
            if ($lease->status === 'ended' && self::openCharges($lease)->isEmpty() && Deposits::held($lease) === 0) {
                throw BusinessRuleException::make('lease_not_live', 'This lease has ended and owes nothing, so there is no bill for this money to settle.');
            }
            if ($split !== null) {
                $this->assertSplit($lease, $split, $amount);
            }
            $p = RentalPayment::create([
                'company_id' => $cid, 'uuid' => $uuid, 'number' => RentalsModule::nextNumber($cid, 'receipt'),
                'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'unit_id' => $lease->unit_id, 'property_id' => $lease->property_id,
                'landlord_id' => $lease->landlord_id, 'amount_minor' => $amount, 'method' => $method,
                'reference' => self::text($data['reference'] ?? null, 100), 'paid_on' => $paidOn->toDateString(),
                'received_by_id' => $by?->id, 'channel' => $channel, 'notes' => self::text($data['notes'] ?? null, 255),
                'unallocated_minor' => $amount, 'device_id' => self::text($data['device_id'] ?? null, 64),
            ]);
            $group = RentalLedger::group();
            $left = $this->allocate($p, $lease, $amount, 'cash', 'direct', $paidOn, $by?->id, $group, null, $split);
            if ($left > 0) {
                RentalLedger::post($cid, $group, [self::row($lease, $p, 'prepaid', $paidOn, ['cash_minor' => $left, 'credit_minor' => $left], $method,
                    'Paid ahead')], $by?->id);
            }
            $p->forceFill(['unallocated_minor' => $left])->save();
            RentalAudit::record($cid, 'payment.received', $p, "Received {$p->number} for lease {$lease->number}", ['amount_minor' => $amount, 'method' => $method], $by ? 'staff' : 'system', $by?->id);

            return Receipt::stamp($p);
        });
    }

    /** Forgive part or all of one bill (rentals_bill): no money moves, the bill is settled by that much (§5.4). */
    public function waive(User $by, RentalCharge $charge, int $amount, string $reason, ?Carbon $on = null): RentalPayment
    {
        RentalAccess::authorize($by, 'rentals_bill');
        RentalAccess::assertInScope($by, (int) $charge->property_id, 'bill');

        return $this->waiveCharge($charge, $amount, $reason, $by->id, $on);
    }

    /** The waiver itself, for services that settle a bill on someone's behalf (a move-out's unused days). */
    public function waiveCharge(RentalCharge $charge, int $amount, string $reason, ?int $userId, ?Carbon $on = null): RentalPayment
    {
        $cid = (int) $charge->company_id;
        $reason = trim($reason);
        if ($reason === '') {
            throw BusinessRuleException::make('reason_required', 'Say why this bill is forgiven.');
        }
        Minor::positive($amount);

        return RentalLedger::locked($cid, function () use ($charge, $amount, $reason, $userId, $cid, $on) {
            $charge = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($charge->id);
            if ($charge->status === 'void') {
                throw BusinessRuleException::make('charge_void', 'That bill was cancelled.');
            }
            if ($amount > $charge->left()) {
                throw BusinessRuleException::make('nothing_to_waive', 'You can forgive at most what is still owed on this bill ('.Minor::format($charge->left(), $cid).').');
            }
            $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($charge->lease_id);
            $on ??= LocalDate::today($cid);
            $p = RentalPayment::create([
                'company_id' => $cid, 'uuid' => (string) Str::uuid(), 'number' => RentalsModule::nextNumber($cid, 'receipt'),
                'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'unit_id' => $lease->unit_id, 'property_id' => $lease->property_id,
                'landlord_id' => $lease->landlord_id, 'amount_minor' => $amount, 'method' => 'waiver', 'paid_on' => $on->toDateString(),
                'received_by_id' => $userId, 'channel' => 'system', 'notes' => mb_substr($reason, 0, 255), 'unallocated_minor' => 0,
                'waived_charge_id' => $charge->id,
            ]);
            $this->allocate($p, $lease, $amount, 'waiver', 'direct', $on, $userId, RentalLedger::group(), [$charge->id]);
            RentalAudit::record($cid, 'charge.waived', $charge, "Forgave part of a bill on lease {$lease->number}", ['amount_minor' => $amount, 'reason' => $reason], $userId ? 'staff' : 'system', $userId);

            return Receipt::stamp($p);
        });
    }

    /** Take back a waiver, for a service whose bill no longer stands (a move-out cancelling a later period): no money moved, so none does. */
    public function reverseWaiver(RentalPayment $waiver, string $why, ?int $userId): void
    {
        $cid = (int) $waiver->company_id;
        RentalLedger::locked($cid, function () use ($waiver, $why, $userId, $cid) {
            foreach (RentalAllocation::withoutGlobalScopes()->where('company_id', $cid)->where('payment_id', $waiver->id)->whereNull('reversed_at')->whereNull('reverses_id')->lockForUpdate()->get() as $a) {
                RentalAllocation::create(['company_id' => $cid, 'payment_id' => $waiver->id, 'charge_id' => $a->charge_id, 'source' => $a->source, 'amount_minor' => -(int) $a->amount_minor,
                    'reverses_id' => $a->id, 'applied_on' => LocalDate::today($cid)->toDateString(), 'created_at' => now()]);
                $a->forceFill(['reversed_at' => now()])->save();
                $c = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($a->charge_id);
                $c->forceFill(['paid_minor' => max(0, (int) $c->paid_minor - (int) $a->amount_minor), 'status' => $c->status === 'void' ? 'void' : 'open'])->save();
            }
            $waiver->forceFill(['reversed_at' => now(), 'reversed_by_id' => $userId, 'reverse_reason' => mb_substr($why, 0, 255)])->save();
        });
    }

    /** Use the deposit held for a lease to settle what it owes (move-out, §5.7): never a deposit bill. */
    public function useDeposit(RentalLease $lease, int $amount, Carbon $on, ?int $userId): ?RentalPayment
    {
        $cid = (int) $lease->company_id;
        if ($amount <= 0) {
            return null;
        }

        return RentalLedger::locked($cid, function () use ($lease, $amount, $on, $userId, $cid) {
            $held = Deposits::held($lease);
            if ($amount > $held) {
                throw BusinessRuleException::make('deposit_short', 'The deposit held is only '.Minor::format($held, $cid).'.');
            }
            $owed = self::openCharges($lease, false)->reject(fn ($c) => $c->kind === 'deposit')->sum(fn ($c) => $c->left());
            $amount = min($amount, (int) $owed);
            if ($amount <= 0) {
                return null;
            }
            $p = RentalPayment::create([
                'company_id' => $cid, 'uuid' => (string) Str::uuid(), 'number' => RentalsModule::nextNumber($cid, 'receipt'),
                'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'unit_id' => $lease->unit_id, 'property_id' => $lease->property_id,
                'landlord_id' => $lease->landlord_id, 'amount_minor' => $amount, 'method' => 'deposit', 'paid_on' => $on->toDateString(),
                'received_by_id' => $userId, 'channel' => 'system', 'notes' => 'Deposit used at move-out', 'unallocated_minor' => 0,
            ]);
            $this->allocate($p, $lease, $amount, 'deposit', 'direct', $on, $userId, RentalLedger::group());

            return Receipt::stamp($p);
        });
    }

    /** Credit a tenant had paid ahead before Estate Ledger (an import): no cash now, it is in the opening cash (§5.10). */
    public function openingCredit(RentalLease $lease, int $amount, Carbon $on, ?int $userId): RentalPayment
    {
        $cid = (int) $lease->company_id;
        Minor::positive($amount);

        return RentalLedger::locked($cid, function () use ($lease, $amount, $on, $userId, $cid) {
            $p = RentalPayment::create([
                'company_id' => $cid, 'uuid' => (string) Str::uuid(), 'number' => RentalsModule::nextNumber($cid, 'receipt'),
                'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'unit_id' => $lease->unit_id, 'property_id' => $lease->property_id,
                'landlord_id' => $lease->landlord_id, 'amount_minor' => $amount, 'method' => 'opening', 'paid_on' => $on->toDateString(),
                'received_by_id' => $userId, 'channel' => 'import', 'notes' => 'Paid ahead before Estate Ledger', 'unallocated_minor' => $amount,
            ]);
            RentalLedger::post($cid, RentalLedger::group(), [self::row($lease, $p, 'opening_credit', $on, ['credit_minor' => $amount, 'equity_minor' => -$amount], null, 'Credit brought in')], $userId);
            $this->applyCredit($lease, $on, $userId);

            return Receipt::stamp($p);
        });
    }

    /**
     * A deposit already held when the tenancy was brought in (an import): a deposit bill paid by opening money, so the
     * deposit held counts it like any other (journal `opening_deposit`: deposit +, equity −).
     */
    public function openingDeposit(RentalLease $lease, int $amount, Carbon $on, ?int $userId): RentalPayment
    {
        $cid = (int) $lease->company_id;
        Minor::positive($amount);

        return RentalLedger::locked($cid, function () use ($lease, $amount, $on, $userId, $cid) {
            $charge = app(\App\Services\Rentals\Billing\Billing::class)->makeFor($lease, ['kind' => 'deposit', 'description' => 'Security deposit (brought forward)',
                'due_date' => $on->toDateString(), 'amount_minor' => $amount, 'goes_to' => 'deposit', 'source' => 'import', 'dedupe_key' => "deposit:{$lease->id}",
                'created_by_id' => $userId, 'commission_type' => 'none', 'commission_bp' => 0, 'commission_minor' => 0])
                ?? throw BusinessRuleException::make('deposit_exists', 'This lease already has a deposit bill.');
            $p = RentalPayment::create([
                'company_id' => $cid, 'uuid' => (string) Str::uuid(), 'number' => RentalsModule::nextNumber($cid, 'receipt'),
                'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'unit_id' => $lease->unit_id, 'property_id' => $lease->property_id,
                'landlord_id' => $lease->landlord_id, 'amount_minor' => $amount, 'method' => 'opening', 'paid_on' => $on->toDateString(),
                'received_by_id' => $userId, 'channel' => 'import', 'notes' => 'Deposit held before Estate Ledger', 'unallocated_minor' => 0,
            ]);
            $landlordHolds = $lease->deposit_held_by === 'landlord';
            $alloc = RentalAllocation::create(['company_id' => $cid, 'payment_id' => $p->id, 'charge_id' => $charge->id, 'source' => 'direct', 'amount_minor' => $amount,
                'landlord_minor' => $landlordHolds ? $amount : 0, 'deposit_minor' => $landlordHolds ? 0 : $amount, 'applied_on' => $on->toDateString(), 'created_at' => now()]);
            $charge->forceFill(['paid_minor' => $amount, 'status' => 'paid'])->save();
            RentalLedger::post($cid, RentalLedger::group(), [self::row($lease, $p, 'opening_deposit', $on,
                ($landlordHolds ? ['landlord_minor' => $amount] : ['deposit_minor' => $amount]) + ['equity_minor' => -$amount], null, 'Deposit brought forward', $charge, $alloc)], $userId);

            return Receipt::stamp($p);
        });
    }

    /** Let credit (money paid ahead) settle open bills, oldest credit and oldest bill first (§5.5). */
    public function applyCredit(RentalLease $lease, ?Carbon $on = null, ?int $userId = null): int
    {
        $cid = (int) $lease->company_id;

        return RentalLedger::locked($cid, function () use ($lease, $on, $userId, $cid) {
            $on ??= LocalDate::today($cid);
            $applied = 0;
            $payments = RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $lease->id)
                ->whereNull('reversed_at')->where('unallocated_minor', '>', 0)->orderBy('paid_on')->orderBy('id')->lockForUpdate()->get();
            foreach ($payments as $p) {
                if (self::openCharges($lease, false)->isEmpty()) {
                    break;
                }
                $before = (int) $p->unallocated_minor;
                $left = $this->allocate($p, $lease, $before, 'credit', 'credit', $on, $userId, RentalLedger::group());
                if ($left !== $before) {
                    $p->forceFill(['unallocated_minor' => $left])->save();
                    $applied += $before - $left;
                }
            }

            return $applied;
        });
    }

    /**
     * Take $amount back off a bill into the tenant's credit, newest allocation first (a bill raised for a period after
     * a move-out, or the paid part of unused days). The money stays in hand; the landlord's and agency's shares of it
     * go back to the tenant (journal `unapplied`).
     */
    public function unapply(RentalCharge $charge, int $amount, Carbon $on, ?int $userId, string $why): int
    {
        $cid = (int) $charge->company_id;

        return RentalLedger::locked($cid, function () use ($charge, $amount, $on, $userId, $why, $cid) {
            $charge = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($charge->id);
            $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($charge->lease_id);
            $todo = min($amount, (int) $charge->paid_minor);
            $done = 0;
            $allocs = RentalAllocation::withoutGlobalScopes()->where('company_id', $cid)->where('charge_id', $charge->id)
                ->whereNull('reverses_id')->whereNull('reversed_at')->where('amount_minor', '>', 0)->orderByDesc('id')->lockForUpdate()->get();
            foreach ($allocs as $a) {
                if ($todo <= 0) {
                    break;
                }
                $p = RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->find($a->payment_id);
                if ($p === null || in_array($p->method, ['waiver', 'deposit'], true)) {
                    continue; // a waiver or deposit use is reversed, never turned into credit
                }
                $x = min($todo, (int) $a->amount_minor);
                $part = fn (int $v) => $x === (int) $a->amount_minor ? $v : Minor::share($v, $x, (int) $a->amount_minor);
                $c = $part((int) $a->commission_minor);
                $g = $part((int) $a->agency_minor);
                $d = $part((int) $a->deposit_minor);
                $l = $x - $c - $g - $d;
                $neg = RentalAllocation::create(['company_id' => $cid, 'payment_id' => $p->id, 'charge_id' => $charge->id, 'source' => $a->source,
                    'amount_minor' => -$x, 'commission_minor' => -$c, 'landlord_minor' => -$l, 'agency_minor' => -$g, 'deposit_minor' => -$d,
                    'reverses_id' => $a->id, 'applied_on' => $on->toDateString(), 'created_at' => now()]);
                if ($x === (int) $a->amount_minor) {
                    $a->forceFill(['reversed_at' => now()])->save();
                }
                $depositSide = $lease->deposit_held_by === 'landlord' ? ['landlord_minor' => -$l - $d] : ['landlord_minor' => -$l, 'deposit_minor' => -$d];
                RentalLedger::post($cid, RentalLedger::group(), [self::row($lease, $p, 'unapplied', $on,
                    $depositSide + ['income_minor' => -($c + $g), 'credit_minor' => $x], null, mb_substr($why, 0, 200), $charge, $neg)], $userId);
                $p->forceFill(['unallocated_minor' => (int) $p->unallocated_minor + $x])->save();
                $charge->forceFill(['paid_minor' => (int) $charge->paid_minor - $x, 'status' => 'open'])->save();
                $todo -= $x;
                $done += $x;
            }

            return $done;
        });
    }

    /** Pay a tenant's credit back to them (move-out, or on request): a `refund` bill settled from credit at once. */
    public function refundCredit(?User $by, RentalLease $lease, int $amount, string $method, Carbon $on, string $why = 'Credit refunded'): ?RentalCharge
    {
        $cid = (int) $lease->company_id;
        if ($by !== null) {
            RentalAccess::authorize($by, 'rentals_collect');
            RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        }
        if ($amount <= 0) {
            return null;
        }
        if (! in_array($method, RentalPayment::CASH_METHODS, true)) {
            throw BusinessRuleException::make('method_invalid', 'Choose how the money goes back: cash, mobile money, bank or cheque.');
        }

        return RentalLedger::locked($cid, function () use ($by, $lease, $amount, $method, $on, $why, $cid) {
            $credit = self::credit($lease);
            if ($amount > $credit) {
                throw BusinessRuleException::make('credit_short', 'The tenant has only '.Minor::format($credit, $cid).' paid ahead.');
            }
            CashGuard::assertCovers($cid, $method, $amount);
            $charge = RentalCharge::create(['company_id' => $cid, 'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'unit_id' => $lease->unit_id,
                'property_id' => $lease->property_id, 'landlord_id' => $lease->landlord_id, 'kind' => 'refund', 'description' => mb_substr($why, 0, 255),
                'due_date' => $on->toDateString(), 'amount_minor' => $amount, 'goes_to' => 'tenant', 'source' => 'manual', 'created_by_id' => $by?->id]);
            $todo = $amount;
            $payments = RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $lease->id)
                ->whereNull('reversed_at')->where('unallocated_minor', '>', 0)->orderBy('paid_on')->orderBy('id')->lockForUpdate()->get();
            foreach ($payments as $p) {
                if ($todo <= 0) {
                    break;
                }
                $x = min($todo, (int) $p->unallocated_minor);
                $alloc = RentalAllocation::create(['company_id' => $cid, 'payment_id' => $p->id, 'charge_id' => $charge->id, 'source' => 'credit',
                    'amount_minor' => $x, 'applied_on' => $on->toDateString(), 'created_at' => now()]);
                RentalLedger::post($cid, RentalLedger::group(), [self::row($lease, $p, 'credit_refund', $on, ['cash_minor' => -$x, 'credit_minor' => -$x], $method,
                    'Credit refunded', $charge, $alloc)], $by?->id);
                $p->forceFill(['unallocated_minor' => (int) $p->unallocated_minor - $x])->save();
                $todo -= $x;
            }
            $charge->forceFill(['paid_minor' => $amount, 'status' => 'paid'])->save();
            RentalAudit::record($cid, 'credit.refunded', $lease, "Refunded credit on lease {$lease->number}", ['amount_minor' => $amount, 'method' => $method], $by ? 'staff' : 'system', $by?->id);

            return $charge->fresh();
        });
    }

    /**
     * Credit kept at move-out, or asked for by a tenant, goes back later (rentals_manage): $amount of it, all by default.
     *
     * @return array{credit_refunded_minor: int, credit_minor: int}
     */
    public function refundKeptCredit(User $by, RentalLease $lease, ?int $amount, string $method, ?string $on = null): array
    {
        RentalAccess::authorize($by, 'rentals_manage');
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        $cid = (int) $lease->company_id;
        $day = LocalDate::date($cid, $on);
        if ($day->gt(LocalDate::today($cid))) {
            throw BusinessRuleException::make('date_in_future', 'A refund cannot be dated after today.');
        }

        return RentalLedger::locked($cid, function () use ($by, $lease, $amount, $method, $day) {
            $credit = self::credit($lease);
            if ($credit === 0) {
                throw BusinessRuleException::make('nothing_held', 'This lease has no money paid ahead.');
            }
            $back = $amount ?? $credit;
            $this->refundCredit($by, $lease, $back, $method, $day);

            return ['credit_refunded_minor' => $back, 'credit_minor' => self::credit($lease)];
        });
    }

    /**
     * Reverse a payment (rentals_reverse): every bill it settled reopens, its credit is gone, the journal rows it made
     * are negated, and other credit then settles what it can (§5.6).
     */
    public function reverse(User $by, RentalPayment $payment, string $reason): RentalPayment
    {
        RentalAccess::authorize($by, 'rentals_reverse');
        RentalAccess::assertInScope($by, (int) $payment->property_id, 'payment');
        $cid = (int) $payment->company_id;
        $reason = trim($reason);
        if ($reason === '') {
            throw BusinessRuleException::make('reason_required', 'Say why this payment is reversed.');
        }

        return RentalLedger::locked($cid, function () use ($by, $payment, $reason, $cid) {
            $p = RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($payment->id);
            if ($p->reversed_at !== null) {
                throw BusinessRuleException::make('already_reversed', 'This payment was already reversed.');
            }
            $refunded = RentalAllocation::withoutGlobalScopes()->where('company_id', $cid)->where('payment_id', $p->id)->whereNull('reversed_at')
                ->whereNull('reverses_id')->whereIn('charge_id', RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('kind', 'refund')->select('id'))->exists();
            if ($refunded) {
                throw BusinessRuleException::make('payment_refunded', 'Part of this payment was refunded to the tenant: it cannot be reversed.');
            }
            // Money that paid a deposit is the tenant's deposit. Once that has gone back or been used, taking the payment away would leave less than nothing held.
            if ($p->method !== 'waiver') {
                $toDeposit = (int) RentalAllocation::withoutGlobalScopes()->where('company_id', $cid)->where('payment_id', $p->id)
                    ->whereIn('charge_id', RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $p->lease_id)->where('kind', 'deposit')->select('id'))->sum('amount_minor');
                $leased = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($p->lease_id);
                if ($toDeposit > 0 && Deposits::collected($leased) - $toDeposit - Deposits::used($leased) - Deposits::refunded($leased) < 0) {
                    throw BusinessRuleException::make('deposit_gone', 'This payment paid a deposit that has since been refunded or used, so it cannot be reversed: the money has already gone out. Record what the tenant owes as a new bill instead.');
                }
            }
            $today = LocalDate::today($cid);
            $lease = RentalLease::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($p->lease_id);
            $allocs = RentalAllocation::withoutGlobalScopes()->where('company_id', $cid)->where('payment_id', $p->id)
                ->whereNull('reversed_at')->whereNull('reverses_id')->lockForUpdate()->get();
            $cols = ['amount_minor', 'commission_minor', 'landlord_minor', 'agency_minor', 'deposit_minor'];
            foreach ($allocs as $a) {
                // What is still on the bill from this allocation: the original less any part taken back (unapply).
                $back = RentalAllocation::withoutGlobalScopes()->where('company_id', $cid)->where('reverses_id', $a->id)
                    ->selectRaw(implode(', ', array_map(fn ($k) => "COALESCE(SUM({$k}), 0) AS {$k}", $cols)))->first();
                $net = [];
                foreach ($cols as $k) {
                    $net[$k] = (int) $a->{$k} + (int) $back->{$k};
                }
                RentalAllocation::create(['company_id' => $cid, 'payment_id' => $p->id, 'charge_id' => $a->charge_id, 'source' => $a->source,
                    'amount_minor' => -$net['amount_minor'], 'commission_minor' => -$net['commission_minor'], 'landlord_minor' => -$net['landlord_minor'],
                    'agency_minor' => -$net['agency_minor'], 'deposit_minor' => -$net['deposit_minor'], 'reverses_id' => $a->id,
                    'applied_on' => $today->toDateString(), 'created_at' => now()]);
                $a->forceFill(['reversed_at' => now()])->save();
                $net = $net['amount_minor'];
                $c = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->find($a->charge_id);
                if ($c !== null && $net !== 0) {
                    $c->forceFill(['paid_minor' => max(0, (int) $c->paid_minor - $net), 'status' => $c->status === 'void' ? 'void' : 'open'])->save();
                }
            }
            $rows = RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('payment_id', $p->id)->whereNull('reversed_at')->whereNull('reverses_id')->get();
            RentalLedger::reverse($cid, $rows, $today->toDateString(), $reason, $by->id);
            $p->forceFill(['unallocated_minor' => 0, 'reversed_at' => now(), 'reversed_by_id' => $by->id, 'reverse_reason' => mb_substr($reason, 0, 255)])->save();
            $this->applyCredit($lease, $today, $by->id);
            RentalAudit::record($cid, 'payment.reversed', $p, "Reversed {$p->number}", ['reason' => $reason], 'staff', $by->id);

            return $p->fresh();
        });
    }

    /**
     * What a payment of $amount would settle now, oldest first (for the Receive payment screen): no writes.
     *
     * @return array{lines: list<array{charge_id: int, description: string, due_date: string, amount_minor: int}>, credit_minor: int}
     */
    public function preview(RentalLease $lease, int $amount): array
    {
        $lines = [];
        $left = max(0, $amount);
        foreach (self::openCharges($lease, false) as $c) {
            if ($left <= 0) {
                break;
            }
            $a = min($left, $c->left());
            $lines[] = ['charge_id' => (int) $c->id, 'description' => (string) $c->description, 'due_date' => $c->due_date->toDateString(), 'amount_minor' => $a];
            $left -= $a;
        }

        return ['lines' => $lines, 'credit_minor' => $left];
    }

    /**
     * The split a payment would make, for a cashier who may change it: every open bill, oldest first (KR-10), with what
     * goes on it. Lines the cashier typed ($fixed: charge id => amount) stay as typed and the rest of the money flows
     * over the others. `credit_minor` is what would stay as credit; `unplaced_minor` is money on no bill while a bill
     * they cut short stays open; `over_minor` is how far the typed lines pass the payment. `ok` says receive() would take
     * it. No writes.
     *
     * @param  array<int, int>  $fixed
     * @return array{lines: list<array{charge_id: int, kind: string, description: string, due_date: string, left_minor: int, amount_minor: int, fixed: bool, too_much: bool}>,
     *               credit_minor: int, unplaced_minor: int, over_minor: int, ok: bool}
     */
    public function suggestSplit(RentalLease $lease, int $amount, array $fixed = []): array
    {
        $amount = max(0, $amount);
        $open = self::openCharges($lease, false);
        $typed = 0;
        foreach ($open as $c) {
            if (array_key_exists((int) $c->id, $fixed)) {
                $typed += max(0, (int) $fixed[(int) $c->id]);
            }
        }
        $rest = max(0, $amount - $typed);
        $lines = [];
        $short = false;
        foreach ($open as $c) {
            $id = (int) $c->id;
            $isFixed = array_key_exists($id, $fixed);
            $a = $isFixed ? max(0, (int) $fixed[$id]) : min($rest, $c->left());
            if (! $isFixed) {
                $rest -= $a;
            } elseif ($a < $c->left()) {
                $short = true;
            }
            $lines[] = ['charge_id' => $id, 'kind' => (string) $c->kind, 'description' => (string) $c->description, 'due_date' => $c->due_date->toDateString(),
                'left_minor' => $c->left(), 'amount_minor' => $a, 'fixed' => $isFixed, 'too_much' => $a > $c->left()];
        }
        $over = max(0, $typed - $amount);
        $unplaced = $short ? $rest : 0;

        return ['lines' => $lines, 'credit_minor' => $short ? 0 : $rest, 'unplaced_minor' => $unplaced, 'over_minor' => $over,
            'ok' => $over === 0 && $unplaced === 0 && ! collect($lines)->contains('too_much', true)];
    }

    /** A lease's open bills in the order money settles them (KR-10). @return Collection<int, RentalCharge> */
    public static function openCharges(RentalLease $lease, bool $lock = true): Collection
    {
        $q = RentalCharge::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('lease_id', $lease->id)
            ->where('status', 'open')->where('kind', '!=', 'refund');
        if ($lock && RentalLedger::isLocked((int) $lease->company_id)) {
            $q->lockForUpdate();
        }

        return $q->get()->sort(function (RentalCharge $a, RentalCharge $b) {
            return [$a->due_date->toDateString(), RentalCharge::PRIORITY[$a->kind] ?? 9, $a->id] <=> [$b->due_date->toDateString(), RentalCharge::PRIORITY[$b->kind] ?? 9, $b->id];
        })->values();
    }

    /** Money the tenant has paid ahead on this lease. */
    public static function credit(RentalLease $lease): int
    {
        return (int) RentalPayment::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('lease_id', $lease->id)
            ->whereNull('reversed_at')->sum('unallocated_minor');
    }

    /**
     * Spread $amount of payment $p over the lease's open bills (or only $only), oldest first. Returns what is left.
     * $funding: cash | credit | deposit | waiver (a waiver's split is zero and posts no journal row).
     */
    private function allocate(RentalPayment $p, RentalLease $lease, int $amount, string $funding, string $source, Carbon $on, ?int $userId, string $group, ?array $only = null, ?array $plan = null): int
    {
        $cid = (int) $lease->company_id;
        $left = $amount;
        $charges = self::openCharges($lease);
        if ($only !== null) {
            $charges = $charges->filter(fn ($c) => in_array((int) $c->id, $only, true))->values();
        }
        if ($plan !== null) {
            $charges = $charges->filter(fn ($c) => isset($plan[(int) $c->id]))->values();
        }
        foreach ($charges as $c) {
            if ($left <= 0) {
                break;
            }
            if ($funding === 'deposit' && $c->kind === 'deposit') {
                continue;
            }
            $a = min($left, $c->left(), $plan[(int) $c->id] ?? PHP_INT_MAX);
            if ($a <= 0) {
                continue;
            }
            $split = $funding === 'waiver'
                ? ['commission' => 0, 'landlord' => 0, 'agency' => 0, 'deposit' => 0]
                : Splits::split($c->only(['amount_minor', 'goes_to', 'commission_type', 'commission_bp', 'commission_minor']), $a, (int) $c->paid_minor, (string) $lease->deposit_held_by,
                    ...self::commissionSoFar($c));
            $alloc = RentalAllocation::create(['company_id' => $cid, 'payment_id' => $p->id, 'charge_id' => $c->id, 'source' => $source,
                'amount_minor' => $a, 'commission_minor' => $split['commission'], 'landlord_minor' => $split['landlord'],
                'agency_minor' => $split['agency'], 'deposit_minor' => $split['deposit'], 'applied_on' => $on->toDateString(), 'created_at' => now()]);
            $paid = (int) $c->paid_minor + $a;
            $c->forceFill(['paid_minor' => $paid, 'status' => $paid >= (int) $c->amount_minor ? 'paid' : 'open'])->save();
            if ($funding !== 'waiver') {
                $effects = ['landlord_minor' => $split['landlord'], 'deposit_minor' => $split['deposit'], 'income_minor' => $split['commission'] + $split['agency']];
                $method = null;
                if ($funding === 'cash') {
                    $effects['cash_minor'] = $a;
                    $method = $p->method;
                } elseif ($funding === 'credit') {
                    $effects['credit_minor'] = -$a;
                } elseif ($lease->deposit_held_by === 'landlord') {
                    $effects['landlord_minor'] -= $a; // the landlord already holds the deposit that pays this bill
                } else {
                    $effects['deposit_minor'] -= $a;
                }
                RentalLedger::post($cid, $group, [self::row($lease, $p, self::ROW[$funding], $on, $effects, $method, (string) $c->description, $c, $alloc)], $userId);
            }
            $left -= $a;
        }

        return $left;
    }

    /**
     * What a commissionable bill's allocations already hold: the commission taken, and the part forgiven (which carries
     * none), for Splits::split. @return array{0: ?int, 1: int}
     */
    private static function commissionSoFar(RentalCharge $c): array
    {
        if ($c->goes_to !== 'landlord' || $c->commission_type === 'none' || (int) $c->paid_minor === 0) {
            return [$c->goes_to === 'landlord' && $c->commission_type !== 'none' ? 0 : null, 0];
        }
        $rows = RentalAllocation::withoutGlobalScopes()->from('rental_allocations as a')->join('rental_payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.company_id', $c->company_id)->where('a.charge_id', $c->id)
            ->selectRaw("COALESCE(SUM(a.commission_minor), 0) AS commission, COALESCE(SUM(CASE WHEN p.method = 'waiver' THEN a.amount_minor ELSE 0 END), 0) AS waived")->first();

        return [(int) $rows->commission, (int) $rows->waived];
    }

    /** A journal row about one payment (and optionally one bill and allocation). */
    public static function row(RentalLease $lease, ?RentalPayment $p, string $type, Carbon $on, array $effects, ?string $method, string $description, ?RentalCharge $c = null, ?RentalAllocation $a = null): array
    {
        return $effects + [
            'type' => $type, 'occurred_on' => $on->toDateString(), 'method' => $method, 'description' => $description,
            'landlord_id' => $c?->landlord_id ?? $lease->landlord_id, 'property_id' => $c?->property_id ?? $lease->property_id,
            'unit_id' => $c?->unit_id ?? $lease->unit_id, 'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
            'payment_id' => $p?->id, 'allocation_id' => $a?->id, 'charge_id' => $c?->id,
        ];
    }

    private function byUuid(int $cid, string $uuid, array $payload): ?RentalPayment
    {
        $p = RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first();
        if ($p === null) {
            return null;
        }
        if ((int) $p->lease_id !== $payload['lease_id'] || (int) $p->amount_minor !== $payload['amount_minor'] || $p->method !== $payload['method']) {
            throw BusinessRuleException::make('idempotency_conflict', 'This payment id was already used for a different payment.');
        }
        if ($payload['split'] !== null) {
            $made = RentalAllocation::withoutGlobalScopes()->where('company_id', $cid)->where('payment_id', $p->id)->where('source', 'direct')->whereNull('reverses_id')
                ->pluck('amount_minor', 'charge_id')->map(fn ($v) => (int) $v)->all();
            ksort($made);
            $asked = $payload['split'];
            ksort($asked);
            if ($made !== $asked) {
                throw BusinessRuleException::make('idempotency_conflict', 'This payment id was already used for a different split.');
            }
        }

        return $p;
    }

    /**
     * A cashier's split as charge id => amount (lines of the same bill added up, zero lines dropped).
     *
     * @param  array<int, mixed>  $lines
     * @return array<int, int>
     */
    private static function splitMap(array $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            $id = is_array($line) ? (int) ($line['charge_id'] ?? 0) : 0;
            $a = is_array($line) ? ($line['amount_minor'] ?? null) : null;
            if ($id <= 0 || ! is_int($a) || $a < 0) {
                throw BusinessRuleException::make('split_invalid', 'Each line of the split needs a bill and an amount in figures.');
            }
            if ($a > 0) {
                $map[$id] = ($map[$id] ?? 0) + $a;
            }
        }

        return $map;
    }

    /** Refuse a split that names another lease's bill, puts more on a bill than it owes, or does not add up to the payment. */
    private function assertSplit(RentalLease $lease, array $split, int $amount): void
    {
        $cid = (int) $lease->company_id;
        $open = self::openCharges($lease)->keyBy(fn ($c) => (int) $c->id);
        foreach ($split as $id => $a) {
            $c = $open->get($id);
            if ($c === null) {
                $bill = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $lease->id)->where('kind', '!=', 'refund')->find($id);
                throw $bill === null
                    ? BusinessRuleException::make('split_wrong_bill', 'That bill is not on this lease.')
                    : BusinessRuleException::make('split_closed', "{$bill->description} is no longer open.");
            }
            if ($a > $c->left()) {
                throw BusinessRuleException::make('split_too_much', 'You can put at most '.Minor::format($c->left(), $cid)." on {$c->description}.");
            }
        }
        $placed = array_sum($split);
        if ($placed > $amount) {
            throw BusinessRuleException::make('split_total', 'The split adds up to '.Minor::format($placed, $cid).' but the payment is '.Minor::format($amount, $cid).'.');
        }
        if ($placed < $amount && $open->sum(fn ($c) => $c->left()) > $placed) {
            throw BusinessRuleException::make('split_total', Minor::format($amount - $placed, $cid).' is on no bill while bills are still open. Put it on a bill.');
        }
    }

    private static function text(mixed $v, int $max): ?string
    {
        $v = trim((string) ($v ?? ''));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
