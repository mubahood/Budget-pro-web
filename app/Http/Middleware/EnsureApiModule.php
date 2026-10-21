<?php

namespace App\Http\Middleware;

use App\Models\AdminMenu;
use App\Models\Company;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api.module:shop`, `api.module:finance`, `api.module:budget`, `api.module:poultry`, `api.module:pingpin`, `api.module:sacco`,
 * or `api.module:core` (roadmap 02 §3, phase-0 plan P0-3). Every authenticated /api/v1 route carries one
 * (tests/Feature/Api/ModuleGatingTest walks the routes), so an endpoint answers only when the account has its
 * module, the same idea as the portal's `module:` middleware and the classic `module_paths`.
 *
 * - Runs after `api.tenant` and reads the company it resolved (the `company` request attribute).
 * - `core` is the explicit "every account" mark: the account itself, team, billing, sync, files, chat…
 * - Several modules (`api.module:shop,finance`) pass when the account has any of them.
 * - Ping Pin is not a module the owner switches on: it is on once the company has a Ping Pin
 *   subscription or a tracked device (AdminMenu::modulesOn). Registering the first device stays core.
 * - Hidden modules keep their data; the refusal says how to add the module back.
 *
 * The refusal keeps the kernel envelope and the portal's words (budget-pro-new EnsureModule):
 *   403 {code: 0, message: "Shop is not on your account.", data: null,
 *        errors: {code: "module_off", module: "shop", add: "PUT /api/v1/company/modules"}}
 */
class EnsureApiModule
{
    /** The explicit "every account" mark. */
    public const CORE = 'core';

    /** Modules a route can belong to (config/onboarding.php `modules`, plus Ping Pin). */
    public const MODULES = ['shop', 'finance', 'budget', 'poultry', 'pingpin', 'sacco'];

    /** Where the owner adds a module back (OnboardingController::modules; manage_settings). */
    public const ADD = 'PUT /api/v1/company/modules';

    /** Ping Pin turns on with the first tracked device (TrackingController::register, core on purpose). */
    public const ADD_PINGPIN = 'POST /api/v1/tracking/devices/register';

    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        if ($modules === []) {
            throw new \LogicException('api.module needs a module: api.module:core, or one of '.implode(', ', self::MODULES).'.');
        }
        foreach ($modules as $module) {
            if ($module !== self::CORE && ! in_array($module, self::MODULES, true)) {
                throw new \LogicException("api.module: unknown module '{$module}'.");
            }
        }
        if (in_array(self::CORE, $modules, true)) {
            return $next($request);
        }

        $company = $request->attributes->get('company');
        if (! $company instanceof Company) {
            // A route marked with a module but outside api.tenant: a wiring mistake, never an open door.
            throw new \LogicException('api.module runs after api.tenant: no company was resolved for this request.');
        }

        $on = in_array('pingpin', $modules, true) ? AdminMenu::modulesOn($company) : $company->modules();
        foreach ($modules as $module) {
            if (in_array($module, $on, true)) {
                return $next($request);
            }
        }

        return self::refuse($modules);
    }

    /** @param  array<int, string>  $modules  the route's modules; the first one is named */
    public static function refuse(array $modules): JsonResponse
    {
        $module = $modules[0];
        $errors = ['code' => 'module_off', 'module' => $module, 'add' => $module === 'pingpin' ? self::ADD_PINGPIN : self::ADD];
        if (count($modules) > 1) {
            $errors['any_of'] = array_values($modules);
        }

        return response()->json(['code' => 0, 'message' => self::label($module).' is not on your account.', 'data' => null, 'errors' => $errors], 403);
    }

    /** What the portal calls the module (budget-pro-new EnsureModule::describe): its app label, else the onboarding label. */
    public static function label(string $module): string
    {
        $app = config("apps.core_modules.{$module}.label");
        if (is_string($app) && $app !== '') {
            return $app;
        }

        return match ($module) {
            'finance' => 'Income & expenses',
            'pingpin' => 'Ping Pin',
            default => Str::before((string) config("onboarding.modules.{$module}", ucfirst($module)), ':'),
        };
    }
}
