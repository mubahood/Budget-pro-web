<?php

namespace App\Services\Support;

use App\Support\SupportContact;
use Illuminate\Support\Facades\DB;

/**
 * Counts taps on "WhatsApp us" (docs/roadmap/06 §4): the web app's /go/whatsapp records one row per
 * tap, then redirects to wa.me. What a row holds is decided here, so no caller can add more: the
 * message's context, where the link was, the page's path, and the signed-in shop. Never an IP
 * address, a name or a phone number. A failed write never stands between a person and help.
 */
class SupportClicks
{
    public function record(string $context, string $app, ?string $page = null, ?int $companyId = null): bool
    {
        try {
            DB::table('support_clicks')->insert([
                'context' => mb_substr(array_key_exists($context, SupportContact::templates()) ? $context : SupportContact::FALLBACK, 0, 64),
                'app' => in_array($app, SupportContact::APPS, true) ? $app : 'site',
                'page' => $page === null || $page === '' ? null : SupportContact::pagePath($page),
                'company_id' => $companyId !== null && $companyId > 0 ? $companyId : null,
                'created_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Taps per context (and per app) since a moment, most first: for the platform screens.
     *
     * @return list<array{context: string, app: string, clicks: int}>
     */
    public function summary(\DateTimeInterface $since): array
    {
        return DB::table('support_clicks')->where('created_at', '>=', $since)
            ->groupBy('context', 'app')->selectRaw('context, app, COUNT(*) AS clicks')->orderByDesc('clicks')->get()
            ->map(fn ($r) => ['context' => (string) $r->context, 'app' => (string) $r->app, 'clicks' => (int) $r->clicks])->all();
    }
}
