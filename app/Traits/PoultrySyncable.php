<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared push/pull/conflict logic for the 11 tenant-scoped poultry tables,
 * matching the mobile app's already-built offline-first sync engine
 * (budget-pro-mobo: lib/poultry/services/poultry_sync_service.dart).
 *
 * Wire format quirks this trait bridges deliberately:
 * - The mobile client's cursor is a device-ms TIMESTAMP (`client_updated_at`
 *   here), not an auto-increment id — pull/push both key off it.
 * - The client's `toMap()` sends cross-entity links as `*_uuid` string
 *   values (e.g. `batch_uuid`), but this table's own FK columns are plain
 *   integer ids (`batch_id`) — the web admin CRUD needs real ids, so a
 *   translation happens both ways via $syncUuidRefs.
 * - Wire booleans must be plain 0/1 ints: the client's `PoultryModel.intOf()`
 *   parser turns a JSON `true`/`false` into 0 (via a failed int-parse of the
 *   string "true"/"false"), so this trait never emits real JSON booleans.
 * - A missing parent uuid (not synced yet) resolves to a null FK rather than
 *   rejecting the push — retried automatically next sync since the child
 *   stays dirty client-side until its own push succeeds.
 *
 * A model using this trait must declare:
 *   protected static array $syncColumns = ['name', 'type', ...];      // direct-copy fields
 *   protected static array $syncUuidRefs = ['batch_uuid' => ['model' => PoultryBatch::class, 'column' => 'batch_id']];
 */
trait PoultrySyncable
{
    protected static function bootPoultrySyncable(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            $nowMs = (int) round(microtime(true) * 1000);
            if (empty($model->client_created_at)) {
                $model->client_created_at = $nowMs;
            }
            if (empty($model->client_updated_at)) {
                $model->client_updated_at = $nowMs;
            }
        });

        // Admin-UI edits (laravel-admin Form::save() -> Eloquent save()) must
        // bump the LWW clock so a later mobile pull sees the change as
        // newer, and a stale local copy correctly loses on its next push.
        // Sync-applied writes bypass this entirely by using the query
        // builder directly (see syncPush()), never Eloquent save().
        static::updating(function ($model) {
            $model->version = ((int) $model->getOriginal('version') ?: 0) + 1;
            $model->client_updated_at = (int) round(microtime(true) * 1000);
        });
    }

    /**
     * Rows changed since $sinceMs for $companyId, oldest first, capped at
     * $limit. Returns [rows(wire-format), newCursor].
     */
    public static function syncPull(int $companyId, int $sinceMs, int $limit = 200): array
    {
        $query = static::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('client_updated_at', '>', $sinceMs)
            ->orderBy('client_updated_at')
            ->orderBy('id')
            ->limit($limit);

        $rows = $query->get();

        $newCursor = $sinceMs;
        $wire = [];
        foreach ($rows as $row) {
            $wire[] = $row->toSyncArray();
            $newCursor = max($newCursor, (int) $row->client_updated_at);
        }

        return [$wire, $newCursor];
    }

    /**
     * Applies one pushed row. Returns:
     *   ['conflict' => false, 'server_data' => <wire row just written>]
     *   ['conflict' => true,  'server_data' => <authoritative server wire row>]
     */
    public static function syncPush(int $companyId, string $uuid, array $payload, bool $isDelete, ?string $enteredBy): array
    {
        $table = (new static())->getTable();
        $existing = static::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)->where('uuid', $uuid)->first();

        $clientUpdatedAt = (int) ($payload['updated_at'] ?? 0);
        $clientCreatedAt = (int) ($payload['created_at'] ?? $clientUpdatedAt);

        if ($existing && (int) $existing->client_updated_at > $clientUpdatedAt) {
            // Server already has a strictly newer write — client loses.
            return ['conflict' => true, 'server_data' => $existing->toSyncArray()];
        }

        $row = [
            'company_id' => $companyId,
            'uuid' => $uuid,
            'is_deleted' => $isDelete ? 1 : 0,
            'client_created_at' => $existing->client_created_at ?? $clientCreatedAt,
            'client_updated_at' => $clientUpdatedAt,
            'version' => ($existing->version ?? 0) + 1,
            'entered_by' => $enteredBy,
        ];

        foreach (static::$syncColumns as $column) {
            if (array_key_exists($column, $payload)) {
                $row[$column] = static::normalizeIncoming($payload[$column]);
            }
        }

        foreach (static::$syncUuidRefs as $wireField => $ref) {
            $refUuid = $payload[$wireField] ?? null;
            $row[$ref['column']] = empty($refUuid)
                ? null
                : $ref['model']::query()->withoutGlobalScopes()
                    ->where('company_id', $companyId)->where('uuid', $refUuid)->value('id');
        }

        if ($existing) {
            DB::table($table)->where('id', $existing->id)->update($row);
        } else {
            DB::table($table)->insert($row);
        }

        $fresh = static::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)->where('uuid', $uuid)->first();

        return ['conflict' => false, 'server_data' => $fresh->toSyncArray()];
    }

    /**
     * A pushed boolean arrives as PHP true/false/0/1/"0"/"1" depending on
     * how the client's JSON encoder serialized it — normalize to plain int
     * for storage, matching every other column's already-int-friendly shape.
     */
    protected static function normalizeIncoming($value)
    {
        return is_bool($value) ? (int) $value : $value;
    }

    /**
     * This row in the exact wire shape the mobile client's `fromMap()`
     * expects — see the class docblock for why booleans/dates/uuid-refs
     * each need explicit normalization rather than a raw ->toArray().
     */
    public function toSyncArray(): array
    {
        $wire = [
            'uuid' => $this->uuid,
            'created_at' => (int) $this->client_created_at,
            'updated_at' => (int) $this->client_updated_at,
            'version' => (int) $this->version,
            'is_deleted' => (int) $this->is_deleted,
            'entered_by' => $this->entered_by,
        ];

        foreach (static::$syncColumns as $column) {
            $value = $this->{$column};
            if (is_bool($value)) {
                $value = (int) $value;
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }
            $wire[$column] = $value;
        }

        foreach (static::$syncUuidRefs as $wireField => $ref) {
            $localId = $this->{$ref['column']};
            $wire[$wireField] = $localId
                ? $ref['model']::query()->withoutGlobalScopes()->where('id', $localId)->value('uuid')
                : null;
        }

        return $wire;
    }
}
