<?php

namespace App\Services\Sacco\Dues;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoDuesPayment;
use App\Models\SaccoDuesProgramme;
use App\Models\SaccoMember;
use App\Models\User;
use App\Services\Sacco\PostingCurrency;
use App\Support\LocalDate;
use App\Support\Sacco\Amount;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Contributions (dues), shared by every app that keeps sacco_members: programmes, one line per person per period
 * (prepare(), run by sacco:dues-prepare), and payments that are never changed: a reversal is a second row with the
 * negative amount. Every total is summed from the payments; nothing is stored.
 *
 * A person's payments go to their oldest periods first, so a period's "paid" is what is left for it after the earlier ones.
 * In an "any amount" programme nobody owes a set sum: a person has paid once they gave something, and the programme's
 * balance is what is left of its target.
 */
class DuesService
{
    public const KINDS = ['recurring', 'once'];

    public const PERIODS = ['weekly', 'monthly', 'yearly'];

    public const AMOUNT_MODES = ['fixed', 'any', 'personal'];

    public const METHODS = ['cash', 'mobile_money', 'bank'];

    public const MAX_MINOR = 999_999_999_999;

    /** People who owe: living (not departed, not gone from the books), not merged, and compulsory. */
    public static function duePeople(int $companyId)
    {
        return DB::table('sacco_members')->where('company_id', $companyId)->where('is_deleted', 0)->whereNull('merged_into_id')
            ->whereNotIn('status', ['deceased', 'exited'])->whereNull('date_of_death')->where('dues_duty', 'compulsory');
    }

    // ---------------------------------------------------------------- periods

    /** @return array{key: string, start: CarbonImmutable, end: ?CarbonImmutable} the period that holds $day */
    public static function periodOf(SaccoDuesProgramme $p, CarbonImmutable $day): array
    {
        return match ($p->kind === 'once' ? 'once' : (string) $p->period) {
            'weekly' => ['key' => sprintf('%04d-W%02d', (int) $day->isoWeekYear(), (int) $day->isoWeek()), 'start' => $day->startOfWeek(Carbon::MONDAY), 'end' => $day->startOfWeek(Carbon::MONDAY)->addDays(6)],
            'monthly' => ['key' => $day->format('Y-m'), 'start' => $day->startOfMonth(), 'end' => $day->endOfMonth()->startOfDay()],
            'yearly' => ['key' => $day->format('Y'), 'start' => $day->startOfYear(), 'end' => $day->endOfYear()->startOfDay()],
            default => ['key' => 'once', 'start' => CarbonImmutable::parse($p->starts_on), 'end' => $p->ends_on ? CarbonImmutable::parse($p->ends_on) : null],
        };
    }

    /** "November 2026", "Week 48, 2026", "2026"; null for a one-time programme. */
    public static function periodLabel(string $key): ?string
    {
        return match (true) {
            preg_match('/^(\d{4})-W(\d{2})$/', $key, $m) === 1 => 'Week '.(int) $m[2].', '.$m[1],
            preg_match('/^\d{4}-\d{2}$/', $key) === 1 => CarbonImmutable::parse($key.'-01')->format('F Y'),
            preg_match('/^\d{4}$/', $key) === 1 => $key,
            default => null,
        };
    }

    // ---------------------------------------------------------------- the lines (sacco:dues-prepare)

    /**
     * Make every missing line up to today, for every active programme (of one company, or of all). Lines already made are
     * left alone (unique programme, member, period), so running it twice changes nothing. Returns how many were made.
     */
    public function prepare(?int $companyId = null, ?int $programmeId = null): int
    {
        $made = 0;
        $q = SaccoDuesProgramme::withoutGlobalScopes()->where('status', 'active');
        if ($companyId !== null) {
            $q->where('company_id', $companyId);
        }
        if ($programmeId !== null) {
            $q->whereKey($programmeId);
        }
        foreach ($q->orderBy('id')->get() as $p) {
            $made += $this->prepareProgramme($p);
        }

        return $made;
    }

