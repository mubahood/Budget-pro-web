<?php

namespace App\Services\Unity;

use App\Http\Controllers\Api\V1\Unity\SubscriptionsController;
use App\Models\SaccoMember;
use App\Models\UnitySubscription;
use Illuminate\Support\Facades\DB;

/**
 * One member's whole account with the group: dues, pledges, fund entries, welfare requests and attendance. Money is shown
 * only to the treasurer (unity_finance) or to the member reading their own statement; otherwise money fields are null.
 */
final class MemberStatement
{
    /** @return array<string, mixed> */
    public static function build(SaccoMember $m, bool $money): array
    {
        $cid = (int) $m->company_id;
        $mid = (int) $m->id;
        $hide = fn ($v) => $money ? $v : null;

        $subs = UnitySubscription::withoutGlobalScopes()->where('company_id', $cid)->where('member_id', $mid)->where('is_deleted', 0)
            ->orderByDesc('starts_on')->orderByDesc('id')->get();
        $subscriptions = $subs->map(function ($s) use ($money) {
            $row = SubscriptionsController::shape($s);
            if (! $money) {
                foreach (['amount_minor', 'paid_minor', 'balance_minor'] as $k) {
                    $row[$k] = null;
                }
            }

            return $row;
        })->values()->all();

        $pledges = DB::table('unity_pledges AS p')->join('unity_drives AS d', 'd.id', '=', 'p.drive_id')
            ->where('p.company_id', $cid)->where('p.member_id', $mid)->where('p.is_deleted', 0)->where('d.is_deleted', 0)
            ->orderByDesc('p.id')->get(['d.id AS drive_id', 'd.title', 'd.kind', 'p.pledged_minor', 'p.paid_minor', 'p.due_on', 'p.status']);

        $fund = DB::table('unity_fund_entries')->where('company_id', $cid)->where('member_id', $mid)->where('is_deleted', 0)
            ->orderByDesc('on')->orderByDesc('id')->get();

        $welfare = DB::table('unity_welfare_requests')->where('company_id', $cid)->where('member_id', $mid)->where('is_deleted', 0)
            ->orderByDesc('id')->get();

        // Attendance: the held meetings this member was expected at (marked in the register), the last ten listed.
        $att = DB::table('unity_attendance AS a')->join('unity_meetings AS mt', 'mt.id', '=', 'a.meeting_id')
            ->where('a.company_id', $cid)->where('a.member_id', $mid)->where('a.is_deleted', 0)->where('mt.is_deleted', 0)->where('mt.status', 'held');
        $tot = (clone $att)->selectRaw('COUNT(*) AS held, COALESCE(SUM(a.present), 0) AS present')->first();
        $last = $att->orderByDesc('mt.starts_at')->limit(10)->get(['mt.id', 'mt.title', 'mt.starts_at', 'a.present', 'a.apology']);

        $owed = Dues::arrears($cid, $mid);

        return [
            'member' => UnityMembers::present($m),
            'subscriptions' => $subscriptions,
            'pledges' => $pledges->map(fn ($p) => ['drive' => ['id' => (int) $p->drive_id, 'title' => $p->title, 'kind' => $p->kind],
                'pledged_minor' => $hide((int) $p->pledged_minor), 'paid_minor' => $hide((int) $p->paid_minor),
                'due_on' => $p->due_on ? substr((string) $p->due_on, 0, 10) : null, 'status' => $p->status])->values()->all(),
            'fund' => $money ? $fund->map(fn ($e) => Welfare::shapeEntry($e))->values()->all() : [],
            'welfare' => $welfare->map(fn ($r) => Welfare::shapeRequest($r, [], $money))->values()->all(),
            'attendance' => ['held' => (int) $tot->held, 'present' => (int) $tot->present,
                'pct' => (int) $tot->held > 0 ? round((int) $tot->present * 100 / (int) $tot->held, 1) : null,
                'last' => $last->map(fn ($r) => ['meeting' => ['id' => (int) $r->id, 'title' => $r->title], 'date' => substr((string) Meetings::iso($cid, $r->starts_at), 0, 10),
                    'present' => (bool) $r->present, 'apology' => (bool) $r->apology])->values()->all()],
            'totals' => [
                'dues_paid_minor' => $hide((int) $subs->where('status', '!=', 'cancelled')->sum('paid_minor')),
                'dues_owed_minor' => $hide((int) ($owed[0]['owed_minor'] ?? 0)),
                'pledged_minor' => $hide((int) $pledges->whereIn('status', ['open', 'paid'])->sum('pledged_minor')),
                'pledge_paid_minor' => $hide((int) $pledges->sum('paid_minor')),
                'fund_in_minor' => $hide((int) $fund->where('direction', 'in')->sum('amount_minor')),
                'fund_out_minor' => $hide((int) $fund->where('direction', 'out')->sum('amount_minor')),
            ],
        ];
    }
}
