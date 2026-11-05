<?php

namespace App\Services\Rentals\Reports;

use App\Services\Rentals\RentalsModule;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A file for the accountant (LC-19): the journal, one line per posting per account, and the trial balance. Both are read from the signed columns of
 * `rental_entries` and nothing else, so they agree with every other figure of the account: cash and expense are debit accounts (a plus is a debit), the
 * other five are credit accounts (a plus is a credit). ADR 0010's invariant is why debits equal credits, and the footer shows it. Read only.
 *
 * States no tax rule and works out no tax. A period that is not closed is stamped draft, since a late receipt can still land in it (LC-02).
 */
final class JournalExport
{
    use ReportTable;

    private const DEBIT_ACCOUNTS = ['cash', 'expense'];

    private const ACCOUNTS = ['cash' => 'Cash and bank', 'landlord' => 'Owed to landlords', 'deposit' => 'Deposits held', 'credit' => 'Tenants\' money paid ahead', 'income' => 'Income',
        'expense' => 'Agency expenses', 'equity' => 'The agency\'s own money'];

    private const OWNER_ACCOUNTS = ['landlord' => 'Owner\'s money from the property', 'expense' => 'Expenses', 'equity' => 'Owner\'s opening money'];

    /** An account as people read it. */
    public static function label(string $account, bool $owner = false): string
    {
        return ($owner ? self::OWNER_ACCOUNTS[$account] ?? null : null) ?? self::ACCOUNTS[$account] ?? ucfirst($account);
    }

    /** @return array<string, mixed> */
    public function journal(object $x): array
    {
        $codes = $this->codes($x);
        $rows = [];
        $debit = 0;
        $credit = 0;
        $entries = DB::table('rental_entries as e')->leftJoin('rental_landlords as l', 'l.id', '=', 'e.landlord_id')->leftJoin('rental_properties as p', 'p.id', '=', 'e.property_id')
            ->leftJoin('rental_payments as pay', 'pay.id', '=', 'e.payment_id')->leftJoin('rental_payouts as po', 'po.id', '=', 'e.payout_id')->leftJoin('rental_expenses as ex', 'ex.id', '=', 'e.expense_id')
            ->where('e.company_id', $x->cid)->whereBetween('e.occurred_on', [$x->from, $x->to])->orderBy('e.occurred_on')->orderBy('e.id')
            ->select('e.*', 'l.name as landlord_name', 'p.name as property_name', 'pay.number as payment_number', 'po.number as payout_number', 'ex.number as expense_number')->get();
        foreach ($entries as $e) {
            foreach (RentalsModule::JOURNAL_ACCOUNTS as $account) {
                $value = (int) $e->{$account.'_minor'};
                if ($value === 0) {
                    continue;
                }
                [$d, $c] = self::side($account, $value);
                $debit += $d;
                $credit += $c;
                $rows[] = ['date' => substr((string) $e->occurred_on, 0, 10), 'group' => (string) $e->group_id, 'number' => $e->payment_number ?? $e->payout_number ?? $e->expense_number,
                    'description' => (string) ($e->description ?: (RentalReports::ENTRY_LABELS[$e->type] ?? $e->type))]
                    + ($codes === [] ? [] : ['code' => $codes[$account] ?? null])
                    + ['account' => self::label($account, $x->owner), 'debit_minor' => $d, 'credit_minor' => $c, 'landlord' => $e->landlord_name, 'property' => $e->property_name];
            }
        }
        $columns = [self::c('date', 'Date', 'date'), self::c('group', 'Group'), self::c('number', 'Number'), self::c('description', 'Description')];
        if ($codes !== []) {
            $columns[] = self::c('code', 'Code');
        }
        array_push($columns, self::c('account', 'Account'), self::c('debit_minor', 'Debit', 'money'), self::c('credit_minor', 'Credit', 'money'), self::c('landlord', 'Landlord'), self::c('property', 'Property'));

        return ['subtitle' => $this->stamped($x), 'columns' => $columns, 'rows' => $rows,
            'totals' => ['debit_minor' => $debit, 'credit_minor' => $credit, 'difference_minor' => $debit - $credit],
            'sections' => [$this->notes($x, $debit, $credit)], 'paper' => ['hide' => ['group']]];
    }

