<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The video studio's worker (config/promo_videos.php): a bearer token whose SHA-256 matches PROMO_STUDIO_TOKEN_SHA256.
 * No hash set: the studio API is off (503).
 */
class StudioToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $want = strtolower(trim((string) config('promo_videos.token_sha256')));
        if (! preg_match('/^[a-f0-9]{64}$/', $want)) {
            return response()->json(['message' => 'The studio API is off on this server.'], 503);
        }
        // X-Studio-Token as well: some Apache set-ups (MAMP's, hosts without Laravel's Authorization rule) drop the Authorization header.
        $token = (string) ($request->bearerToken() ?: $request->header('X-Studio-Token'));
        if ($token === '' || ! hash_equals($want, hash('sha256', $token))) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
