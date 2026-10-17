<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| laravel-admin's operation log wrote each request's whole input, so the classic settings page
| (PUT auth/setting) and the Employees form left passwords in admin_operation_log in plain text.
| New rows are redacted before they are written (App\Http\Middleware\RedactedOperationLog); this
| scrubs the rows already there. JSON-aware: only the secret values become "[redacted]", the rest of
| each row's input is kept. Rows whose input is not valid JSON have the same keys blanked by pattern.
| The key rule is a copy of RedactedOperationLog::isSecret(), so this migration never changes meaning.
*/
return new class extends Migration
{
    private const MASK = '[redacted]';

    public function up(): void
    {
        if (! Schema::hasTable('admin_operation_log')) {
            return;
        }
        DB::table('admin_operation_log')
            ->where(fn ($q) => $q->where('input', 'like', '%password%')->orWhere('input', 'like', '%secret%')
                ->orWhere('input', 'like', '%token%')->orWhere('input', 'like', '%pin%')
                ->orWhere('input', 'like', '%passcode%')->orWhere('input', 'like', '%api_key%')->orWhere('input', 'like', '%private_key%'))
            ->select(['id', 'input'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $clean = $this->clean((string) $row->input);
                    if ($clean !== $row->input) {
                        DB::table('admin_operation_log')->where('id', $row->id)->update(['input' => $clean]);
                    }
                }
            });
    }

    public function down(): void
    {
        // The values are gone on purpose.
    }

    private function clean(string $input): string
    {
        $decoded = json_decode($input, true);
        if (is_array($decoded)) {
            $redacted = $this->redact($decoded);

            return $redacted === $decoded ? $input : (string) json_encode($redacted);
        }

        // Not JSON (cut short, or written by something else): blank "key":"value" pairs of secret keys.
        return (string) preg_replace_callback('/"([^"\\\\]{1,100})"\s*:\s*"(?:[^"\\\\]|\\\\.)*"/', fn ($m) => $this->isSecret($m[1]) ? '"'.$m[1].'":"'.self::MASK.'"' : $m[0], $input);
    }

    /** @param  array<mixed>  $input @return array<mixed> */
    private function redact(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($key) && $this->isSecret($key)) {
                $input[$key] = self::MASK;
            } elseif (is_array($value)) {
                $input[$key] = $this->redact($value);
            }
        }

        return $input;
    }

    private function isSecret(string $key): bool
    {
        $key = strtolower($key);

        return $key === 'pin' || str_ends_with($key, '_pin') || str_starts_with($key, 'pin_')
            || str_contains($key, 'password') || str_contains($key, 'secret') || str_contains($key, 'token')
            || str_contains($key, 'passcode') || str_contains($key, 'api_key') || str_contains($key, 'private_key');
    }
};
