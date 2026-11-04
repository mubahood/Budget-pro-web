<?php

namespace App\Services\Rentals\Ledger;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalEntry;
use App\Models\RentalSetting;
use App\Services\Rentals\RentalsModule;
use Closure;
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
 */
final class RentalLedger
{
    /** Accounts already locked in this process (a nested locked() call runs inside the outer transaction). */
    private static array $held = [];

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
            RentalSetting::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->first(['id']);
            self::$held[$companyId] = true;
            try {
                return $fn();
            } finally {
                unset(self::$held[$companyId]);
            }
        }, 3);
    }

    public static function isLocked(int $companyId): bool
    {
        return isset(self::$held[$companyId]) && DB::transactionLevel() > 0;
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
