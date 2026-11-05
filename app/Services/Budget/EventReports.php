<?php

namespace App\Services\Budget;

use App\Models\Company;
use App\Models\ContributionPayment;
use App\Support\Decimal;
use App\Support\LocalDate;
use Illuminate\Support\Facades\DB;

/**
 * The figures behind an event's documents (EventExport), from plain queries: no model's eager loads, so a big wedding's
 * document stays a handful of queries. Every total is an exact sum (App\Support\Decimal) of the rows it sits under, the event's
 * own totals come from EventBook, and nothing here writes. Money is decimal strings, as everywhere in the book.
 */
final class EventReports
{
    public const METHODS = ['cash' => 'Cash', 'mobile_money' => 'Mobile money', 'bank' => 'Bank', 'card' => 'Card', 'cheque' => 'Cheque', 'other' => 'Other'];

    private const ROLES = ['chair' => 'Chair', 'treasurer' => 'Treasurer', 'secretary' => 'Secretary'];

    public function __construct(private readonly EventBook $book) {}

    /** @return array<string, mixed>|null the event's figures with what is in hand, or null when it is not this account's */
    public function position(Company $company, int $eventId): ?array
    {
        $event = $this->book->event($company, $eventId);

        return $event === null ? null : $event + ['in_hand' => EventBook::inHand($event)];
    }

    /** Whole-number percent of a part, or null when there is no whole to be a percent of. */
    public static function percent(string $part, string $whole): ?int
    {
        return Decimal::isZero($whole) ? null : (int) round((float) Decimal::percent($part, $whole));
    }

    /**
     * The budget by category, each line with what was agreed, paid out and is still to pay. A line whose category was
     * deleted, or that never had one, stays in the budget under "Not in a category", so the sheet always adds up to the
     * event's budget. A category with no lines stays too, at nothing.
     *
     * @return array{categories: list<array<string, mixed>>, agreed: string, paid: string, left: string, over: string, percent: ?int, lines: int, over_lines: list<array<string, mixed>>, payments: array{rows: list<array<string, mixed>>, total: string}}
     */
    public function budget(int $companyId, int $eventId): array
    {
        // The order the Budget page shows them in, so paper and screen read alike.
        $categories = BudgetPayments::inOrder(DB::table('budget_item_categories')->where('company_id', $companyId)->where('budget_program_id', $eventId)->where('is_deleted', 0), 'budget_item_categories')->get(['id', 'name']);
        $items = BudgetPayments::inOrder(DB::table('budget_items')->where('company_id', $companyId)->where('budget_program_id', $eventId)->where('is_deleted', 0), 'budget_items')
            ->get(['id', 'name', 'budget_item_category_id', 'target_amount', 'invested_amount', 'unit_price', 'quantity']);

        $groups = [];
        foreach ($categories as $c) {
            $groups[(int) $c->id] = ['name' => (string) $c->name, 'lines' => []];
        }
        foreach ($items as $i) {
            $key = isset($groups[(int) $i->budget_item_category_id]) ? (int) $i->budget_item_category_id : 0;
            $groups[$key] ??= ['name' => 'Not in a category', 'lines' => []];
            $groups[$key]['lines'][] = $this->line($i);
        }

        $out = [];
        $totals = ['agreed' => '0', 'paid' => '0', 'left' => '0', 'over' => '0'];
        $over = [];
        foreach ($groups as $g) {
            $sum = ['agreed' => '0', 'paid' => '0', 'left' => '0', 'over' => '0'];
            foreach ($g['lines'] as $l) {
                foreach ($sum as $k => $v) {
                    $sum[$k] = Decimal::add($v, $l[$k]);
                }
                if (Decimal::cmp($l['over'], '0') > 0) {
                    $over[] = $l;
                }
            }
            foreach ($totals as $k => $v) {
                $totals[$k] = Decimal::add($v, $sum[$k]);
            }
            $out[] = $g + $sum + ['percent' => self::percent($sum['paid'], $sum['agreed'])];
        }

        return ['categories' => $out, 'percent' => self::percent($totals['paid'], $totals['agreed']), 'lines' => $items->count(), 'over_lines' => $over, 'payments' => $this->paymentsOut($companyId, $eventId)] + $totals;
    }

