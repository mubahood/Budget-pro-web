<?php

namespace App\Services\Budget;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetItem;
use App\Models\BudgetProgram;
use App\Models\ContributionPayment;
use App\Models\ContributionRecord;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pledges written by a signed-in person on the web portal (budget-pro-new Events › Pledges): one at a time,
 * with an optional first payment, or many pasted at once. Every save goes through ContributionRecord (its
 * phone and amount rules, closed events) and every payment through ContributionPayments, so the pledge's paid
 * figure is always the sum of its payment history. Permissions are the caller's to check (BudgetAccess).
 *
 * The web and the API refuse what a phone is allowed (PB-03, PB-07): a pledge below what is paid, a fraction of a
 * shilling. A pasted list never guesses money (PB-01). A pledge is corrected by withdrawing it with a reason (PB-04),
 * and two people may share a name (PB-05).
 */
class PledgeService
{
    /** A pasted figure above this, or under UNUSUAL_BELOW in a currency without cents, waits for the person to say it is right. */
    public const UNUSUAL_ABOVE = '50000000';

    public const UNUSUAL_BELOW = '1000';

    /** Currency words and marks around a pasted amount: 200,000/=, UGX 200,000, shs 50,000, 50,000 ugx. */
    private const MONEY_WORDS = 'ugx|ushs|ush|shs|sh|kes|kshs|ksh|tzs|tshs|tsh|rwf|frw|usd|eur|gbp';

    public function __construct(private readonly ContributionPayments $payments = new ContributionPayments())
    {
    }

    /**
     * A new pledge, or changes to one (name, phone, amount, group); never its paid figure. With `first_payment`
     * (new pledges only) the money already handed over is recorded as its first payment, in the same transaction.
     * `received_by_id` is who took the pledge and any first payment: the person saving unless it names someone on the
     * event's team. `uuid` makes a resent form the first answer. `check_same_name` makes the form ask
     * possible_same_person before a second pledge with this name and the same or no phone.
     *
     * @param  array{name: string, phone?: ?string, amount: mixed, group?: ?string, first_payment?: mixed, method?: ?string, paid_on?: ?string, received_by_id?: mixed, uuid?: ?string, check_same_name?: bool}  $data
     */
    public function save(User $by, BudgetProgram $program, array $data, ?ContributionRecord $pledge = null): ContributionRecord
    {
        $companyId = (int) $program->company_id;
        $amount = $data['amount'] ?? null;
        if (! Decimal::valid($amount) || Decimal::cmp((string) $amount, '0') <= 0) {
            throw BusinessRuleException::make('invalid_amount', 'Enter the amount pledged.');
        }
        ContributionPayments::assertExact($companyId, (string) $amount);
        $first = $data['first_payment'] ?? null;
        $first = $pledge === null && Decimal::valid($first) && Decimal::cmp((string) $first, '0') > 0 ? (string) $first : null;
        if ($first !== null) {
            ContributionPayments::assertExact($companyId, $first);
        }
        $uuid = isset($data['uuid']) && Str::isUuid((string) $data['uuid']) ? strtolower((string) $data['uuid']) : null;

        return DB::transaction(function () use ($by, $program, $data, $pledge, $amount, $first, $uuid, $companyId) {
            if ($pledge === null && $uuid !== null) {
                $sent = self::only(self::find()->where('company_id', $companyId)->where('uuid', $uuid)->first());
                if ($sent !== null) {
                    return $sent; // the same form again: the first answer
                }
            }
            if ($pledge !== null) {
                DB::table('contribution_records')->where('id', $pledge->id)->lockForUpdate()->value('id');
                self::refuseBelowPaid($companyId, (int) $pledge->id, Decimal::of((string) $amount));
            }
            if (! empty($data['check_same_name'])) {
                $this->refuseSamePerson($program, (string) $data['name'], $data['phone'] ?? null, $pledge);
            }
            $received = (int) ($data['received_by_id'] ?? 0);
            if ($received > 0 && $received !== (int) $by->id && ! BudgetAccess::onTeam($companyId, (int) $program->id, $received)) {
                throw BusinessRuleException::make('invalid_receiver', "Choose someone on this event's team.");
            }
            $record = $pledge ?? new ContributionRecord();
            if ($pledge === null) {
                $record->budget_program_id = $program->id;
                $record->company_id = $program->company_id;
                $record->treasurer_id = $by->id;
                $record->received_by_id = $received ?: $by->id;
                if ($uuid !== null) {
                    $record->uuid = $uuid;
                }
            } elseif ($received > 0) {
                $record->received_by_id = $received;
            }
            $record->name = trim((string) $data['name']);
            $record->phone = trim((string) ($data['phone'] ?? '')) ?: null;
            $record->amount = Decimal::of((string) $amount);
            $record->category_id = EventBook::groupKey($data['group'] ?? null);
            $record->chaned_by_id = $by->id;
            $record->save();

            if ($first !== null) {
                $this->payments->record($companyId, (int) $record->id, ['amount' => $first, 'method' => $data['method'] ?? null, 'paid_on' => $data['paid_on'] ?? null,
                    'received_by_id' => $record->received_by_id, 'confirm_duplicate' => true], (int) $by->id);
            }

            return self::only(self::find()->find($record->id)) ?? $record;
        });
    }

