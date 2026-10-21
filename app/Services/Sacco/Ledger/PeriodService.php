<?php

namespace App\Services\Sacco\Ledger;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoAccountBalance;
use App\Models\SaccoLedgerAccount;
use App\Models\SaccoLedgerHead;
use App\Models\SaccoPeriod;
use App\Models\SaccoPeriodBalance;
use App\Services\Sacco\SaccoAudit;
use App\Support\LocalDate;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A SACCO's months (ADR 0009 §8, AK-53). Postings land only in an open month; closing takes the ledger head's lock,
 * so nothing posts while a month closes, and writes the closing snapshot (opening, debits, credits and closing per
 * account, with the head hash as the month's anchor). Only the latest closed month can be reopened, with a reason;
 * closing it again writes a new snapshot beside the old one. Both go through an approval (SaccoApprovals executors
 * `period_close` and `period_reopen`); these are the services they execute.
 */
final class PeriodService
{
    /** The month of $from and the ones after it, so postings never wait for a cron (setup: 3). */
    public function ensureAhead(int $companyId, CarbonInterface $from, int $months = 3): void
    {
        $month = Carbon::parse($from->toDateString())->startOfMonth();
        for ($i = 0; $i < $months; $i++) {
            $this->ensure($companyId, $month->copy()->addMonthsNoOverflow($i));
        }
    }

    private function ensure(int $companyId, CarbonInterface $month): SaccoPeriod
    {
        return SaccoPeriod::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'year' => (int) $month->year, 'month' => (int) $month->month],
            ['starts_on' => $month->copy()->startOfMonth()->toDateString(), 'ends_on' => $month->copy()->endOfMonth()->toDateString(), 'status' => 'open'],
        );
    }

    /**
     * The open month an entry dated $entryDate posts into (called by PostingService under the head lock). A month
     * not created yet is opened, unless a later month is already closed: a late item is posted today with its own
     * value date, never into the past (AK-45).
     */
    public function forPosting(int $companyId, CarbonInterface $entryDate): SaccoPeriod
    {
        $period = SaccoPeriod::withoutGlobalScopes()->where('company_id', $companyId)->where('year', $entryDate->year)->where('month', $entryDate->month)->first();
        if ($period === null) {
            $laterClosed = SaccoPeriod::withoutGlobalScopes()->where('company_id', $companyId)->where('status', '!=', 'open')
                ->where('ends_on', '>=', $entryDate->toDateString())->exists();
            if ($laterClosed) {
                throw self::closed($entryDate);
            }
            $period = $this->ensure($companyId, $entryDate);
        }
        if (! $period->isOpen()) {
            throw self::closed($entryDate);
        }

        return $period;
    }

    /** The period an entry date falls in, without opening one. */
    public function find(int $companyId, CarbonInterface $date): ?SaccoPeriod
    {
        return SaccoPeriod::withoutGlobalScopes()->where('company_id', $companyId)->where('year', $date->year)->where('month', $date->month)->first();
    }

    /**
     * Close a month: it has ended, every earlier month is closed, and LedgerVerifier finds the books sound (ADR 0009
     * §7: a failing check blocks the close). Writes the snapshot and anchors the head hash.
     */
    public function close(int $companyId, int $periodId, ?int $userId = null, ?int $approvalId = null): SaccoPeriod
    {
        return DB::transaction(function () use ($companyId, $periodId, $userId, $approvalId) {
            $head = SaccoLedgerHead::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->first()
                ?? throw BusinessRuleException::make('ledger_not_set_up', 'This SACCO\'s books are not set up yet.');
            $period = SaccoPeriod::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->find($periodId)
                ?? throw BusinessRuleException::make('period_not_found', 'That month is not in this SACCO\'s books.');
            if (! $period->isOpen()) {
                throw BusinessRuleException::make('period_not_open', $period->label().' is already closed.');
            }
            if ($period->ends_on->gte(LocalDate::today($companyId))) {
                throw BusinessRuleException::make('period_not_ended', $period->label().' has not ended yet.');
            }
            $earlier = SaccoPeriod::withoutGlobalScopes()->where('company_id', $companyId)->where('status', '!=', 'closed')
                ->where('starts_on', '<', $period->starts_on->toDateString())->orderBy('starts_on')->first();
            if ($earlier !== null) {
                throw BusinessRuleException::make('earlier_period_open', 'Close '.$earlier->label().' first: months close in order.');
            }
            foreach (CloseChecks::all() as $check) {
                $check($companyId, $period);
            }
            $verify = (new LedgerVerifier())->verify($companyId);
            if (! $verify['ok']) {
                SaccoAudit::record('sacco.ledger.verify_failed', $period, $companyId, ['failed' => LedgerVerifier::failed($verify), 'during' => 'period_close'], $userId);
                throw BusinessRuleException::make('ledger_not_verified', 'The ledger check failed, so '.$period->label().' cannot close: '.implode(', ', LedgerVerifier::failed($verify)).'.',
                    ['failed' => LedgerVerifier::failed($verify)]);
            }

            $snapshot = $period->snapshot_no + 1;
            foreach ($this->snapshot($companyId, $period) as $accountId => $row) {
                SaccoPeriodBalance::create(['company_id' => $companyId, 'period_id' => $period->id, 'snapshot_no' => $snapshot, 'ledger_account_id' => $accountId,
                    'head_hash' => $head->last_hash] + $row);
            }
            $period->update(['status' => 'closed', 'snapshot_no' => $snapshot, 'close_hash' => $head->last_hash, 'closed_by_id' => $userId,
                'closed_at' => now(), 'approval_id' => $approvalId ?? $period->approval_id]);
            SaccoAudit::record('sacco.period.closed', $period, $companyId, ['period' => $period->label(), 'snapshot_no' => $snapshot, 'head_hash' => $head->last_hash,
                'approval_id' => $approvalId], $userId);

            return $period->fresh();
        });
    }

    /** Reopen the latest closed month, with a reason (AK-53). Its snapshots stay; the next close writes a new one. */
    public function reopen(int $companyId, int $periodId, ?int $userId, string $reason, ?int $approvalId = null): SaccoPeriod
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw BusinessRuleException::make('reason_required', 'Say why the month is reopened: it is shown on the next closing report.');
        }

        return DB::transaction(function () use ($companyId, $periodId, $userId, $reason, $approvalId) {
            SaccoLedgerHead::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->first();
            $period = SaccoPeriod::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->find($periodId)
                ?? throw BusinessRuleException::make('period_not_found', 'That month is not in this SACCO\'s books.');
            if ($period->status !== 'closed') {
                throw BusinessRuleException::make('period_not_closed', $period->label().' is not closed.');
            }
            $later = SaccoPeriod::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'closed')
                ->where('starts_on', '>', $period->starts_on->toDateString())->exists();
            if ($later) {
                throw BusinessRuleException::make('period_not_latest', 'Only the latest closed month can be reopened.');
            }
            $period->update(['status' => 'open', 'reopen_count' => $period->reopen_count + 1, 'reopened_by_id' => $userId, 'reopened_at' => now(),
                'reopen_reason' => mb_substr($reason, 0, 255)]);
            SaccoAudit::record('sacco.period.reopened', $period, $companyId, ['period' => $period->label(), 'reason' => $reason, 'approval_id' => $approvalId], $userId);

            return $period->fresh();
        });
    }

    /**
     * Each account's month: opening (the previous month's latest snapshot, or every line before the month), the
     * month's debits and credits, and closing, all on the account's normal side.
     *
     * @return array<int, array{opening_minor: int, debits_minor: int, credits_minor: int, closing_minor: int}>
     */
    public function snapshot(int $companyId, SaccoPeriod $period): array
    {
        $accounts = SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('id')->get()->keyBy('id');
        $previous = SaccoPeriod::withoutGlobalScopes()->where('company_id', $companyId)->where('starts_on', '<', $period->starts_on->toDateString())
            ->orderByDesc('starts_on')->first();
        $opening = [];
        if ($previous !== null && $previous->status === 'closed' && $previous->snapshot_no > 0) {
            $opening = SaccoPeriodBalance::withoutGlobalScopes()->where('period_id', $previous->id)->where('snapshot_no', $previous->snapshot_no)
                ->pluck('closing_minor', 'ledger_account_id')->map(fn ($v) => (int) $v)->all();
        } else {
            foreach ($this->sums($companyId, null, $period->starts_on->copy()->subDay()) as $id => [$dr, $cr]) {
                $opening[$id] = PostingService::signed($accounts[$id], $dr, $cr);
            }
        }
        $month = $this->sums($companyId, $period->starts_on, $period->ends_on);
        $out = [];
        foreach ($accounts as $id => $account) {
            [$dr, $cr] = $month[$id] ?? [0, 0];
            $open = $opening[$id] ?? 0;
            $out[$id] = ['opening_minor' => $open, 'debits_minor' => $dr, 'credits_minor' => $cr, 'closing_minor' => $open + PostingService::signed($account, $dr, $cr)];
        }

        return $out;
    }

    /**
     * The trial balance for entries posted up to and including a day: [debit total, credit total] per account (the
     * figures a month's snapshot must match when nothing is posted after it).
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public function sums(int $companyId, ?CarbonInterface $from, CarbonInterface $to): array
    {
        $q = DB::table('sacco_journal_lines')->where('company_id', $companyId)->where('entry_date', '<=', $to->toDateString());
        if ($from !== null) {
            $q->where('entry_date', '>=', $from->toDateString());
        }

        return $q->groupBy('ledger_account_id')->selectRaw('ledger_account_id, SUM(debit_minor) AS dr, SUM(credit_minor) AS cr')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->ledger_account_id => [(int) $r->dr, (int) $r->cr]])->all();
    }

    /** The account caches as they stand (for comparing with a snapshot). @return array<int, int> */
    public static function balances(int $companyId): array
    {
        return SaccoAccountBalance::withoutGlobalScopes()->where('company_id', $companyId)->pluck('balance_minor', 'ledger_account_id')->map(fn ($v) => (int) $v)->all();
    }

    private static function closed(CarbonInterface $date): BusinessRuleException
    {
        return BusinessRuleException::make('period_closed', $date->format('F Y').' is closed: post it today with its own value date instead.', ['month' => $date->format('Y-m')]);
    }
}
