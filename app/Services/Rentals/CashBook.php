<?php

namespace App\Services\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalEntry;
use App\Models\User;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\CashGuard;
use App\Support\LocalDate;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;

/** The cash book: every shilling in and out by method, with running balances; moving money between methods. */
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
        $run = $opening;
        $in = 0;
        $out = 0;
        $list = [];
        foreach ($rows as $r) {
            $run += (int) $r->cash_minor;
            $r->cash_minor > 0 ? $in += (int) $r->cash_minor : $out += -(int) $r->cash_minor;
            $list[] = ['id' => (int) $r->id, 'date' => $r->occurred_on->toDateString(), 'type' => $r->type, 'method' => $r->method,
                'description' => (string) $r->description, 'in_minor' => max(0, (int) $r->cash_minor), 'out_minor' => max(0, -(int) $r->cash_minor),
                'balance_minor' => $run, 'payment_id' => $r->payment_id, 'payout_id' => $r->payout_id, 'expense_id' => $r->expense_id, 'lease_id' => $r->lease_id];
        }

        return ['opening_minor' => $opening, 'closing_minor' => $run, 'in_minor' => $in, 'out_minor' => $out, 'rows' => $list];
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

    /** The opening balance rows of one account still standing. @return \Illuminate\Support\Collection<int, RentalEntry> */
    public static function openingRows(int $companyId, string $method)
    {
        return RentalEntry::withoutGlobalScopes()->where('company_id', $companyId)->where('type', 'opening_cash')->where('method', $method)->whereNull('reversed_at')->get();
    }
}
