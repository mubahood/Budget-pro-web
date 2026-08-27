<?php

namespace App\Traits;

use Illuminate\Support\Str;

/**
 * Pull-only sync for the 2 platform-wide poultry reference tables (farm
 * types, production guide tasks — BACKEND_API_MASTER_TASKS.md §9). Unlike
 * PoultrySyncable's 11 tenant tables, these are NOT company-scoped and
 * clients never push to them — only admin-edited via the web CRUD, then
 * pulled by every mobile client.
 *
 * A model using this trait must declare:
 *   protected static array $syncColumns = ['name', 'description', ...];
 *   protected static string $syncUuidColumn = 'slug'; // this table's uuid is a stable, human slug, not a generated one
 */
trait PoultryReferenceSyncable
{
    protected static function bootPoultryReferenceSyncable(): void
    {
        static::creating(function ($model) {
            // Only auto-generate for a dedicated synthetic uuid column — a
            // repurposed business field (e.g. FarmType's `slug`) is always
            // supplied by the form itself and must never be overwritten.
            if (static::$syncUuidColumn === 'uuid' && empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });

        static::saving(function ($model) {
            $model->version = $model->exists ? (((int) $model->getOriginal('version')) ?: 0) + 1 : 1;
            $model->client_updated_at = (int) round(microtime(true) * 1000);
        });
    }

    public static function syncPull(int $sinceMs, int $limit = 500): array
    {
        $rows = static::query()
            ->where('client_updated_at', '>', $sinceMs)
            ->orderBy('client_updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $cursor = $sinceMs;
        $wire = [];
        foreach ($rows as $row) {
            $wire[] = $row->toSyncArray();
            $cursor = max($cursor, (int) $row->client_updated_at);
        }

        return [$wire, $cursor];
    }

    public function toSyncArray(): array
    {
        $uuidColumn = static::$syncUuidColumn;
        $wire = [
            'uuid' => $this->{$uuidColumn},
            'created_at' => (int) $this->client_updated_at,
            'updated_at' => (int) $this->client_updated_at,
            'version' => (int) $this->version,
            'is_deleted' => 0,
            'entered_by' => '',
        ];

        foreach (static::$syncColumns as $column) {
            $value = $this->{$column};
            $wire[$column] = is_bool($value) ? (int) $value : $value;
        }

        return $wire;
    }
}
