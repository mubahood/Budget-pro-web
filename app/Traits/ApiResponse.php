<?php

namespace App\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Standard API response envelope for Budget Pro.
 *
 * Every API response uses the shape:
 *   { "code": 1|0, "message": string, "data": mixed, "meta": object|null, "errors": object|null }
 *
 * - code = 1 for success, 0 for failure (kept for backward compatibility with existing clients)
 * - Responses flow through the normal Laravel pipeline (unlike the old Utils::success/error
 *   which used echo + exit), so CORS headers, throttle headers and middleware terminate() all work.
 * - HTTP status codes are meaningful: 200/201 success, 4xx/5xx for errors.
 */
trait ApiResponse
{
    /**
     * Success response.
     */
    protected function success($data = null, string $message = 'Success.', int $status = 200, ?array $meta = null): JsonResponse
    {
        $payload = [
            'code' => 1,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Created (201) response.
     */
    protected function created($data = null, string $message = 'Created successfully.'): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    /**
     * Error response.
     */
    protected function error(string $message = 'Request failed.', int $status = 400, $errors = null): JsonResponse
    {
        $payload = [
            'code' => 0,
            'message' => $message,
            'data' => null,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /**
     * 401 Unauthenticated.
     */
    protected function unauthenticated(string $message = 'Unauthenticated.'): JsonResponse
    {
        return $this->error($message, 401);
    }

    /**
     * 403 Forbidden.
     */
    protected function forbidden(string $message = 'You are not allowed to perform this action.'): JsonResponse
    {
        return $this->error($message, 403);
    }

    /**
     * 404 Not found.
     */
    protected function notFound(string $message = 'Resource not found.'): JsonResponse
    {
        return $this->error($message, 404);
    }

    /**
     * 422 Validation error.
     */
    protected function validationError($errors, string $message = 'The given data was invalid.'): JsonResponse
    {
        return $this->error($message, 422, $errors);
    }

    /**
     * Paginated list response — serializes a LengthAwarePaginator into { data, meta }.
     */
    protected function paginated(LengthAwarePaginator $paginator, $data = null, string $message = 'Listed successfully.'): JsonResponse
    {
        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'has_more' => $paginator->hasMorePages(),
        ];

        return $this->success($data ?? $paginator->items(), $message, 200, $meta);
    }
}