    private function prepareProgramme(SaccoDuesProgramme $p): int
    {
        $cid = (int) $p->company_id;
        $today = CarbonImmutable::parse(LocalDate::today($cid)->toDateString());
        $starts = CarbonImmutable::parse($p->starts_on);
        if ($starts->gt($today)) {
            return 0;
        }
        $upto = $p->ends_on !== null && CarbonImmutable::parse($p->ends_on)->lt($today) ? CarbonImmutable::parse($p->ends_on) : $today;
        $people = self::duePeople($cid)->get(['id', 'dues_start_date', 'dues_personal_minor']);
        $now = now();
        $rows = [];
        foreach ($people as $m) {
            $expected = match ((string) $p->amount_mode) {
                'fixed' => (int) $p->amount_minor,
                'personal' => (int) ($m->dues_personal_minor ?? $p->amount_minor),
                default => 0,
            };
            $from = $m->dues_start_date !== null && CarbonImmutable::parse($m->dues_start_date)->gt($starts) ? CarbonImmutable::parse($m->dues_start_date) : $starts;
            if ($p->kind === 'once') {
                if ($from->gt($upto)) {
                    continue;
                }
                $period = self::periodOf($p, $from);
                $rows[] = $this->row($p, (int) $m->id, $period, $expected, $now);

                continue;
            }
            for ($day = $from, $guard = 0; $day->lte($upto) && $guard < 600; $guard++) {
                $period = self::periodOf($p, $day);
                $rows[] = $this->row($p, (int) $m->id, $period, $expected, $now);
                $day = $period['end']->addDay();
            }
        }
        $made = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $made += DB::table('sacco_dues_lines')->insertOrIgnore($chunk); // a line already made is skipped (unique key)
        }

