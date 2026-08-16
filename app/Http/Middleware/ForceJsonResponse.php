<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces the "Accept: application/json" header on all API requests so that
 * Laravel's exception handler, validation, and auth middleware always respond
 * with JSON (never an HTML redirect or whoops page), regardless of what the
 * client sent.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