    /** @return array<string, mixed> */
    public function trial(object $x): array
    {
        $codes = $this->codes($x);
        $sum = fn (string $column) => "COALESCE(SUM({$column}_minor), 0) AS {$column}";
        $opening = (array) DB::table('rental_entries')->where('company_id', $x->cid)->where('occurred_on', '<', $x->from)
            ->selectRaw(implode(', ', array_map($sum, RentalsModule::JOURNAL_ACCOUNTS)))->first();
        $moved = (array) DB::table('rental_entries')->where('company_id', $x->cid)->whereBetween('occurred_on', [$x->from, $x->to])
            ->selectRaw(implode(', ', array_map(fn ($a) => "COALESCE(SUM(CASE WHEN {$a}_minor > 0 THEN {$a}_minor ELSE 0 END), 0) AS {$a}_up, COALESCE(SUM(CASE WHEN {$a}_minor < 0 THEN -{$a}_minor ELSE 0 END), 0) AS {$a}_down", RentalsModule::JOURNAL_ACCOUNTS)))->first();
        $rows = [];
        $totals = array_fill_keys(['opening_debit_minor', 'opening_credit_minor', 'debit_minor', 'credit_minor', 'closing_debit_minor', 'closing_credit_minor'], 0);
        foreach (RentalsModule::JOURNAL_ACCOUNTS as $account) {
            $open = (int) ($opening[$account] ?? 0);
            $up = (int) ($moved[$account.'_up'] ?? 0);
            $down = (int) ($moved[$account.'_down'] ?? 0);
            [$openD, $openC] = self::side($account, $open);
            [$upD, $upC] = self::side($account, $up);
            [$downD, $downC] = self::side($account, -$down);
            [$closeD, $closeC] = self::side($account, $open + $up - $down);
            $row = ['opening_debit_minor' => $openD, 'opening_credit_minor' => $openC, 'debit_minor' => $upD + $downD, 'credit_minor' => $upC + $downC, 'closing_debit_minor' => $closeD, 'closing_credit_minor' => $closeC];
            foreach ($row as $k => $v) {
                $totals[$k] += $v;
            }
            $rows[] = ($codes === [] ? [] : ['code' => $codes[$account] ?? null]) + ['account' => self::label($account, $x->owner)] + $row;
        }
        $columns = $codes === [] ? [] : [self::c('code', 'Code')];
        array_push($columns, self::c('account', 'Account'), self::c('opening_debit_minor', 'Opening debit', 'money'), self::c('opening_credit_minor', 'Opening credit', 'money'),
            self::c('debit_minor', 'Debit', 'money'), self::c('credit_minor', 'Credit', 'money'), self::c('closing_debit_minor', 'Closing debit', 'money'), self::c('closing_credit_minor', 'Closing credit', 'money'));

        return ['subtitle' => $this->stamped($x), 'columns' => $columns, 'rows' => $rows,
            'totals' => $totals + ['difference_minor' => $totals['debit_minor'] - $totals['credit_minor']],
            'sections' => [...($x->owner ? [] : [$this->byLandlord($x)]), $this->notes($x, $totals['debit_minor'], $totals['credit_minor'], $totals['closing_debit_minor'], $totals['closing_credit_minor'])]];
    }

