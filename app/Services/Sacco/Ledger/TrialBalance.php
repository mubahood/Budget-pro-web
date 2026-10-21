<?php

namespace App\Services\Sacco\Ledger;

use App\Models\SaccoLedgerAccount;
use App\Models\SaccoPeriod;
use App\Models\SaccoPeriodBalance;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The trial balance (04 §12 `trial_balance`, P1.13): per account, the opening balance before a range, its debits and
 * credits within it and the closing balance, all on the account's normal side, computed from the journal lines
 * (never from stored totals). The debit and credit columns must total the same; `balanced` says whether they do.
 * forPeriod() reads a closed month's snapshot instead, and equals between() for that month (TrialBalanceTest).
 */
final class TrialBalance
{
    /**
     * @return array{from: ?string, to: string, rows: list<array{account_id: int, code: string, name: string, type: string, normal_side: string, opening_minor: int, debits_minor: int, credits_minor: int, closing_minor: int, debit_balance_minor: int, credit_balance_minor: int}>, totals: array<string, int>, balanced: bool}
     */
    public function between(int $companyId, CarbonInterface|string|null $from, CarbonInterface|string $to, bool $withEmpty = false): array
    {
        $to = Carbon::parse($to)->startOfDay();
        $from = $from === null ? null : Carbon::parse($from)->startOfDay();
        $periods = new PeriodService();
        $before = $from === null ? [] : $periods->sums($companyId, null, $from->copy()->subDay());
        $within = $periods->sums($companyId, $from, $to);
        $rows = [];
        foreach (SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('sort')->get() as $a) {
            [$bd, $bc] = $before[$a->id] ?? [0, 0];
            [$d, $c] = $within[$a->id] ?? [0, 0];
            $rows[] = self::row($a, PostingService::signed($a, $bd, $bc), $d, $c);
        }

        return self::finish($rows, $from?->toDateString(), $to->toDateString(), $withEmpty);
    }

    /** A closed month's trial balance from its latest snapshot. @return array<string, mixed> */
    public function forPeriod(int $companyId, SaccoPeriod $period, bool $withEmpty = false): array
    {
        $snap = SaccoPeriodBalance::withoutGlobalScopes()->where('period_id', $period->id)->where('snapshot_no', $period->snapshot_no)->get()->keyBy('ledger_account_id');
        $rows = [];
        foreach (SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('sort')->get() as $a) {
            $s = $snap[$a->id] ?? null;
            $rows[] = self::row($a, (int) ($s->opening_minor ?? 0), (int) ($s->debits_minor ?? 0), (int) ($s->credits_minor ?? 0));
        }

        return self::finish($rows, $period->starts_on->toDateString(), $period->ends_on->toDateString(), $withEmpty) + ['snapshot_no' => (int) $period->snapshot_no];
    }

    /** @return array<string, mixed> */
    private static function row(SaccoLedgerAccount $a, int $opening, int $debits, int $credits): array
    {
        $closing = $opening + PostingService::signed($a, $debits, $credits);
        $debitSide = $a->isDebitNormal() ? $closing : -$closing; // a balance expressed as a debit (+) or credit (−)

        return ['account_id' => (int) $a->id, 'code' => (string) $a->code, 'name' => (string) $a->name, 'type' => (string) $a->type, 'normal_side' => (string) $a->normal_side,
            'opening_minor' => $opening, 'debits_minor' => $debits, 'credits_minor' => $credits, 'closing_minor' => $closing,
            'debit_balance_minor' => max(0, $debitSide), 'credit_balance_minor' => max(0, -$debitSide)];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private static function finish(array $rows, ?string $from, string $to, bool $withEmpty): array
    {
        $totals = ['debits_minor' => 0, 'credits_minor' => 0, 'debit_balance_minor' => 0, 'credit_balance_minor' => 0];
        foreach ($rows as $r) {
            foreach ($totals as $k => $v) {
                $totals[$k] = $v + $r[$k];
            }
        }
        if (! $withEmpty) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['opening_minor'] !== 0 || $r['debits_minor'] !== 0 || $r['credits_minor'] !== 0));
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $totals,
            'balanced' => $totals['debits_minor'] === $totals['credits_minor'] && $totals['debit_balance_minor'] === $totals['credit_balance_minor']];
    }
}
