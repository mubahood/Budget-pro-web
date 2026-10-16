<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the phone's writes safe to retry (MOBILE_EXCELLENCE_PLAN.md D5, the True-Doctor pattern).
 *
 * A write (POST/PUT/PATCH/DELETE) sent with `Idempotency-Key` stores its first final answer for 24 hours
 * per (company, user, key) in `api_idempotency_keys`. The same key again:
 *   - same request (method + path + query + body)  → the stored answer, header `Idempotent-Replay: true`;
 *   - a different request                          → 422 `idempotency_key_reused`;
 *   - while the first one is still running          → 409 `in_progress`.
 * Not stored (so a resend runs again): 5xx, and the answers a client fixes and resends (401, 403, 404,
 * 408, 409, 419, 422, 423, 425, 426, 429), and anything that is not JSON. Left alone: requests without
 * the header, guests, sync (it has its own op ids) and bodies over 1 MB (uploads). Attached last to the
 * authenticated route groups in routes/api.php, so tenant and permission checks run first.
 */
class IdempotentRequests
{
    public const TABLE = 'api_idempotency_keys';

    public const TTL_HOURS = 24;

    /** A first request still "running" after this long is taken to have died. */
    public const STALE_SECONDS = 120;

    public const MAX_BODY_BYTES = 1048576;

    /** Paths (after api/v1/) that never use the key. */
    public const EXCLUDED = ['#^auth/login$#', '#^sync/#', '#^poultry/sync/#'];

    private const NOT_STORED = [401, 403, 404, 408, 409, 419, 422, 423, 425, 426, 429];

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('Idempotency-Key', '');
        $user = $request->user();
        if ($key === '' || $user === null || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) || $this->excluded($request)) {
            return $next($request);
        }
        if (! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/', $key)) {
            return $this->refuse('The Idempotency-Key header must be 1 to 100 letters, digits or . _ : -', 422, 'idempotency_key_invalid');
        }

        $owner = ['company_id' => (int) $user->company_id, 'user_id' => (int) $user->getKey(), 'key' => $key];
        $fingerprint = $this->fingerprint($request);
        $claim = $this->claim($owner, $fingerprint);
        if ($claim instanceof Response) {
            return $claim;
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table(self::TABLE)->where('id', $claim)->delete();

            throw $e;
        }

        if ($this->isFinal($response)) {
            DB::table(self::TABLE)->where('id', $claim)->update([
                'status' => $response->getStatusCode(), 'content_type' => mb_substr((string) $response->headers->get('Content-Type'), 0, 100),
                'body' => (string) $response->getContent(), 'completed_at' => now(),
            ]);
        } else {
            DB::table(self::TABLE)->where('id', $claim)->delete();
        }

        return $response;
    }

    /** The row id this request now owns, or the answer to give instead (a replay, 409 or 422). */
    private function claim(array $owner, string $fingerprint): int|Response
    {
        for ($try = 0; $try < 3; $try++) {
            try {
                return (int) DB::table(self::TABLE)->insertGetId($owner + [
                    'fingerprint' => $fingerprint, 'created_at' => now(), 'expires_at' => now()->addHours(self::TTL_HOURS),
                ]);
            } catch (UniqueConstraintViolationException) {
                $row = DB::table(self::TABLE)->where($owner)->first();
                if ($row === null) {
                    continue; // removed between the insert and the read: try again
                }
                $expired = $row->expires_at !== null && $row->expires_at < now()->toDateTimeString();
                $stale = $row->status === null && $row->created_at < now()->subSeconds(self::STALE_SECONDS)->toDateTimeString();
                if ($expired || $stale) {
                    DB::table(self::TABLE)->where('id', $row->id)->delete();

                    continue;
                }
                if (! hash_equals((string) $row->fingerprint, $fingerprint)) {
                    return $this->refuse('This Idempotency-Key was already used for a different request. Send a new key for a new action.', 422, 'idempotency_key_reused');
                }
                if ($row->status === null) {
                    return $this->refuse('This request is still being processed. Wait a moment, then check before sending it again.', 409, 'in_progress');
                }

                return response((string) $row->body, (int) $row->status, [
                    'Content-Type' => $row->content_type ?: 'application/json', 'Idempotent-Replay' => 'true',
                ]);
            }
        }

        return $this->refuse('This request is still being processed. Wait a moment, then check before sending it again.', 409, 'in_progress');
    }

    private function excluded(Request $request): bool
    {
        $uri = preg_replace('#^api/v1/#', '', $request->path());
        foreach (self::EXCLUDED as $pattern) {
            if (preg_match($pattern, $uri)) {
                return true;
            }
        }
        $length = (int) $request->server('CONTENT_LENGTH', 0);
        if ($length === 0 && $request->allFiles() === []) {
            $length = strlen((string) $request->getContent());
        }

        return $length > self::MAX_BODY_BYTES;
    }

    private function isFinal(Response $response): bool
    {
        $status = $response->getStatusCode();

        return $status >= 200 && $status < 500 && ! ($status >= 300 && $status < 400)
            && ! in_array($status, self::NOT_STORED, true)
            && ! $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            && ! $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            && str_contains((string) $response->headers->get('Content-Type'), 'json');
    }

    /** Method + path + query + body (for uploads: field values plus each file's name and size). */
    private function fingerprint(Request $request): string
    {
        $files = [];
        $uploads = $request->allFiles();
        array_walk_recursive($uploads, function ($file, $name) use (&$files) {
            $files[] = $name.':'.$file->getClientOriginalName().':'.$file->getSize();
        });
        sort($files);
        $fields = $request->isJson() ? $request->json()->all() : $request->request->all();
        $body = $this->canonical($fields).($files === [] ? '' : '|'.implode('|', $files));

        return hash('sha256', $request->method()."\n".$request->path()."\n".$this->canonical($request->query())."\n".hash('sha256', $body));
    }

    private function canonical(mixed $value): string
    {
        $sort = function (mixed $v) use (&$sort) {
            if (is_array($v)) {
                $v = array_map($sort, $v);
                if (! array_is_list($v)) {
                    ksort($v);
                }
            }

            return $v;
        };

        return (string) json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function refuse(string $message, int $status, string $code): Response
    {
        return response()->json(['code' => 0, 'message' => $message, 'data' => null, 'errors' => ['code' => $code]], $status);
    }
}
