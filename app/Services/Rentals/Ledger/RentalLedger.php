<?php

namespace App\Services\Rentals\Ledger;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalEntry;
use App\Models\RentalSetting;
use App\Services\Rentals\RentalsModule;
use App\Support\LocalDate;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The rentals journal (ADR 0010): the only writer of `rental_entries`, and the account's money lock.
 *
 * Every posting balances: cash = landlord + deposit + credit + income − expense + equity over the rows posted together
 * (one group), and every row but the two legs of a transfer between cash accounts balances on its own. A row is never edited or
 * deleted; reverse() posts its negation and marks the original. All money writes of one account run inside
 * locked(), which holds the account's `rental_settings` row FOR UPDATE for the length of the transaction, so two
 * payments, a payout and the nightly billing can never interleave.
 *
 * A closed month (LC-02) is refused here, at the one place all money passes: a row dated on or before the account's
 * `closed_through` is `period_closed`. The day is read under the lock, so a close and a posting cannot cross.
 */
final class RentalLedger
{
    /** Accounts already locked in this process (a nested locked() call runs inside the outer transaction). */
    private static array $held = [];

    /** @var array<int, ?string> `closed_through` of the accounts locked in this process, read under the lock. */
    private static array $closed = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function locked(int $companyId, Closure $fn): mixed
    {
        if (isset(self::$held[$companyId]) && DB::transactionLevel() > 0) {
            return $fn();
        }
        RentalsModule::settings($companyId); // the lock row exists before the transaction takes it

        return DB::transaction(function () use ($companyId, $fn) {
            $settings = RentalSetting::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->first();
            self::$closed[$companyId] = self::day($settings?->getAttribute('closed_through'));
            self::$held[$companyId] = true;
            try {
                return $fn();
            } finally {
                unset(self::$held[$companyId], self::$closed[$companyId]);
            }
        }, 3);
    }

    public static function isLocked(int $companyId): bool
    {
        return isset(self::$held[$companyId]) && DB::transactionLevel() > 0;
    }

    /** The last day of the account's books that is closed, or null. Under the lock, the day read with it. */
    public static function closedThrough(int $companyId): ?string
    {
        if (self::isLocked($companyId)) {
            return self::$closed[$companyId] ?? null;
        }

        return self::day(RentalSetting::withoutGlobalScopes()->where('company_id', $companyId)->first()?->getAttribute('closed_through'));
    }

    /** Closing or reopening a month moved the day, inside this lock (MonthClose only). */
    public static function closedThroughMoved(int $companyId, ?string $through): void
    {
        if (! self::isLocked($companyId)) {
            throw new \LogicException('A month is closed or reopened only inside RentalLedger::locked().');
        }
        self::$closed[$companyId] = $through;
    }

    /**
     * Refuse a date in a closed month: what the ledger does to a row, for what posts no row of its own (a waiver, a move-out's day).
     *
     * @throws BusinessRuleException period_closed, with what a form or a phone needs to offer today instead
     */
    public static function assertOpen(int $companyId, string $date): void
    {
        $date = substr($date, 0, 10);
        $through = self::closedThrough($companyId);
        if ($through === null || $date > $through) {
            return;
        }
        $month = Carbon::parse($date);
        $today = LocalDate::today($companyId);
        $late = self::lateMark($date, $today);
        $name = $month->format('F');

        throw BusinessRuleException::make('period_closed', "{$name} is closed. Date this today, and it will show on {$today->format('F')}'s statement, marked \"{$late}\". Or ask the account owner to reopen {$name}.",
            ['closed_through' => $through, 'month' => $month->format('Y-m'), 'use_date' => $today->toDateString(), 'mark' => $late]);
    }

    /** What marks a late entry for the month it belongs to (D7): "For October", with the year when it is not the same year. */
    public static function lateMark(string $date, Carbon $today): string
    {
        $month = Carbon::parse(substr($date, 0, 10));
        $sameYear = $month->year === $today->year || ($month->month === 12 && $today->month === 1 && $month->year === $today->year - 1);

        return 'For '.$month->format($sameYear ? 'F' : 'F Y');
    }

