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
    /** [methods, uri regex (after api/v1/), permission] — first match wins. A null permission: checked in the controller. */
    public const RULES = [
        // A member may set their own till PIN; someone else's needs manage_team (TeamController::setMemberPin).
        [['PUT'], '#^team/members/\d+/pin$#', null],
        [['POST'], '#^company/logo$#', 'manage_settings'],
        // Supermarket / phone parity (reads needing "any of" several permissions are checked in the controllers).
        [['PUT'], '#^store-features$#', 'manage_settings'],
        [['POST'], '#^company/currency(/preview)?$#', 'manage_settings'],
        [['POST'], '#^debts(/[^/]+)?/(receive|adopt)$#', 'sell'],
        [['POST'], '#^gift-cards$#', 'sell'],
        [['POST', 'DELETE'], '#^held-carts(/\d+(/take)?)?$#', 'sell'],
        [['POST'], '#^batches/\d+/(markdown|write-off)$#', 'adjust'],
        [['PUT'], '#^tax-classes$#', 'manage_settings'],
        [['POST'], '#^cash-movements$#', 'sell'],
        [['POST'], '#^sales/\d+/void$#', 'void'],
        [['POST'], '#^sales/\d+/returns$#', 'refund'],
        [['POST'], '#^sales/[0-9A-Fa-f-]+/(send-receipt|momo-request)$#', 'sell'],
        [['POST'], '#^customers/[0-9A-Fa-f-]+/remind$#', 'sell'],
        [['PUT'], '#^company/(engagement|momo)$#', 'manage_settings'],
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
        [['POST'], '#^stock-transfers(/\d+/receive)?$#', 'adjust'],
        [['POST'], '#^stock-requests(/\d+/(approve|send|cancel))?$#', 'adjust'],
        [['GET', 'POST'], '#^duplicates(/merge)?$#', 'manage_products'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^stock-takes(/\d+(/counts|/recount|/post|/cancel)?)?$#', 'stock_take'],
        [['POST'], '#^stock-records/\d+/reverse$#', 'adjust'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^(stock-items|stock-categories|stock-sub-categories|units|product-barcodes)(/\d+)?$#', 'manage_products'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^(financial-records|financial-categories|financial-periods)(/\d+)?$#', 'manage_finance'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^financial-reports(/\d+)?$#', 'view_reports'],
        // Pledges, pledge payments and committees: per event (chair, treasurer, secretary…), checked in the
        // controllers by App\Services\Budget\BudgetAccess; the owner and manage_budget keep full access.
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^contribution-records(/\d+(/payments)?)?$#', null],
        [['POST'], '#^contribution-payments/\d+/reverse$#', null],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^budget-programs/\d+/members(/\d+)?$#', null],
        // An event's settings (chair; payment details also the treasurer) and applying a budget template (chair): BudgetAccess.
        [['POST', 'PUT', 'PATCH'], '#^budget-programs/\d+/(settings|apply-template)$#', null],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^(budget-programs|budget-item-categories|budget-items)(/\d+)?$#', 'manage_budget'],
        // Akiba Ledger P1 (docs/akiba/04-architecture.md §13.2). Reads of the books need sacco_view (or the auditor's
        // sacco_audit_view, checked in the controllers: null here); finer rules (maker ≠ checker, positions) are SaccoAccess.
        [['POST'], '#^sacco/setup$#', 'manage_settings'],
        [['PUT', 'PATCH'], '#^sacco/settings$#', 'sacco_settings'],
        [['POST', 'PUT', 'PATCH'], '#^sacco/officers(/\d+(/end)?)?$#', 'sacco_officers'],
        [['POST'], '#^sacco/members/\d+/kyc$#', 'sacco_kyc'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^sacco/members(/import|/\d+(/status|/relations(/\d+)?|/documents)?)?$#', 'sacco_members'],
        [['POST'], '#^sacco/opening-balances/import$#', 'sacco_accounts'],
        [['POST'], '#^sacco/shares/purchase$#', 'sacco_teller'],
        [['POST'], '#^sacco/shares/transfer$#', 'sacco_teller'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], '#^sacco/savings-products(/\d+)?$#', 'sacco_settings'],
        [['POST'], '#^sacco/savings-accounts/\d+/(freeze|unfreeze|close)$#', 'sacco_approve'],
        [['POST'], '#^sacco/savings-accounts$#', 'sacco_teller'],
        [['POST'], '#^sacco/transactions/\d+/reverse$#', 'sacco_teller'],
        [['POST'], '#^sacco/transactions$#', 'sacco_teller'],
        [['POST'], '#^sacco/withdrawals$#', 'sacco_withdraw'],
        [['POST'], '#^sacco/approvals/\d+/cancel$#', null], // the maker cancels their own request (SaccoApprovals)
        [['POST'], '#^sacco/approvals/\d+/(approve|reject)$#', 'sacco_approve'],
        [['POST', 'PUT', 'PATCH'], '#^sacco/ledger/(accounts|journal)(/\d+(/reverse)?)?$#', 'sacco_accounts'],
        [['POST'], '#^sacco/periods/\d+/(close|reopen)$#', 'sacco_accounts'],
        [['GET'], '#^sacco/(reports|members/\d+/statement)(/.*)?$#', null], // sacco_reports or the auditor (controller)
        [['GET'], '#^sacco/(audit|ledger/verify)$#', 'sacco_audit_view'],
        // Akiba Ledger P2 loans (04 §13.2): the services check the same permissions again, with positions and maker ≠ checker.
        [['POST'], '#^sacco/loan-products/\d+/preview$#', null], // a read with a body (sacco_view, controller)
        [['POST', 'PUT', 'PATCH'], '#^sacco/loan-products(/\d+)?$#', 'sacco_settings'],
        [['POST'], '#^sacco/loans/\d+/(votes|recuse)$#', 'sacco_loans_vote'],
        [['POST'], '#^sacco/loans/\d+/decide$#', 'sacco_approve'],
        [['POST'], '#^sacco/loans/\d+/disburse$#', 'sacco_disburse'],
        [['POST'], '#^sacco/loans/\d+/(repayments|recover)$#', 'sacco_teller'],
        [['POST'], '#^sacco/loans/\d+/cancel$#', null], // withdrawing (sacco_loans) or a cooling-off cancellation (sacco_teller): the service
        [['POST', 'PUT', 'PATCH'], '#^sacco/loans(/\d+(/[a-z-]+(/\d+/[a-z]+)?)?)?$#', 'sacco_loans'],
        [['POST'], '#^sacco/members/\d+/exit$#', 'sacco_members'],
        [['GET'], '#^sacco(/.*)?$#', null], // every other read: sacco_view or sacco_audit_view (controller)
        [['POST'], '#^sync/conflicts/\d+/resolve$#', 'resolve_conflicts'],
        [['POST'], '#^subscription/(checkout|verify|cancel|resume|momo|schedule-change|cancel-change)$#', 'billing'],
        [['GET'], '#^subscription/payments/\d+$#', 'billing'],
        [['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '#^team(/.*)?$#', 'manage_team'],
        [['PUT', 'PATCH'], '#^company(/settings|/modules)?$#', 'manage_settings'],
        [['GET', 'POST'], '#^company/(data-requests|export|exports/\d+/download|delete|delete/cancel)$#', 'manage_settings'],
        [['POST', 'PUT'], '#^onboarding/(templates/apply|import)$#', 'manage_products'],
        [['POST', 'PUT'], '#^onboarding/(business|money|steps/[a-z_]+|skip|checklist/dismiss)$#', 'manage_settings'],
        [['POST', 'DELETE'], '#^devices/\d+/revoke$#', 'manage_team'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $uri = preg_replace('#^api/v1/#', '', $request->path());
        foreach (self::RULES as [$methods, $pattern, $permission]) {
            if (in_array($request->method(), $methods, true) && preg_match($pattern, $uri)) {
                if ($permission !== null && ! Permissions::can($request->user(), $permission)) {
                    return response()->json(['code' => 0, 'message' => 'Your role does not allow this. Ask the shop owner.', 'data' => null,
                        'errors' => ['code' => 'forbidden', 'permission' => $permission]], 403);
                }
                break;
            }
        }

        return $next($request);
    }
}
