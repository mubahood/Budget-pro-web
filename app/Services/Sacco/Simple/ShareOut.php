<?php

namespace App\Services\Sacco\Simple;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoAccountBalance;
use App\Models\SaccoLedgerAccount;
use App\Models\SaccoMember;
use App\Models\SaccoSavingsAccount;
use App\Models\SaccoShareAccount;
use App\Models\SaccoShareout;
use App\Models\SaccoShareoutLine;
use App\Models\User;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\JournalDraft;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoAudit;
use App\Services\Sacco\SaccoTransactions;
use App\Support\Sacco\Amount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Flow 3, the share-out ("what everyone gets if we share out now").
 *
 *   profit = all income − all expenses + what is kept in the surplus accounts (3400, 3410; earlier share-outs took
 *            their part from 3410, so it is never shared twice)
 *   pool   = members' savings + profit
 *   payout = their savings + (their shares ÷ all shares) × profit
 *
 * A run shares an amount A, at most the pool and at most what the paying account holds. When A covers all savings
 * each member gets their savings back and the rest (A − savings) is the profit shared by shares; when it does not,
 * savings are paid back in proportion and no profit is shared. Every split is Amount::allocate (largest remainder),
 * so the lines add up to A to the last unit. The run is one ledger entry (Dr each savings account, Dr 3410 for the
 * profit, Cr cash/bank/mobile money), saved with one line per member, idempotent on its uuid, one per day, and
 * locked once posted. Who: sacco_accounts.
 */
final class ShareOut
{
    public const FORMULA = 'Payout = their savings + (their shares ÷ all shares) × profit';

    /**
     * The live preview: with no amount, the whole pool.
     *
     * @return array<string, mixed>
     */
    public function preview(int $companyId, ?int $amountMinor = null): array
    {
        $basis = $this->basis($companyId);
        $pool = self::pool($basis);
        $amount = $amountMinor ?? $pool;
        $lines = $this->allocate($basis, max(0, min($amount, $pool)));

        return $this->summary($companyId, $basis, $lines, $amount, $pool);
    }

