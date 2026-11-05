<?php

namespace App\Services\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalEntry;
use App\Models\User;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\CashGuard;
use App\Services\Rentals\Reports\MoneyPosition;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;

/** The cash book: every shilling in and out by method, with running balances; moving money between methods; what the agency takes out of its own money. */
final class CashBook
{
    public const METHODS = ['cash', 'bank', 'mobile_money', 'cheque'];

    /** @return array{cash: int, bank: int, mobile_money: int, cheque: int, total: int} */
    public static function balances(int $companyId): array
    {
        return RentalLedger::cash($companyId);
    }

    /**
     * Rows that moved cash in a period, with the balance before and after each (of the method, or all methods).
     *
     * @return array{opening_minor: int, closing_minor: int, in_minor: int, out_minor: int, rows: list<array<string, mixed>>}
     */
    public static function statement(int $companyId, string $from, string $to, ?string $method = null, int $limit = 2000): array
    {
        $base = RentalEntry::withoutGlobalScopes()->where('company_id', $companyId)->where('cash_minor', '!=', 0);
        if ($method !== null) {
            $base->where('method', $method);
        }
        $opening = (int) (clone $base)->where('occurred_on', '<', $from)->sum('cash_minor');
        $rows = (clone $base)->whereBetween('occurred_on', [$from, $to])->orderBy('occurred_on')->orderBy('id')->limit($limit)->get();
        // The period's own figures, not the rows': past the limit a sum of the rows would close on the wrong balance.
        $in = (int) (clone $base)->whereBetween('occurred_on', [$from, $to])->where('cash_minor', '>', 0)->sum('cash_minor');
        $out = -(int) (clone $base)->whereBetween('occurred_on', [$from, $to])->where('cash_minor', '<', 0)->sum('cash_minor');
        $run = $opening;
        $list = [];
        foreach ($rows as $r) {
            $run += (int) $r->cash_minor;
            $list[] = ['id' => (int) $r->id, 'date' => $r->occurred_on->toDateString(), 'type' => $r->type, 'method' => $r->method,
                'description' => (string) $r->description, 'in_minor' => max(0, (int) $r->cash_minor), 'out_minor' => max(0, -(int) $r->cash_minor),
                'balance_minor' => $run, 'payment_id' => $r->payment_id, 'payout_id' => $r->payout_id, 'expense_id' => $r->expense_id, 'lease_id' => $r->lease_id, 'reversed' => $r->reversed_at !== null];
        }

        return ['opening_minor' => $opening, 'closing_minor' => $opening + $in - $out, 'in_minor' => $in, 'out_minor' => $out, 'rows' => $list];
    }

    /** Move money between methods (banking cash, withdrawing mobile money): two rows, one group (rentals_expenses). */
    public function transfer(User $by, string $from, string $to, int $amount, ?string $on = null, ?string $note = null): array
    {
        RentalAccess::authorize($by, 'rentals_expenses');
        $cid = (int) $by->company_id;
        if (! in_array($from, self::METHODS, true) || ! in_array($to, self::METHODS, true) || $from === $to) {
            throw BusinessRuleException::make('method_invalid', 'Choose two different accounts.');
        }
        Minor::positive($amount);
        $day = LocalDate::date($cid, $on);
        if ($day->gt(LocalDate::today($cid))) {
            throw BusinessRuleException::make('date_in_future', 'A transfer cannot be dated after today.');
        }

        return RentalLedger::locked($cid, function () use ($by, $from, $to, $amount, $day, $note, $cid) {
            CashGuard::assertCovers($cid, $from, $amount);
            $text = trim('Moved from '.CashGuard::LABELS[$from].' to '.CashGuard::LABELS[$to].($note ? ': '.$note : ''));
            $rows = RentalLedger::post($cid, RentalLedger::group(), [
                ['type' => 'transfer', 'occurred_on' => $day->toDateString(), 'method' => $from, 'cash_minor' => -$amount, 'description' => $text],
                ['type' => 'transfer', 'occurred_on' => $day->toDateString(), 'method' => $to, 'cash_minor' => $amount, 'description' => $text],
            ], $by->id);
            RentalAudit::record($cid, 'cash.transfer', null, $text, ['amount_minor' => $amount], 'staff', $by->id);

            return $rows;
        });
    }

    /** Opening cash per method (an account moving from another system): cash +, equity +. */
    public function opening(User $by, string $method, int $amount, Carbon $on, bool $replace = false): array
    {
        RentalAccess::authorize($by, 'rentals_settings');
        if (! in_array($method, self::METHODS, true)) {
            throw BusinessRuleException::make('method_invalid', 'Choose cash, bank, mobile money or cheque.');
        }
        Minor::positive($amount);
        $cid = (int) $by->company_id;
        if ($on->gt(LocalDate::today($cid))) {
            throw BusinessRuleException::make('date_in_future', 'An opening balance cannot be dated after today.');
        }

        return RentalLedger::locked($cid, function () use ($by, $method, $amount, $on, $replace, $cid) {
            $label = CashGuard::LABELS[$method];
            $earlier = self::openingRows($cid, $method);
            if ($earlier->isNotEmpty()) {
                if (! $replace) {
                    throw BusinessRuleException::make('opening_exists', "The opening balance of {$label} is already recorded (".Minor::format((int) $earlier->sum('cash_minor'), $cid).'). To correct it, say that it replaces the one there.');
                }
                RentalLedger::reverse($cid, $earlier, LocalDate::today($cid)->toDateString(), 'Opening balance corrected', $by->id);
            }
            $rows = RentalLedger::post($cid, RentalLedger::group(), [
                ['type' => 'opening_cash', 'occurred_on' => $on->toDateString(), 'method' => $method, 'cash_minor' => $amount, 'equity_minor' => $amount, 'description' => 'Opening balance: '.$label],
            ], $by->id);
            RentalAudit::record($cid, 'cash.opening', null, "Opening balance of {$label}: ".Minor::format($amount, $cid), ['amount_minor' => $amount, 'method' => $method, 'replaced' => $earlier->isNotEmpty()], 'staff', $by->id);

            return $rows;
        });
    }

