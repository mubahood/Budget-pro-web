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

        return $wire;
    }

    public function uuidOf(string $modelClass, int $id): ?string
    {
        if (! isset($this->uuidCache[$modelClass][$id])) {
            $this->uuidCache[$modelClass][$id] = $modelClass::query()->withoutGlobalScopes()->where('id', $id)->value('uuid');
        }

        return $this->uuidCache[$modelClass][$id];
    }
}
