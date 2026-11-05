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
use App\Services\Rentals\Reports\MoneyPosition;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;

/** Money paid to a landlord out of what was collected for them (rentals_landlords); in owner mode, an owner drawing. */
final class Payouts
{
    public const METHODS = ['cash', 'bank', 'mobile_money', 'cheque'];

    /**
     * $data: amount_minor, method, paid_on, reference, period_from, period_to, notes; to pay more than is owed: advance (the tick),
     * advance_reason, and advance_others_ok once told that the advance would come out of money held for others. A payout keeps what it
     * was: the landlord's payment details then, what they were owed before and after, and the part paid ahead. The run a payout belongs
     * to comes only from PayoutRuns.
     */
    public function record(User $by, RentalLandlord $landlord, array $data, ?int $runId = null): RentalPayout
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

        return RentalLedger::locked($cid, function () use ($by, $landlord, $data, $cid, $amount, $method, $on, $runId) {
            $landlord = RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($landlord->id);
            $balance = LandlordAccounts::balance($landlord);
            $owner = RentalsModule::isOwnerMode($cid);
            $advance = max(0, $amount - max(0, $balance));
            $reason = null;
            if ($advance > 0) {
                $reason = self::advanceReason($cid, $advance, $balance, $owner, $data);
            }
            CashGuard::assertCovers($cid, $method, $amount);
            $paidOn = $on->toDateString();
            $p = RentalPayout::create(['company_id' => $cid, 'number' => RentalsModule::nextNumber($cid, 'payout'), 'run_id' => $runId, 'landlord_id' => $landlord->id,
                'amount_minor' => $amount, 'method' => $method, 'reference' => isset($data['reference']) ? mb_substr((string) $data['reference'], 0, 100) : null,
                'paid_on' => $paidOn, 'period_from' => $data['period_from'] ?? $on->copy()->startOfMonth()->toDateString(), 'period_to' => $data['period_to'] ?? $on->copy()->endOfMonth()->toDateString(),
                'notes' => isset($data['notes']) ? mb_substr((string) $data['notes'], 0, 255) : null, 'created_by_id' => $by->id,
                'paid_to_snapshot' => trim((string) $landlord->payout_details) !== '' ? mb_substr(trim((string) $landlord->payout_details), 0, 500) : null,
                'balance_before_minor' => $balance, 'balance_after_minor' => $balance - $amount, 'advance_minor' => $advance, 'advance_reason' => $reason]);
            $ahead = $advance > 0 ? ' ('.Minor::format($advance, $cid).($owner ? ' taken ahead)' : ' paid ahead)') : '';
            RentalLedger::post($cid, RentalLedger::group(), [['type' => 'payout', 'occurred_on' => $paidOn, 'method' => $method,
                'cash_minor' => -$amount, 'landlord_minor' => -$amount, 'landlord_id' => $landlord->id, 'payout_id' => $p->id,
                'description' => ($owner ? 'Owner drawing ' : 'Payout ').$p->number.$ahead]], $by->id);
            RentalAudit::record($cid, 'payout.recorded', $p, ($owner ? 'Owner drawing ' : 'Paid ').Minor::format($amount, $cid)." to {$landlord->name}", ['method' => $method] + ($advance > 0 ? ['advance_minor' => $advance, 'reason' => $reason] : []), 'staff', $by->id);

            return $p->fresh();
        });
    }

    /**
     * What may be paid above the balance (LC-11): the tick, a reason, and a second tick if the agency's own money does not cover the
     * advance. The second is a warning that can be passed, never a refusal.
     *
     * @param  array<string, mixed>  $data
     */
    private static function advanceReason(int $cid, int $advance, int $balance, bool $owner, array $data): string
    {
        if (empty($data['advance'])) {
            $have = Minor::format(max(0, $balance), $cid);
            throw BusinessRuleException::make('payout_above_balance', $owner ? "Only {$have} is in hand from the property. Tick \"pay ahead\" to take more."
                : "The landlord is owed {$have}. Tick \"pay ahead\" to pay more.", ['balance_minor' => $balance]);
        }
        $reason = trim((string) ($data['advance_reason'] ?? ''));
        if ($reason === '') {
            throw BusinessRuleException::make('advance_reason_required', 'Say why '.Minor::format($advance, $cid).($owner ? ' is taken ahead.' : ' is paid ahead.').' It stays on the record.', ['advance_minor' => $advance]);
        }
        $others = self::fromOthers($cid, $advance);
        if ($others > 0 && empty($data['advance_others_ok'])) {
            throw BusinessRuleException::make('advance_from_others', self::othersWords($cid, $others, $owner), ['advance_minor' => $advance, 'others_minor' => $others]);
        }

        return mb_substr($reason, 0, 255);
    }

    /** How much of an advance would come out of money held for others: the part the agency's own money does not cover. */
    public static function fromOthers(int $companyId, int $advance): int
    {
        $own = MoneyPosition::asOf($companyId, LocalDate::today($companyId)->toDateString())['agency_own_minor'];

        return max(0, $advance - max(0, $own));
    }

    public static function othersWords(int $companyId, int $others, bool $owner): string
    {
        return Minor::format($others, $companyId).' of this would come from '.($owner ? 'tenants\' deposits or rent they paid ahead.' : 'money held for other landlords or tenants\' deposits.');
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
