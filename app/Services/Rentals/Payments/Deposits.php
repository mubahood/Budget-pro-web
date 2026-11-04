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
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;

/**
 * Security deposits (KR-13, docs/estate-ledger/04-architecture.md §4): the tenant's money until the lease ends. Held by
 * the agency (a liability in `deposit_minor`) or passed to the landlord with their collections, per lease.
 *
 * Deposit held = collected (allocations to the lease's deposit bills) − used at move-out (`deposit` payments) −
 * refunded. Reversed rows never count: a reversal marks its original.
 */
final class Deposits
{
    public const REFUND_TYPES = ['deposit_refund', 'deposit_refund_landlord'];

    public static function collected(RentalLease $lease): int
    {
        $deposits = RentalCharge::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('lease_id', $lease->id)->where('kind', 'deposit')->select('id');

        // A forgiven part of a deposit bill (a waiver, as at move-out) settled the bill, but no money came in.
        $waivers = RentalPayment::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('lease_id', $lease->id)->where('method', 'waiver')->select('id');

        return (int) RentalAllocation::withoutGlobalScopes()->where('company_id', $lease->company_id)->whereIn('charge_id', $deposits)
            ->whereNotIn('payment_id', $waivers)->sum('amount_minor');
    }

    public static function used(RentalLease $lease): int
    {
        return (int) RentalPayment::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('lease_id', $lease->id)
            ->where('method', 'deposit')->whereNull('reversed_at')->sum('amount_minor');
    }

    public static function refunded(RentalLease $lease): int
    {
        return (int) -RentalEntry::withoutGlobalScopes()->where('company_id', $lease->company_id)->where('lease_id', $lease->id)
            ->whereIn('type', self::REFUND_TYPES)->whereNull('reversed_at')->sum('cash_minor');
    }

    public static function held(RentalLease $lease): int
    {
        return max(0, self::collected($lease) - self::used($lease) - self::refunded($lease));
    }

    /**
     * A deposit kept at move-out goes back later (rentals_manage; §5.7 "keep it on the lease to refund later"): first it
     * settles whatever the lease still owes (a damage found after they left), then $amount of the rest goes back, all
     * of it by default. A tenant still living there has their deposit held until they leave.
     *
     * @return array{deposit_used_minor: int, deposit_refunded_minor: int, deposit_held_minor: int, still_owed_minor: int}
     */
    public static function refundKept(User $by, RentalLease $lease, ?int $amount, string $method, ?string $on = null): array
    {
        RentalAccess::authorize($by, 'rentals_manage');
        RentalAccess::assertInScope($by, (int) $lease->property_id, 'lease');
        $cid = (int) $lease->company_id;
        if (! in_array($method, RentalPayment::CASH_METHODS, true)) {
            throw BusinessRuleException::make('method_invalid', 'Choose how the deposit goes back: cash, mobile money, bank or cheque.');
        }
        if ($lease->isLive()) {
            throw BusinessRuleException::make('lease_live', 'The tenant still lives here: their deposit stays held until they move out.');
        }
        $day = LocalDate::date($cid, $on);
        if ($day->gt(LocalDate::today($cid))) {
            throw BusinessRuleException::make('date_in_future', 'A refund cannot be dated after today.');
        }

        return RentalLedger::locked($cid, function () use ($by, $lease, $amount, $method, $day, $cid) {
            $owed = (int) Payments::openCharges($lease)->reject(fn ($c) => $c->kind === 'deposit')->sum(fn ($c) => $c->left());
            $used = app(Payments::class)->useDeposit($lease, min(self::held($lease), $owed), $day, (int) $by->id);
            $left = self::held($lease);
            if ($left === 0 && $used === null) {
                throw BusinessRuleException::make('nothing_held', 'No deposit is held for this lease.');
            }
            if ($amount !== null && $amount > $left) {
                throw BusinessRuleException::make('deposit_short', 'The deposit held is only '.Minor::format($left, $cid).'.');
            }
            $back = $amount ?? $left;
            self::refund($by, $lease, $back, $method, $day);
            $owedAfter = (int) Payments::openCharges($lease)->reject(fn ($c) => $c->kind === 'deposit')->sum(fn ($c) => $c->left());

            return ['deposit_used_minor' => (int) ($used?->amount_minor ?? 0), 'deposit_refunded_minor' => $back, 'deposit_held_minor' => self::held($lease), 'still_owed_minor' => $owedAfter];
        });
    }

    /** Pay the deposit (or part of it) back to the tenant. When the landlord holds it, the agency pays it for them. */
    public static function refund(?User $by, RentalLease $lease, int $amount, string $method, Carbon $on): ?RentalEntry
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
            throw BusinessRuleException::make('method_invalid', 'Choose how the deposit goes back: cash, mobile money, bank or cheque.');
        }

        return RentalLedger::locked($cid, function () use ($by, $lease, $amount, $method, $on, $cid) {
            $held = self::held($lease);
            if ($amount > $held) {
                throw BusinessRuleException::make('deposit_short', 'The deposit held is only '.Minor::format($held, $cid).'.');
            }
            CashGuard::assertCovers($cid, $method, $amount);
            $landlord = $lease->deposit_held_by === 'landlord';
            $rows = RentalLedger::post($cid, RentalLedger::group(), [Payments::row($lease, null, $landlord ? 'deposit_refund_landlord' : 'deposit_refund', $on,
                ['cash_minor' => -$amount] + ($landlord ? ['landlord_minor' => -$amount] : ['deposit_minor' => -$amount]), $method,
                $landlord ? 'Deposit refunded for the landlord' : 'Deposit refunded')], $by?->id);
            RentalAudit::record($cid, 'deposit.refunded', $lease, "Refunded the deposit on lease {$lease->number}", ['amount_minor' => $amount, 'method' => $method], $by ? 'staff' : 'system', $by?->id);

            return $rows[0] ?? null;
        });
    }
}
