<?php

namespace App\Services\Billing;

use App\Models\Company;
use App\Support\Edition;

/**
 * The plan badge and its nudge: one definition for the web portal's topbar chip (budget-pro-new
 * partials/plan-chip) and the phone's meta (GET /api/v1/meta → plan). Only when there is something to
 * know: a trial, a paid plan ending within 7 days (14 when cancelled), a lapsed plan, or the Free plan.
 * SaaS only: a standalone copy has a licence, so every method answers null there.
 */
final class PlanBadge
{
    /** kind => web chip icon */
    public const ICONS = ['suspended' => 'fa-ban', 'read_only' => 'fa-lock', 'expired' => 'fa-lock', 'trial' => 'fa-hourglass-half',
        'free' => 'fa-arrow-up', 'ending' => 'fa-calendar-xmark', 'renew' => 'fa-rotate'];

    /** Whole days until the plan's end (rounded up), 0 once it has passed, null when it has no end. */
    public static function daysLeft(Company $company): ?int
    {
        $end = $company->accessEndedAt();
        if ($end === null) {
            return null;
        }
        $seconds = $end->getTimestamp() - now()->getTimestamp();

        return $seconds > 0 ? (int) ceil($seconds / 86400) : 0;
    }

    /** @return array{kind: string, label: string, tone: string, days_left: ?int}|null the chip, or null when there is nothing to say */
    public static function chip(?Company $company): ?array
    {
        if ($company === null || ! Edition::isSaas()) {
            return null;
        }
        $state = $company->accessState();
        $sub = $company->subscription;
        $days = self::daysLeft($company);
        $d = (int) $days;
        $n = fn (int $x) => $x.' '.($x === 1 ? 'day' : 'days');
        [$kind, $label, $tone] = match (true) {
            $state === 'inactive' => ['suspended', 'Suspended', 'bad'],
            $state === 'grace' => ['read_only', 'Ended — read-only', 'bad'],
            $state === 'expired' => ['expired', 'Expired', 'bad'],
            $sub?->status === 'trialing' => ['trial', 'Trial: '.$n($d).' left', $d <= 3 ? 'warn' : 'neutral'],
            (bool) $sub?->plan?->isFree() => ['free', 'Free plan', 'neutral'],
            $sub?->status === 'canceled' && $d <= 14 => ['ending', 'Ends in '.$n($d), 'warn'],
            $days !== null && $d <= 7 => ['renew', ($sub?->auto_renew ? 'Renews' : 'Renew').' in '.$n($d), 'warn'],
            default => [null, null, null],
        };

        return $kind === null ? null : ['kind' => $kind, 'label' => $label, 'tone' => $tone, 'days_left' => $days];
    }

    /**
     * The phone's plan block: name, state, days left, the badge with its tone, a sentence to show (nudge)
     * and whether it is urgent (red, or 3 days or fewer and not renewing by itself).
     *
     * @return array{name: ?string, slug: ?string, status: ?string, state: string, ends_at: ?string, days_left: ?int, badge: ?string, badge_kind: ?string, tone: string, nudge: ?string, urgent: bool}|null
     */
    public static function forApp(?Company $company): ?array
    {
        if ($company === null || ! Edition::isSaas()) {
            return null;
        }
        $sub = $company->subscription;
        $chip = self::chip($company);
        $days = self::daysLeft($company);
        $d = (int) $days;
        $when = $d <= 0 ? 'today' : 'in '.$d.' '.($d === 1 ? 'day' : 'days');
        $grace = (int) config('saas.grace_days', 7);
        $graceLeft = max(0, $grace - (int) floor(max(0, now()->getTimestamp() - (int) $company->accessEndedAt()?->getTimestamp()) / 86400));
        $nudge = match ($chip['kind'] ?? null) {
            'suspended' => 'This shop is switched off. Contact Budget Pro support to switch it back on.',
            'read_only' => 'Your plan has ended and the shop is read-only on the web. Renew within '.$graceLeft.' '.($graceLeft === 1 ? 'day' : 'days').' to keep phones syncing.',
            'expired' => 'Your plan has expired. Renew to start recording sales again. Sales made on phones are kept until then.',
            'trial' => 'Your free trial ends '.$when.'. Choose a plan to keep selling without a break.',
            'free' => 'You are on the Free plan. Upgrade for more products, team members and features.',
            'ending' => 'Your plan was cancelled and ends '.$when.'.',
            'renew' => $sub?->auto_renew ? 'Your plan renews '.$when.'.' : 'Your plan ends '.$when.'. Renew to keep selling without a break.',
            default => null,
        };
        $urgent = $chip !== null && ($chip['tone'] === 'bad'
            || (in_array($chip['kind'], ['trial', 'ending', 'renew'], true) && $d <= 3 && ! ($chip['kind'] === 'renew' && $sub?->auto_renew)));

        return [
            'name' => $sub?->plan?->name, 'slug' => $sub?->plan?->slug, 'status' => $sub?->status, 'state' => $company->accessState(),
            'ends_at' => $company->accessEndedAt()?->toIso8601String(), 'days_left' => $days,
            'badge' => $chip['label'] ?? null, 'badge_kind' => $chip['kind'] ?? null, 'tone' => $chip['tone'] ?? 'neutral',
            'nudge' => $nudge, 'urgent' => $urgent,
        ];
    }
}