    /**
     * Every payment out and reversal on the event's lines, by date, with who was paid; the total is the budget's Paid out.
     *
     * @return array{rows: list<array<string, mixed>>, total: string}
     */
    public function paymentsOut(int $companyId, int $eventId): array
    {
        if (! BudgetPayments::ready()) {
            return ['rows' => [], 'total' => '0.00'];
        }
        $rows = (new BudgetPayments())->ledger($companyId, $eventId)->orderBy('p.paid_on')->orderBy('p.id')->get();
        $reversed = $rows->whereNotNull('reverses_id')->pluck('reverses_id')->flip();
        $total = '0.00';
        $out = [];
        foreach ($rows as $p) {
            $amount = Decimal::of((string) $p->amount);
            $total = Decimal::add($total, $amount);
            $out[] = ['date' => substr((string) $p->paid_on, 0, 10), 'line' => (string) $p->line, 'paid_to' => (string) ($p->paid_to ?? ''), 'how' => self::METHODS[(string) $p->method] ?? ucfirst(str_replace('_', ' ', (string) $p->method)),
                'paid_by' => (string) ($p->paid_by ?? ''), 'approved_by' => (string) ($p->approved_by ?? ''), 'reference' => (string) ($p->reference ?? ''), 'amount' => $amount,
                'state' => $p->reverses_id ? 'Reversal' : (isset($reversed[$p->id]) ? 'Reversed' : (BudgetPayments::sourceLabel((string) $p->source) ?? ''))];
        }

        return ['rows' => $out, 'total' => $total];
    }

    /** @return array<string, mixed> */
    private function line(object $i): array
    {
        $agreed = Decimal::of((string) $i->target_amount);
        $paid = Decimal::of((string) $i->invested_amount);

        return [
            'name' => (string) $i->name, 'agreed' => $agreed, 'paid' => $paid,
            'left' => Decimal::max(Decimal::sub($agreed, $paid), '0'), 'over' => Decimal::max(Decimal::sub($paid, $agreed), '0'),
            'percent' => self::percent($paid, $agreed),
        ];
    }

    /**
     * The pledges, as the pledge book filters them (EventBook::pledges), with their totals.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function pledges(int $companyId, int $eventId, array $filters = []): array
    {
        $q = $this->book->pledges($companyId, $eventId, $filters + ['sort' => 'name']);
        $rows = (clone $q)->get(['r.id', 'r.name', 'r.phone', 'r.category_id', 'r.amount', 'r.paid_amount', 'r.not_paid_amount', 'r.fully_paid'])
            ->map(fn ($r) => [
                'name' => (string) $r->name, 'phone' => (string) ($r->phone ?? ''), 'group' => EventBook::groupLabel($r->category_id),
                'pledged' => Decimal::of((string) $r->amount), 'paid' => Decimal::of((string) $r->paid_amount), 'owed' => Decimal::of((string) $r->not_paid_amount),
                'status' => $r->fully_paid === 'Yes' ? 'Paid' : (Decimal::cmp((string) $r->paid_amount, '0') > 0 ? 'Part paid' : 'Not paid'),
            ])->all();

        return ['rows' => $rows, 'totals' => $this->book->totals($q)];
    }

    /**
     * Every payment and reversal, oldest first, the way a treasurer reads a mobile money statement against it.
     *
     * @return array{rows: list<array<string, mixed>>, total: string}
     */
    public function payments(int $companyId, int $eventId): array
    {
        $reversed = DB::table('contribution_payments')->where('company_id', $companyId)->where('budget_program_id', $eventId)->where('is_deleted', 0)
            ->whereNotNull('reverses_id')->pluck('reverses_id')->flip();
        $rows = DB::table('contribution_payments as x')
            ->leftJoin('contribution_records as r', 'r.id', '=', 'x.contribution_record_id')
            ->leftJoin('admin_users as u', 'u.id', '=', 'x.received_by_id')
            ->where('x.company_id', $companyId)->where('x.budget_program_id', $eventId)->where('x.is_deleted', 0)
            ->orderBy('x.paid_on')->orderBy('x.id')
            ->get(['x.id', 'x.paid_on', 'x.receipt_no', 'x.amount', 'x.method', 'x.reference', 'x.source', 'x.reverses_id', 'r.name', 'r.category_id', 'u.name as by_name']);
        $total = '0';
        $out = [];
        foreach ($rows as $p) {
            $amount = Decimal::of((string) $p->amount);
            $total = Decimal::add($total, $amount);
            $out[] = [
                'date' => substr((string) $p->paid_on, 0, 10), 'receipt' => (string) ($p->receipt_no ?? ''), 'name' => (string) ($p->name ?? ''), 'group' => EventBook::groupLabel($p->category_id),
                'amount' => $amount, 'how' => self::METHODS[(string) $p->method] ?? ucfirst(str_replace('_', ' ', (string) $p->method)), 'reference' => (string) ($p->reference ?? ''),
                'received_by' => (string) ($p->by_name ?? ''), 'state' => $p->reverses_id ? 'Reversal' : (isset($reversed[$p->id]) ? 'Reversed' : ''),
            ];
        }

        return ['rows' => $out, 'total' => $total];
    }