    /** A new id for rows posted together (one payment, one move-out, one transfer). */
    public static function group(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Post balanced rows. Each row: type, occurred_on, the effects it moves (missing = 0), its references, method,
     * description. Refuses a row that does not balance, or a call outside locked().
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<RentalEntry>
     */
    public static function post(int $companyId, string $groupId, array $rows, ?int $userId = null): array
    {
        if (! self::isLocked($companyId)) {
            throw new \LogicException('Rentals money is posted only inside RentalLedger::locked().');
        }
        $rows = array_map(fn ($r) => self::normalise($r), $rows);
        $sum = 0;
        foreach ($rows as $row) {
            self::assertOpen($companyId, self::day($row['occurred_on']) ?? '');
            if ($row['type'] !== 'transfer') {
                self::assertBalanced($row); // every row but a transfer's two legs balances on its own
            }
            $sum += self::imbalance($row);
        }
        if ($sum !== 0) {
            throw BusinessRuleException::make('journal_unbalanced', 'The books would not balance, so nothing was saved.', ['difference' => $sum]);
        }
        $out = [];
        foreach ($rows as $row) {
            if (self::isZero($row)) {
                continue; // nothing moves (a waiver, a zero split)
            }
            $out[] = RentalEntry::create($row + ['company_id' => $companyId, 'group_id' => $groupId, 'created_by_id' => $userId, 'created_at' => now()]);
        }

        return $out;
    }

    /**
     * Negate rows (and mark them reversed). The reversal rows share one new group and point at their originals.
     *
     * @param  iterable<RentalEntry>  $entries
     * @return list<RentalEntry>
     */
    public static function reverse(int $companyId, iterable $entries, string $occurredOn, string $reason, ?int $userId = null): array
    {
        if (! self::isLocked($companyId)) {
            throw new \LogicException('Rentals money is reversed only inside RentalLedger::locked().');
        }
        self::assertOpen($companyId, $occurredOn);
        $group = self::group();
        $out = [];
        $sum = 0;
        foreach ($entries as $e) {
            $e = RentalEntry::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->find($e->id);
            if ($e === null || $e->reversed_at !== null || $e->reverses_id !== null) {
                continue;
            }
            $row = ['type' => 'reversal', 'occurred_on' => $occurredOn, 'method' => $e->method, 'reverses_id' => $e->id,
                'description' => mb_substr('Reversal: '.$reason, 0, 255)];
            foreach (RentalEntry::EFFECTS as $col) {
                $row[$col] = -(int) $e->{$col};
            }
            foreach (['landlord_id', 'property_id', 'unit_id', 'lease_id', 'tenant_id', 'payment_id', 'allocation_id', 'charge_id', 'payout_id', 'expense_id'] as $ref) {
                $row[$ref] = $e->{$ref};
            }
            $sum += self::imbalance($row);
            $out[] = RentalEntry::create($row + ['company_id' => $companyId, 'group_id' => $group, 'created_by_id' => $userId, 'created_at' => now()]);
            $e->forceFill(['reversed_at' => now()])->save();
        }
        if ($sum !== 0) {
            throw BusinessRuleException::make('journal_unbalanced', 'Reverse every part of a posting together.', ['difference' => $sum]);
        }

        return $out;
    }

    /** cash − (landlord + deposit + credit + income − expense + equity): zero for a balanced row. */
    public static function imbalance(array|RentalEntry $row): int
    {
        $v = fn (string $k) => (int) ($row instanceof RentalEntry ? $row->{$k} : ($row[$k] ?? 0));

        return $v('cash_minor') - ($v('landlord_minor') + $v('deposit_minor') + $v('credit_minor') + $v('income_minor') - $v('expense_minor') + $v('equity_minor'));
    }

    /** Σ of every effect over the account's journal (the module's trial balance). @return array<string, int> */
    public static function totals(int $companyId, array $where = []): array
    {
        $q = DB::table('rental_entries')->where('company_id', $companyId);
        foreach ($where as $col => $val) {
            is_array($val) ? $q->whereIn($col, $val) : $q->where($col, $val);
        }
        $sel = implode(', ', array_map(fn ($c) => "COALESCE(SUM({$c}), 0) AS {$c}", RentalEntry::EFFECTS));
        $r = (array) $q->selectRaw($sel)->first();

        return array_map('intval', $r);
    }

    /** Cash, bank, mobile money and cheques in hand. @return array{cash: int, bank: int, mobile_money: int, cheque: int, total: int} */
    public static function cash(int $companyId): array
    {
        $by = DB::table('rental_entries')->where('company_id', $companyId)->whereNotNull('method')
            ->groupBy('method')->selectRaw('method, COALESCE(SUM(cash_minor), 0) AS total')->pluck('total', 'method')->all();
        $out = ['cash' => 0, 'bank' => 0, 'mobile_money' => 0, 'cheque' => 0];
        foreach ($by as $m => $t) {
            if (array_key_exists($m, $out)) {
                $out[$m] = (int) $t;
            }
        }
        $out['total'] = array_sum($out);

        return $out;
    }

    /** A date column or a date given to the ledger, as Y-m-d. */
    private static function day(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    private static function normalise(array $row): array
    {
        if (! isset($row['type'], $row['occurred_on'])) {
            throw new \InvalidArgumentException('A journal row needs a type and a date.');
        }
        foreach (RentalEntry::EFFECTS as $col) {
            $row[$col] = (int) ($row[$col] ?? 0);
        }
        if ($row['cash_minor'] !== 0 && empty($row['method'])) {
            throw new \InvalidArgumentException('A row that moves cash names its method.');
        }
        if (isset($row['description'])) {
            $row['description'] = mb_substr((string) $row['description'], 0, 255);
        }

        return $row;
    }

    private static function assertBalanced(array $row): void
    {
        if (($d = self::imbalance($row)) !== 0) {
            throw BusinessRuleException::make('journal_unbalanced', 'The books would not balance, so nothing was saved.', ['type' => $row['type'], 'difference' => $d]);
        }
    }

    private static function isZero(array $row): bool
    {
        foreach (RentalEntry::EFFECTS as $col) {
            if ($row[$col] !== 0) {
                return false;
            }
        }

        return true;
    }
}