    /** Refuses to set a pledge below what has been paid on it (web and API; an older phone's edit shows as extra instead). */
    public static function refuseBelowPaid(int $companyId, int $recordId, string $amount): void
    {
        $paid = (new ContributionPayments())->sum($recordId);
        if (Decimal::cmp($amount, $paid) < 0) {
            $figure = Money::format($paid, null, $companyId);

            throw BusinessRuleException::make('below_paid', $figure.' has been paid on this pledge. Reverse a payment first, or keep the pledge at '.$figure.' or more.', ['paid' => $paid]);
        }
    }

    /**
     * A payment above the balance, with one step instead of a dead end: the pledge is raised to what will then be
     * paid, and the money is recorded, together. Sent again with the same uuid, the first answer.
     *
     * @param  array<string, mixed>  $payment  as ContributionPayments::record takes it
     * @return array{payment: ContributionPayment, record: ContributionRecord, replayed: bool}
     */
    public function raiseAndPay(User $by, BudgetProgram $program, ContributionRecord $pledge, array $payment): array
    {
        $companyId = (int) $program->company_id;
        $amount = $payment['amount'] ?? null;
        if (! Decimal::valid($amount) || Decimal::cmp((string) $amount, '0') <= 0) {
            throw BusinessRuleException::make('invalid_amount', 'Enter an amount greater than zero.');
        }
        ContributionPayments::assertExact($companyId, (string) $amount);

        return DB::transaction(function () use ($by, $pledge, $payment, $amount, $companyId) {
            $sent = ! empty($payment['uuid']) && ContributionPayment::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', strtolower((string) $payment['uuid']))->exists();
            if (! $sent) {
                $locked = self::only(self::find()->where('company_id', $companyId)->where('is_deleted', 0)->lockForUpdate()->find($pledge->id));
                if ($locked === null) {
                    throw BusinessRuleException::make('not_found', 'This pledge was not found.');
                }
                $needed = Decimal::add($this->payments->sum((int) $locked->id), (string) $amount);
                if (Decimal::cmp($needed, (string) $locked->amount) > 0) {
                    $locked->amount = $needed;
                    $locked->chaned_by_id = $by->id;
                    $locked->save();
                }
            }

            return $this->payments->record($companyId, (int) $pledge->id, $payment, (int) $by->id);
        });
    }

