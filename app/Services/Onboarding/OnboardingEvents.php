<?php

namespace App\Services\Onboarding;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * First-run events per shop (POWER_PLAN §3.1): signed_up, step_completed, step_skipped,
 * template_applied, import_done, first_product, first_sale (with the time it took), invite_sent,
 * invite_accepted, momo_ready, app_login, converted_to_paid, demo_created…
 *
 * Recording never fails the action it describes: a missing table or a database hiccup is logged
 * and ignored. Demo shops are filled with events muted, so they never skew the numbers.
 */
class OnboardingEvents
{
    public const EVENTS = ['signed_up', 'step_completed', 'step_skipped', 'wizard_skipped', 'template_applied', 'import_done', 'first_product', 'first_sale',
        'invite_sent', 'invite_accepted', 'momo_ready', 'app_login', 'converted_to_paid', 'demo_created', 'demo_opened', 'tour_seen', 'checklist_dismissed'];

    private static bool $muted = false;

    /** Run $fn without writing events (a demo shop being filled). */
    public static function muted(callable $fn): mixed
    {
        $was = self::$muted;
        self::$muted = true;
        try {
            return $fn();
        } finally {
            self::$muted = $was;
        }
    }

    public static function record(int $companyId, string $event, array $meta = [], ?string $channel = null, ?int $userId = null): void
    {
        if (self::$muted || $companyId <= 0) {
            return;
        }
        try {
            DB::table('onboarding_events')->insert([
                'company_id' => $companyId,
                'user_id' => $userId ?? self::currentUserId(),
                'event' => mb_substr($event, 0, 60),
                'channel' => mb_substr($channel ?? self::channel(), 0, 12),
                'meta' => $meta === [] ? null : json_encode($meta),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::info('[onboarding] event not recorded: '.$e->getMessage(), ['company_id' => $companyId, 'event' => $event]);
        }
    }

    /** Record an event only the first time it happens for the shop. Returns true when it was written now. */
    public static function once(int $companyId, string $event, array $meta = [], ?string $channel = null, ?int $userId = null): bool
    {
        if (self::$muted || self::has($companyId, $event)) {
            return false;
        }
        self::record($companyId, $event, $meta, $channel, $userId);

        return true;
    }

    public static function has(int $companyId, string $event): bool
    {
        try {
            return DB::table('onboarding_events')->where('company_id', $companyId)->where('event', $event)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array<int, string> distinct events the shop has */
    public static function seen(int $companyId): array
    {
        try {
            return DB::table('onboarding_events')->where('company_id', $companyId)->distinct()->pluck('event')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Seconds from the shop's sign-up to its first sale (null until it has sold). Uses the sign-up
     * event when there is one, else the company's creation time.
     */
    public static function timeToFirstSale(int $companyId): ?int
    {
        $first = DB::table('sale_records')->where('company_id', $companyId)->min('created_at');
        if ($first === null) {
            return null;
        }
        $start = null;
        try {
            $start = DB::table('onboarding_events')->where('company_id', $companyId)->where('event', 'signed_up')->min('created_at');
        } catch (\Throwable $e) {
            // table not there yet
        }
        $start ??= DB::table('companies')->where('id', $companyId)->value('created_at');
        if ($start === null) {
            return null;
        }

        return max(0, \Illuminate\Support\Carbon::parse($first)->getTimestamp() - \Illuminate\Support\Carbon::parse($start)->getTimestamp());
    }

    /** Where the action came from: the phone app (API), the new web app, the classic admin, or a scheduled job. */
    public static function channel(): string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return 'system';
        }
        $request = request();
        if ($request->is('api/*')) {
            return 'app';
        }

        // The new shop interface loads budget-pro's classes and has its own `budgetpro` config.
        return config('budgetpro.path') !== null ? 'web' : 'classic';
    }

    private static function currentUserId(): ?int
    {
        foreach (['sanctum', 'web', 'admin'] as $guard) {
            try {
                $id = auth()->guard($guard)->id();
            } catch (\Throwable $e) {
                continue;
            }
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }
}
