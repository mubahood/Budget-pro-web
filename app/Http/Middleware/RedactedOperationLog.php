<?php

namespace App\Http\Middleware;

use Encore\Admin\Auth\Database\OperationLog as OperationLogModel;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Middleware\LogOperation;
use Illuminate\Http\Request;

/**
 * laravel-admin's operation log (admin_operation_log), without secrets. Encore's LogOperation
 * stores the whole request input, so a password typed on the classic settings page or the
 * Employees form ended up in the log in plain text. This writes the same row with every password,
 * PIN, token and secret value replaced by "[redacted]" (the key stays, so the log still shows the
 * field was sent). Wired in config/admin.php in place of Encore's `admin.log`. The migration
 * 2026_10_17_420003 scrubbed the rows written before.
 */
class RedactedOperationLog extends LogOperation
{
    public const MASK = '[redacted]';

    public function handle(Request $request, \Closure $next)
    {
        if ($this->shouldLogOperation($request)) {
            try {
                OperationLogModel::create([
                    'user_id' => Admin::user()->id,
                    'path' => substr($request->path(), 0, 255),
                    'method' => $request->method(),
                    'ip' => $request->getClientIp(),
                    'input' => json_encode(self::redact($request->input())),
                ]);
            } catch (\Exception $exception) {
                // As Encore: a log that cannot be written never stops the request.
            }
        }

        return $next($request);
    }

    /**
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    public static function redact(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($key) && self::isSecret($key)) {
                $input[$key] = self::MASK;
            } elseif (is_array($value)) {
                $input[$key] = self::redact($value);
            }
        }

        return $input;
    }

    /** password, password_confirmation, new_password, current_password, old_password, pin, *_token, *secret*, api keys. */
    public static function isSecret(string $key): bool
    {
        $key = strtolower($key);

        return $key === 'pin' || str_ends_with($key, '_pin') || str_starts_with($key, 'pin_')
            || str_contains($key, 'password') || str_contains($key, 'secret') || str_contains($key, 'token')
            || str_contains($key, 'passcode') || str_contains($key, 'api_key') || str_contains($key, 'private_key');
    }
}
