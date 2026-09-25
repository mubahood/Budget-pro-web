<?php

namespace App\Http\Middleware;

use App\Services\Team\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One auditable table of which API calls need which permission (plan C5).
 * Reads are open to every member; writes are checked here. Finer rules that
 * depend on the payload (discounts, movement direction) live in the controllers.
 */
class ApiPermissionMap
{
    /** [methods, uri regex (after api/v1/), permission] — first match wins. */
    public const RULES = [
        [['POST'], '#^sales/\d+/void$#', 'void'],
        [['POST'], '#^sales/\d+/returns$#', 'refund'],
        [['POST', 'PUT', 'PATCH'], '#^sales(/checkout|/\d+(/payments)?)?$#', 'sell'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^customers(/\d+(/payments)?)?$#', 'sell'],
        [['POST'], '#^shifts(/open|/\d+/close)?$#', 'sell'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^suppliers(/\d+(/payments)?)?$#', 'restock'],
        [['POST'], '#^goods-receipts$#', 'restock'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^purchase-orders(/\d+(/send|/receive|/cancel)?)?$#', 'restock'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^purchase-returns(/\d+)?$#', 'restock'],
        [['GET', 'POST'], '#^reorder-suggestions(/orders)?$#', 'restock'],
        [['POST', 'PUT'], '#^locations(/\d+)?$#', 'manage_settings'],
        [['PUT'], '#^devices/\d+/location$#', 'manage_settings'],
        [['POST'], '#^stock-transfers$#', 'adjust'],
        [['GET', 'POST'], '#^duplicates(/merge)?$#', 'manage_products'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^stock-takes(/\d+(/counts|/post)?)?$#', 'stock_take'],
        [['POST'], '#^stock-records/\d+/reverse$#', 'adjust'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^(stock-items|stock-categories|stock-sub-categories|units|product-barcodes)(/\d+)?$#', 'manage_products'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^(financial-records|financial-categories|financial-periods)(/\d+)?$#', 'manage_finance'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^financial-reports(/\d+)?$#', 'view_reports'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^(budget-programs|budget-item-categories|budget-items|contribution-records)(/\d+)?$#', 'manage_budget'],
        [['POST'], '#^sync/conflicts/\d+/resolve$#', 'resolve_conflicts'],
        [['POST'], '#^subscription/(checkout|verify|cancel|resume)$#', 'billing'],
        [['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '#^team(/.*)?$#', 'manage_team'],
        [['PUT', 'PATCH'], '#^company(/settings|/modules)?$#', 'manage_settings'],
        [['GET', 'POST'], '#^company/(data-requests|export|exports/\d+/download|delete|delete/cancel)$#', 'manage_settings'],
        [['POST', 'PUT'], '#^onboarding/(templates/apply|import)$#', 'manage_products'],
        [['POST', 'PUT'], '#^onboarding/(business|money|steps/[a-z_]+|checklist/dismiss)$#', 'manage_settings'],
        [['POST', 'DELETE'], '#^devices/\d+/revoke$#', 'manage_team'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $uri = preg_replace('#^api/v1/#', '', $request->path());
        foreach (self::RULES as [$methods, $pattern, $permission]) {
            if (in_array($request->method(), $methods, true) && preg_match($pattern, $uri)) {
                if (! Permissions::can($request->user(), $permission)) {
                    return response()->json(['code' => 0, 'message' => 'Your role does not allow this. Ask the shop owner.', 'data' => null,
                        'errors' => ['code' => 'forbidden', 'permission' => $permission]], 403);
                }
                break;
            }
        }

        return $next($request);
    }
}