    /**
     * Pledged, collected and still owed per pledge group, each row summed from the book and the whole adding up to the event.
     *
     * @return list<array{label: string, pledged: string, paid: string, owed: string, percent: ?int, count: int}>
     */
    public function byGroup(int $companyId, int $eventId): array
    {
        $rows = $this->book->pledges($companyId, $eventId)->reorder()->groupBy('r.category_id')
            ->selectRaw('r.category_id AS k, COUNT(*) AS n, COALESCE(SUM(r.amount), 0) AS a, COALESCE(SUM(r.paid_amount), 0) AS p, COALESCE(SUM(r.not_paid_amount), 0) AS o')->get();
        $out = [];
        foreach ($rows as $r) {
            $paid = Decimal::of((string) $r->p);
            $pledged = Decimal::of((string) $r->a);
            $out[] = ['label' => EventBook::groupLabel($r->k), 'pledged' => $pledged, 'paid' => $paid, 'owed' => Decimal::of((string) $r->o), 'percent' => self::percent($paid, $pledged), 'count' => (int) $r->n];
        }
        usort($out, fn ($a, $b) => Decimal::cmp($b['pledged'], $a['pledged']) ?: strcmp($a['label'], $b['label']));

        return $out;
    }

    /** @return list<array{label: string, amount: string}> money in by how it came, reversals netted off */
    public function byMethod(int $companyId, int $eventId): array
    {
        $rows = DB::table('contribution_payments')->where('company_id', $companyId)->where('budget_program_id', $eventId)->where('is_deleted', 0)
            ->groupBy('method')->selectRaw('method AS m, SUM(amount) AS a')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['label' => self::METHODS[(string) $r->m] ?? ucfirst(str_replace('_', ' ', (string) $r->m)), 'amount' => Decimal::of((string) $r->a)];
        }
        usort($out, fn ($a, $b) => Decimal::cmp($b['amount'], $a['amount']));

