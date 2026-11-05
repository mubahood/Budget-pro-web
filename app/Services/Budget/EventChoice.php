<?php

namespace App\Services\Budget;

use App\Models\User;
use App\Support\LocalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Which event a person is in when they name none (the owner's brief, OB-02), for the web and the phone alike: what they chose ("Set as default event",
 * admin_users.main_event_id), else worked out on each request: the nearest upcoming open event they have a seat on, else their latest. The phone keeps a copy of this rule,
 * so it can answer with no network (lib/pledges/main_event.dart).
 */
final class EventChoice
{
    /**
     * The events this person may see, soonest first: open with a day to come, open with no day, open with the day passed, then closed.
     *
     * @return list<array{id: int, uuid: string, name: string, open: bool, date: ?string, days_left: ?int, seat: bool, role: ?string}>
     */
    public static function events(User $user): array
    {
        $companyId = (int) $user->company_id;
        $rows = DB::table('budget_programs as p')
            ->leftJoin('budget_program_members as m', function ($join) use ($user, $companyId) {
                $join->on('m.budget_program_id', '=', 'p.id')->where('m.user_id', $user->id)->where('m.company_id', $companyId)->where('m.is_deleted', 0)->where('m.status', 'active');
            })
            ->where('p.company_id', $companyId)->where(fn ($q) => $q->where('p.is_deleted', 0)->orWhereNull('p.is_deleted'))
            ->get(['p.id', 'p.uuid', 'p.name', 'p.title', 'p.status', 'p.deadline', 'm.id as seat', 'm.role'])->unique('id');
        $tz = LocalDate::timezone($companyId);
        $today = Carbon::now($tz)->startOfDay();
        $events = [];
        foreach ($rows as $p) {
            if (! BudgetAccess::canOpen($user, (int) $p->id)) {
                continue;
            }
            $date = $p->deadline ? Carbon::parse($p->deadline, $tz)->startOfDay() : null;
            $events[] = ['id' => (int) $p->id, 'uuid' => (string) $p->uuid, 'name' => trim((string) ($p->name ?: $p->title)) ?: 'Event', 'open' => EventBook::isOpen($p->status),
                'date' => $date?->toDateString(), 'days_left' => $date ? (int) round($today->diffInDays($date, false)) : null, 'seat' => $p->seat !== null, 'role' => $p->role];
        }
        usort($events, fn (array $a, array $b): int => self::rank($a) <=> self::rank($b));

        return $events;
    }

    /**
     * The nearest upcoming open event with a seat; otherwise their latest: newest first among those they have a seat on, or among all
     * they can see when they have no seat, an open one ahead of a closed one.
     *
     * @param  list<array{id: int, open: bool, days_left: ?int, seat: bool}>  $events
     */
    public static function workedOut(array $events): ?int
    {
        $upcoming = array_filter($events, fn (array $e): bool => $e['seat'] && $e['open'] && $e['days_left'] !== null && $e['days_left'] >= 0);
        if ($upcoming !== []) {
            usort($upcoming, fn (array $a, array $b): int => [$a['days_left'], $b['id']] <=> [$b['days_left'], $a['id']]);

            return $upcoming[0]['id'];
        }
        $seated = array_filter($events, fn (array $e): bool => $e['seat']);
        $pool = $seated !== [] ? $seated : $events;
        if ($pool === []) {
            return null;
        }
        usort($pool, fn (array $a, array $b): int => [$b['open'], $b['id']] <=> [$a['open'], $a['id']]);

        return $pool[0]['id'];
    }

    /**
     * @param  array{id: int, open: bool, days_left: ?int}  $e
     * @return array{int, int, int}
     */
    public static function rank(array $e): array
    {
        return match (true) {
            ! $e['open'] => [3, -$e['id'], 0],
            $e['days_left'] === null => [1, -$e['id'], 0],
            $e['days_left'] >= 0 => [0, $e['days_left'], -$e['id']],
            default => [2, -$e['days_left'], -$e['id']],
        };
    }
}