    /**
     * The agency takes money out of its own (LC-13, rentals_settings: owner and manager, EL-28): cash −, equity −. Not an expense, so its income
     * statement does not move, and no landlord's statement either. An owner-run account has none: a drawing against its own "self" landlord does this.
     * Taking more than its own money is allowed, once the person says so: $belowZeroAgreed, in the no-hard-block spirit. The cash must still be there (CashGuard).
     */
    public function agencyDrawing(User $by, string $method, int $amount, ?string $on = null, ?string $note = null, bool $belowZeroAgreed = false): RentalEntry
    {
        RentalAccess::authorize($by, 'rentals_settings');
        self::assertWholeAccount($by);
        $cid = (int) $by->company_id;
        if (RentalsModule::isOwnerMode($cid)) {
            throw BusinessRuleException::make('agency_only', 'An owner takes money out as an owner drawing.');
        }
        if (! in_array($method, self::METHODS, true)) {
            throw BusinessRuleException::make('method_invalid', 'Choose cash, bank, mobile money or cheque.');
        }
        Minor::positive($amount);
        $day = LocalDate::date($cid, $on);
        if ($day->gt(LocalDate::today($cid))) {
            throw BusinessRuleException::make('date_in_future', 'Money taken out cannot be dated after today.');
        }
        $note = trim((string) $note);

        return RentalLedger::locked($cid, function () use ($by, $method, $amount, $day, $note, $cid, $belowZeroAgreed) {
            CashGuard::assertCovers($cid, $method, $amount);
            $own = MoneyPosition::asOf($cid, LocalDate::today($cid)->toDateString())['agency_own_minor'];
            if ($own - $amount < 0 && ! $belowZeroAgreed) {
                throw BusinessRuleException::make('agency_own_below_zero', 'The agency\'s own money is '.Minor::format($own, $cid).'. Taking '.Minor::format($amount, $cid).' spends money held for others. Tick the box to take it anyway.', ['agency_own_minor' => $own]);
            }
            $text = 'Taken out by the agency'.($note !== '' ? ': '.$note : '');
            $rows = RentalLedger::post($cid, RentalLedger::group(), [
                ['type' => 'agency_drawing', 'occurred_on' => $day->toDateString(), 'method' => $method, 'cash_minor' => -$amount, 'equity_minor' => -$amount, 'description' => $text],
            ], $by->id);
            RentalAudit::record($cid, 'cash.agency_drawing', null, 'The agency took out '.Minor::format($amount, $cid).' from '.CashGuard::LABELS[$method], ['amount_minor' => $amount, 'method' => $method, 'below_zero' => $own - $amount < 0], 'staff', $by->id);

            return $rows[0];
        });
    }

    /** Puts back what the agency took: a reversal dated today, with a reason (rentals_reverse), as every correction is. */
    public function reverseDrawing(User $by, int $entryId, string $reason): RentalEntry
    {
        RentalAccess::authorize($by, 'rentals_reverse');
        self::assertWholeAccount($by);
        $cid = (int) $by->company_id;
        if (trim($reason) === '') {
            throw BusinessRuleException::make('reason_required', 'Say why this is reversed.');
        }

        return RentalLedger::locked($cid, function () use ($by, $entryId, $reason, $cid) {
            $row = RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('type', 'agency_drawing')->lockForUpdate()->find($entryId)
                ?? throw BusinessRuleException::make('not_found', 'That line was not found.');
            if ($row->reversed_at !== null) {
                throw BusinessRuleException::make('already_reversed', 'This was already reversed.');
            }
            $rows = RentalLedger::reverse($cid, [$row], LocalDate::today($cid)->toDateString(), $reason, $by->id);
            RentalAudit::record($cid, 'cash.agency_drawing_reversed', null, 'Reversed what the agency took out: '.Minor::format((int) -$row->cash_minor, $cid), ['entry_id' => $entryId, 'reason' => mb_substr($reason, 0, 255)], 'staff', $by->id);

            return $rows[0];
        });
    }

    /** The agency's own money is the whole account's: a login tied to some properties never touches it (EL-20, EL-26), whatever its role was given. */
    private static function assertWholeAccount(User $by): void
    {
        if (RentalAccess::propertyIds($by) !== null) {
            throw BusinessRuleException::make('forbidden', 'This covers the whole account\'s money. Ask your property manager for it.');
        }
    }

    /** The opening balance rows of one account still standing. @return \Illuminate\Support\Collection<int, RentalEntry> */
    public static function openingRows(int $companyId, string $method)
    {
        return RentalEntry::withoutGlobalScopes()->where('company_id', $companyId)->where('type', 'opening_cash')->where('method', $method)->whereNull('reversed_at')->get();
    }
}
