<?php

namespace App\Services\Unity;

use App\Models\Company;
use App\Support\LocalDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * UnityDesk's two daily jobs (doc 12):
 * - lapse: members whose period has ended become lapsed, and each is told once on their noticeboard;
 * - remind: members whose period ends within remind_days are told once per period.
 * Each notice is claimed in scheduled_notices first (company, key, member and period), so a job that runs twice,
 * or a day the host's cron missed, never sends a notice twice.
 */
class StandingJobs
{
    public const REMIND_DAYS = 7;

    /** @return Collection<int, Company> every association (company with the unity module) */
    public static function associations(?int $only = null): Collection
    {
        return Company::withoutGlobalScopes()
            ->when($only, fn ($q) => $q->where('id', $only))
            ->where(fn ($q) => $q->where('enabled_modules', 'like', '%"unity"%')->orWhere('business_type', 'unity'))
            ->get()->filter(fn (Company $c) => Memberships::isAssociation($c))->values();
    }

    /** @return array{lapsed: int, told: int} */
    public static function lapse(Company $company): array
    {
        $cid = (int) $company->id;
        $today = LocalDate::today($cid)->toDateString();
        $due = Memberships::members($cid)->where('standing', Memberships::GOOD)
            ->where('membership_ends_on', '<', $today)->get(['id', 'membership_ends_on']);
        $lapsed = Memberships::refreshStanding($cid);
        $told = 0;
        foreach ($due as $m) {
            $ended = (string) $m->membership_ends_on;
            if (self::claim($cid, 'unity:lapsed', (int) $m->id, $ended)) {
                $told += Voice::notices($cid, [(int) $m->id], 'Your membership has ended',
                    'Your membership of '.$company->name.' ended on '.Carbon::parse($ended)->format('j M Y').'. Renew with the treasurer to be in good standing again.');
            }
        }

        return ['lapsed' => $lapsed, 'told' => $told];
    }

    /** @return array{told: int} */
    public static function remind(Company $company, int $days = self::REMIND_DAYS): array
    {
        $cid = (int) $company->id;
        $told = 0;
        foreach (Memberships::expiring($cid, $days)->where('standing', Memberships::GOOD)->get(['id', 'membership_ends_on']) as $m) {
            $ends = (string) $m->membership_ends_on;
            if (self::claim($cid, 'unity:remind', (int) $m->id, $ends)) {
                $told += Voice::notices($cid, [(int) $m->id], 'Your membership ends soon',
                    'Your membership of '.$company->name.' ends on '.Carbon::parse($ends)->format('j M Y').'. Renew before then to stay in good standing.');
            }
        }

        return ['told' => $told];
    }

    /** True once per company, key, member and period. */
    private static function claim(int $cid, string $key, int $memberId, string $period): bool
    {
        return DB::table('scheduled_notices')->insertOrIgnore([
            'company_id' => $cid, 'key' => $key, 'period' => 'm'.$memberId.':'.substr($period, 0, 10), 'created_at' => now(),
        ]) > 0;
    }
}
