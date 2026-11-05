<?php

namespace App\Services\Rentals\Landlords;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalClose;
use App\Models\RentalSetting;
use App\Models\User;
use App\Services\Rentals\Jobs\RentalCheck;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Support\LocalDate;
use Illuminate\Support\Carbon;

/**
 * Closing a month (LC-02): the books through its last day take no more money, and every landlord's statement for it is issued, in one
 * transaction under the account's lock. A close posts nothing and moves no money; it writes totals for proof.
 *
 * A month closes once it is over, and months close in order, though the first close can start at any month (what is before it locks too, with
 * no statements of its own). Only the latest closed month reopens, by the account owner (EL-28), with a written reason, so a change cannot
 * flow through months of sent papers unnoticed. Closing again keeps the statements whose figures did not change.
 *
 * The close runs in the request, on the shared host's one process: measured by RentalsMonthEndFiguresTest at 200 landlords.
 */
final class MonthClose
{
    /** The last closed day, or null. */
    public static function closedThrough(int $companyId): ?string
    {
        return RentalLedger::closedThrough($companyId);
    }

    /** Whether the whole of a month ('Y-m') is closed. */
    public static function isClosedMonth(int $companyId, string $month): bool
    {
        $through = self::closedThrough($companyId);

        return $through !== null && self::lastDay($month) <= $through;
    }

    /** The month that closes next ('Y-m'): the one after the last closed, if it is over; with nothing closed yet, the month just ended. */
    public static function nextMonth(int $companyId): ?string
    {
        $through = self::closedThrough($companyId);
        $thisMonth = LocalDate::today($companyId)->format('Y-m');
        if ($through === null) {
            return self::earliestReopened($companyId) ?? Carbon::parse($thisMonth.'-01')->subMonthNoOverflow()->format('Y-m');
        }
        $next = Carbon::parse($through)->addDay()->format('Y-m');

        return $next < $thisMonth ? $next : null;
    }

