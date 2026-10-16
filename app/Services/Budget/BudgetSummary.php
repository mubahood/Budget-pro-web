<?php

namespace App\Services\Budget;

use App\Models\Company;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Open events (weddings, introductions, fundraising) at a glance, for the web portal's dashboard
 * (budget-pro-new docs/FAMILY_PLAN.md Part 2). Read from the tables the phone and the classic screens
 * use; rows with is_deleted = 1 are left out.
 *
 * - An event is a budget_programs row; it is open while its status is Active (or not set). Its day
 *   is `deadline`; days left count from today in the account's time zone (negative once it has passed).
 * - Budget = the sum of its budget_items' target_amount (unit price × quantity); spent = their
 *   invested_amount. These are what budget_programs.budget_total and budget_spent store.
 * - Pledged = the sum of contribution_records.amount; paid = paid_amount; still owed = not_paid_amount
 *   (what budget_programs.total_expected, total_collected and total_in_pledge store).
 * - Still needed = budget − paid (not below 0). Per week = still needed ÷ the weeks left to the day
 *   (part weeks count as a week, at least one), null when the day has passed or has no date.
 * - Not yet pledged = budget − pledged (not below 0).
 *
 * One query for any number of events.
 */
final class BudgetSummary
{
    /**
     * @return array{open_count: int, events: list<array{id: int, name: string, date: ?string, days_left: ?int,
     *   budget: float, spent: float, pledged: float, paid: float, owed: float, still_needed: float,
     *   not_pledged: float, weeks_left: ?int, per_week: ?float, pledges: int}>}
     */
    public function for(Company $company, ?Carbon $now = null, int $limit = 3): array
    {
        $cid = (int) $company->id;
        $today = ($now ?? now())->copy()->setTimezone(LocalTime::timezone($company))->startOfDay();

        // One query: each open event with its budget items and pledges summed alongside (correlated subqueries).
        $alive = '(%1$s.is_deleted = 0 OR %1$s.is_deleted IS NULL)';
        $sum = fn (string $table, string $expr, string $alias) => DB::raw(sprintf('(SELECT COALESCE(%s, 0) FROM %s x WHERE x.company_id = p.company_id AND x.budget_program_id = p.id AND %s) AS %s',
            $expr, $table, sprintf($alive, 'x'), $alias));
        $programs = DB::table('budget_programs as p')->where('p.company_id', $cid)->whereRaw(sprintf($alive, 'p'))
            ->where(fn ($q) => $q->whereNull('p.status')->orWhere('p.status', '')->orWhere('p.status', 'Active'))
            ->select(['p.id', 'p.name', 'p.title', 'p.deadline',
                $sum('budget_items', 'SUM(x.target_amount)', 'budget'), $sum('budget_items', 'SUM(x.invested_amount)', 'spent'),
                $sum('contribution_records', 'COUNT(*)', 'n'), $sum('contribution_records', 'SUM(x.amount)', 'pledged'),
                $sum('contribution_records', 'SUM(x.paid_amount)', 'paid'), $sum('contribution_records', 'SUM(x.not_paid_amount)', 'owed')])
            ->get();
        if ($programs->isEmpty()) {
            return ['open_count' => 0, 'events' => []];
        }

        $events = $programs->map(function ($p) use ($today) {
            $id = (int) $p->id;
            $budget = (float) $p->budget;
            $paid = (float) $p->paid;
            $pledged = (float) $p->pledged;
            $date = $p->deadline ? Carbon::parse($p->deadline, $today->getTimezone())->startOfDay() : null;
            $daysLeft = $date ? (int) round($today->diffInDays($date, false)) : null;
            $needed = max(0.0, $budget - $paid);
            $weeks = $daysLeft !== null && $daysLeft >= 0 ? max(1, (int) ceil($daysLeft / 7)) : null;

            return [
                'id' => $id, 'name' => trim((string) ($p->name ?: $p->title)) ?: 'Event',
                'date' => $date?->toDateString(), 'days_left' => $daysLeft,
                'budget' => $budget, 'spent' => (float) $p->spent,
                'pledged' => $pledged, 'paid' => $paid, 'owed' => (float) $p->owed,
                'still_needed' => $needed, 'not_pledged' => max(0.0, $budget - $pledged),
                'weeks_left' => $weeks, 'per_week' => $weeks !== null && $needed > 0 ? round($needed / $weeks, 2) : null,
                'pledges' => (int) $p->n,
            ];
        });

        // The next day first, then events with no date, then those whose day has passed (latest first).
        $sorted = $events->sortBy(fn ($e) => match (true) {
            $e['days_left'] === null => [1, 0, -$e['id']],
            $e['days_left'] >= 0 => [0, $e['days_left'], -$e['id']],
            default => [2, -$e['days_left'], -$e['id']],
        })->values();

        return ['open_count' => $sorted->count(), 'events' => $sorted->take(max(1, $limit))->all()];
    }
}
