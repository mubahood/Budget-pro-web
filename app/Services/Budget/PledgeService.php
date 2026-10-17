<?php

namespace App\Services\Budget;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetProgram;
use App\Models\ContributionRecord;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

/**
 * Pledges written by a signed-in person on the web portal (budget-pro-new Events › Pledges): one at a time,
 * with an optional first payment, or many pasted at once. Every save goes through ContributionRecord (its
 * name, phone and amount rules) and every payment through ContributionPayments, so the pledge's paid figure
 * is always the sum of its payment history. Permissions are the caller's to check (BudgetAccess).
 */
class PledgeService
{
    public function __construct(private readonly ContributionPayments $payments = new ContributionPayments())
    {
    }

    /**
     * A new pledge, or changes to one (name, phone, amount, group); never its paid figure. With `first_payment`
     * (new pledges only) the money already handed over is recorded as its first payment, in the same transaction.
     *
     * @param  array{name: string, phone?: ?string, amount: mixed, group?: ?string, first_payment?: mixed, method?: ?string, paid_on?: ?string}  $data
     */
    public function save(User $by, BudgetProgram $program, array $data, ?ContributionRecord $pledge = null): ContributionRecord
    {
        $amount = $data['amount'] ?? null;
        if (! Decimal::valid($amount) || Decimal::cmp((string) $amount, '0') <= 0) {
            throw BusinessRuleException::make('invalid_amount', 'Enter the amount pledged.');
        }
        $first = $data['first_payment'] ?? null;
        $first = $pledge === null && Decimal::valid($first) && Decimal::cmp((string) $first, '0') > 0 ? Decimal::of((string) $first) : null;

        return DB::transaction(function () use ($by, $program, $data, $pledge, $amount, $first) {
            $record = $pledge ?? new ContributionRecord();
            if ($pledge === null) {
                $record->budget_program_id = $program->id;
                $record->company_id = $program->company_id;
                $record->treasurer_id = $by->id;
            }
            $record->name = trim((string) $data['name']);
            $record->phone = trim((string) ($data['phone'] ?? '')) ?: null;
            $record->amount = Decimal::of((string) $amount);
            $record->category_id = EventBook::groupKey($data['group'] ?? null);
            $record->chaned_by_id = $by->id;
            $record->save();

            if ($first !== null) {
                $this->payments->record((int) $program->company_id, (int) $record->id, ['amount' => $first, 'method' => $data['method'] ?? null, 'paid_on' => $data['paid_on'] ?? null], (int) $by->id);
            }

            return ContributionRecord::withoutGlobalScopes()->without(['budgetProgram', 'treasurer', 'changedBy'])->findOrFail($record->id);
        });
    }

    /**
     * Pasted lines, one pledge each: "Name, phone, amount, group" (tabs from a spreadsheet, or semicolons, work
     * too; the phone and group may be left out, in any order after the name). Nothing is saved: each row says
     * what would be, or why it cannot (`error`), and a name already in the event or twice in the paste is
     * flagged (`duplicate`) and skipped on save.
     *
     * @return list<array{line: int, raw: string, name: string, phone: ?string, amount: ?string, group: ?string, error: ?string, duplicate: bool}>
     */
    public function parse(BudgetProgram $program, string $text): array
    {
        $country = (string) (DB::table('companies')->where('id', $program->company_id)->value('country')
            ?: Phone::countryFor(\App\Support\Money::symbol((int) $program->company_id)));
        $taken = DB::table('contribution_records')->where('budget_program_id', $program->id)->where('is_deleted', 0)->pluck('name')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))->flip()->all();
        $seen = [];
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $i => $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            if (count($rows) >= 500) {
                $rows[] = ['line' => $i + 1, 'raw' => mb_substr($raw, 0, 120), 'name' => '', 'phone' => null, 'amount' => null, 'group' => null, 'error' => 'At most 500 lines at a time.', 'duplicate' => false];
                break;
            }
            $row = $this->parseLine($raw, $country) + ['line' => $i + 1, 'raw' => mb_substr($raw, 0, 200), 'duplicate' => false];
            $key = mb_strtolower($row['name']);
            if ($row['error'] === null && (isset($taken[$key]) || isset($seen[$key]))) {
                $row['duplicate'] = true;
            }
            $seen[$key] = true;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Save the rows of parse() that are ready (no error, not a duplicate), all or nothing.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return int how many pledges were added
     */
    public function import(User $by, BudgetProgram $program, array $rows): int
    {
        $ready = array_values(array_filter($rows, fn ($r) => ($r['error'] ?? null) === null && empty($r['duplicate'])));
        if ($ready === []) {
            throw BusinessRuleException::make('nothing_to_add', 'There is no line ready to add. Fix the lines marked in red, or paste others.');
        }

        return DB::transaction(function () use ($by, $program, $ready) {
            foreach ($ready as $r) {
                $this->save($by, $program, ['name' => (string) $r['name'], 'phone' => $r['phone'] ?? null, 'amount' => $r['amount'], 'group' => $r['group'] ?? null]);
            }

            return count($ready);
        });
    }

    /** @return array{name: string, phone: ?string, amount: ?string, group: ?string, error: ?string} */
    private function parseLine(string $raw, string $country): array
    {
        $parts = array_map('trim', match (true) {
            str_contains($raw, "\t") => explode("\t", $raw),
            str_contains($raw, ';') => explode(';', $raw),
            default => $this->splitCommas($raw),
        });
        $name = mb_substr((string) array_shift($parts), 0, 191);
        $out = ['name' => $name, 'phone' => null, 'amount' => null, 'group' => null, 'error' => null];
        $phoneRaw = null;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $compact = (string) preg_replace('/[\s\-().]/', '', $part);
            if ($phoneRaw === null && preg_match('/^(\+|0)\d{5,14}$/', $compact)) {
                $phoneRaw = $part;
            } elseif ($out['amount'] === null && ($amount = self::amount($part)) !== null) {
                $out['amount'] = $amount;
            } elseif ($out['group'] === null) {
                $out['group'] = EventBook::groupKey($part);
            }
        }
        if ($phoneRaw !== null) {
            $out['phone'] = Phone::e164($phoneRaw, $country);
        }
        $out['error'] = match (true) {
            $name === '' => 'No name.',
            $out['amount'] === null => 'No amount.',
            $phoneRaw !== null && $out['phone'] === null => 'The phone number is not valid.',
            default => null,
        };

        return $out;
    }

    /** Commas separate fields, but "50,000" stays one amount. @return list<string> */
    private function splitCommas(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $piece) {
            $last = count($out) - 1;
            if ($last >= 1 && preg_match('/^\d{3}(\.\d+)?\s*[kKmM]?$/', $piece) && preg_match('/^\s*\d{1,3}(,\d{3})*$/', $out[$last])) {
                $out[$last] .= ','.$piece;

                continue;
            }
            $out[] = $piece;
        }

        return $out;
    }

    /** "50,000", "50 000", "50000.50", "50k", "1.5m" as an exact amount, or null when it is not one. */
    public static function amount(string $text): ?string
    {
        $t = strtolower(str_replace([',', ' ', "\u{00A0}"], '', trim($text)));
        if (! preg_match('/^(\d+(?:\.\d+)?)([km]?)$/', $t, $m)) {
            return null;
        }
        $value = match ($m[2]) {
            'k' => bcmul($m[1], '1000', 4),
            'm' => bcmul($m[1], '1000000', 4),
            default => $m[1],
        };
        $value = Decimal::of($value);

        return Decimal::cmp($value, '0') > 0 ? $value : null;
    }
}