    /**
     * Run it: date, amount and how it is paid. A replay of the same uuid gives the same share-out.
     *
     * @param  array{uuid?: ?string, share_on?: ?string, amount_minor: int, method?: string, notes?: ?string}  $data
     */
    public function run(Company $company, User $by, array $data): SaccoShareout
    {
        SaccoAccess::authorize($by, 'sacco_accounts');
        $cid = (int) $company->id;
        $v = validator($data, ['uuid' => ['nullable', 'uuid'], 'share_on' => ['nullable', 'date'], 'amount_minor' => \App\Support\Rules\SaccoRules::minor(true, 1),
            'method' => ['nullable', \Illuminate\Validation\Rule::in(Money::METHODS)], 'notes' => ['nullable', 'string', 'max:255']]);
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_shareout', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }
        $d = $v->validated();
        $uuid = strtolower((string) ($d['uuid'] ?? Str::uuid()));
        $method = (string) ($d['method'] ?? 'cash');
        $amount = (int) $d['amount_minor'];
        $on = Carbon::parse($d['share_on'] ?? LoanBooks::today($cid))->startOfDay();
        [$entryDate, $valueDate] = LoanBooks::dates($cid, $on);

        return DB::transaction(function () use ($cid, $by, $d, $uuid, $method, $amount, $entryDate, $valueDate) {
            SaccoTransactions::lockHead($cid);
            $existing = SaccoShareout::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->first();
            if ($existing !== null) {
                if ((int) $existing->amount_minor !== $amount) {
                    throw BusinessRuleException::make('idempotency_conflict', 'That share-out was already run with another amount.');
                }

                return $existing->load('lines');
            }
            if (SaccoShareout::withoutGlobalScopes()->where('company_id', $cid)->where('share_on', $valueDate)->exists()) {
                throw BusinessRuleException::make('shareout_exists', 'A share-out was already run on '.$valueDate.'. It cannot be run twice.');
            }
            $basis = $this->basis($cid);
            $pool = self::pool($basis);
            if ($pool <= 0) {
                throw BusinessRuleException::make('nothing_to_share', 'There are no savings or profit to share out.');
            }
            if ($amount > $pool) {
                throw BusinessRuleException::make('amount_too_high', 'At most '.LoanBooks::fmt($cid, $pool).' can be shared out (savings plus profit).', ['max_minor' => $pool]);
            }
            $available = Money::available($cid, $method);
            if ($amount > $available) {
                throw BusinessRuleException::make('insufficient_funds', 'The '.strtolower(Money::LABELS[$method]).' account holds '.LoanBooks::fmt($cid, max(0, $available)).': share out at most that.',
                    ['available_minor' => max(0, $available)]);
            }
            $lines = $this->allocate($basis, $amount);
            $savingsPaid = array_sum(array_column($lines, 'savings_paid_minor'));
            $profit = array_sum(array_column($lines, 'profit_minor'));
            $currency = PostingService::currency($cid);
            $shareout = SaccoShareout::create(['company_id' => $cid, 'uuid' => $uuid, 'share_on' => $valueDate, 'method' => $method, 'amount_minor' => $amount,
                'savings_minor' => $savingsPaid, 'profit_minor' => $profit, 'savings_total_minor' => $basis['savings_total_minor'], 'profit_available_minor' => $basis['profit_minor'],
                'total_shares' => $basis['total_shares'], 'currency' => $currency, 'notes' => $d['notes'] ?? null, 'posted_by_id' => $by->id]);
            $money = fn (int $m) => Amount::of($m, $currency);
            $draft = JournalDraft::make($cid, 'shareout', 'shareout:'.$uuid)->dated($entryDate, $valueDate)->source('sacco_shareouts', (int) $shareout->id)
                ->by((int) $by->id)->memo('Share-out of '.$valueDate);
            foreach ($lines as $l) {
                foreach ($l['accounts'] as $accountId => $minor) {
                    if ($minor > 0) {
                        $draft->debit((int) $basis['savings_ledger'][$accountId], $money($minor), 'savings', (int) $accountId, (int) $l['member_id']);
                    }
                }
                SaccoShareoutLine::create(['company_id' => $cid, 'shareout_id' => $shareout->id, 'member_id' => $l['member_id'], 'savings_minor' => $l['savings_minor'],
                    'shares' => $l['shares'], 'savings_paid_minor' => $l['savings_paid_minor'], 'profit_minor' => $l['profit_minor'], 'payout_minor' => $l['payout_minor']]);
            }
            if ($profit > 0) {
                $draft->debit(ChartOfAccounts::id($cid, 're.current'), $money($profit));
            }
            $draft->credit(Money::account($cid, $method), $money($amount));
            $je = (new PostingService())->post($draft);
            $shareout->forceFill(['journal_entry_id' => $je->id])->saveQuietly();
            SaccoAudit::record('sacco.shareout.posted', $shareout, $cid, ['amount_minor' => $amount, 'members' => count($lines)], (int) $by->id);

            return $shareout->fresh()->load('lines');
        });
    }

    /** @return array<string, mixed> */
    public function show(int $companyId, int $id): array
    {
        $s = SaccoShareout::withoutGlobalScopes()->where('company_id', $companyId)->find($id) ?? throw BusinessRuleException::make('not_found', 'That share-out is not in this SACCO.');

        return self::present($s, true);
    }

    /** One member's payout slip (PDF bytes). */
    public function slipPdf(int $companyId, int $shareoutId, int $memberId): string
    {
        $s = SaccoShareout::withoutGlobalScopes()->where('company_id', $companyId)->find($shareoutId) ?? throw BusinessRuleException::make('not_found', 'That share-out is not in this SACCO.');
        $line = SaccoShareoutLine::withoutGlobalScopes()->where('shareout_id', $s->id)->where('member_id', $memberId)->first()
            ?? throw BusinessRuleException::make('not_found', 'That member had no part in this share-out.');
        $member = SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($memberId);
        $settings = LoanBooks::settings($companyId);
        $f = fn (int $m) => LoanBooks::fmt($companyId, $m);
        $parts = ['header' => [(string) ($settings->legal_name ?: $settings->short_name), 'Share-out payout slip', 'Date: '.$s->share_on->toDateString()],
            'lines' => [['Member', $member->member_no.' '.$member->full_name], ['Savings', $f((int) $line->savings_minor)], ['Savings paid back', $f((int) $line->savings_paid_minor)],
                ['Shares', (string) $line->shares.' of '.$s->total_shares], ['Share of profit', $f((int) $line->profit_minor)], ['Total payout', $f((int) $line->payout_minor)],
                ['Paid by', Money::LABELS[$s->method] ?? $s->method]],
            'footer' => [self::FORMULA, 'Profit shared: '.$f((int) $s->profit_minor), 'Received by: ____________________']];

        return app('dompdf.wrapper')->loadHTML(view()->file(dirname(__DIR__, 4).'/resources/views/sacco/receipt.blade.php', ['p' => $parts])->render())
            ->setPaper('a5')->output();
    }

