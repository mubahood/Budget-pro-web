<?php

namespace App\Services\Sacco\YearEnd;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoInterestRun;
use App\Models\SaccoInterestRunLine;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoSavingsProduct;
use App\Models\SaccoSavingsProductVersion;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\SaccoTransactions;
use App\Support\Sacco\Amount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Interest on members' savings (P4.6, 04 §5.1, 02 §5: an expense accrued through the year at the board's rate, never
 * an appropriation). Each month, for each product with a rate and a basis:
 *
 * - **accrual** (key `sint:{run_id}`): every account's basis for the month from its own journal lines by value date,
 *   either the balance-days (`daily_balance`, actual/365) or the month's lowest balance (`min_monthly_balance`, a twelfth
 *   of the annual rate). The month's total is rounded half-up once (AK-09) and shared over the accounts by largest
 *   remainder on their exact interest, so each line is within one unit of it and the lines add up to the posted total
 *   exactly. Dr the product's interest expense (5100) / Cr 2140 Interest payable.
 * - **credit**, at the end of the product's posting period (monthly, quarterly or annually, counted from the start of
 *   the financial year): every accrual line not yet credited moves from 2140 to its account (Cr the product's 21x0). An
 *   account closed before its interest was credited cannot take it, so its share goes back to the expense.
 *
 * Both are idempotent: one run per product, kind and period (a unique row, made under the ledger lock), so the monthly
 * job, a catch-up after missed months and an officer's run never post twice. A month is run only once it has ended;
 * its postings are dated its last day while that month is open (a demo's history), otherwise today with that value date.
 */
final class SavingsInterest
{
    /** Days in the interest year (actual/365, the simple rule SACCOs print on their disclosures). */
    public const DAYS_IN_YEAR = 365;

    /** Accrue and credit a month for every interest-bearing product. @return array{accrued_minor: int, credited_minor: int, runs: list<int>} */
    public function run(int $companyId, string $month, ?int $byUserId = null, ?int $approvalId = null): array
    {
        $end = $this->monthEnd($companyId, $month);
        $out = ['accrued_minor' => 0, 'credited_minor' => 0, 'runs' => []];
        foreach ($this->products($companyId) as $product) {
            if (($a = $this->accrue($companyId, (int) $product->id, $month, $byUserId, $approvalId)) !== null) {
                $out['accrued_minor'] += $a->total_minor;
                $out['runs'][] = (int) $a->id;
            }
            if ($this->periodEnds($companyId, $product, $end) && ($c = $this->credit($companyId, (int) $product->id, $month, $byUserId, $approvalId)) !== null) {
                $out['credited_minor'] += $c->total_minor;
                $out['runs'][] = (int) $c->id;
            }
        }

        return $out;
    }

    /** Every month from the first savings account up to the last month that has ended (or $upTo). @return int months run */
    public function catchUp(int $companyId, ?string $upTo = null): int
    {
        if ($this->products($companyId) === []) {
            return 0;
        }
        $first = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $companyId)->min('opened_on');
        if ($first === null) {
            return 0;
        }
        $last = LoanBooks::today($companyId)->startOfMonth()->subDay();
        if ($upTo !== null && Carbon::parse($upTo.'-01')->endOfMonth()->lt($last)) {
            $last = Carbon::parse($upTo.'-01')->endOfMonth();
        }
        $n = 0;
        for ($m = Carbon::parse($first)->startOfMonth(); $m->lte($last); $m->addMonthNoOverflow()) {
            $this->run($companyId, $m->format('Y-m'));
            $n++;
        }

        return $n;
    }

    /** What a month's accrual would post, without posting. @return array{product_id: int, rate_bp: int, basis: string, total_minor: int, lines: list<array<string, int>>} */
    public function preview(int $companyId, int $productId, string $month): array
    {
        $product = $this->product($companyId, $productId);
        [$from, $to] = $this->bounds($companyId, $month);
        [$rate, $basis] = $this->terms($product, $to);
        [$total, $lines] = $this->compute($companyId, $product, $from, $to, $rate, $basis);

        return ['product_id' => $productId, 'month' => $month, 'rate_bp' => $rate, 'basis' => $basis, 'total_minor' => $total, 'lines' => $lines];
    }

    /** The month's accrual for one product (null: no rate, no basis or nothing earned and nothing to record). */
    public function accrue(int $companyId, int $productId, string $month, ?int $byUserId = null, ?int $approvalId = null): ?SaccoInterestRun
    {
        $product = $this->product($companyId, $productId);
        [$from, $to] = $this->bounds($companyId, $month);
        [$rate, $basis] = $this->terms($product, $to);
        if ($rate <= 0 || $basis === 'none') {
            return null;
        }
        if (($done = $this->existing($companyId, $productId, 'accrual', $from)) !== null) {
            return $done;
        }

        return DB::transaction(function () use ($companyId, $product, $from, $to, $rate, $basis, $byUserId, $approvalId) {
            SaccoTransactions::lockHead($companyId);
            if (($done = $this->existing($companyId, (int) $product->id, 'accrual', $from, true)) !== null) {
                return $done;
            }
            [$total, $lines] = $this->compute($companyId, $product, $from, $to, $rate, $basis);
            $run = SaccoInterestRun::create(['company_id' => $companyId, 'product_id' => $product->id, 'kind' => 'accrual', 'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(), 'rate_bp' => $rate, 'basis' => $basis, 'accounts_count' => count($lines), 'total_minor' => $total,
                'created_by_id' => $byUserId, 'approval_id' => $approvalId]);
            foreach ($lines as $l) {
                SaccoInterestRunLine::create(['company_id' => $companyId, 'run_id' => $run->id] + $l);
            }
            if ($total > 0) {
                $money = LoanBooks::money($companyId, $total);
                $draft = JournalDraft::make($companyId, 'savings_interest', 'sint:'.$run->id)->source('sacco_interest_runs', (int) $run->id)
                    ->debit($this->expenseAccount($companyId, $product), $money)->credit(ChartOfAccounts::id($companyId, 'savings.interest_payable'), $money)
                    ->memo('Savings interest accrued: '.$product->name.', '.$from->format('F Y'))->by($byUserId)->approval($approvalId);
                $run->update(['entry_id' => (int) (new PostingService())->post($this->dated($draft, $companyId, $to))->id]);
            }

            return $run->fresh();
        });
    }

    /**
     * Credit what a product accrued up to the end of $month to its accounts (the posting period ending that month).
     * Returns null when nothing waits to be credited.
     */
    public function credit(int $companyId, int $productId, string $month, ?int $byUserId = null, ?int $approvalId = null): ?SaccoInterestRun
    {
        $product = $this->product($companyId, $productId);
        [, $to] = $this->bounds($companyId, $month);
        $from = $this->periodStart($companyId, $product, $to);
        if (($done = $this->existing($companyId, $productId, 'credit', $from)) !== null) {
            return $done;
        }

        return DB::transaction(function () use ($companyId, $product, $from, $to, $byUserId, $approvalId) {
            SaccoTransactions::lockHead($companyId);
            if (($done = $this->existing($companyId, (int) $product->id, 'credit', $from, true)) !== null) {
                return $done;
            }
            $pending = SaccoInterestRunLine::withoutGlobalScopes()->from('sacco_interest_run_lines as l')->join('sacco_interest_runs as r', 'r.id', '=', 'l.run_id')
                ->where('l.company_id', $companyId)->where('r.product_id', $product->id)->where('r.kind', 'accrual')->where('r.period_to', '<=', $to->toDateString())
                ->whereNull('l.credited_run_id')->where('l.amount_minor', '>', 0)->orderBy('l.id')->lockForUpdate()->get(['l.*']);
            if ($pending->isEmpty()) {
                return null;
            }
            $accounts = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $pending->pluck('savings_account_id')->unique()->all())
                ->get()->keyBy('id');
            $per = [];
            $released = 0;
            foreach ($pending as $l) {
                $a = $accounts[$l->savings_account_id] ?? null;
                if ($a === null || $a->status === 'closed') {
                    $released += (int) $l->amount_minor;

                    continue;
                }
                $per[(int) $a->id] = ($per[(int) $a->id] ?? 0) + (int) $l->amount_minor;
            }
            $credited = array_sum($per);
            $run = SaccoInterestRun::create(['company_id' => $companyId, 'product_id' => $product->id, 'kind' => 'credit', 'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(), 'rate_bp' => (int) $product->interest_rate_bp, 'basis' => (string) $product->interest_basis, 'accounts_count' => count($per),
                'total_minor' => $credited, 'released_minor' => $released, 'created_by_id' => $byUserId, 'approval_id' => $approvalId]);
            $payable = ChartOfAccounts::id($companyId, 'savings.interest_payable');
            $draft = JournalDraft::make($companyId, 'savings_interest', 'sint:'.$run->id)->source('sacco_interest_runs', (int) $run->id)
                ->memo('Savings interest credited: '.$product->name.', '.$from->format('M Y').' to '.$to->format('M Y'))->by($byUserId)->approval($approvalId)
                ->debit($payable, LoanBooks::money($companyId, $credited + $released));
            ksort($per);
            foreach ($per as $id => $minor) {
                $a = $accounts[$id];
                $draft->credit((int) $a->ledger_account_id, LoanBooks::money($companyId, $minor), 'savings', (int) $a->id, (int) $a->member_id, 'Interest');
            }
            if ($released > 0) {
                $draft->credit($this->expenseAccount($companyId, $product), LoanBooks::money($companyId, $released), memo: 'Interest of closed accounts, not credited');
            }
            $run->update(['entry_id' => (int) (new PostingService())->post($this->dated($draft, $companyId, $to))->id]);
            foreach ($pending as $l) {
                $a = $accounts[$l->savings_account_id] ?? null;
                SaccoInterestRunLine::withoutGlobalScopes()->whereKey($l->id)->first()
                    ?->fill(['credited_run_id' => $run->id, 'released' => $a === null || $a->status === 'closed'])->save();
            }

            return $run->fresh();
        });
    }

    /**
     * A month's figures for one product: each account's basis, days and share of the rounded total.
     *
     * @return array{0: int, 1: list<array{savings_account_id: int, member_id: int, basis_minor: int, days: int, amount_minor: int}>}
     */
    public function compute(int $companyId, SaccoSavingsProduct $product, Carbon $from, Carbon $to, int $rateBp, string $basis): array
    {
        $days = $from->diffInDays($to) + 1;
        $accounts = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('product_id', $product->id)
            ->where('opened_on', '<=', $to->toDateString())->where(fn ($q) => $q->whereNull('closed_on')->orWhere('closed_on', '>=', $from->toDateString()))
            ->orderBy('id')->get(['id', 'member_id']);
        if ($accounts->isEmpty()) {
            return [0, []];
        }
        $ids = $accounts->pluck('id')->all();
        $opening = DB::table('sacco_journal_lines')->where('company_id', $companyId)->where('sub_type', 'savings')->whereIn('sub_id', $ids)
            ->where('value_date', '<', $from->toDateString())->groupBy('sub_id')->selectRaw('sub_id, SUM(credit_minor) - SUM(debit_minor) AS b')->pluck('b', 'sub_id');
        $moves = [];
        foreach (DB::table('sacco_journal_lines')->where('company_id', $companyId)->where('sub_type', 'savings')->whereIn('sub_id', $ids)
            ->whereBetween('value_date', [$from->toDateString(), $to->toDateString()])->groupBy('sub_id', 'value_date')
            ->selectRaw('sub_id, value_date, SUM(credit_minor) - SUM(debit_minor) AS m')->get() as $r) {
            $moves[(int) $r->sub_id][(int) Carbon::parse($r->value_date)->diffInDays($from)] = (int) $r->m;
        }
        // exact interest of each account = weight / denominator; the denominator is the same for every account
        $denominator = $basis === 'daily_balance' ? 10000 * self::DAYS_IN_YEAR : 10000 * 12;
        $rows = [];
        $weights = [];
        foreach ($accounts as $a) {
            $balance = (int) ($opening[$a->id] ?? 0);
            $sum = 0;
            $min = null;
            $live = 0;
            for ($d = 0; $d < $days; $d++) {
                $balance += $moves[(int) $a->id][$d] ?? 0;
                $b = max(0, $balance);
                $sum += $b;
                $min = $min === null ? $b : min($min, $b);
                $live += $b > 0 ? 1 : 0;
            }
            $base = $basis === 'daily_balance' ? $sum : (int) $min;
            if ($base <= 0) {
                continue;
            }
            $rows[(int) $a->id] = ['savings_account_id' => (int) $a->id, 'member_id' => (int) $a->member_id, 'basis_minor' => $base, 'days' => $live, 'amount_minor' => 0];
            $weights[(int) $a->id] = (int) bcmul((string) $base, (string) $rateBp, 0);
        }
        if ($rows === []) {
            return [0, []];
        }
        $exact = bcdiv((string) array_sum($weights), (string) $denominator, 10);
        $total = (int) bcadd($exact, '0.5', 0); // half-up, once (AK-09); the exact figure is never negative
        // Largest remainder on the exact figures: each account gets its exact interest rounded down, and the units the
        // rounded total still holds go to the largest fractions (ties: the older account). So every line is within one
        // unit of its exact interest and the lines add up to the total exactly.
        $given = 0;
        $fractions = [];
        foreach ($weights as $id => $w) {
            $rows[$id]['amount_minor'] = intdiv($w, $denominator);
            $given += $rows[$id]['amount_minor'];
            $fractions[$id] = $w % $denominator;
        }
        $order = array_keys($fractions);
        $position = array_flip($order);
        usort($order, fn ($x, $y) => $fractions[$y] <=> $fractions[$x] ?: $position[$x] <=> $position[$y]);
        foreach (array_slice($order, 0, max(0, $total - $given)) as $id) {
            $rows[$id]['amount_minor']++;
        }

        return [$total, array_values($rows)];
    }

    /** The products that earn interest at all (a rate and a basis), in id order. @return list<SaccoSavingsProduct> */
    public function products(int $companyId): array
    {
        return SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('id')->get()
            ->filter(fn (SaccoSavingsProduct $p) => $this->earns($p))->values()->all();
    }

    /** A product earns if its terms now, or any version it had, set a rate and a basis. */
    private function earns(SaccoSavingsProduct $p): bool
    {
        if ((int) $p->interest_rate_bp > 0 && $p->interest_basis !== 'none') {
            return true;
        }

        return SaccoSavingsProductVersion::withoutGlobalScopes()->where('product_id', $p->id)->get()
            ->contains(fn ($v) => (int) ($v->terms['interest_rate_bp'] ?? 0) > 0 && ($v->terms['interest_basis'] ?? 'none') !== 'none');
    }

    /**
     * The rate and basis in force on a day: the latest version effective by then, else the product's terms.
     *
     * @return array{0: int, 1: string}
     */
    public function terms(SaccoSavingsProduct $product, \Carbon\CarbonInterface $on): array
    {
        $versions = SaccoSavingsProductVersion::withoutGlobalScopes()->where('product_id', $product->id);
        // before its first version took effect (a product made today, used for older months), the product's first terms
        $v = (clone $versions)->where('effective_from', '<=', $on->toDateString())->orderByDesc('terms_version')->first()
            ?? (clone $versions)->orderBy('terms_version')->first();
        $terms = $v !== null ? (array) $v->terms : [];

        return [(int) ($terms['interest_rate_bp'] ?? $product->interest_rate_bp), (string) ($terms['interest_basis'] ?? $product->interest_basis)];
    }

    /** Whether a product's posting period (counted from the financial year's first month) ends on this month end. */
    public function periodEnds(int $companyId, SaccoSavingsProduct $product, Carbon $monthEnd): bool
    {
        $months = self::periodMonths((string) $product->interest_posting);
        $start = (int) (LoanBooks::settings($companyId)->fiscal_year_start_month ?: 1);

        return ((($monthEnd->month - $start) % 12 + 12) % 12 + 1) % $months === 0;
    }

    private function periodStart(int $companyId, SaccoSavingsProduct $product, Carbon $monthEnd): Carbon
    {
        $months = self::periodMonths((string) $product->interest_posting);
        $start = (int) (LoanBooks::settings($companyId)->fiscal_year_start_month ?: 1);
        $into = (($monthEnd->month - $start) % 12 + 12) % 12; // months since the year began, 0-based

        return $monthEnd->copy()->startOfMonth()->subMonthsNoOverflow($into % $months);
    }

    public static function periodMonths(string $posting): int
    {
        return match ($posting) {
            'monthly' => 1,
            'quarterly' => 3,
            default => 12,
        };
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function bounds(int $companyId, string $month): array
    {
        $end = $this->monthEnd($companyId, $month);

        return [$end->copy()->startOfMonth(), $end];
    }

    private function monthEnd(int $companyId, string $month): Carbon
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw BusinessRuleException::make('invalid_month', 'Name the month as YYYY-MM.');
        }
        $end = Carbon::parse($month.'-01')->endOfMonth()->startOfDay();
        if ($end->gte(LoanBooks::today($companyId))) {
            throw BusinessRuleException::make('month_not_ended', 'Interest for '.$end->format('F Y').' is worked out once the month has ended.');
        }

        return $end;
    }

    private function product(int $companyId, int $productId): SaccoSavingsProduct
    {
        return SaccoSavingsProduct::withoutGlobalScopes()->where('company_id', $companyId)->find($productId)
            ?? throw BusinessRuleException::make('product_not_found', 'That savings product is not in this SACCO.');
    }

    private function existing(int $companyId, int $productId, string $kind, Carbon $from, bool $lock = false): ?SaccoInterestRun
    {
        $q = SaccoInterestRun::withoutGlobalScopes()->where('company_id', $companyId)->where('product_id', $productId)->where('kind', $kind)->where('period_from', $from->toDateString());

        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    private function expenseAccount(int $companyId, SaccoSavingsProduct $product): int
    {
        return (int) ($product->interest_expense_account_id ?: ChartOfAccounts::id($companyId, 'exp.savings_interest'));
    }

    /** Dated the period's last day while its month is open; otherwise today with that value date (AK-45). */
    private function dated(JournalDraft $draft, int $companyId, Carbon $day): JournalDraft
    {
        [$entry, $value] = LoanBooks::dates($companyId, $day);

        return $draft->dated($entry, $value);
    }

    /** For reports: a money figure in the SACCO's currency. */
    public static function money(int $companyId, int $minor): Amount
    {
        return LoanBooks::money($companyId, $minor);
    }
}
