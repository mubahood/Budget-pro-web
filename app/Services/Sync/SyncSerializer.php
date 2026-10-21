<?php

namespace App\Services\Sync;

use Illuminate\Database\Eloquent\Model;

/**
 * Turns a model row into its wire form (A.3): every column, `*_uuid` for each
 * registered foreign key, integers for booleans, strings for money/dates.
 */
class SyncSerializer
{
    /** @var array<string, array<int, string|null>> uuid lookups per model class */
    private array $uuidCache = [];

    public function row(string $table, Model $model): array
    {
        $config = SyncRegistry::get($table) ?? [];
        if (in_array($config['kind'] ?? '', [SyncRegistry::KIND_POULTRY, SyncRegistry::KIND_REFERENCE], true) && method_exists($model, 'toSyncArray')) {
            return $model->toSyncArray() + ['server_seq' => (int) $model->server_seq];
        }

        $wire = [];
        foreach ($model->getAttributes() as $column => $raw) {
            $value = $model->getAttribute($column);
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            } elseif (is_bool($value)) {
                $value = (int) $value;
            }
            $wire[$column] = $value;
        }
        foreach ($config['refs'] ?? [] as $wireField => $ref) {
            $localId = $model->getAttribute($ref['column']);
            $wire[$wireField] = $localId ? $this->uuidOf($ref['model'], (int) $localId) : null;
        }
        $wire['server_seq'] = (int) $model->server_seq;
        $wire['version'] = (int) $model->version;
        $wire['is_deleted'] = (int) $model->is_deleted;
        unset($wire['client_uuid'], $wire['password']);
        foreach ($model->getHidden() as $hidden) {
            unset($wire[$hidden]); // what a model hides never leaves (a SACCO member's NIN and its hash, AK-31)
        }

        return $wire;
    }

    /**
     * F3: load the reference uuids a page of rows needs with one whereIn per referenced model, so row()
     * does not query once per foreign key.
     *
     * @param  iterable<Model>  $models
     */
    public function preload(string $table, iterable $models): void
    {
        $want = [];
        foreach (SyncRegistry::get($table)['refs'] ?? [] as $ref) {
            foreach ($models as $model) {
                $id = (int) $model->getAttribute($ref['column']);
                if ($id > 0 && ! array_key_exists($id, $this->uuidCache[$ref['model']] ?? [])) {
                    $want[$ref['model']][$id] = true;
                }
            }
        }
        foreach ($want as $modelClass => $ids) {
            foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
                $found = $modelClass::query()->withoutGlobalScopes()->whereIn('id', $chunk)->pluck('uuid', 'id')->all();
                foreach ($chunk as $id) {
                    $this->uuidCache[$modelClass][$id] = $found[$id] ?? null;
                }
            }
        }
    }

    public function uuidOf(string $modelClass, int $id): ?string
    {
        if (! array_key_exists($id, $this->uuidCache[$modelClass] ?? [])) {
            $this->uuidCache[$modelClass][$id] = $modelClass::query()->withoutGlobalScopes()->where('id', $id)->value('uuid');
        }

        return $this->uuidCache[$modelClass][$id];
    }
}