    /** The latest closed month's close, or null. */
    public static function latest(int $companyId): ?RentalClose
    {
        return RentalClose::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'closed')->orderByDesc('month')->orderByDesc('version')->first();
    }

    /**
     * Close a month and issue its statements. Refuses a month not over, one out of order, one already closed, and books that do not agree.
     *
     * @param  string  $month  Y-m
     */
    public function close(User $by, string $month): RentalClose
    {
        RentalAccess::authorize($by, 'rentals_close');
        if (RentalAccess::propertyIds($by) !== null) {
            throw BusinessRuleException::make('forbidden', 'Closing a month covers the whole account\'s money. Ask your property manager.');
        }
        $month = self::valid($month);
        $cid = (int) $by->company_id;
        if ($month >= LocalDate::today($cid)->format('Y-m')) {
            throw BusinessRuleException::make('month_not_over', self::name($month).' is not over yet. A month closes once it has ended.');
        }

        return RentalLedger::locked($cid, function () use ($by, $month, $cid) {
            $through = RentalLedger::closedThrough($cid);
            if ($through !== null) {
                if (self::lastDay($month) <= $through) {
                    throw BusinessRuleException::make('month_already_closed', self::name($month).' is already closed. Nothing changed.');
                }
                $next = Carbon::parse($through)->addDay()->format('Y-m');
                if ($month !== $next) {
                    throw BusinessRuleException::make('month_out_of_order', 'Months close in order: close '.self::name($next).' first.');
                }
            } elseif (($reopened = self::earliestReopened($cid)) !== null && $month > $reopened) {
                // A later close would lock a reopened month with its old statements still standing.
                throw BusinessRuleException::make('month_out_of_order', self::name($reopened).' was reopened: close it first.');
            }
            $problems = app(RentalCheck::class)->run($cid);
            if ($problems !== []) {
                throw BusinessRuleException::make('books_disagree', 'The books do not agree, so '.self::name($month).' cannot close. Nothing was changed.', ['problems' => array_slice($problems, 0, 20)]);
            }
            $to = self::lastDay($month);
            $version = 1 + (int) RentalClose::withoutGlobalScopes()->where('company_id', $cid)->where('month', $month.'-01')->max('version');
            $close = RentalClose::create(['company_id' => $cid, 'month' => $month.'-01', 'version' => $version, 'status' => 'closed', 'closed_at' => now(), 'closed_by_id' => $by->id,
                'totals' => [], 'proof' => str_repeat('0', 64)]);
            $figures = MonthEndFigures::month($cid, $month, false);
            $counts = StatementBook::issueMonth($close, $by, $month, array_column($figures['rows'], 'landlord_id'));
            $totals = $figures['totals'] + ['statements' => $counts];
            $this->assertAddsUp($cid, $month, $totals);
            $this->writeTotals($close, $totals);
            RentalSetting::withoutGlobalScopes()->where('company_id', $cid)->update(['closed_through' => $to, 'updated_at' => now()]);
            RentalLedger::closedThroughMoved($cid, $to);
            RentalAudit::record($cid, 'month.closed', $close, 'Closed '.self::name($month).' with '.array_sum([$counts['issued'], $counts['kept']]).' statements',
                ['version' => $version, 'statements' => $counts], 'staff', (int) $by->id);

            return $close->fresh();
        });
    }

    /**
     * Reopen the latest closed month: the account owner alone, with a reason. Its statements stay readable and stamped until it closes again; the
     * books through the month before stay closed.
     *
     * @param  string  $month  Y-m
     */
    public function reopen(User $by, string $month, string $reason): RentalClose
    {
        RentalAccess::authorize($by, 'rentals_reopen');
        $month = self::valid($month);
        $reason = trim($reason);
        if ($reason === '') {
            throw BusinessRuleException::make('reason_required', 'Say why this month is reopened. The reason is kept.');
        }
        $cid = (int) $by->company_id;

        return RentalLedger::locked($cid, function () use ($by, $month, $reason, $cid) {
            $latest = self::latest($cid);
            if ($latest === null || self::lastDay($month) !== self::closedThrough($cid)) {
                throw BusinessRuleException::make('reopen_latest_only', $latest === null ? 'No month is closed.'
                    : 'Only the latest closed month can reopen, one at a time. Reopen '.self::name($latest->month->format('Y-m')).' first.');
            }
            $latest->forceFill(['status' => 'reopened', 'reopened_at' => now(), 'reopened_by_id' => $by->id, 'reopen_reason' => mb_substr($reason, 0, 255)])->save();
            $before = Carbon::parse($month.'-01')->subMonthNoOverflow()->format('Y-m');
            $stillClosed = RentalClose::withoutGlobalScopes()->where('company_id', $cid)->where('month', $before.'-01')->where('status', 'closed')->exists();
            $through = $stillClosed ? self::lastDay($before) : null;
            RentalSetting::withoutGlobalScopes()->where('company_id', $cid)->update(['closed_through' => $through, 'updated_at' => now()]);
            RentalLedger::closedThroughMoved($cid, $through);
            RentalAudit::record($cid, 'month.reopened', $latest, 'Reopened '.self::name($month), ['reason' => mb_substr($reason, 0, 255)], 'staff', (int) $by->id);

            return $latest->fresh();
        });
    }

    /** The earliest month reopened and not closed again ('Y-m'), or null. */
    private static function earliestReopened(int $companyId): ?string
    {
        $latest = RentalClose::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('month')->orderByDesc('version')->get(['month', 'status'])->unique(fn (RentalClose $c) => $c->month->format('Y-m'));

        return $latest->firstWhere('status', 'reopened')?->month->format('Y-m');
    }

    /** The last day of a month 'Y-m'. */
    public static function lastDay(string $month): string
    {
        return Carbon::parse($month.'-01')->endOfMonth()->toDateString();
    }

    /** "October 2026". */
    public static function name(string $month): string
    {
        return Carbon::parse($month.'-01')->format('F Y');
    }

    private static function valid(string $month): string
    {
        $month = trim($month);
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw BusinessRuleException::make('date_invalid', 'Choose a month, for example 2026-10.');
        }

        return $month;
    }

    /**
     * The close's own check, in its transaction: what the landlords are owed in the close, on their statements and in the journal is one figure.
     * A failure here rolls the whole close back: a paper that does not add up must never be issued.
     *
     * @param  array<string, mixed>  $totals
     */
    private function assertAddsUp(int $cid, string $month, array $totals): void
    {
        $owed = (int) $totals['owed_to_landlords'] - (int) $totals['owed_back_by_landlords'];
        $onStatements = (int) \App\Models\RentalStatement::withoutGlobalScopes()->where('company_id', $cid)->where('month', $month.'-01')->where('status', 'issued')->sum('closing_minor');
        $inJournal = (int) \App\Models\RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('occurred_on', '<=', self::lastDay($month))->sum('landlord_minor');
        if ($owed !== $onStatements || $owed !== $inJournal) {
            throw BusinessRuleException::make('close_does_not_add_up', 'The statements do not add up to what the journal says is owed, so nothing was closed.',
                ['position' => $owed, 'statements' => $onStatements, 'journal' => $inJournal]);
        }
    }

    /** @param array<string, mixed> $totals */
    private function writeTotals(RentalClose $close, array $totals): void
    {
        // The row was written first so the statements could name it; this is its only fill, before anyone can read it.
        \Illuminate\Support\Facades\DB::table('rental_closes')->where('id', $close->id)->update(['totals' => json_encode($totals, JSON_THROW_ON_ERROR),
            'proof' => StatementProof::of(['month' => $close->month->format('Y-m'), 'version' => (int) $close->version, 'totals' => $totals]), 'updated_at' => now()]);
    }
}