    /** @return array<string, mixed> */
    public static function present(SaccoShareout $s, bool $withLines = false): array
    {
        $out = ['id' => (int) $s->id, 'uuid' => $s->uuid, 'share_on' => $s->share_on->toDateString(), 'method' => $s->method, 'amount_minor' => (int) $s->amount_minor,
            'savings_minor' => (int) $s->savings_minor, 'profit_minor' => (int) $s->profit_minor, 'savings_total_minor' => (int) $s->savings_total_minor,
            'profit_available_minor' => (int) $s->profit_available_minor, 'total_shares' => (int) $s->total_shares, 'currency' => $s->currency, 'status' => $s->status,
            'journal_entry_id' => $s->journal_entry_id, 'notes' => $s->notes, 'created_at' => $s->created_at?->toIso8601String()];
        if ($withLines) {
            $names = SaccoMember::withoutGlobalScopes()->where('company_id', $s->company_id)->whereIn('id', SaccoShareoutLine::withoutGlobalScopes()->where('shareout_id', $s->id)->pluck('member_id'))
                ->get()->keyBy('id');
            $out['lines'] = SaccoShareoutLine::withoutGlobalScopes()->where('shareout_id', $s->id)->orderBy('id')->get()->map(fn ($l) => ['member_id' => (int) $l->member_id,
                'member_no' => $names[$l->member_id]->member_no ?? null, 'name' => $names[$l->member_id]->full_name ?? null, 'savings_minor' => (int) $l->savings_minor,
                'shares' => (int) $l->shares, 'savings_paid_minor' => (int) $l->savings_paid_minor, 'profit_minor' => (int) $l->profit_minor, 'payout_minor' => (int) $l->payout_minor])->all();
        }

        return $out;
    }

    /**
     * What the share-out rests on, from the ledger caches: each member's savings (per account) and shares, the profit,
     * and the figures that reconcile the pool to the SACCO balance.
     *
     * @return array<string, mixed>
     */
    public function basis(int $companyId): array
    {
        $accounts = SaccoSavingsAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('status', '!=', 'closed')->where('balance_minor', '>', 0)
            ->orderBy('id')->get(['id', 'member_id', 'balance_minor', 'ledger_account_id']);
        $shares = SaccoShareAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('shares_count', '>', 0)->pluck('shares_count', 'member_id')->map(fn ($v) => (int) $v)->all();
        $members = [];
        $ledger = [];
        foreach ($accounts as $a) {
            $ledger[(int) $a->id] = (int) $a->ledger_account_id;
            $members[(int) $a->member_id]['accounts'][(int) $a->id] = (int) $a->balance_minor;
        }
        foreach ($shares as $memberId => $n) {
            $members[(int) $memberId] ??= ['accounts' => []];
        }
        ksort($members);
        $rows = [];
        foreach ($members as $memberId => $m) {
            $rows[$memberId] = ['member_id' => $memberId, 'savings_minor' => array_sum($m['accounts']), 'shares' => $shares[$memberId] ?? 0, 'accounts' => $m['accounts']];
        }
        $byType = SaccoLedgerAccount::withoutGlobalScopes()->from('sacco_ledger_accounts as a')->join('sacco_account_balances as b', 'b.ledger_account_id', '=', 'a.id')
            ->where('a.company_id', $companyId)->groupBy('a.type')->selectRaw('a.type, SUM(b.balance_minor) AS total')->pluck('total', 'type')->map(fn ($v) => (int) $v)->all();
        $bal = fn (string $key) => (int) (SaccoAccountBalance::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('ledger_account_id', SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('system_key', $key)->value('id'))->value('balance_minor') ?? 0);
        $income = $byType['income'] ?? 0;
        $expense = $byType['expense'] ?? 0;
        $kept = $bal('re.current') + $bal('re.prior');
        $profit = $income - $expense + $kept;
        $loansOut = $bal('loans.principal') + $bal('loans.interest') + $bal('loans.penalty') + $bal('loans.fees');

        return ['members' => $rows, 'savings_ledger' => $ledger, 'savings_total_minor' => array_sum(array_column($rows, 'savings_minor')), 'total_shares' => array_sum($shares),
            'income_minor' => $income, 'expense_minor' => $expense, 'kept_minor' => $kept, 'profit_minor' => $profit, 'share_capital_minor' => $bal('shares.capital'),
            'loans_out_minor' => $loansOut, 'balance' => Money::balances($companyId)];
    }

