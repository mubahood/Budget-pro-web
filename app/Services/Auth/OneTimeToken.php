<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;

/**
 * Tokens the API hands out after a code was verified: the password-reset token and the sign-up's
 * "this phone or email was verified" token. 32 random bytes each, stored only as a SHA-256 hash with
 * their purpose, the account or identifier they belong to and their expiry, and used up once in one
 * atomic update. Nothing here depends on APP_KEY, so a leaked key cannot forge one.
 */
final class OneTimeToken
{
    public const TABLE = 'auth_one_time_tokens';

    public const RESET = 'password_reset';

    public const REGISTER = 'register';

    public const RESET_MINUTES = 15;

    public const REGISTER_MINUTES = 30;

    /**
     * A new token for this purpose and owner (the account, or the identifier when there is no account yet).
     * The owner's earlier open tokens of the same purpose stop working: only the newest code's token counts.
     */
    public static function issue(string $purpose, ?int $userId, ?string $identifier, int $minutes): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $identifier = $identifier === null ? null : mb_substr($identifier, 0, 191);
        DB::transaction(function () use ($purpose, $userId, $identifier, $token, $minutes) {
            self::revokeOpen($purpose, $userId, $identifier);
            DB::table(self::TABLE)->insert([
                'purpose' => $purpose, 'token_hash' => hash('sha256', $token), 'user_id' => $userId, 'identifier' => $identifier,
                'expires_at' => now()->addMinutes($minutes), 'created_at' => now(),
            ]);
        });

        return $token;
    }

    /** Use up the owner's open tokens of this purpose (marked used, so a late one explains itself as expired). */
    public static function revokeOpen(string $purpose, ?int $userId, ?string $identifier): int
    {
        if ($userId === null && ($identifier === null || $identifier === '')) {
            return 0;
        }

        return DB::table(self::TABLE)->where('purpose', $purpose)->whereNull('used_at')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId), fn ($q) => $q->whereNull('user_id')->where('identifier', $identifier))
            ->update(['used_at' => now()]);
    }

    /**
     * The open token row (not used, not expired) for this purpose, without using it up.
     *
     * @return object{id: int, user_id: int|null, identifier: string|null}|null
     */
    public static function find(string $purpose, ?string $token): ?object
    {
        if ($token === null || $token === '' || strlen($token) > 200) {
            return null;
        }
        /** @var object{id: int, user_id: int|null, identifier: string|null}|null $row */
        $row = DB::table(self::TABLE)->where('token_hash', hash('sha256', $token))->where('purpose', $purpose)
            ->whereNull('used_at')->where('expires_at', '>=', now())->first(['id', 'user_id', 'identifier']);

        return $row;
    }

    /**
     * Use the token up: true for exactly one caller, within its expiry, for its own purpose and, when
     * given, its own account or one of the identifiers. Inside a transaction, a rollback gives it back.
     *
     * @param  array<int, string|null>|null  $identifiers
     * @return object{id: int, user_id: int|null, identifier: string|null}|null
     */
    public static function consume(string $purpose, ?string $token, ?int $userId = null, ?array $identifiers = null): ?object
    {
        $row = self::find($purpose, $token);
        if ($row === null
            || ($userId !== null && (int) $row->user_id !== $userId)
            || ($identifiers !== null && ! in_array($row->identifier, array_values(array_filter($identifiers, fn ($i) => $i !== null && $i !== '')), true))) {
            return null;
        }
        $used = DB::table(self::TABLE)->where('id', $row->id)->whereNull('used_at')->where('expires_at', '>=', now())->update(['used_at' => now()]);

        return $used === 1 ? $row : null;
    }

    /** Rows a day past their expiry (kept that long to explain a refusal). */
    public static function prune(): int
    {
        return DB::table(self::TABLE)->where('expires_at', '<', now()->subDay())->delete();
    }
}
