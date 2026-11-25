<?php

namespace App\Http\Controllers\Api\V1\Kin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Team\Permissions;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * My Family Roots 360 (the kin module), API v1: routes/api_kin.php. Who may write is ApiPermissionMap's (kin_people for the
 * people, links and marriages; kin_branches for the branches); everyone in the family reads. Every query names the
 * request's company as well as CompanyScope.
 */
abstract class KinController extends Controller
{
    use ApiResponse;

    /** Refusals that mean "no such record" and "already there". */
    private const NOT_FOUND = ['not_found'];

    private const CONFLICT = ['link_exists', 'spouse_exists'];

    protected function company(Request $request): Company
    {
        $company = $request->attributes->get('company');

        return $company instanceof Company ? $company : Company::withoutGlobalScopes()->findOrFail((int) $request->user()->company_id);
    }

    protected function cid(Request $request): int
    {
        return (int) $this->company($request)->id;
    }

    /** May this person change the family's people (an officer or the head), or only read? */
    protected function officer(Request $request): bool
    {
        return Permissions::can($request->user(), 'kin_people');
    }

    /** @param callable(): JsonResponse $fn */
    protected function attempt(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (BusinessRuleException $e) {
            $code = $e->errorCode();
            $status = match (true) {
                in_array($code, self::NOT_FOUND, true) => 404,
                in_array($code, self::CONFLICT, true) => 409,
                $code === 'forbidden' => 403,
                default => 422,
            };

            return $this->error($e->getMessage(), $status, $e->toErrors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('That record was not found.', 404, ['code' => 'not_found']);
        } catch (\Carbon\Exceptions\InvalidFormatException) {
            return $this->error('Give dates like 2026-10-31.', 422, ['code' => 'date_invalid']);
        }
    }

    protected function perPage(Request $request, int $default = 50): int
    {
        return max(1, min(200, (int) $request->query('per_page', (string) $default)));
    }

    /** @param array<int, mixed> $items */
    protected function page(LengthAwarePaginator $p, array $items): JsonResponse
    {
        return $this->success($items, 'Listed successfully.', 200, ['pagination' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(),
            'total' => $p->total(), 'last_page' => $p->lastPage(), 'has_more' => $p->hasMorePages()]]);
    }
}
