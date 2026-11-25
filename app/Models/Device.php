<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A registered mobile device / till (plan A.1, Appendix D): owns a receipt prefix. */
class Device extends Model
{
    public const WEB_PREFIX = 'W';

    protected $fillable = ['company_id', 'user_id', 'device_id', 'name', 'platform', 'app_version', 'number_prefix', 'prefix_index', 'status', 'last_seen_at', 'last_pull_seq',
        'push_token', 'push_provider', 'app', 'locale', 'access_token_id', 'push_token_at'];

    protected $hidden = ['push_token'];

    protected $casts = ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime', 'prefix_index' => 'integer', 'last_pull_seq' => 'integer', 'push_token_at' => 'datetime'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }

    /** D1..D9, DA..DZ, D10, D11 ... — short, unique per company, never reused. */
    public static function prefixFor(int $index): string
    {
        if ($index <= 9) {
            return 'D'.$index;
        }
        if ($index <= 35) {
            return 'D'.chr(ord('A') + ($index - 10));
        }

        return 'D'.$index;
    }

    /** Register (or re-register) a device for a company; the prefix is stable per device_id. */
    public static function register(int $companyId, ?int $userId, string $deviceId, array $attrs = []): self
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($companyId, $userId, $deviceId, $attrs) {
            $existing = static::withoutGlobalScopes()->where('company_id', $companyId)->where('device_id', $deviceId)->lockForUpdate()->first();
            if ($existing) {
                $existing->fill(array_filter([
                    'name' => $attrs['name'] ?? null, 'platform' => $attrs['platform'] ?? null, 'app_version' => $attrs['app_version'] ?? null,
                ], fn ($v) => $v !== null));
                $existing->user_id = $userId ?? $existing->user_id;
                $existing->last_seen_at = now();
                $existing->save();

                return $existing;
            }
            $index = (int) static::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->max('prefix_index') + 1;

            return static::create([
                'company_id' => $companyId, 'user_id' => $userId, 'device_id' => $deviceId,
                'name' => $attrs['name'] ?? 'Device '.$index, 'platform' => $attrs['platform'] ?? null, 'app_version' => $attrs['app_version'] ?? null,
                'number_prefix' => static::prefixFor($index), 'prefix_index' => $index, 'status' => 'active', 'last_seen_at' => now(),
            ]);
        });
    }

    /**
     * The phone's push token (FCM), which app it is and its language. One token belongs to one device row (one user, one app) at a
     * time: registering it here takes it off any other row, in any account. A null token clears this row's.
     */
    public function setPush(?string $token, ?string $provider, ?string $app, ?string $locale, ?int $accessTokenId): void
    {
        $token = $token !== null && trim($token) !== '' ? trim($token) : null;
        if ($token !== null) {
            static::withoutGlobalScopes()->where('push_token', $token)->where('id', '!=', $this->id)
                ->update(['push_token' => null, 'push_provider' => null, 'access_token_id' => null, 'push_token_at' => null]);
        }
        $this->push_token = $token;
        $this->push_provider = $token !== null ? ($provider ?: 'fcm') : null;
        $this->access_token_id = $token !== null ? $accessTokenId : null;
        $this->push_token_at = $token !== null ? now() : null;
        $this->app = $app ?? $this->app;
        $this->locale = $locale ?? $this->locale;
        $this->save();
    }

    /** Sign-out: the sign-in's phone stops getting pushes. */
    public static function clearPushForAccessTokens(array $accessTokenIds): int
    {
        $ids = array_values(array_filter(array_map('intval', $accessTokenIds)));

        return $ids === [] ? 0 : static::withoutGlobalScopes()->whereIn('access_token_id', $ids)->whereNotNull('push_token')
            ->update(['push_token' => null, 'push_provider' => null, 'access_token_id' => null, 'push_token_at' => null]);
    }

    public static function clearPushForUser(int $userId): int
    {
        return static::withoutGlobalScopes()->where('user_id', $userId)->whereNotNull('push_token')
            ->update(['push_token' => null, 'push_provider' => null, 'access_token_id' => null, 'push_token_at' => null]);
    }
}