        return $out;
    }

    /** @return list<array{label: string, amount: string, count: int}> money in by who is holding it (the team member who received it) */
    public function byReceiver(int $companyId, int $eventId): array
    {
        // A reversal comes off the person who took the payment, even an old one that named who reversed it (as EventBook::holders).
        $rows = DB::table('contribution_payments as x')->leftJoin('contribution_payments as o', 'o.id', '=', 'x.reverses_id')
            ->leftJoin('admin_users as u', 'u.id', '=', DB::raw('COALESCE(o.received_by_id, x.received_by_id)'))
            ->where('x.company_id', $companyId)->where('x.budget_program_id', $eventId)->where('x.is_deleted', 0)
            ->groupBy('u.id', 'u.name')->selectRaw('u.name AS n, SUM(x.amount) AS a, COUNT(*) AS c')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['label' => trim((string) $r->n) ?: 'Not recorded', 'amount' => Decimal::of((string) $r->a), 'count' => (int) $r->c];
        }
        usort($out, fn ($a, $b) => Decimal::cmp($b['amount'], $a['amount']));

        return $out;
    }

    /** @return array{pledged: string, collected: string, paid_out: string, from: string} the last seven days, in the account's own days */
    public function thisWeek(int $companyId, int $eventId): array
    {
        $from = LocalDate::today($companyId)->copy()->subDays(6)->toDateString();
        $pledged = $this->book->pledges($companyId, $eventId)->reorder()->where('r.created_at', '>=', $from.' 00:00:00')->sum('r.amount');
        $collected = DB::table('contribution_payments')->where('company_id', $companyId)->where('budget_program_id', $eventId)->where('is_deleted', 0)
            ->where('paid_on', '>=', $from)->sum('amount');

        $paidOut = BudgetPayments::ready() ? DB::table('budget_payments as p')->join('budget_items as i', 'i.id', '=', 'p.budget_item_id')->where('p.company_id', $companyId)->where('p.budget_program_id', $eventId)
            ->where('p.is_deleted', 0)->where('i.is_deleted', 0)->where('p.paid_on', '>=', $from)->whereNotIn('p.source', ['opening', 'moved'])->sum('p.amount') : 0;

        return ['pledged' => Decimal::of((string) $pledged), 'collected' => Decimal::of((string) $collected), 'paid_out' => Decimal::of((string) $paidOut), 'from' => $from];
    }

    /** @return array<string, string> role => the name of who sits in it on this event, for the signature lines */
    public function officers(int $companyId, int $eventId): array
    {
        $rows = DB::table('budget_program_members as m')->join('admin_users as u', 'u.id', '=', 'm.user_id')
            ->where('m.company_id', $companyId)->where('m.budget_program_id', $eventId)->where('m.is_deleted', 0)->where('m.status', 'active')
            ->whereIn('m.role', array_keys(self::ROLES))->get(['m.role', 'u.name']);
        $out = [];
        foreach (self::ROLES as $role => $label) {
            $out[$label] = (string) ($rows->firstWhere('role', $role)?->name ?? '');
        }

        return $out;
    }

    /**
     * The committee's one page: where things stand, this week, by group, by how the money came, what still has to be paid.
     *
     * @return array<string, mixed>|null
     */
    public function committee(Company $company, int $eventId, bool $withNames = false): ?array
    {
        $event = $this->position($company, $eventId);
        if ($event === null) {
            return null;
        }
        $cid = (int) $company->id;
        $budget = $this->budget($cid, $eventId);
        $lines = [];
        foreach ($budget['categories'] as $c) {
            foreach ($c['lines'] as $l) {
                if (Decimal::cmp($l['left'], '0') > 0) {
                    $lines[] = $l;
                }
            }
        }
        usort($lines, fn ($a, $b) => Decimal::cmp($b['left'], $a['left']) ?: strcmp($a['name'], $b['name']));

        return [
            'event' => $event, 'week' => $this->thisWeek($cid, $eventId), 'groups' => $this->byGroup($cid, $eventId), 'methods' => $this->byMethod($cid, $eventId),
            'receivers' => $this->byReceiver($cid, $eventId), 'to_pay' => array_slice($lines, 0, 5), 'over' => $budget['over_lines'], 'officers' => $this->officers($cid, $eventId),
            'names' => $withNames ? $this->pledges($cid, $eventId)['rows'] : [],
        ];
    }

    /**
     * The account a committee gives after the event: income by group, spending by category, what is left and who holds it.
     *
     * @return array<string, mixed>|null
     */
    public function finalAccount(Company $company, int $eventId): ?array
    {
        $event = $this->position($company, $eventId);
        if ($event === null) {
            return null;
        }
        $cid = (int) $company->id;
        $row = DB::table('budget_programs')->where('company_id', $cid)->where('id', $eventId)->first();
        $closing = (array) (($row ? (array) EventSettings::read($row)['settings'] : [])['closing'] ?? []);

        return [
            'event' => $event, 'groups' => $this->byGroup($cid, $eventId), 'budget' => $this->budget($cid, $eventId), 'officers' => $this->officers($cid, $eventId),
            'held_by' => trim((string) ($closing['held_by'] ?? '')), 'note' => trim((string) ($closing['note'] ?? '')),
        ];
    }

    /**
     * One payment's receipt. What was left to pay is worked out from the history up to this payment, so an old receipt
     * still says what it said on the day. A reversal row has no receipt of its own; the payment it cancelled says REVERSED.
     *
     * @return array<string, mixed>|null
     */
    public function receipt(int $companyId, string $uuid): ?array
    {
        $p = ContributionPayment::withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $uuid)->where('is_deleted', 0)->first();
        if ($p === null || Decimal::cmp((string) $p->amount, '0') <= 0) {
            return null;
        }
        $pledge = DB::table('contribution_records')->where('company_id', $companyId)->where('id', $p->contribution_record_id)->first(['name', 'amount', 'category_id']);
        $event = DB::table('budget_programs')->where('company_id', $companyId)->where('id', $p->budget_program_id)->first(['id', 'name', 'title', 'deadline', 'bottom']);
        if ($pledge === null || $event === null) {
            return null;
        }
        $paidThen = DB::table('contribution_payments')->where('company_id', $companyId)->where('contribution_record_id', $p->contribution_record_id)->where('is_deleted', 0)
            ->where('id', '<=', $p->id)->sum('amount');
        $paidThen = Decimal::of((string) $paidThen);
        $reversal = DB::table('contribution_payments')->where('company_id', $companyId)->where('reverses_id', $p->id)->where('is_deleted', 0)->first(['paid_on', 'reason']);
        $receiver = $p->received_by_id ? DB::table('admin_users')->where('id', $p->received_by_id)->where('company_id', $companyId)->value('name') : null;

        return [
            'event' => trim((string) ($event->name ?: $event->title)) ?: 'Event', 'event_id' => (int) $event->id, 'day' => $event->deadline ? substr((string) $event->deadline, 0, 10) : null, 'footer' => trim((string) ($event->bottom ?? '')),
            'number' => (string) ($p->receipt_no ?? ''), 'from' => (string) $pledge->name, 'amount' => Decimal::of((string) $p->amount), 'date' => substr((string) $p->getRawOriginal('paid_on'), 0, 10),
            'how' => self::METHODS[(string) $p->method] ?? ucfirst(str_replace('_', ' ', (string) $p->method)), 'reference' => (string) ($p->reference ?? ''), 'received_by' => trim((string) $receiver),
            'pledged' => Decimal::of((string) $pledge->amount), 'paid_then' => $paidThen, 'left_then' => Decimal::max(Decimal::sub((string) $pledge->amount, $paidThen), '0'),
            'reversed_on' => $reversal ? substr((string) $reversal->paid_on, 0, 10) : null,
        ];
    }

    /** An amount in words for a receipt: "Two hundred thousand" and, where there are cents, "and 50/100". */
    public static function inWords(string $amount): string
    {
        $v = Decimal::round(Decimal::of($amount));
        [$whole, $cents] = array_pad(explode('.', ltrim($v, '-')), 2, '00');
        $words = self::wholeInWords($whole);

        return ucfirst($words).((int) $cents > 0 ? ' and '.$cents.'/100' : '');
    }

    private static function wholeInWords(string $digits): string
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return 'zero';
        }
        $scales = ['', 'thousand', 'million', 'billion', 'trillion'];
        $parts = [];
        foreach (array_reverse(str_split(str_pad($digits, (int) ceil(strlen($digits) / 3) * 3, '0', STR_PAD_LEFT), 3)) as $i => $chunk) {
            if ((int) $chunk > 0) {
                array_unshift($parts, trim(self::hundreds((int) $chunk).' '.($scales[$i] ?? '')));
            }
        }
        // "... thousand and twenty-five": the last part joins with "and" when it is under a hundred.
        $last = (int) substr($digits, -3);
        if (count($parts) > 1 && $last > 0 && $last < 100) {
            $parts[count($parts) - 1] = 'and '.$parts[count($parts) - 1];
        }

        return implode(' ', $parts);
    }

    private static function hundreds(int $n): string
    {
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        $out = $n >= 100 ? $ones[(int) ($n / 100)].' hundred' : '';
        $rest = $n % 100;
        if ($rest > 0) {
            $out .= ($out !== '' ? ' and ' : '').($rest < 20 ? $ones[$rest] : trim($tens[(int) ($rest / 10)].($rest % 10 ? '-'.$ones[$rest % 10] : '')));
        }

        return $out;
    }
}
