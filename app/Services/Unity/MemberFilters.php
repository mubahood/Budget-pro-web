<?php

namespace App\Services\Unity;

use App\Support\LocalDate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The members filters shared by GET unity/members, the organisation tree and POST unity/messages. Every key matches a number
 * on the home (Insights, docs/13-home-stats.md), so tapping a bar lists exactly the members it counted. Queries on
 * sacco_members without joins.
 */
final class MemberFilters
{
    public const AGE = ['u18', '18_25', '26_35', '36_45', '46_60', '60p', 'unknown'];

    public const TENURE = ['lt1', '1_3', '3_5', '5_10', '10p', 'unknown'];

    public const ATTENTION = ['lapsed_60', 'missed_3', 'pledges_overdue', 'applicants', 'missing_photo', 'missing_phone', 'missing_next_of_kin'];

    public const SORTS = ['name', 'member_no', 'joined', 'ends_on'];

    /** The base: the group's members, not merged, not deleted. */
    public static function base(int $companyId): Builder
    {
        return DB::table('sacco_members')->where('company_id', $companyId)->where('is_deleted', 0)->whereNull('merged_into_id');
    }

    /** Current members (M10): active or dormant. */
    public static function current(int $companyId): Builder
    {
        return self::base($companyId)->whereIn('status', UnityMembers::CURRENT);
    }

    /**
     * @param  array<string, mixed>  $f  the request's query
     */
    public static function apply(Builder|EloquentBuilder $q, int $cid, array $f): Builder|EloquentBuilder
    {
        $today = LocalDate::today($cid);
        $d = $today->toDateString();
        $get = fn (string $k) => isset($f[$k]) && $f[$k] !== '' && $f[$k] !== null ? (is_string($f[$k]) ? trim($f[$k]) : $f[$k]) : null;

        if (in_array($standing = (string) $get('standing'), [Memberships::GOOD, Memberships::LAPSED, Memberships::LIFE, Memberships::NONE], true)) {
            Memberships::whereStanding($q, $standing, $today);
        }
        if (in_array($status = $get('status'), [...config('unity.statuses'), 'rejected'], true)) {
            $q->where('status', $status);
        } elseif ($status === 'current') {
            $q->whereIn('status', UnityMembers::CURRENT);
        }
        foreach (['category_id', 'chapter_id'] as $key) {
            if ($get($key) !== null) {
                $q->where($key, (int) $get($key));
            }
        }
        if (($region = $get('region_id')) !== null) {
            $q->whereIn('chapter_id', DB::table('unity_chapters')->where('company_id', $cid)->where('parent_id', (int) $region)->where('is_deleted', 0)->select('id'));
        }
        if ($get('expiring_days') !== null) {
            $q->whereIn('standing', [Memberships::GOOD, Memberships::LAPSED])
                ->whereBetween('membership_ends_on', [$d, $today->copy()->addDays(max(0, min(366, (int) $get('expiring_days'))))->toDateString()]);
        }
        if ((string) $get('paid_up') === '1') {
            Memberships::whereStanding($q, Memberships::GOOD, $today);
        }
        if (($term = (string) $get('q')) !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $q->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('other_names', 'like', $like)
                ->orWhere('member_no', 'like', $like)->orWhere('phone_e164', 'like', $like)->orWhere('email', 'like', $like));
        }
        if (in_array($age = $get('age'), self::AGE, true)) {
            self::age($q, $age, $today);
        }
        if (in_array($gender = $get('gender'), ['male', 'female', 'unknown'], true)) {
            $gender === 'unknown' ? $q->where(fn ($w) => $w->whereNull('gender')->orWhereNotIn('gender', ['male', 'female'])) : $q->where('gender', $gender);
        }
        if (($edu = $get('education')) !== null) {
            $edu === 'unknown' ? $q->where(fn ($w) => $w->whereNull('education_level')->orWhereNotIn('education_level', UnityMembers::EDUCATION))
                : $q->where('education_level', (string) $edu);
        }
        if (($occ = $get('occupation')) !== null) {
            self::occupation($q, $cid, mb_strtolower((string) $occ));
        }
        if (($district = $get('district')) !== null) {
            $district === 'unknown' ? $q->where(fn ($w) => $w->whereNull('district')->orWhere('district', ''))
                : $q->whereRaw('LOWER(TRIM(district)) = ?', [mb_strtolower((string) $district)]);
        }
        if (($sub = $get('subcounty')) !== null) {
            $sub === 'unknown' ? $q->where(fn ($w) => $w->whereNull('subcounty')->orWhere('subcounty', ''))
                : $q->whereRaw('LOWER(TRIM(subcounty)) = ?', [mb_strtolower((string) $sub)]);
        }
        if (in_array($tenure = $get('tenure'), self::TENURE, true)) {
            self::tenure($q, $tenure, $today);
        }
        if (in_array($attention = $get('attention'), self::ATTENTION, true)) {
            self::attention($q, $cid, $attention, $today);
        }
        if ($get('birthday') === 'week') {
            self::dayInWeek($q, 'date_of_birth', $today);
        }
        if ($get('anniversary') === 'week') {
            self::dayInWeek($q, 'member_since', $today);
            $q->where('member_since', '<=', $today->copy()->subYear()->addDays(6)->toDateString());
        }

