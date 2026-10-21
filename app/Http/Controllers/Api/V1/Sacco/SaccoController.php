<?php

namespace App\Http\Controllers\Api\V1\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\Sacco\SaccoAccess;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the Akiba Ledger API controllers share (04 §13): thin, every write through a service, every read narrowed to
 * the caller's SACCO. A refusal from a service answers with its code: `forbidden` → 403, anything not in this SACCO
 * → 404 (the same for another tenant's ids), every other rule → 422. Reads need sacco_view, or the auditor's
 * sacco_audit_view (ApiPermissionMap leaves reads to the controllers). Money is `*_minor` integers with `currency`.
 */
abstract class SaccoController extends Controller
{
    use ApiResponse;

    /** Refusal codes that mean "not in this SACCO". */
    public const NOT_FOUND = ['member_not_found', 'account_not_found', 'product_not_found', 'transaction_not_found', 'approval_not_found', 'entry_not_found',
        'period_not_found', 'officer_not_found', 'not_found', 'relation_not_found', 'unknown_report', 'loan_not_found', 'payout_not_found', 'import_not_found', 'line_not_found'];

    protected function company(Request $request): Company
    {
        $company = $request->attributes->get('company');

        return $company instanceof Company ? $company : Company::withoutGlobalScopes()->findOrFail((int) $request->user()->company_id);
    }

    protected function user(Request $request): User
    {
        return $request->user();
    }

    protected function cid(Request $request): int
    {
        return (int) $this->company($request)->id;
    }

    /** Reads: sacco_view or sacco_audit_view. */
    protected function mayRead(Request $request): bool
    {
        return SaccoAccess::can($request->user(), 'sacco_view') || SaccoAccess::can($request->user(), 'sacco_audit_view');
    }

    protected function refuseRead(): JsonResponse
    {
        return $this->error('Your role does not allow this. Ask the SACCO\'s manager.', 403, ['code' => 'forbidden', 'permission' => 'sacco_view']);
    }

    /** Run a service call and answer its refusal in the API's words. @param callable(): JsonResponse $fn */
    protected function attempt(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (BusinessRuleException $e) {
            $status = $e->errorCode() === 'forbidden' ? 403 : (in_array($e->errorCode(), self::NOT_FOUND, true) ? 404 : 422);

            return $this->error($e->getMessage(), $status, $e->toErrors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('Not found.');
        }
    }

    /** @param array<int, mixed> $items */
    protected function page(\Illuminate\Contracts\Pagination\LengthAwarePaginator $p, array $items): JsonResponse
    {
        return $this->success($items, 'Listed successfully.', 200, ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(),
            'last_page' => $p->lastPage(), 'from' => $p->firstItem(), 'to' => $p->lastItem(), 'has_more' => $p->hasMorePages()]);
    }

    protected function perPage(Request $request): int
    {
        return max(1, min(200, (int) $request->query('per_page', '50')));
    }
}
