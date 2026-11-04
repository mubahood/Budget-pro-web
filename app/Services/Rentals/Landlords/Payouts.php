<?php

namespace App\Services\Rentals\Landlords;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalEntry;
use App\Models\RentalLandlord;
use App\Models\RentalPayout;
use App\Models\User;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\CashGuard;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;

/** Money paid to a landlord out of what was collected for them (rentals_landlords); in owner mode, an owner drawing. */
final class Payouts
{
    public const METHODS = ['cash', 'bank', 'mobile_money', 'cheque'];

    /** $data: amount_minor, method, paid_on, reference, period_from, period_to, notes, advance (pay more than the balance). */
    public function record(User $by, RentalLandlord $landlord, array $data): RentalPayout
    {
        RentalAccess::authorize($by, 'rentals_landlords');
        $cid = (int) $landlord->company_id;
        $amount = Minor::positive($data['amount_minor'] ?? null);
        $method = (string) ($data['method'] ?? $landlord->payout_method ?? 'bank');
        if (! in_array($method, self::METHODS, true)) {
            throw BusinessRuleException::make('method_invalid', 'Choose cash, bank, mobile money or cheque.');
        }
        $on = LocalDate::date($cid, $data['paid_on'] ?? null);
        if ($on->gt(LocalDate::today($cid))) {
            throw BusinessRuleException::make('date_in_future', 'A payout cannot be dated after today.');
        }

        return RentalLedger::locked($cid, function () use ($by, $landlord, $data, $cid, $amount, $method, $on) {
            $balance = LandlordAccounts::balance($landlord);
            $owner = RentalsModule::isOwnerMode($cid);
            if ($amount > $balance && empty($data['advance'])) {
                $have = Minor::format(max(0, $balance), $cid);
                throw BusinessRuleException::make('payout_above_balance', $owner ? "Only {$have} is in hand from the property. Tick \"pay ahead\" to take more."
                    : "The landlord is owed {$have}. Tick \"pay ahead\" to pay more.", ['balance_minor' => $balance]);
            }
            CashGuard::assertCovers($cid, $method, $amount);
            $p = RentalPayout::create(['company_id' => $cid, 'number' => RentalsModule::nextNumber($cid, 'payout'), 'landlord_id' => $landlord->id,
                'amount_minor' => $amount, 'method' => $method, 'reference' => isset($data['reference']) ? mb_substr((string) $data['reference'], 0, 100) : null,
                'paid_on' => $on->toDateString(), 'period_from' => $data['period_from'] ?? null, 'period_to' => $data['period_to'] ?? null,
                'notes' => isset($data['notes']) ? mb_substr((string) $data['notes'], 0, 255) : null, 'created_by_id' => $by->id]);
            RentalLedger::post($cid, RentalLedger::group(), [['type' => 'payout', 'occurred_on' => $on->toDateString(), 'method' => $method,
                'cash_minor' => -$amount, 'landlord_minor' => -$amount, 'landlord_id' => $landlord->id, 'payout_id' => $p->id,
                'description' => ($owner ? 'Owner drawing ' : 'Payout ').$p->number]], $by->id);
            RentalAudit::record($cid, 'payout.recorded', $p, ($owner ? 'Owner drawing ' : 'Paid ').Minor::format($amount, $cid)." to {$landlord->name}", ['method' => $method], 'staff', $by->id);

            return $p->fresh();
        });
    }

    public function reverse(User $by, RentalPayout $payout, string $reason): RentalPayout
    {
        RentalAccess::authorize($by, 'rentals_reverse');
        $cid = (int) $payout->company_id;
        $word = RentalsModule::isOwnerMode($cid) ? 'drawing' : 'payout';
        if (trim($reason) === '') {
            throw BusinessRuleException::make('reason_required', "Say why this {$word} is reversed.");
        }

        return RentalLedger::locked($cid, function () use ($by, $payout, $reason, $cid, $word) {
            $p = RentalPayout::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->findOrFail($payout->id);
            if ($p->reversed_at !== null) {
                throw BusinessRuleException::make('already_reversed', "This {$word} was already reversed.");
            }
            $rows = RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('payout_id', $p->id)->whereNull('reversed_at')->whereNull('reverses_id')->get();
            RentalLedger::reverse($cid, $rows, LocalDate::today($cid)->toDateString(), $reason, $by->id);
            $p->forceFill(['reversed_at' => now(), 'reversed_by_id' => $by->id, 'reverse_reason' => mb_substr($reason, 0, 255)])->save();
            RentalAudit::record($cid, 'payout.reversed', $p, "Reversed {$word} {$p->number}", ['reason' => $reason], 'staff', $by->id);

            return $p->fresh();
        });
    }
}