        return $made;
    }

    /** @param array{key: string, start: CarbonImmutable, end: ?CarbonImmutable} $period @return array<string, mixed> */
    private function row(SaccoDuesProgramme $p, int $memberId, array $period, int $expected, Carbon $now): array
    {
        return ['company_id' => $p->company_id, 'programme_id' => $p->id, 'member_id' => $memberId, 'period_key' => $period['key'],
            'period_start' => $period['start']->toDateString(), 'period_end' => $period['end']?->toDateString(), 'expected_minor' => $expected,
            'created_at' => $now, 'updated_at' => $now];
    }

    // ---------------------------------------------------------------- programmes

    /** @return array<string, array<int, mixed>> */
    public static function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:120'],
            'kind' => [$req, Rule::in(self::KINDS)],
            'period' => ['nullable', Rule::in(self::PERIODS)],
            'amount_mode' => [$req, Rule::in(self::AMOUNT_MODES)],
            'amount_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_MINOR],
            'target_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_MINOR],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(['active', 'closed'])],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'allow_advance' => ['sometimes', 'boolean'],
        ];
    }

    /** @param array<string, mixed> $d */
    public function create(Company $company, array $d, User $by): SaccoDuesProgramme
    {
        $cid = (int) $company->id;
        $p = new SaccoDuesProgramme(['company_id' => $cid, 'status' => 'active', 'created_by_id' => $by->id, 'allow_advance' => false,
            'starts_on' => LocalDate::today($cid)->toDateString(), 'amount_minor' => 0]);
        $this->fill($p, $d);
        $p->save();
        $this->prepare($cid, (int) $p->id);

        return $p->fresh();
    }

    /** @param array<string, mixed> $d */
    public function update(SaccoDuesProgramme $p, array $d): SaccoDuesProgramme
    {
        $hasLines = DB::table('sacco_dues_lines')->where('programme_id', $p->id)->exists();
        foreach (['kind', 'period', 'starts_on', 'amount_mode'] as $field) {
            if ($hasLines && array_key_exists($field, $d) && (string) $d[$field] !== (string) ($field === 'starts_on' ? $p->starts_on?->toDateString() : $p->{$field})) {
                throw BusinessRuleException::make('programme_in_use', 'People already have dues in this programme, so its '.str_replace('_', ' ', $field).' cannot change. Close it and start a new one.',
                    [$field => ['Already in use.']]);
            }
        }
        if (($d['status'] ?? null) === 'closed' && $p->status !== 'closed') {
            $p->closed_at = now();
        } elseif (($d['status'] ?? null) === 'active') {
            $p->closed_at = null;
        }
        $this->fill($p, $d);
        $p->save();
        if ($p->status === 'active') {
            $this->prepare((int) $p->company_id, (int) $p->id);
        }

        return $p->fresh();
    }

    public function close(SaccoDuesProgramme $p): SaccoDuesProgramme
    {
        return $this->update($p, ['status' => 'closed']);
    }

    /** @param array<string, mixed> $d */
    private function fill(SaccoDuesProgramme $p, array $d): void
    {
        $p->fill(array_intersect_key($d, array_flip(['name', 'kind', 'period', 'amount_mode', 'amount_minor', 'target_minor', 'starts_on', 'ends_on', 'status', 'description', 'allow_advance'])));
        $p->amount_minor = (int) ($p->amount_minor ?? 0);
        if ($p->kind === 'once') {
            $p->period = null;
        } elseif (! in_array($p->period, self::PERIODS, true)) {
            throw BusinessRuleException::make('period_required', 'Say how often people give: weekly, monthly or yearly.', ['period' => ['Required for a recurring programme.']]);
        }
        if (in_array($p->amount_mode, ['fixed', 'personal'], true) && $p->amount_minor <= 0 && $p->amount_mode === 'fixed') {
            throw BusinessRuleException::make('amount_required', 'Give the amount each person gives.', ['amount_minor' => ['Required for a fixed amount.']]);
        }
        if ($p->ends_on !== null && $p->starts_on !== null && CarbonImmutable::parse($p->ends_on)->lt(CarbonImmutable::parse($p->starts_on))) {
            throw BusinessRuleException::make('dates_invalid', 'The end date is before the start date.', ['ends_on' => ['Before the start date.']]);
        }
    }

    public static function find(int $companyId, int $id): SaccoDuesProgramme
    {
        return SaccoDuesProgramme::withoutGlobalScopes()->where('company_id', $companyId)->find($id)
            ?? throw BusinessRuleException::make('not_found', 'That contribution was not found.');
    }

    // ---------------------------------------------------------------- standing (derived from the payments)

    /**
     * Who has paid what, per person, for the whole programme to date or for one period.
     *
     * @return array{people: list<array<string, mixed>>, expected: int, collected: int, balance: int, paid: int, part: int, owing: int, count: int}
     */
    public function standing(SaccoDuesProgramme $p, ?string $period = null): array
    {
        $lines = DB::table('sacco_dues_lines')->where('programme_id', $p->id)->orderBy('period_start')->orderBy('id')
            ->get(['member_id', 'period_key', 'period_start', 'period_end', 'expected_minor']);
        $paidTotal = DB::table('sacco_dues_payments')->where('programme_id', $p->id)->groupBy('member_id')
            ->selectRaw('member_id, SUM(amount_minor) AS paid')->pluck('paid', 'member_id')->map(fn ($v) => (int) $v)->all();
        $named = self::named($p);
        $any = $p->amount_mode === 'any';
        $periodPaid = [];
        if ($period !== null && $any) {
            $range = $lines->firstWhere('period_key', $period);
            if ($range !== null) {
                $periodPaid = self::inPeriod(DB::table('sacco_dues_payments')->where('programme_id', $p->id), $period, $range->period_start, $range->period_end)
                    ->groupBy('member_id')->selectRaw('member_id, SUM(amount_minor) AS paid')->pluck('paid', 'member_id')->map(fn ($v) => (int) $v)->all();
            }
        }

        $rows = [];
        foreach ($lines->groupBy('member_id') as $memberId => $own) {
            $shares = self::allocate($own->values(), $paidTotal[$memberId] ?? 0, $named[$memberId] ?? []);
            $expected = 0;
            $paid = 0;
            foreach ($own->values() as $i => $line) {
                if ($period === null || $line->period_key === $period) {
                    $expected += (int) $line->expected_minor;
                    $paid += $shares[$i];
                }
            }
            if ($period === null) {
                $paid = $paidTotal[$memberId] ?? 0; // with anything paid ahead
            } elseif ($any) {
                $paid = $periodPaid[$memberId] ?? 0;
            } elseif (! $own->contains('period_key', $period)) {
                continue;
            }
            $rows[(int) $memberId] = ['expected' => $expected, 'paid' => $paid];
        }
        if ($period === null) {
            foreach ($paidTotal as $memberId => $paid) {
                $rows[(int) $memberId] ??= ['expected' => 0, 'paid' => $paid]; // gave without a line (optional, or ahead)
            }
        }

        $people = SaccoMember::withoutGlobalScopes()->where('company_id', $p->company_id)->whereIn('id', array_keys($rows))
            ->get(['id', 'first_name', 'last_name', 'other_names', 'dues_duty', 'photo_file_id'])->keyBy('id');
        $photos = self::photoUrls((int) $p->company_id, $people->pluck('photo_file_id')->filter()->all());
        $out = [];
        $t = ['expected' => 0, 'collected' => 0, 'balance' => 0, 'paid' => 0, 'part' => 0, 'owing' => 0, 'count' => 0];
        foreach ($rows as $memberId => $r) {
            $m = $people[$memberId] ?? null;
            if ($m === null) {
                continue;
            }
            $status = self::status((string) $m->dues_duty, $r['expected'], $r['paid'], $any);
            $balance = $any ? 0 : max(0, $r['expected'] - $r['paid']);
            $out[] = ['member_id' => (int) $memberId, 'name' => $m->full_name, 'photo_url' => $m->photo_file_id ? ($photos[(int) $m->photo_file_id] ?? null) : null,
                'duty' => $m->dues_duty, 'expected_minor' => $r['expected'], 'paid_minor' => $r['paid'], 'balance_minor' => $balance, 'status' => $status];
            $t['expected'] += $r['expected'];
            $t['collected'] += $r['paid'];
            $t['balance'] += $balance;
            if ($status !== 'exempt') {
                $t['count']++;
                $t[in_array($status, ['paid', 'advance'], true) ? 'paid' : $status]++;
            }
        }
        if ($any) {
            $t['expected'] = $period === null ? (int) ($p->target_minor ?? 0) : 0;
            $t['balance'] = max(0, $t['expected'] - $t['collected']);
        }
        $order = ['owing' => 0, 'part' => 1, 'paid' => 2, 'advance' => 3, 'exempt' => 4];
        usort($out, fn ($a, $b) => [$order[$a['status']], $a['name']] <=> [$order[$b['status']], $b['name']]);

        return ['people' => $out] + $t;
    }

    /** @return array<int, array<string, int>> member => period => amount, for payments that named their period */
    private static function named(SaccoDuesProgramme $p): array
    {
        $out = [];
        foreach (DB::table('sacco_dues_payments')->where('programme_id', $p->id)->whereNotNull('period_key')->groupBy('member_id', 'period_key')
            ->selectRaw('member_id, period_key, SUM(amount_minor) AS paid')->get() as $r) {
            $out[(int) $r->member_id][(string) $r->period_key] = (int) $r->paid;
        }

        return $out;
    }

    /**
     * What each of a person's lines (oldest first) is paid: a payment that named its period fills that period first;
     * everything else (and what a named payment gives beyond its period) fills the oldest periods first.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $own
     * @param  array<string, int>  $named
     * @return list<int>
     */
    private static function allocate($own, int $total, array $named): array
    {
        $pool = $total;
        $shares = [];
        foreach ($own as $i => $line) {
            $given = $named[$line->period_key] ?? 0;
            $shares[$i] = max(0, min($given, (int) $line->expected_minor));
            $pool -= $shares[$i];
        }
        foreach ($own as $i => $line) {
            $more = max(0, min($pool, (int) $line->expected_minor - $shares[$i]));
            $shares[$i] += $more;
            $pool -= $more;
        }

        return $shares;
    }

    /** Payments for one period: those that named it, and unnamed ones received within it. */
    private static function inPeriod($q, string $period, $start, $end)
    {
        return $q->where(fn ($w) => $w->where('period_key', $period)
            ->orWhere(fn ($u) => $u->whereNull('period_key')->where('paid_on', '>=', $start)->when($end, fn ($x) => $x->where('paid_on', '<=', $end))));
    }

    public static function status(string $duty, int $expected, int $paid, bool $any): string
    {
        if ($paid <= 0 && $duty === 'exempt') {
            return 'exempt';
        }
        if ($any || $expected === 0) {
            return $paid > 0 ? ($any ? 'paid' : 'advance') : 'owing';
        }

        return match (true) {
            $paid > $expected => 'advance',
            $paid === $expected => 'paid',
            $paid > 0 => 'part',
            default => 'owing',
        };
    }

    /** @return array<string, mixed> the programme as the API shows it, with its totals */
    public function present(SaccoDuesProgramme $p, ?array $standing = null): array
    {
        $s = $standing ?? $this->standing($p);

        return ['id' => (int) $p->id, 'name' => $p->name, 'kind' => $p->kind, 'period' => $p->kind === 'once' ? null : $p->period, 'amount_mode' => $p->amount_mode,
            'amount_minor' => (int) $p->amount_minor, 'target_minor' => $p->target_minor !== null ? (int) $p->target_minor : null,
            'starts_on' => $p->starts_on?->toDateString(), 'ends_on' => $p->ends_on?->toDateString(), 'status' => $p->status, 'description' => $p->description,
            'allow_advance' => (bool) $p->allow_advance,
            'expected_minor' => $s['expected'], 'collected_minor' => $s['collected'], 'balance_minor' => $s['balance'],
            'paid_count' => $s['paid'], 'part_count' => $s['part'], 'owing_count' => $s['owing'], 'people_count' => $s['count']];
    }

    /** @return list<string> the periods that have lines, newest first */
    public static function periods(SaccoDuesProgramme $p): array
    {
        return DB::table('sacco_dues_lines')->where('programme_id', $p->id)->where('period_key', '!=', 'once')->groupBy('period_key')
            ->orderByRaw('MIN(period_start) DESC')->pluck('period_key')->all();
    }

    // ---------------------------------------------------------------- payments

    /** @return array<string, array<int, mixed>> */
    public static function paymentRules(): array
    {
        return [
            'member_id' => ['required', 'integer'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:'.self::MAX_MINOR],
            'paid_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'method' => ['required', Rule::in(self::METHODS)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'paid_by_member_id' => ['sometimes', 'nullable', 'integer'],
            'period' => ['sometimes', 'nullable', 'string', 'max:10'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** What this person still owes the programme to date (lines less payments). */
    public static function balanceOf(SaccoDuesProgramme $p, int $memberId): int
    {
        $expected = (int) DB::table('sacco_dues_lines')->where('programme_id', $p->id)->where('member_id', $memberId)->sum('expected_minor');
        $paid = (int) DB::table('sacco_dues_payments')->where('programme_id', $p->id)->where('member_id', $memberId)->sum('amount_minor');

        return $expected - $paid;
    }

    /** @param array<string, mixed> $d */
    public function pay(Company $company, SaccoDuesProgramme $p, array $d, User $by): SaccoDuesPayment
    {
        $cid = (int) $company->id;
        $member = self::member($cid, (int) $d['member_id']);
        if (! empty($d['paid_by_member_id'])) {
            self::member($cid, (int) $d['paid_by_member_id']);
        }
        $today = LocalDate::today($cid)->toDateString();
        $paidOn = (string) ($d['paid_on'] ?? $today);
        if ($paidOn > $today) {
            throw BusinessRuleException::make('date_in_future', 'A payment is recorded on the day it was received, never a day ahead.', ['paid_on' => ['In the future.']]);
        }

        $period = $p->kind === 'once' ? null : (filled($d['period'] ?? null) ? (string) $d['period'] : null);
        if ($period !== null && ! DB::table('sacco_dues_lines')->where('programme_id', $p->id)->where('member_id', $member->id)->where('period_key', $period)->exists()) {
            throw BusinessRuleException::make('period_invalid', "{$member->full_name} has no dues for ".(self::periodLabel($period) ?? $period).' in this contribution.', ['period' => ['Not one of their periods.']]);
        }

        return DB::transaction(function () use ($company, $cid, $p, $member, $d, $paidOn, $by, $period) {
            DB::table('companies')->where('id', $cid)->lockForUpdate()->first();
            $amount = (int) $d['amount_minor'];
            if ($p->amount_mode !== 'any' && ! $p->allow_advance) {
                $balance = self::balanceOf($p, (int) $member->id);
                if ($amount > $balance) {
                    $fmt = fn (int $m) => self::fmt($company, $m);
                    throw BusinessRuleException::make('over_balance', $balance > 0
                        ? "{$member->full_name} owes {$fmt($balance)} here: a payment of {$fmt($amount)} is more than that."
                        : "{$member->full_name} owes nothing here, and this contribution does not take payments ahead.",
                        ['balance_minor' => max(0, $balance), 'amount_minor' => [$balance > 0 ? 'More than the balance.' : 'Nothing is owed.']]);
                }
            }

            return SaccoDuesPayment::create(['company_id' => $cid, 'programme_id' => $p->id, 'member_id' => $member->id, 'period_key' => $period, 'amount_minor' => $amount, 'paid_on' => $paidOn,
                'method' => $d['method'], 'reference' => $d['reference'] ?? null, 'paid_by_member_id' => $d['paid_by_member_id'] ?? null, 'note' => $d['note'] ?? null,
                'receipt_no' => self::nextReceipt($cid), 'recorded_by_id' => $by->id]);
        });
    }

    public function reverse(Company $company, int $paymentId, string $reason, User $by): SaccoDuesPayment
    {
        $cid = (int) $company->id;

        return DB::transaction(function () use ($cid, $paymentId, $reason, $by) {
            DB::table('companies')->where('id', $cid)->lockForUpdate()->first();
            $pay = SaccoDuesPayment::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->find($paymentId)
                ?? throw BusinessRuleException::make('not_found', 'That payment was not found.');
            if ($pay->reverses_id !== null) {
                throw BusinessRuleException::make('is_reversal', 'This is already a reversal: record the payment again instead.');
            }
            if ($pay->reversed_at !== null) {
                throw BusinessRuleException::make('already_reversed', 'This payment was already reversed.');
            }
            $back = SaccoDuesPayment::create(['company_id' => $cid, 'programme_id' => $pay->programme_id, 'member_id' => $pay->member_id, 'period_key' => $pay->period_key, 'amount_minor' => -1 * (int) $pay->amount_minor,
                'paid_on' => LocalDate::today($cid)->toDateString(), 'method' => $pay->method, 'reference' => $pay->reference, 'paid_by_member_id' => $pay->paid_by_member_id,
                'note' => 'Reversal of '.$pay->receipt_no, 'receipt_no' => self::nextReceipt($cid), 'reverses_id' => $pay->id, 'reason' => $reason, 'recorded_by_id' => $by->id]);
            $pay->forceFill(['reversed_at' => now(), 'reason' => $reason])->save();

            return $back;
        });
    }

    private static function nextReceipt(int $companyId): string
    {
        $n = (int) DB::table('sacco_dues_payments')->where('company_id', $companyId)->count() + 1;

        return 'DUE-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    private static function member(int $companyId, int $id): SaccoMember
    {
        return SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->whereNull('merged_into_id')->find($id)
            ?? throw BusinessRuleException::make('not_found', 'That person is not in this family.');
    }

    /** @return list<array<string, mixed>> payments as the API shows them, newest first */
    public function payments(int $companyId, array $filter, int $limit = 50): array
    {
        $q = SaccoDuesPayment::withoutGlobalScopes()->where('company_id', $companyId);
        foreach ($filter as $column => $value) {
            if ($column === 'period') {
                self::inPeriod($q, ...$value); // [key, start, end]
            } else {
                is_array($value) ? $q->whereBetween($column, $value) : $q->where($column, $value);
            }
        }
        $rows = $q->orderByDesc('paid_on')->orderByDesc('id')->limit($limit)->get();

        return $this->presentPayments($companyId, $rows);
    }

    /** @return list<array<string, mixed>> */
    public function presentPayments(int $companyId, $rows): array
    {
        $ids = $rows->pluck('member_id')->merge($rows->pluck('paid_by_member_id'))->filter()->unique()->all();
        $names = SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'other_names'])
            ->mapWithKeys(fn ($m) => [(int) $m->id => $m->full_name]);
        $programmes = SaccoDuesProgramme::withoutGlobalScopes()->whereIn('id', $rows->pluck('programme_id')->unique())->get()->keyBy('id');
        $periodOf = function (SaccoDuesPayment $r) use ($programmes): ?string {
            $p = $programmes[$r->programme_id] ?? null;
            if ($r->period_key !== null || $p === null || $p->kind === 'once' || $r->paid_on === null) {
                return $r->period_key;
            }

            return self::periodOf($p, CarbonImmutable::parse($r->paid_on->toDateString()))['key'] ?? null; // unnamed: the period it was received in
        };

        return $rows->map(fn (SaccoDuesPayment $r) => ['id' => (int) $r->id, 'receipt_no' => $r->receipt_no, 'programme_id' => (int) $r->programme_id,
            'programme_name' => $programmes[$r->programme_id]->name ?? null, 'member_id' => (int) $r->member_id, 'name' => $names[(int) $r->member_id] ?? null,
            'member_name' => $names[(int) $r->member_id] ?? null, 'period' => $periodOf($r), 'period_named' => $r->period_key !== null,
            'amount_minor' => (int) $r->amount_minor, 'paid_on' => $r->paid_on?->toDateString(), 'method' => $r->method, 'reference' => $r->reference,
            'paid_by_member_id' => $r->paid_by_member_id ? (int) $r->paid_by_member_id : null, 'paid_by_name' => $r->paid_by_member_id ? ($names[(int) $r->paid_by_member_id] ?? null) : null,
            'note' => $r->note, 'reverses_id' => $r->reverses_id ? (int) $r->reverses_id : null, 'reversed' => $r->reversed_at !== null,
            'reversed_at' => $r->reversed_at?->toIso8601String(), 'reason' => $r->reason, 'reversal_reason' => $r->reason, 'recorded_by_id' => $r->recorded_by_id ? (int) $r->recorded_by_id : null,
            'created_at' => $r->created_at?->toIso8601String()])->values()->all();
    }

    // ---------------------------------------------------------------- one person's dues

    /** The person a sign-in is: linked by user_id, else the one person in the family with the sign-in's email. */
    public static function personOf(User $user, int $companyId): ?SaccoMember
    {
        $base = fn () => SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->where('is_deleted', 0)->whereNull('merged_into_id');
        $m = $base()->where('user_id', $user->id)->first();
        if ($m === null && filled($user->email)) {
            $matches = $base()->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $user->email)])->limit(2)->get();
            $m = $matches->count() === 1 ? $matches->first() : null;
        }

        return $m;
    }

    /** @return array<string, mixed> */
    public function mine(User $user, int $companyId): array
    {
        $m = self::personOf($user, $companyId);
        if ($m === null) {
            return ['member' => null, 'expected_minor' => 0, 'paid_minor' => 0, 'balance_minor' => 0, 'lines' => [], 'payments' => []];
        }
        $lines = [];
        $totals = ['expected' => 0, 'paid' => 0];
        $programmes = SaccoDuesProgramme::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereIn('id', DB::table('sacco_dues_lines')->where('member_id', $m->id)->distinct()->pluck('programme_id')
                ->merge(DB::table('sacco_dues_payments')->where('member_id', $m->id)->distinct()->pluck('programme_id'))->unique()->values()->all())
            ->orderByRaw("status = 'active' DESC")->orderByDesc('id')->get();
        foreach ($programmes as $p) {
            $total = (int) DB::table('sacco_dues_payments')->where('programme_id', $p->id)->where('member_id', $m->id)->sum('amount_minor');
            $totals['paid'] += $total;
            $own = DB::table('sacco_dues_lines')->where('programme_id', $p->id)->where('member_id', $m->id)->orderBy('period_start')->orderBy('id')->get()->values();
            $shares = self::allocate($own, $total, self::named($p)[(int) $m->id] ?? []);
            foreach ($own as $i => $line) {
                $expected = (int) $line->expected_minor;
                $share = $p->amount_mode === 'any' ? 0 : $shares[$i];
                $totals['expected'] += $expected;
                $lines[] = ['programme_id' => (int) $p->id, 'programme_name' => $p->name, 'period' => $line->period_key, 'period_key' => $line->period_key, 'period_label' => self::periodLabel($line->period_key),
                    'expected_minor' => $expected, 'paid_minor' => $share, 'balance_minor' => max(0, $expected - $share),
                    'status' => self::status((string) $m->dues_duty, $expected, $share, $p->amount_mode === 'any')];
            }
        }
        $pays = SaccoDuesPayment::withoutGlobalScopes()->where('company_id', $companyId)->where('member_id', $m->id)->orderByDesc('paid_on')->orderByDesc('id')->limit(100)->get();

        return ['member' => ['id' => (int) $m->id, 'name' => $m->full_name, 'duty' => $m->dues_duty], 'expected_minor' => $totals['expected'], 'paid_minor' => $totals['paid'],
            'balance_minor' => max(0, $totals['expected'] - $totals['paid']), 'lines' => array_reverse($lines), 'payments' => $this->presentPayments($companyId, $pays)];
    }

    // ---------------------------------------------------------------- the message for the family's chat

    public function shareText(Company $company, SaccoDuesProgramme $p, ?string $period = null): string
    {
        $s = $this->standing($p, $period);
        $fmt = fn (int $m) => self::fmt($company, $m);
        $when = $period !== null ? self::periodLabel($period) : ($p->kind === 'once' ? null : 'Up to '.LocalDate::today((int) $company->id)->format('j M Y'));
        $out = ['*'.$p->name.'*'];
        if ($when !== null) {
            $out[] = $when;
        }
        $out[] = $p->amount_mode === 'any'
            ? 'Collected: '.$fmt($s['collected']).($s['expected'] > 0 ? ' of '.$fmt($s['expected']) : '')
            : 'Collected: '.$fmt($s['collected']).' of '.$fmt($s['expected']).'. Still owed: '.$fmt($s['balance']).'.';
        $groups = ['Paid' => [], 'Part paid' => [], 'Not yet paid' => []];
        foreach ($s['people'] as $r) {
            match ($r['status']) {
                'paid', 'advance' => $groups['Paid'][] = $r['name'],
                'part' => $groups['Part paid'][] = $r['name'].': paid '.$fmt($r['paid_minor']).', owes '.$fmt($r['balance_minor']),
                'owing' => $groups['Not yet paid'][] = $r['name'].($r['balance_minor'] > 0 ? ': owes '.$fmt($r['balance_minor']) : ''),
                default => null,
            };
        }
        foreach ($groups as $title => $names) {
            if ($names === []) {
                continue;
            }
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
            $out[] = '';
            $out[] = $title.' ('.count($names).'):';
            foreach ($names as $i => $name) {
                $out[] = ($i + 1).'. '.$name;
            }
        }

        return implode("\n", $out);
    }

    public static function fmt(Company $company, int $minor): string
    {
        return Amount::of($minor, PostingCurrency::forCompany($company))->format((int) $company->id);
    }

    /** @param list<int> $fileIds @return array<int, string> file id => public url */
    public static function photoUrls(int $companyId, array $fileIds): array
    {
        if ($fileIds === []) {
            return [];
        }

        return DB::table('files')->where('company_id', $companyId)->whereIn('id', $fileIds)->pluck('path', 'id')
            ->map(fn ($path) => \Illuminate\Support\Facades\Storage::disk('public')->url($path))->all();
    }
}
