<?php

namespace App\Services\Unity;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\UnityCategory;
use App\Models\UnitySubscription;
use App\Services\Billing\Quotas;
use App\Support\LocalDate;
use App\Support\Sync\SyncSequence;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * UnityDesk memberships: periods, renewals and standing. Amounts are minor units (integers), dates are the association's own.
 *
 * - A period is in force when its subscription is paid or waived. A category's period is period_months long (0: for life),
 *   so a year from 1 March ends on the last day of February.
 * - A renewal starts the day after the current period ends while that period is still in force (ends today or later), else today.
 * - Each member carries two caches the server alone writes: membership_ends_on (the latest end in force) and standing:
 *   good (ends today or later), lapsed (ended), life (a life period in force), or null (nothing in force yet).
 *   Lists and counts read membership_ends_on against today, so a cache a day old never shows a lapsed member as good.
 */
final class Memberships
{
    public const GOOD = 'good';

    public const LAPSED = 'lapsed';

    public const LIFE = 'life';

    /** No period in force yet (a new member, or every period cancelled). */
    public const NONE = 'none';

    public const IN_FORCE = ['paid', 'waived'];

    public static function isAssociation(Company|int|null $company): bool
    {
        if ($company instanceof Company) {
            return in_array('unity', $company->modules(), true);
        }
        $company = $company ? Company::withoutGlobalScopes()->find($company) : null;

        return $company !== null && in_array('unity', $company->modules(), true);
    }

    /** The last day of a period of this category that starts on [start]; null for life. */
    public static function periodEnd(Carbon $start, UnityCategory $category): ?Carbon
    {
        return $category->isLife() ? null : $start->copy()->addMonthsNoOverflow((int) $category->period_months)->subDay();
    }

    /** Where a renewal starts: the day after the current period if it is still in force, else today. */
    public static function nextStart(SaccoMember $member, ?Carbon $today = null): Carbon
    {
        $today ??= LocalDate::today((int) $member->company_id);
        [$ends, $standing] = self::workOut((int) $member->company_id, (int) $member->id, $today);
        if ($standing === self::LIFE) {
            throw BusinessRuleException::make('life_member', 'This member is a member for life: there is nothing to renew.');
        }

        return $ends !== null && $ends->gte($today) ? $ends->copy()->addDay() : $today->copy();
    }

