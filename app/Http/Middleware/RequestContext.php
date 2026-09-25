<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Structured logs (plan Part D): every log line of a request carries request_id, company_id, device_id; the id is echoed as X-Request-Id. */
class RequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string) $request->header('X-Request-Id')) ?: (string) Str::uuid(), 0, 64);
        $request->attributes->set('request_id', $id);
        Log::withContext(['request_id' => $id, 'device_id' => $request->header('X-Device-Id'), 'path' => $request->path()]);
        $response = $next($request);
        if ($user = $request->user()) {
            Log::withContext(['company_id' => $user->company_id, 'user_id' => $user->id]);
        }
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