    /** Savings plus the profit, when there is anyone to share the profit by (a member with shares or savings). @param array<string, mixed> $basis */
    public static function pool(array $basis): int
    {
        $weights = array_sum(array_map(fn ($r) => (int) $r['shares'] + (int) $r['savings_minor'], $basis['members']));

        return (int) $basis['savings_total_minor'] + ($weights > 0 ? max(0, (int) $basis['profit_minor']) : 0);
    }

    /**
     * Split an amount by the rule above. Pure: Σ payout = $amount exactly.
     *
     * @param  array<string, mixed>  $basis
     * @return list<array<string, mixed>>
     */
    public function allocate(array $basis, int $amount): array
    {
        $rows = $basis['members'];
        if ($rows === []) {
            return [];
        }
        $currency = 'UGX'; // the split is in minor units: the currency only labels the Amounts used for it
        $savings = array_map(fn ($r) => (int) $r['savings_minor'], $rows);
        $totalSavings = array_sum($savings);
        if ($amount >= $totalSavings) {
            $paid = $savings;
            $profit = $amount - $totalSavings;
            $weights = array_map(fn ($r) => (int) $r['shares'], $rows);
            if (array_sum($weights) === 0) {
                $weights = $savings; // nobody holds shares: profit follows savings
            }
            if ($profit > 0 && array_sum($weights) === 0) {
                throw BusinessRuleException::make('nothing_to_share', 'No member holds shares or savings to share the profit by.');
            }
            $profitShares = $profit > 0 && array_sum($weights) > 0 ? array_map(fn (Amount $a) => $a->minor, Amount::of($profit, $currency)->allocate($weights)) : array_map(fn () => 0, $rows);
        } else {
            $paid = $amount > 0 && $totalSavings > 0 ? array_map(fn (Amount $a) => $a->minor, Amount::of($amount, $currency)->allocate($savings)) : array_map(fn () => 0, $rows);
            $profitShares = array_map(fn () => 0, $rows);
        }
        $out = [];
        foreach ($rows as $memberId => $r) {
            $accounts = $r['accounts'];
            $split = [];
            if ($paid[$memberId] > 0) {
                $split = $paid[$memberId] === $r['savings_minor'] ? $accounts : array_map(fn (Amount $a) => $a->minor, Amount::of($paid[$memberId], $currency)->allocate($accounts));
            }
            $out[] = ['member_id' => $memberId, 'savings_minor' => (int) $r['savings_minor'], 'shares' => (int) $r['shares'], 'savings_paid_minor' => $paid[$memberId],
                'profit_minor' => $profitShares[$memberId], 'payout_minor' => $paid[$memberId] + $profitShares[$memberId], 'accounts' => $split];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $basis
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function summary(int $companyId, array $basis, array $lines, int $amount, int $pool): array
    {
        $names = SaccoMember::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', array_column($lines, 'member_id'))->get()->keyBy('id');
        $balance = $basis['balance'];
        $other = $balance['total'] - ($basis['savings_total_minor'] + $basis['share_capital_minor'] + $basis['profit_minor'] - $basis['loans_out_minor']);

        return ['formula' => self::FORMULA, 'currency' => PostingService::currency($companyId), 'pool_minor' => $pool, 'amount_minor' => min(max(0, $amount), $pool),
            'savings_total_minor' => $basis['savings_total_minor'], 'profit_minor' => $basis['profit_minor'], 'total_shares' => $basis['total_shares'],
            'profit' => ['income_minor' => $basis['income_minor'], 'expenses_minor' => $basis['expense_minor'], 'already_shared_or_kept_minor' => $basis['kept_minor']],
            'balance' => $balance, 'covered' => $pool <= $balance['total'],
            'reconciliation' => ['savings_minor' => $basis['savings_total_minor'], 'share_capital_minor' => $basis['share_capital_minor'], 'profit_minor' => $basis['profit_minor'],
                'loans_out_minor' => $basis['loans_out_minor'], 'other_minor' => $other, 'balance_minor' => $balance['total']],
            'members' => array_map(fn ($l) => ['member_id' => $l['member_id'], 'member_no' => $names[$l['member_id']]->member_no ?? null, 'name' => $names[$l['member_id']]->full_name ?? null]
                + array_diff_key($l, ['accounts' => 1, 'member_id' => 1]), $lines),
            'total_payout_minor' => array_sum(array_column($lines, 'payout_minor'))];
    }
}
