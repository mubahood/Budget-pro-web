<?php

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\RentalLease;
use App\Models\User;
use App\Services\Rentals\RentalAccess;
use App\Support\Rentals\Minor;
use App\Traits\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * What the Estate Ledger API controllers share (docs/estate-ledger/04-architecture.md §9): thin, every write through a service,
 * every read narrowed to the caller's account (CompanyScope and an explicit company_id) and, for a caretaker, to
 * their properties (RentalAccess::scope). A refusal answers with its code: `forbidden` 403; `not_found` (anything not
 * in this account or outside a caretaker's properties) 404, never 403, so ids cannot be probed; a uuid already used
 * for something else 409 `idempotency_conflict`; every other rule 422. Lists take ?q, ?page and ?per_page (at most
 * 100) and answer meta.pagination. Money is `*_minor` integers: a decimal is refused, never rounded.
 */
abstract class RentalsController extends Controller
{
    use ApiResponse;

    public const NOT_FOUND = ['not_found', 'unknown_report'];

    public const CONFLICT = ['idempotency_conflict'];

    public const MAX_PER_PAGE = 100;

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

    protected function present(Request $request): Present
    {
        $p = $request->attributes->get('rentals_present');
        if (! $p instanceof Present) {
            $p = new Present($this->cid($request), 'staff', (int) $request->user()->id);
            $request->attributes->set('rentals_present', $p);
        }

        return $p;
    }

    /** Run a service call and answer its refusal in the API's words. @param callable(): JsonResponse $fn */
    protected function attempt(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (BusinessRuleException $e) {
            return $this->refusal($e);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->error('That record was not found.', 404, ['code' => 'not_found']);
        } catch (\Carbon\Exceptions\InvalidFormatException) {
            return $this->error('Give dates like 2026-10-31.', 422, ['code' => 'date_invalid']);
        } catch (\ErrorException|\TypeError $e) {
            if (! self::listWhereOneValueBelongs($e)) {
                throw $e;
            }

            return $this->error('One of the fields holds a list where one value belongs.', 422, ['code' => 'field_invalid']);
        }
    }

    /** A client sent an array for a text or number field: its mistake, not a server fault. */
    public static function listWhereOneValueBelongs(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'Array to string conversion') || str_contains($e->getMessage(), 'array given');
    }

    protected function refusal(BusinessRuleException $e): JsonResponse
    {
        $code = $e->errorCode();
        $status = match (true) {
            $code === 'forbidden' => 403,
            in_array($code, self::NOT_FOUND, true) => 404,
            in_array($code, self::CONFLICT, true) => 409,
            default => 422,
        };

        return $this->error($e->getMessage(), $status, $e->toErrors());
    }

    protected function notFoundRecord(string $what = 'record'): BusinessRuleException
    {
        return BusinessRuleException::make('not_found', "That {$what} was not found.");
    }

    protected function perPage(Request $request, int $default = 50): int
    {
        return max(1, min(self::MAX_PER_PAGE, (int) $request->query('per_page', (string) $default)));
    }

    /** A page of records answered with meta.pagination. @param array<int, mixed> $items */
    protected function page(LengthAwarePaginator $p, array $items, array $meta = []): JsonResponse
    {
        return $this->success($items, 'Listed successfully.', 200, ['pagination' => self::pagination($p)] + $meta);
    }

    /** @return array{current_page: int, per_page: int, total: int, last_page: int, from: ?int, to: ?int, has_more: bool} */
    public static function pagination(LengthAwarePaginator $p): array
    {
        return ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage(),
            'from' => $p->firstItem(), 'to' => $p->lastItem(), 'has_more' => $p->hasMorePages()];
    }

    /** A page of a list worked out in memory (arrears). @param list<mixed> $rows */
    protected function paginateArray(array $rows, Request $request, int $default = 50): LengthAwarePaginator
    {
        $per = $this->perPage($request, $default);
        $page = max(1, (int) $request->query('page', '1'));

        return new Paginator(array_slice($rows, ($page - 1) * $per, $per), count($rows), $per, $page);
    }

    protected function paginate(Builder $q, Request $request, int $default = 50): LengthAwarePaginator
    {
        return $q->paginate($this->perPage($request, $default), ['*'], 'page', max(1, (int) $request->query('page', '1')));
    }

    /**
     * A caretaker's properties only (no-op for everyone else).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $q
     * @return Builder<TModel>
     */
    protected function scoped(Builder $q, Request $request, string $column = 'property_id'): Builder
    {
        return RentalAccess::scope($q, $this->user($request), $column);
    }

    /** An amount in minor units: a whole number above zero; a decimal or text is refused (Minor::positive). */
    protected function minor(Request $request, string $key, string $label = 'amount'): int
    {
        return Minor::positive($request->input($key), $label);
    }

    /** A Y-m-d day from the query or body, or null; anything else is refused. */
    protected function day(Request $request, string $key): ?string
    {
        $v = $request->input($key);
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
            throw BusinessRuleException::make('date_invalid', "Give {$key} as a date like 2026-10-31.");
        }

        return $v;
    }

    /** A Y-m-d day that must be there. */
    protected function requiredDay(Request $request, string $key, string $message): string
    {
        return $this->day($request, $key) ?? throw BusinessRuleException::make('date_required', $message, ['field' => $key]);
    }

    /** A lease of this account in the caller's properties (404 otherwise). */
    protected function lease(Request $request, int $id): RentalLease
    {
        $lease = $this->scoped(RentalLease::withoutGlobalScopes()->where('company_id', $this->cid($request)), $request)->find($id);

        return $lease ?? throw $this->notFoundRecord('lease');
    }

    /** The app sends X-App-Version; its writes are the phone's (channel `app`). */
    protected function fromApp(Request $request): bool
    {
        return $request->hasHeader('X-App-Version');
    }

    /** A free-text search on several columns. @param list<string> $columns */
    protected static function search(Builder $q, ?string $term, array $columns): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $q->where(function ($w) use ($columns, $like) {
            foreach ($columns as $c) {
                $w->orWhere($c, 'like', $like);
            }
        });
    }
}