        return $q;
    }

    /** Orders a filtered members query (sort=name|member_no|joined|ends_on). */
    public static function sort(Builder|EloquentBuilder $q, ?string $sort): Builder|EloquentBuilder
    {
        return match ($sort) {
            'member_no' => $q->orderBy('member_no')->orderBy('id'),
            'joined' => $q->orderByRaw('COALESCE(member_since, joined_on) IS NULL')->orderByRaw('COALESCE(member_since, joined_on)')->orderBy('id'),
            'ends_on' => $q->orderByRaw('membership_ends_on IS NULL')->orderBy('membership_ends_on')->orderBy('id'),
            default => $q->orderBy('last_name')->orderBy('first_name')->orderBy('id'),
        };
    }

    /** Age bands from date_of_birth, in whole years on [today]. */
    public static function age(Builder|EloquentBuilder $q, string $band, Carbon $today): void
    {
        if ($band === 'unknown') {
            $q->whereNull('date_of_birth');

            return;
        }
        [$min, $max] = match ($band) {
            'u18' => [0, 17], '18_25' => [18, 25], '26_35' => [26, 35], '36_45' => [36, 45], '46_60' => [46, 60], default => [61, 200],
        };
        // Born on or before today - min years, and after today - (max + 1) years.
        $q->where('date_of_birth', '<=', $today->copy()->subYearsNoOverflow($min)->toDateString())
            ->where('date_of_birth', '>', $today->copy()->subYearsNoOverflow($max + 1)->toDateString());
    }

    /** Tenure bands from member_since, in whole years on [today]. */
    public static function tenure(Builder|EloquentBuilder $q, string $band, Carbon $today): void
    {
        if ($band === 'unknown') {
            $q->whereNull('member_since');

            return;
        }
        [$min, $max] = match ($band) {
            'lt1' => [0, 0], '1_3' => [1, 2], '3_5' => [3, 4], '5_10' => [5, 9], default => [10, 500],
        };
        $q->where('member_since', '<=', $today->copy()->subYearsNoOverflow($min)->toDateString())
            ->where('member_since', '>', $today->copy()->subYearsNoOverflow($max + 1)->toDateString());
    }

    /** Occupation key (lower-case, trimmed), or `other` (not among the top 8) or `unknown` (not recorded). */
    public static function occupation(Builder|EloquentBuilder $q, int $cid, string $key): void
    {
        if ($key === 'unknown') {
            $q->where(fn ($w) => $w->whereNull('occupation')->orWhereRaw("TRIM(occupation) = ''"));

            return;
        }
        if ($key === 'other') {
            $top = array_column(Insights::topOccupations($cid), 'key');
            $q->whereNotNull('occupation')->whereRaw("TRIM(occupation) <> ''");
            if ($top !== []) {
                $q->whereRaw('LOWER(TRIM(occupation)) NOT IN ('.implode(',', array_fill(0, count($top), '?')).')', $top);
            }

            return;
        }
        $q->whereRaw('LOWER(TRIM(occupation)) = ?', [$key]);
    }

    /** The attention lists of the home (docs/13-home-stats.md): current members only, except applicants. */
    public static function attention(Builder|EloquentBuilder $q, int $cid, string $key, Carbon $today): void
    {
        if ($key === 'applicants') {
            $q->where('status', 'applicant');

            return;
        }
        $q->whereIn('status', UnityMembers::CURRENT);
        match ($key) {
            'lapsed_60' => $q->whereIn('standing', [Memberships::GOOD, Memberships::LAPSED])->where('membership_ends_on', '<', $today->copy()->subDays(60)->toDateString()),
            'missed_3' => $q->whereIn('id', Insights::missedThree($cid) ?: [0]),
            'pledges_overdue' => $q->whereIn('id', Insights::overduePledgers($cid, $today)),
            'missing_photo' => $q->whereNull('photo_file_id'),
            'missing_phone' => $q->where(fn ($w) => $w->whereNull('phone_e164')->orWhere('phone_e164', '')),
            'missing_next_of_kin' => $q->where(fn ($w) => $w->whereNull('next_of_kin_name')->orWhere('next_of_kin_name', '')),
            default => null,
        };
    }

    /**
     * The seven days from today (today included), as MMDD strings. 29 February counts on 28 February in a year without it.
     *
     * @return list<string>
     */
    public static function weekDays(Carbon $today): array
    {
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $today->copy()->addDays($i);
            $days[] = $day->format('md');
            if ($day->format('md') === '0228' && ! $day->isLeapYear()) {
                $days[] = '0229';
            }
        }

        return $days;
    }

    /** The column's day and month falls in the seven days from today. */
    public static function dayInWeek(Builder|EloquentBuilder $q, string $column, Carbon $today): void
    {
        $days = self::weekDays($today);
        $q->whereNotNull($column)->whereRaw("DATE_FORMAT({$column}, '%m%d') IN (".implode(',', array_fill(0, count($days), '?')).')', $days);
    }
}
