<?php

namespace App\Http\Controllers\Api\V1\Unity;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UnityDesk (the unity module), API v1: routes/api_unity.php. Who may write is ApiPermissionMap's (unity_members for members,
 * unity_finance for categories and subscriptions, unity_chapters for chapters); officers with unity_view read, and a member
 * reads only their own record (unity/me). Every query names the request's company as well as CompanyScope.
 */
abstract class UnityController extends Controller
{
    use ApiResponse;

    private const NOT_FOUND = ['not_found'];

    private const CONFLICT = ['category_in_use', 'chapter_in_use', 'cancelled_final', 'not_open', 'already_voted', 'insufficient_fund', 'not_eligible',
        'committee_in_use', 'not_draft', 'closed'];

    protected function company(Request $request): Company
    {
        $company = $request->attributes->get('company');

        return $company instanceof Company ? $company : Company::withoutGlobalScopes()->findOrFail((int) $request->user()->company_id);
    }

    protected function cid(Request $request): int
    {
        return (int) $this->company($request)->id;
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
                $code === 'feature_not_in_plan' => 402,
                default => 422,
            };

            return $this->error($e->getMessage(), $status, $e->toErrors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('That record was not found.', 404, ['code' => 'not_found']);
        } catch (\Carbon\Exceptions\InvalidFormatException) {
            return $this->error('Give dates like 2026-10-31.', 422, ['code' => 'date_invalid']);
        }
    }

    /** The signed-in person holds an officer's permission (unity_members, unity_finance...). */
    protected function can(Request $request, string $permission): bool
    {
        return \App\Services\Team\Permissions::can($request->user(), $permission);
    }

    /** The signed-in person's own member record, or a 403 when they have none. */
    protected function ownMember(Request $request): \App\Models\SaccoMember
    {
        $m = \App\Services\Unity\UnityAccess::member($this->cid($request), $request->user());
        if ($m === null) {
            throw BusinessRuleException::make('forbidden', 'No member record has your email or phone yet. Ask the secretary to add it.');
        }

        return $m;
    }

    /**
     * The member an action is for: the one named when the person holds [permission], else their own record (a member asking
     * for someone else is refused).
     */
    protected function memberFor(Request $request, ?int $memberId, string $permission): \App\Models\SaccoMember
    {
        if ($this->can($request, $permission)) {
            return $memberId ? \App\Services\Unity\UnityMembers::find($this->cid($request), $memberId) : $this->ownMember($request);
        }
        $own = $this->ownMember($request);
        if ($memberId && $memberId !== (int) $own->id) {
            throw BusinessRuleException::make('forbidden', 'You can do this only for yourself.');
        }

        return $own;
    }

    /** @param array<string, mixed> $data */
    protected function withAccess(Request $request, mixed $data, string $message = 'OK'): JsonResponse
    {
        return $this->success($data, $message, 200, ['access' => \App\Services\Unity\UnityAccess::for($this->company($request), $request->user())]);
    }

    /** A list of rows already shaped, paged in PHP or SQL. */
    protected function pageOf(\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $q, Request $request, callable $shape, int $default = 50): JsonResponse
    {
        $p = $q->paginate($this->perPage($request, $default), ['*'], 'page', $this->pageNo($request));

        return $this->page($p, $shape(collect($p->items())));
    }

    protected function perPage(Request $request, int $default = 50): int
    {
        return max(1, min(200, (int) $request->query('per_page', (string) $default)));
    }

    protected function pageNo(Request $request): int
    {
        return max(1, (int) $request->query('page', '1'));
    }

    /** @param array<int, mixed> $items */
    protected function page(LengthAwarePaginator $p, array $items): JsonResponse
    {
        return $this->success($items, 'Listed successfully.', 200, ['pagination' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(),
            'total' => $p->total(), 'last_page' => $p->lastPage(), 'has_more' => $p->hasMorePages()]]);
    }
}