    /** The landlord account's lines, one landlord each. A closing below zero is a landlord who owes the agency. @return array<string, mixed> */
    private function byLandlord(object $x): array
    {
        $rows = DB::table('rental_entries')->where('company_id', $x->cid)->where('occurred_on', '<=', $x->to)->whereNotNull('landlord_id')->groupBy('landlord_id')
            ->selectRaw('landlord_id, COALESCE(SUM(CASE WHEN occurred_on < ? THEN landlord_minor ELSE 0 END), 0) AS opening, COALESCE(SUM(CASE WHEN occurred_on >= ? AND landlord_minor < 0 THEN -landlord_minor ELSE 0 END), 0) AS debit, COALESCE(SUM(CASE WHEN occurred_on >= ? AND landlord_minor > 0 THEN landlord_minor ELSE 0 END), 0) AS credit', [$x->from, $x->from, $x->from])
            ->get();
        $names = $this->names('rental_landlords', 'name', $rows->pluck('landlord_id')->all(), $x->cid);
        $list = [];
        foreach ($rows as $r) {
            [$open, $debit, $credit] = [(int) $r->opening, (int) $r->debit, (int) $r->credit];
            if ($open !== 0 || $debit !== 0 || $credit !== 0) {
                $list[] = ['landlord' => (string) ($names[$r->landlord_id] ?? ''), 'opening_minor' => $open, 'debit_minor' => $debit, 'credit_minor' => $credit, 'closing_minor' => $open + $credit - $debit];
            }
        }
        usort($list, fn ($a, $b) => strnatcasecmp($a['landlord'], $b['landlord']));
        $total = fn (string $k) => array_sum(array_column($list, $k));

        return ['title' => 'Owed to landlords, by landlord', 'columns' => [self::c('landlord', 'Landlord'), self::c('opening_minor', 'Opening', 'money'), self::c('debit_minor', 'Debit', 'money'), self::c('credit_minor', 'Credit', 'money'), self::c('closing_minor', 'Closing', 'money')],
            'rows' => $list, 'totals' => ['opening_minor' => $total('opening_minor'), 'debit_minor' => $total('debit_minor'), 'credit_minor' => $total('credit_minor'), 'closing_minor' => $total('closing_minor')]];
    }

    /** @return array{0: int, 1: int} a signed amount of an account as [debit, credit] */
    private static function side(string $account, int $value): array
    {
        $debitPlus = in_array($account, self::DEBIT_ACCOUNTS, true);
        if ($value >= 0) {
            return $debitPlus ? [$value, 0] : [0, $value];
        }

        return $debitPlus ? [0, -$value] : [-$value, 0];
    }

    /** @return array<string, string> the account's own codes (Settings), when it has any */
    private function codes(object $x): array
    {
        $codes = array_filter((array) (RentalsModule::settings($x->cid)->export_accounts ?? []), fn ($code) => trim((string) $code) !== '');

        return array_map('strval', $codes);
    }

    private function isDraft(object $x): bool
    {
        $closed = substr((string) (RentalsModule::settings($x->cid)->closed_through ?? ''), 0, 10);

        return $closed === '' || (string) $x->to > $closed;
    }

    private function stamped(object $x): string
    {
        return $this->period($x).($this->isDraft($x) ? ' · DRAFT: not closed yet' : ' · Closed');
    }

    /** What the file proves and what it does not say, as a table of its own, so every format and the screen carry it. @return array<string, mixed> */
    private function notes(object $x, int $debit, int $credit, ?int $closingDebit = null, ?int $closingCredit = null): array
    {
        $money = fn (int $v) => Minor::format($v, $x->cid);
        $agree = fn (string $what, int $d, int $c) => $d === $c ? "{$what}: debits {$money($d)} equal credits {$money($c)}." : "{$what}: debits {$money($d)} and credits {$money($c)} differ by {$money(abs($d - $c))}. Do not rely on this file.";
        $lines = [$agree('This period', $debit, $credit)];
        if ($closingDebit !== null && $closingCredit !== null) {
            $lines[] = $agree('At '.Carbon::parse($x->to)->format('j M Y'), $closingDebit, $closingCredit);
        }
        $lines[] = $this->isDraft($x) ? 'DRAFT: this period is not closed yet, so these figures can still change.' : 'This period is closed.';
        $lines[] = 'This is a record of money handled, for your accountant. It states no tax rule and works out no tax. Ask your accountant to check it before you rely on it.';

        return ['title' => 'Notes', 'columns' => [self::c('note', 'Note')], 'rows' => array_map(fn ($line) => ['note' => $line], $lines), 'totals' => []];
    }
}
