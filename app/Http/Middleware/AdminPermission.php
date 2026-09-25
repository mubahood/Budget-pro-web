<?php

namespace App\Http\Middleware;

use App\Services\Team\Permissions;
use App\Support\AdminAccess;
use Closure;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web admin side of the permission matrix (plan C5): hiding a menu is not
 * enough, so each shop section's URLs need the same permission as its menu.
 * Sections open to everyone (products list) still need the edit permission to write.
 */
class AdminPermission
{
    /** Sections everyone may read but only these permissions may change. */
    public const WRITE_ONLY = ['stock-items' => 'manage_products'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Admin::user();
        if (! $user instanceof \App\Models\User || PlatformAdminOnly::isPlatformAdmin($user)) {
            return $next($request);
        }
        $section = explode('/', trim($request->path(), '/'))[0] ?? '';
        $company = \App\Models\Company::withoutGlobalScopes()->find($user->company_id);
        if ($company && $section !== '' && \App\Models\AdminMenu::pathDisabled($section, \App\Models\AdminMenu::disabledPaths($company))) {
            admin_warning('Module switched off', 'Turn it on under Company settings → Modules.');

            return redirect(admin_url('/'));
        }
        $writing = ! in_array($request->method(), ['GET', 'HEAD'], true) || preg_match('#/(create|edit)$#', $request->path());
        $need = AdminAccess::MENU_PERMISSIONS[$section] ?? null;
        if ($need === null && $writing) {
            $need = self::WRITE_ONLY[$section] ?? null;
        }
        if ($need !== null && ! Permissions::can($user, $need)) {
            abort(403, 'Your role does not allow this. Ask the shop owner.');
        }

        return $next($request);
    }
}
