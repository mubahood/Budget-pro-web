<?php

namespace App\Models;

use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A registered mobile device / till (plan A.1, Appendix D): owns a receipt prefix. */
class Device extends Model
{
    public const WEB_PREFIX = 'W';

    protected $fillable = ['company_id', 'user_id', 'device_id', 'name', 'platform', 'app_version', 'number_prefix', 'prefix_index', 'status', 'last_seen_at', 'last_pull_seq'];

    protected $casts = ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime', 'prefix_index' => 'integer', 'last_pull_seq' => 'integer'];

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
}
