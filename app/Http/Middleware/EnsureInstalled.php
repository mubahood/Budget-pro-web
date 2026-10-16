<?php

namespace App\Http\Middleware;

use App\Support\Edition;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First in the `web` and `api` groups of both apps (docs/STANDALONE_EDITION_PLAN.md §5.5).
 *
 *  - The installer writes a marker (config edition.installed_path) saying which edition it installed.
 *  - SaaS with no marker: normal (our servers were never "installed").
 *  - Standalone with no marker: the copy is not set up yet. Pages go to /install.php; the API answers 503.
 *  - A marker whose edition differs from APP_EDITION: 503. A standalone database opened as SaaS would
 *    expose the platform screens, and a SaaS database opened as standalone would hide every shop but one.
 *
 * Usage: `EnsureInstalled::class` (web) and `EnsureInstalled::class.':api'` (JSON answers).
 */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next, string $channel = 'web'): Response
    {
        $marker = self::marker();
        $api = $channel === 'api' || $request->is('api/*') || $request->expectsJson();

        if ($marker === null) {
            if (Edition::isSaas()) {
                return $next($request);
            }
            if ($api) {
                return response()->json(['code' => 0, 'error' => 'not_installed', 'message' => 'This copy of Budget Pro is not installed yet.', 'data' => null], 503);
            }

            // install.php sits beside the front controller. A plain response: no redirector (Livewire swaps it in the web app).
            return new RedirectResponse($request->getSchemeAndHttpHost().$request->getBasePath().'/install.php');
        }

        if (($marker['edition'] ?? null) !== Edition::name()) {
            $message = 'This installation was set up as a different edition than APP_EDITION says. Put APP_EDITION back as it was installed.';

            return $api
                ? response()->json(['code' => 0, 'error' => 'edition_mismatch', 'message' => $message, 'data' => null], 503)
                : response($message, 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return $next($request);
    }

    /**
     * The installed marker: null when there is none, [] when it cannot be read (treated as a mismatch).
     *
     * @return array<string, mixed>|null
     */
    public static function marker(): ?array
    {
        $path = (string) config('edition.installed_path', '');
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }
}