    /**
     * Withdraw a pledge with a reason (PB-04): it leaves every total, list and message. It also sets the tombstone, so
     * every older phone removes it and every query that filters on is_deleted leaves it out. Refused while money stands
     * on it. This is not a delete: the row stays with who, when and why, and putBack() restores it.
     */
    public function withdraw(User $by, ContributionRecord $pledge, string $reason): ContributionRecord
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw BusinessRuleException::make('reason_required', 'Say why this pledge is being withdrawn.');
        }

        return DB::transaction(function () use ($by, $pledge, $reason) {
            $locked = self::only(self::find()->where('company_id', $pledge->company_id)->where('is_deleted', 0)->lockForUpdate()->find($pledge->id));
            if ($locked === null) {
                throw BusinessRuleException::make('not_found', 'This pledge was not found.');
            }
            EventClosure::assertOpen($locked->budget_program_id);
            if (! Decimal::isZero($this->payments->sum((int) $locked->id))) {
                throw BusinessRuleException::make('has_payments', 'Reverse the payments first.');
            }
            $locked->withdrawn_at = now();
            $locked->withdrawn_by_id = $by->id;
            $locked->withdrawn_reason = mb_substr($reason, 0, 255);
            $locked->is_deleted = 1;
            $locked->chaned_by_id = $by->id;
            $locked->saveQuietlySynced();
            DB::table('budget_items')->where('covered_by_record_id', $locked->id)->update(['covered_by_record_id' => null]);
            BudgetProgram::recalculateFromChildren((int) $locked->budget_program_id);

            return $locked;
        });
    }

    public function putBack(User $by, ContributionRecord $pledge): ContributionRecord
    {
        return DB::transaction(function () use ($by, $pledge) {
            $locked = self::only(self::find()->where('company_id', $pledge->company_id)->where('is_deleted', 1)->whereNotNull('withdrawn_at')->lockForUpdate()->find($pledge->id));
            if ($locked === null) {
                throw BusinessRuleException::make('not_found', 'This pledge was not found.');
            }
            EventClosure::assertOpen($locked->budget_program_id);
            $locked->withdrawn_at = null;
            $locked->withdrawn_by_id = null;
            $locked->withdrawn_reason = null;
            $locked->is_deleted = 0;
            $locked->chaned_by_id = $by->id;
            $locked->saveQuietlySynced();
            BudgetProgram::recalculateFromChildren((int) $locked->budget_program_id);

            return $locked;
        });
    }

    /**
     * "I'll bring the cake" (PB-39): a pledge with no money in it. Its amount stays 0, so it cannot enter Pledged,
     * Collected or Still owed anywhere; what it is and its value have their own columns. Web only until PB-32.
     *
     * @param  array{name: string, what?: ?string, value?: mixed, phone?: ?string, group?: ?string}  $data
     */
    public function saveInKind(User $by, BudgetProgram $program, array $data): ContributionRecord
    {
        $what = trim((string) ($data['what'] ?? ''));
        if ($what === '') {
            throw BusinessRuleException::make('invalid_in_kind', 'Say what is being brought.');
        }
        $value = $data['value'] ?? null;
        $value = Decimal::valid($value) && Decimal::cmp((string) $value, '0') > 0 ? (string) $value : null;
        if ($value !== null) {
            ContributionPayments::assertExact((int) $program->company_id, $value);
        }
        $record = new ContributionRecord();
        $record->budget_program_id = $program->id;
        $record->company_id = $program->company_id;
        $record->treasurer_id = $by->id;
        $record->received_by_id = $by->id;
        $record->chaned_by_id = $by->id;
        $record->name = trim((string) $data['name']);
        $record->phone = trim((string) ($data['phone'] ?? '')) ?: null;
        $record->category_id = EventBook::groupKey($data['group'] ?? null);
        $record->amount = '0.00';
        $record->kind = EventBook::KIND_IN_KIND;
        $record->in_kind_what = mb_substr($what, 0, 191);
        $record->in_kind_value = $value === null ? null : Decimal::of($value);
        $record->save();

        return self::only(self::find()->find($record->id)) ?? $record;
    }

    /** A gift handed over on the day (PB-39): a pledge and its payment at once, under the name given or "Well-wisher". @param array<string, mixed> $data */
    public function onTheDay(User $by, BudgetProgram $program, array $data): ContributionRecord
    {
        $amount = $data['amount'] ?? null;

        return $this->save($by, $program, ['name' => trim((string) ($data['name'] ?? '')) ?: 'Well-wisher', 'phone' => $data['phone'] ?? null, 'amount' => $amount, 'group' => $data['group'] ?? null,
            'first_payment' => $amount, 'method' => $data['method'] ?? null, 'received_by_id' => $data['received_by_id'] ?? null, 'uuid' => $data['uuid'] ?? null]);
    }

    /** Tick a budget line as covered by an in-kind pledge, or clear it with null. This alone lowers Still to find. */
    public function coverLine(User $by, BudgetProgram $program, int $itemId, ?int $recordId): void
    {
        EventClosure::assertOpen($program->id);
        $item = BudgetItem::withoutGlobalScopes()->without(['budgetItemCategory', 'createdBy'])->where('company_id', $program->company_id)->where('budget_program_id', $program->id)->where('is_deleted', 0)->find($itemId);
        if ($item === null) {
            throw BusinessRuleException::make('not_found', 'This budget line was not found.');
        }
        if ($recordId !== null && ! DB::table('contribution_records')->where('id', $recordId)->where('budget_program_id', $program->id)->where('kind', EventBook::KIND_IN_KIND)->where('is_deleted', 0)->exists()) {
            throw BusinessRuleException::make('not_found', 'This in-kind pledge was not found.');
        }
        $item->covered_by_record_id = $recordId;
        $item->changed_by_id = $by->id;
        $item->saveQuietlySynced();
    }

    /**
     * Pledges in this event with a name like this, as it is being typed, for the form to show. From three letters.
     *
     * @return list<array{id: int, name: string, phone: ?string, amount: string}>
     */
    public function similar(BudgetProgram $program, string $typed, ?int $exceptId = null): array
    {
        $key = self::nameKey($typed);
        if (mb_strlen($key) < 3) {
            return [];
        }
        $word = (string) strtok($key, ' ');
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word).'%';
        $out = [];
        foreach (DB::table('contribution_records')->where('company_id', $program->company_id)->where('budget_program_id', $program->id)->where('is_deleted', 0)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))->where('name', 'like', $like)->orderBy('id')->limit(200)->get(['id', 'name', 'phone', 'amount']) as $r) {
            if (str_contains(self::nameKey((string) $r->name), $key)) {
                $out[] = ['id' => (int) $r->id, 'name' => (string) $r->name, 'phone' => $r->phone, 'amount' => (string) $r->amount];
            }
            if (count($out) === 8) {
                break;
            }
        }

        return $out;
    }

    /** "Nakato  Sarah" and "nakato sarah" are the same name. */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * Pasted lines, one pledge each: "Name, phone, amount, group" (tabs from a spreadsheet, or semicolons, work too; the
     * phone and group may be left out, in any order). Nothing is saved: each row says what would be, or why it
     * cannot (`error`). `check` is an unusual figure, added only once the person says it is right. `duplicate` is the same
     * name with the same or no phone, here or earlier in the paste, and is skipped. `same_as` marks someone added who
     * shares a name with a different phone number.
     *
     * @return list<array{line: int, raw: string, name: string, phone: ?string, amount: ?string, group: ?string, error: ?string, check: ?string, duplicate: bool, same_as: ?string}>
     */
    public function parse(BudgetProgram $program, string $text): array
    {
        $companyId = (int) $program->company_id;
        $country = (string) (DB::table('companies')->where('id', $companyId)->value('country') ?: Phone::countryFor(Money::symbol($companyId)));
        $wholeOnly = Money::decimals($companyId) === 0;
        $taken = [];
        foreach (DB::table('contribution_records')->where('budget_program_id', $program->id)->where('is_deleted', 0)->get(['name', 'phone']) as $r) {
            $taken[self::nameKey((string) $r->name)][] = (string) ($r->phone ?? '');
        }
        $seen = [];
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $i => $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            if (count($rows) >= 500) {
                $rows[] = ['line' => $i + 1, 'raw' => mb_substr($raw, 0, 120), 'name' => '', 'phone' => null, 'amount' => null, 'group' => null, 'error' => 'At most 500 lines at a time.', 'check' => null, 'duplicate' => false, 'same_as' => null];
                break;
            }
            $row = $this->parseLine($raw, $country, $wholeOnly) + ['line' => $i + 1, 'raw' => mb_substr($raw, 0, 200), 'duplicate' => false, 'same_as' => null];
            if ($row['error'] === null) {
                $key = self::nameKey($row['name']);
                $phone = (string) ($row['phone'] ?? '');
                $same = fn (string $other) => $phone === '' || $other === '' || $phone === $other;
                $here = $taken[$key] ?? [];
                $earlier = $seen[$key] ?? [];
                if (array_filter($here, $same) !== [] || array_filter($earlier, $same) !== []) {
                    $row['duplicate'] = true;
                } else {
                    if ($earlier !== []) {
                        $row['same_as'] = 'Same name as line '.(int) array_key_first($earlier);
                    } elseif ($here !== []) {
                        $row['same_as'] = 'Same name as a pledge already here';
                    }
                    $seen[$key][$row['line']] = $phone;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Save the rows of parse() that are ready (no error, not a duplicate; an unusual figure only when the person ticked
     * "These amounts are right"), all or nothing.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return int how many pledges were added
     */
    public function import(User $by, BudgetProgram $program, array $rows, bool $amountsAreRight = false): int
    {
        $ready = array_values(array_filter($rows, fn ($r) => self::isReady($r, $amountsAreRight)));
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

    /** @param array<string, mixed> $row a row of parse() */
    public static function isReady(array $row, bool $amountsAreRight = false): bool
    {
        return ($row['error'] ?? null) === null && empty($row['duplicate']) && (($row['check'] ?? null) === null || $amountsAreRight);
    }

    /** @return array{name: string, phone: ?string, amount: ?string, group: ?string, error: ?string, check: ?string} */
    private function parseLine(string $raw, string $country, bool $wholeOnly): array
    {
        $parts = array_map('trim', match (true) {
            str_contains($raw, "\t") => explode("\t", $raw),
            str_contains($raw, ';') => explode(';', $raw),
            default => $this->splitCommas($raw),
        });
        $name = '';
        $group = null;
        $phoneRaw = null;
        $amount = null;
        [$twoPhones, $twoAmounts, $twoGroups, $badGroup, $zero] = [false, false, false, false, false];
        foreach ($parts as $part) {
            if ($part === '' || self::onlyMoneyWords($part)) {
                continue;
            }
            if (self::isPhone($part, $country)) {
                $twoPhones = $twoPhones || $phoneRaw !== null;
                $phoneRaw ??= $part;
            } elseif (($figure = self::amount($part)) !== null) {
                $twoAmounts = $twoAmounts || $amount !== null;
                $amount ??= $figure;
            } elseif (! preg_match('/\pL/u', $part)) {
                // Numbers and marks with no letter are never a name or a group, and a zero is never a pledge.
                if (is_numeric(str_replace([',', ' '], '', $part))) {
                    $zero = true;
                } else {
                    $badGroup = true;
                }
            } elseif ($name === '') {
                $name = mb_substr($part, 0, 191);
            } else {
                $twoGroups = $twoGroups || $group !== null;
                $group ??= EventBook::groupKey($part);
            }
        }
        $phone = $phoneRaw === null ? null : Phone::e164($phoneRaw, $country);
        $error = match (true) {
            $name === '' => 'No name.',
            $twoPhones => 'Two phone numbers on this line. Which one?',
            $twoAmounts => 'Two amounts on this line. Which one?',
            $amount === null => $zero ? 'The amount must be above 0.' : 'No amount.',
            $badGroup => 'The group needs a letter in it.',
            $twoGroups => 'More than one group on this line. Which one?',
            $phoneRaw !== null && $phone === null => 'The phone number is not valid.',
            $wholeOnly && ! Decimal::isWhole($amount) => 'Whole shillings only.',
            default => null,
        };
        $unusual = $error === null && $amount !== null
            && (Decimal::cmp($amount, self::UNUSUAL_ABOVE) > 0 || ($wholeOnly && Decimal::cmp($amount, self::UNUSUAL_BELOW) < 0));

        return ['name' => $name, 'phone' => $phone, 'amount' => $amount, 'group' => $group, 'error' => $error, 'check' => $unusual ? 'Check this amount' : null];
    }

    /** A phone number: with + or 0, or a bare number by the country's rule (Excel drops the 0). A number with thousands commas is money. */
    private static function isPhone(string $part, string $country): bool
    {
        $compact = (string) preg_replace('/[\s\-().]/', '', $part);
        if (preg_match('/^(\+|0)\d{5,14}$/', $compact)) {
            return true;
        }

        return ! str_contains($part, ',') && Phone::isBareNumber((string) preg_replace('/[\s\-()]/', '', $part), $country);
    }

    /** Commas separate fields, but "50,000" and "200,000/=" stay one amount. @return list<string> */
    private function splitCommas(string $raw): array
    {
        $words = self::MONEY_WORDS;
        $out = [];
        foreach (explode(',', $raw) as $piece) {
            $last = count($out) - 1;
            if ($last >= 1 && preg_match('#^\d{3}(\.\d+)?\s*([kKmM]|/=|/-|=/|=|-|('.$words.')\b)?\s*$#i', $piece) && preg_match('#^\s*(('.$words.')\.?\s*)?\d{1,3}(,\d{3})*$#i', $out[$last])) {
                $out[$last] .= ','.$piece;

                continue;
            }
            $out[] = $piece;
        }

        return $out;
    }

    /** What is left of a pasted amount without its currency words and marks. */
    private static function withoutMoneyWords(string $text): string
    {
        $words = self::MONEY_WORDS;
        $t = trim(str_replace("\u{00A0}", ' ', $text));
        $t = (string) preg_replace('#^(('.$words.')\b\.?|\$)\s*#i', '', $t);
        $t = (string) preg_replace('#\s*(/=|/-|=/|=|-)\s*$#', '', $t);

        return trim((string) preg_replace('#\s*\b('.$words.')\.?\s*$#i', '', $t));
    }

    private static function onlyMoneyWords(string $part): bool
    {
        return (bool) preg_match('#^('.self::MONEY_WORDS.')\.?$#i', trim($part));
    }

    /**
     * "50,000", "50 000", "50000.50", "50k", "1.5m", "200,000/=", "UGX 200,000", "shs 50,000" as an exact amount, or null when
     * it is not one. Thousands must be real thousands, so a stray "1,2,3" or "200000 50000" is not read as money.
     */
    public static function amount(string $text): ?string
    {
        $t = strtolower(self::withoutMoneyWords($text));
        if (! preg_match('/^(?:\d{1,3}(?:[, ]\d{3})+|\d+)(?:\.\d+)?\s*[km]?$/', $t)) {
            return null;
        }
        $t = str_replace([',', ' '], '', $t);
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

    /** A pledge row, whether withdrawn or not, with none of the model's always-loaded relations. @return \Illuminate\Database\Eloquent\Builder<ContributionRecord> */
    private static function find(): \Illuminate\Database\Eloquent\Builder
    {
        return ContributionRecord::withoutGlobalScopes()->without(['budgetProgram', 'treasurer', 'changedBy']);
    }

    private static function only(mixed $row): ?ContributionRecord
    {
        return $row instanceof ContributionRecord ? $row : null;
    }

    /** The form's question: this name with the same phone, or with no phone on one side, is probably the same person. */
    private function refuseSamePerson(BudgetProgram $program, string $name, ?string $phone, ?ContributionRecord $pledge): void
    {
        $key = self::nameKey($name);
        if ($pledge !== null && self::nameKey((string) $pledge->name) === $key && trim((string) $phone) === trim((string) $pledge->phone)) {
            return; // an edit that leaves the person as they were
        }
        $country = (string) (DB::table('companies')->where('id', $program->company_id)->value('country') ?: 'UG');
        $mine = trim((string) $phone) === '' ? '' : (string) Phone::e164($phone, $country);
        foreach ($this->similar($program, $name, $pledge?->id) as $match) {
            if (self::nameKey($match['name']) === $key && ($mine === '' || (string) $match['phone'] === '' || (string) $match['phone'] === $mine)) {
                throw BusinessRuleException::make('possible_same_person', 'Is this another person?', ['match' => $match]);
            }
        }
    }
}
