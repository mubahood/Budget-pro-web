<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns a lat/lng into a short, human-readable place name (e.g. "Bugolobi,
 * Kampala") via OpenStreetMap's Nominatim — free, no API key, same choice
 * already made for the admin map tiles to avoid a Google billing setup.
 *
 * Nominatim's usage policy caps public shared-server use at ~1 request/sec
 * and requires an identifying User-Agent, so every lookup is cache-first
 * (rounded to a ~11m grid cell — devices revisit the same places constantly)
 * and real HTTP calls are serialised process-wide via an atomic cache lock.
 */
class GeocodingService
{
    private const MIN_SECONDS_BETWEEN_CALLS = 1.1;

    private const LAST_CALL_CACHE_KEY = 'geocoding:last_call_at';

    private const USER_AGENT = 'FindMyPhone-BudgetPro/1.0 (mubahood360@gmail.com)';

    /** Cache-only lookup — never makes a network call. Used for cheap re-checks. */
    public function cached(float $lat, float $lng): ?string
    {
        [$latR, $lngR] = $this->round($lat, $lng);

        return DB::table('geocode_cache')
            ->where('lat_rounded', $latR)->where('lng_rounded', $lngR)
            ->value('place_name');
    }

    /** Cache-first resolve; falls through to a live Nominatim call on a miss. */
    public function resolve(float $lat, float $lng): ?string
    {
        $cached = $this->cached($lat, $lng);
        if ($cached !== null) {
            return $cached;
        }

        return $this->resolveLive($lat, $lng);
    }

    /** Always hits Nominatim (after cache miss + rate-limit wait), caching the result. */
    public function resolveLive(float $lat, float $lng): ?string
    {
        [$latR, $lngR] = $this->round($lat, $lng);

        try {
            // Lock TTL (10s) is deliberately longer than the worst case of a
            // 1.1s rate-limit wait + 4s HTTP timeout, so it can never
            // auto-expire out from under a call that's still in flight.
            $name = Cache::lock('geocoding:nominatim-call', 10)->block(6, function () use ($lat, $lng) {
                $this->waitForRateLimit();

                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout(4)
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'format' => 'json',
                        'lat' => $lat,
                        'lon' => $lng,
                        'zoom' => 16,
                        'addressdetails' => 1,
                    ]);

                Cache::put(self::LAST_CALL_CACHE_KEY, microtime(true), 60);

                return $response->successful() ? $this->formatName($response->json()) : null;
            });
        } catch (\Throwable $e) {
            // Covers the LockTimeoutException (another request is mid-call
            // and this one waited 6s without getting a turn) as well as any
            // HTTP/network failure — geocoding is a nice-to-have and must
            // never block or fail the actual location push.
            Log::warning('GeocodingService: reverse geocode failed', [
                'lat' => $lat, 'lng' => $lng, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($name !== null) {
            DB::table('geocode_cache')->updateOrInsert(
                ['lat_rounded' => $latR, 'lng_rounded' => $lngR],
                ['place_name' => $name, 'created_at' => now()]
            );
        }

        return $name;
    }

    /** Blocks (briefly) until at least MIN_SECONDS_BETWEEN_CALLS has passed since the last real call. */
    private function waitForRateLimit(): void
    {
        $lastCall = Cache::get(self::LAST_CALL_CACHE_KEY);
        if (! $lastCall) {
            return;
        }

        $elapsed = microtime(true) - $lastCall;
        $remaining = self::MIN_SECONDS_BETWEEN_CALLS - $elapsed;
        if ($remaining > 0) {
            usleep((int) ($remaining * 1_000_000));
        }
    }

    /**
     * Prefers a short, locally-meaningful label over Nominatim's full
     * "display_name" (which is often a whole verbose postal address) —
     * road/suburb first, then the town, then a state/country fallback.
     */
    private function formatName(?array $data): ?string
    {
        $address = $data['address'] ?? null;
        if (! is_array($address)) {
            return $data['display_name'] ?? null;
        }

        $place = $address['road']
            ?? $address['neighbourhood']
            ?? $address['suburb']
            ?? $address['residential']
            ?? null;

        $locality = $address['city']
            ?? $address['town']
            ?? $address['village']
            ?? $address['county']
            ?? null;

        $region = $address['state'] ?? $address['country'] ?? null;

        $parts = array_values(array_unique(array_filter([$place, $locality, $region])));

        if (empty($parts)) {
            return $data['display_name'] ?? null;
        }

        return implode(', ', array_slice($parts, 0, 3));
    }

    private function round(float $lat, float $lng): array
    {
        return [round($lat, 4), round($lng, 4)];
    }
}