    /**
     * Renews a member in a category: a new period from nextStart(), for the category's fee unless another amount is given.
     * paid_minor at or above the amount makes it paid (numbered, dated today unless paid_on is given); 0 due is waived.
     *
     * @param  array{amount_minor?: int, paid_minor?: int, paid_on?: string, method?: string, reference?: string, status?: string, notes?: string, starts_on?: string}  $payment
     */
    public static function renew(SaccoMember $member, UnityCategory $category, array $payment = [], ?int $userId = null): UnitySubscription
    {
        if ((int) $category->company_id !== (int) $member->company_id) {
            throw BusinessRuleException::make('not_found', 'That category is not this association\'s.');
        }
        $start = isset($payment['starts_on']) ? Carbon::parse($payment['starts_on'])->startOfDay() : self::nextStart($member);
        $amount = (int) ($payment['amount_minor'] ?? $category->fee_minor);
        $paid = (int) ($payment['paid_minor'] ?? 0);
        $status = $payment['status'] ?? ($amount === 0 ? 'waived' : ($paid >= $amount ? 'paid' : 'pending'));

        return DB::transaction(function () use ($member, $category, $payment, $userId, $start, $amount, $paid, $status) {
            $sub = UnitySubscription::create([
                'company_id' => $member->company_id, 'member_id' => $member->id, 'category_id' => $category->id,
                'starts_on' => $start->toDateString(), 'ends_on' => self::periodEnd($start, $category)?->toDateString(),
                'amount_minor' => $amount, 'paid_minor' => $paid, 'paid_on' => $payment['paid_on'] ?? null, 'method' => $payment['method'] ?? null,
                'reference' => $payment['reference'] ?? null, 'status' => $status, 'notes' => $payment['notes'] ?? null, 'created_by_id' => $userId,
            ]);
            if ((int) $member->category_id !== (int) $category->id) {
                DB::table('sacco_members')->where('id', $member->id)->update(['category_id' => $category->id, 'server_seq' => SyncSequence::next(),
                    'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            }

            return $sub;
        });
    }

    /** Every save's checks (UnitySubscription::saving), from the API and from a phone alike. */
    public static function guard(UnitySubscription $s): void
    {
        $cid = (int) $s->company_id;
        if (! SaccoMember::withoutGlobalScopes()->where('company_id', $cid)->whereKey($s->member_id)->exists()) {
            throw BusinessRuleException::make('not_a_member', 'That person is not a member of this association.');
        }
        if (! UnityCategory::withoutGlobalScopes()->where('company_id', $cid)->whereKey($s->category_id)->exists()) {
            throw BusinessRuleException::make('not_found', 'That category is not this association\'s.');
        }
        if (! in_array($s->status, config('unity.subscription_statuses'), true)) {
            throw BusinessRuleException::make('validation', 'A subscription is pending, paid, waived or cancelled.', ['status' => ['Unknown status.']]);
        }
        if ($s->getOriginal('status') === 'cancelled' && $s->status !== 'cancelled' && $s->exists) {
            throw BusinessRuleException::make('cancelled_final', 'A cancelled subscription stays cancelled. Record a new one.');
        }
        if (! $s->exists && $s->ends_on === null && $s->starts_on !== null && ($category = UnityCategory::withoutGlobalScopes()->find($s->category_id)) && ! $category->isLife()) {
            $s->ends_on = self::periodEnd($s->starts_on->copy(), $category); // a phone may leave the end to the category
        }
        if ($s->starts_on === null || ($s->ends_on !== null && $s->ends_on->lt($s->starts_on))) {
            throw BusinessRuleException::make('validation', 'A period ends on or after the day it starts.', ['ends_on' => ['A period ends on or after the day it starts.']]);
        }
        if ((int) $s->amount_minor < 0 || (int) $s->paid_minor < 0) {
            throw BusinessRuleException::make('validation', 'Amounts are never below zero.');
        }
        if ($s->status === 'pending' && (int) $s->amount_minor > 0 && (int) $s->paid_minor >= (int) $s->amount_minor) {
            $s->status = 'paid'; // fully paid
        }
        if ($s->status === 'paid' && (int) $s->paid_minor < (int) $s->amount_minor) {
            throw BusinessRuleException::make('not_fully_paid', 'Only part of the fee is paid: keep it pending until the rest comes in, or waive it.', ['paid_minor' => ['Less than the amount due.']]);
        }
        if ($s->status === 'paid') {
            $s->paid_on ??= LocalDate::today($cid)->toDateString();
            if (blank($s->receipt_no)) {
                $s->receipt_no = \App\Services\Shop\NumberSequencer::next($cid, 'rct');
            }
        }
        if (! $s->exists && trim((string) $s->method) !== '' && ! in_array($s->method, config('unity.payment_methods'), true)) {
            throw BusinessRuleException::make('validation', 'Unknown payment method.', ['method' => ['Unknown payment method.']]);
        }
    }

    /**
     * The member's latest end in force and standing, from their subscriptions.
     *
     * @return array{0: ?Carbon, 1: ?string} [membership_ends_on, standing]
     */
    public static function workOut(int $companyId, int $memberId, ?Carbon $today = null): array
    {
        $today ??= LocalDate::today($companyId);
        $rows = DB::table('unity_subscriptions')->where('company_id', $companyId)->where('member_id', $memberId)->where('is_deleted', 0)
            ->whereIn('status', self::IN_FORCE)->get(['starts_on', 'ends_on']);
        if ($rows->isEmpty()) {
            return [null, null];
        }
        if ($rows->contains(fn ($r) => $r->ends_on === null)) {
            return [null, self::LIFE];
        }
        $ends = Carbon::parse((string) $rows->max('ends_on'))->startOfDay();

        return [$ends, $ends->gte($today) ? self::GOOD : self::LAPSED];
    }

    /** Writes the member's caches again (after any subscription changes). Nothing is written when nothing changed. */
    public static function recompute(int $companyId, int $memberId): void
    {
        [$ends, $standing] = self::workOut($companyId, $memberId);
        $now = DB::table('sacco_members')->where('company_id', $companyId)->where('id', $memberId)->first(['membership_ends_on', 'standing']);
        if ($now === null) {
            return;
        }
        $endsOn = $ends?->toDateString();
        if ($now->membership_ends_on === $endsOn && $now->standing === $standing) {
            return;
        }
        DB::table('sacco_members')->where('id', $memberId)->update(['membership_ends_on' => $endsOn, 'standing' => $standing,
            'server_seq' => SyncSequence::next(), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }

    /** Works every member of an association out again (the demo, a repair). @return int members changed */
    public static function recomputeAll(int $companyId): int
    {
        $changed = 0;
        foreach (DB::table('sacco_members')->where('company_id', $companyId)->where('is_deleted', 0)->pluck('id') as $id) {
            $before = DB::table('sacco_members')->where('id', $id)->value('server_seq');
            self::recompute($companyId, (int) $id);
            $changed += (int) (DB::table('sacco_members')->where('id', $id)->value('server_seq') !== $before);
        }

        return $changed;
    }

    /** Members whose period ended since the cache was written are lapsed now (one query; the phone sees them change). */
    public static function refreshStanding(int $companyId): int
    {
        return DB::table('sacco_members')->where('company_id', $companyId)->where('standing', self::GOOD)
            ->where('membership_ends_on', '<', LocalDate::today($companyId)->toDateString())
            ->update(['standing' => self::LAPSED, 'server_seq' => SyncSequence::next(), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }

    /** A member's standing today (good, lapsed, life or none), whatever the cache says. */
    public static function standingOf(object $member, ?Carbon $today = null): string
    {
        $today ??= LocalDate::today((int) $member->company_id);
        if ($member->standing === self::LIFE) {
            return self::LIFE;
        }
        if ($member->membership_ends_on === null || $member->standing === null) {
            return self::NONE;
        }

        return Carbon::parse((string) $member->membership_ends_on)->startOfDay()->gte($today) ? self::GOOD : self::LAPSED;
    }

    /** Narrows a members query to a standing today: good (life counts as good), lapsed, life or none. */
    public static function whereStanding(Builder|EloquentBuilder $q, string $standing, Carbon $today): Builder|EloquentBuilder
    {
        $d = $today->toDateString();

        return match ($standing) {
            self::GOOD => $q->where(fn ($w) => $w->where('standing', self::LIFE)->orWhere(fn ($g) => $g->whereIn('standing', [self::GOOD, self::LAPSED])->where('membership_ends_on', '>=', $d))),
            self::LAPSED => $q->whereIn('standing', [self::GOOD, self::LAPSED])->where('membership_ends_on', '<', $d),
            self::LIFE => $q->where('standing', self::LIFE),
            self::NONE => $q->whereNull('standing'),
            default => $q,
        };
    }

    /** Members that count (not exited, deceased or rejected), not merged, not deleted. */
    public static function members(int $companyId): Builder
    {
        return DB::table('sacco_members')->where('company_id', $companyId)->whereIn('status', Quotas::MEMBER_STATUSES)->whereNull('merged_into_id')->where('is_deleted', 0);
    }

    /** Members whose membership has ended. */
    public static function lapsed(int $companyId): Builder
    {
        return self::whereStanding(self::members($companyId), self::LAPSED, LocalDate::today($companyId));
    }

    /** Members in good standing whose period ends within [days] days (today included). */
    public static function expiring(int $companyId, int $days = 30): Builder
    {
        $today = LocalDate::today($companyId);

        return self::members($companyId)->whereIn('standing', [self::GOOD, self::LAPSED])
            ->whereBetween('membership_ends_on', [$today->toDateString(), $today->copy()->addDays(max(0, $days))->toDateString()]);
    }

    /** What was paid this calendar year (minor units): paid subscriptions by the day they were paid. */
    public static function collectedThisYear(int $companyId): int
    {
        $today = LocalDate::today($companyId);

        return (int) DB::table('unity_subscriptions')->where('company_id', $companyId)->where('is_deleted', 0)->where('status', 'paid')
            ->whereBetween('paid_on', [$today->copy()->startOfYear()->toDateString(), $today->copy()->endOfYear()->toDateString()])->sum('paid_minor');
    }

    /** @return array{members: int, good_standing: int, lapsed: int, life: int, none: int, expiring_30_days: int, collected_this_year_minor: int} */
    public static function summary(int $companyId): array
    {
        $today = LocalDate::today($companyId);
        $count = fn (string $standing) => self::whereStanding(self::members($companyId), $standing, $today)->count();

        return [
            'members' => self::members($companyId)->count(),
            'good_standing' => $count(self::GOOD),
            'lapsed' => $count(self::LAPSED),
            'life' => $count(self::LIFE),
            'none' => $count(self::NONE),
            'expiring_30_days' => self::expiring($companyId, 30)->count(),
            'collected_this_year_minor' => self::collectedThisYear($companyId),
        ];
    }
}
