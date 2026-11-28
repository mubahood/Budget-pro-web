<?php

namespace App\Services\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoMember;
use App\Models\UnityCategory;
use App\Models\UnitySubscription;
use App\Support\LocalDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dues: renewing a member for one to five periods with one payment, the year's collection, and arrears (who owes what).
 * Built on Memberships, which keeps each member's standing.
 */
final class Dues
{
    /**
     * Starts the member's next periods (1..5) in their category, one subscription each, and spreads the payment over them in
     * order: each period takes up to its fee; what is left over is refused (pay no more than the periods asked for).
     *
     * @param  array{periods?: int, paid_minor?: ?int, method?: ?string, paid_on?: ?string, reference?: ?string, category_id?: ?int}  $d
     * @return list<UnitySubscription>
     */
    public static function renew(SaccoMember $m, array $d, ?int $userId): array
    {
        $cid = (int) $m->company_id;
        $categoryId = (int) ($d['category_id'] ?? $m->category_id);
        $category = $categoryId ? UnityCategory::withoutGlobalScopes()->where('company_id', $cid)->where('is_deleted', 0)->find($categoryId) : null;
        if ($category === null) {
            throw BusinessRuleException::make('validation', 'Give the member a category first.', ['category_id' => ['The member has no category.']]);
        }
        $periods = max(1, min(5, (int) ($d['periods'] ?? 1)));
        if ($category->isLife() && $periods > 1) {
            throw BusinessRuleException::make('validation', 'Life membership is one payment.', ['periods' => ['One period for life membership.']]);
        }
        $fee = (int) $category->fee_minor;
        $left = (int) ($d['paid_minor'] ?? $fee * $periods);
        if ($left > $fee * $periods) {
            throw BusinessRuleException::make('validation', 'That is more than '.$periods.' period(s) cost.', ['paid_minor' => ['At most '.($fee * $periods).'.']]);
        }

        return DB::transaction(function () use ($m, $category, $periods, $fee, &$left, $d, $userId) {
            $out = [];
            $start = Memberships::nextStart($m->fresh());
            for ($i = 0; $i < $periods; $i++) {
                $pay = min($left, $fee);
                $left -= $pay;
                $sub = Memberships::renew($m->fresh(), $category, array_filter(['starts_on' => $start->toDateString(), 'paid_minor' => $pay, 'method' => $d['method'] ?? null,
                    'paid_on' => $pay > 0 ? ($d['paid_on'] ?? null) : null, 'reference' => $d['reference'] ?? null], fn ($v) => $v !== null), $userId);
                $out[] = $sub;
                if ($sub->ends_on === null) {
                    break; // life
                }
                $start = $sub->ends_on->copy()->addDay();
            }

            return $out;
        });
    }

    /** @return array<string, mixed> */
    public static function summary(int $cid): array
    {
        $today = LocalDate::today($cid);
        $dues = Insights::dues($cid, $today);
        $arrears = self::arrears($cid);

        return [
            'collected_ytd_minor' => $dues['collected'], 'expected_ytd_minor' => $dues['expected'], 'collection_pct' => $dues['pct'],
            'arrears_minor' => array_sum(array_column($arrears, 'owed_minor')), 'arrears_members' => count($arrears),
            'due_30' => (int) Memberships::expiring($cid, 30)->count(),
            'by_month' => Insights::collections($cid, $today)['this_year'],
        ];
    }

    /**
     * What each current member owes, most first. owed = the unpaid part of their pending periods, plus, when their membership
     * has run out, one fee for every period started since it ended (none yet: one period from the day they joined). since = the
     * first day not paid for. Members of a free or life category owe nothing.
     *
     * @return list<array{member_id: int, owed_minor: int, since: ?string, standing: string}>
     */
    public static function arrears(int $cid, ?int $memberId = null): array
    {
        $today = LocalDate::today($cid);
        $members = MemberFilters::current($cid)->when($memberId, fn ($q) => $q->where('id', $memberId))->get(['id', 'company_id', 'standing', 'membership_ends_on', 'member_since', 'joined_on', 'category_id']);
        $categories = DB::table('unity_categories')->where('company_id', $cid)->get(['id', 'fee_minor', 'period_months'])->keyBy('id');
        $pending = DB::table('unity_subscriptions')->where('company_id', $cid)->where('is_deleted', 0)->where('status', 'pending')->when($memberId, fn ($q) => $q->where('member_id', $memberId))
            ->selectRaw('member_id, SUM(amount_minor - paid_minor) AS owed, MIN(starts_on) AS since')->groupBy('member_id')->get()->keyBy('member_id');
        $out = [];
        foreach ($members as $m) {
            $standing = Memberships::standingOf($m, $today);
            $owed = max(0, (int) ($pending[$m->id]->owed ?? 0));
            $since = $owed > 0 ? (string) $pending[$m->id]->since : null;
            $category = $m->category_id ? ($categories[$m->category_id] ?? null) : null;
            $fee = (int) ($category->fee_minor ?? 0);
            $months = (int) ($category->period_months ?? 0);
            if ($fee > 0 && $months > 0 && $standing === Memberships::LAPSED) {
                $from = Carbon::parse((string) $m->membership_ends_on)->addDay();
                $periods = intdiv((int) $from->diffInMonths($today), $months) + 1;
                $owed += $periods * $fee;
                $since = $since !== null && $since < $from->toDateString() ? $since : $from->toDateString();
            } elseif ($fee > 0 && $months > 0 && $standing === Memberships::NONE) {
                $owed += $fee;
                $since ??= (string) ($m->member_since ?? $m->joined_on);
            }
            if ($owed > 0) {
                $out[] = ['member_id' => (int) $m->id, 'owed_minor' => $owed, 'since' => $since === null ? null : substr($since, 0, 10), 'standing' => $standing];
            }
        }
        usort($out, fn ($a, $b) => [$b['owed_minor'], $a['member_id']] <=> [$a['owed_minor'], $b['member_id']]);

        return $out;
    }
}
