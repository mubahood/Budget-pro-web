<?php

namespace App\Http\Middleware;

use App\Services\Team\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `perm:sell` — 403 with errors.code=forbidden when the member's role lacks the permission (plan C5). */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        foreach ($permissions as $p) {
            if (Permissions::can($user, $p)) {
                return $next($request);
            }
        }

        return response()->json(['code' => 0, 'message' => 'Your role does not allow this. Ask the shop owner.', 'data' => null,
            'errors' => ['code' => 'forbidden', 'permission' => implode('|', $permissions)]], 403);
    }
}
