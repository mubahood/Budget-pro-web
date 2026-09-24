<?php

namespace App\Services\Sync;

use App\Exceptions\BusinessRuleException;
use App\Models\SyncConflict;
use Illuminate\Database\Eloquent\Model;

/**
 * Master-data upsert with whole-row last-write-wins + a version check
 * (Appendix E "Master data", simplified per A.2): a stale client version that
 * collides with a newer server write becomes a conflict-inbox item and the
 * server row is returned; otherwise the client's fields are applied.
 */
class MasterDataService
{
    /**
     * @return array{status: string, model?: Model, code?: string, server_data?: array, conflict_id?: int, parent?: string}
     */
    public function apply(int $companyId, ?string $deviceId, string $table, array $config, array $op, SyncSerializer $serializer): array
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $config['model'];
        $uuid = (string) $op['uuid'];
        $action = $op['action'] ?? 'upsert';
        $data = is_array($op['data'] ?? null) ? $op['data'] : [];
        $clientUpdatedAt = (int) ($op['client_updated_at'] ?? 0);
        $clientVersion = (int) ($op['version'] ?? 0);

        /** @var Model|null $existing */
        $existing = $modelClass::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('uuid', $uuid)->first();

        if ($action === 'delete') {
            if ($existing === null) {
                return ['status' => 'applied'];
            }
            if ($existing->is_deleted) {
                return ['status' => 'replayed'];
            }
            // Delete vs edit: a server edit newer than the client's delete wins (Appendix E).
            if ((int) $existing->client_updated_at > $clientUpdatedAt && $clientVersion < (int) $existing->version) {
                $conflict = $this->conflict($companyId, $deviceId, $table, $uuid, 'delete_vs_edit', $op, $serializer->row($table, $existing));

                return ['status' => 'conflict', 'code' => 'delete_vs_edit', 'server_data' => $serializer->row($table, $existing), 'conflict_id' => $conflict->id];
            }
            $existing->delete(); // tombstone via Syncable

            return ['status' => 'applied', 'model' => $existing->fresh() ?? $existing];
        }

        if ($existing !== null && $clientVersion > 0 && $clientVersion < (int) $existing->version
            && (int) $existing->client_updated_at > $clientUpdatedAt) {
            $serverRow = $serializer->row($table, $existing);
            $conflict = $this->conflict($companyId, $deviceId, $table, $uuid, 'stale_version', $op, $serverRow);

            return ['status' => 'conflict', 'code' => 'stale_version', 'server_data' => $serverRow, 'conflict_id' => $conflict->id];
        }

        $model = $existing ?? new $modelClass();
        if ($existing === null) {
            $model->setAttribute('uuid', $uuid);
            $model->setAttribute('company_id', $companyId);
        }

        // Resolve `*_uuid` references to local ids; an unknown parent rejects the op.
        foreach ($config['refs'] ?? [] as $wireField => $ref) {
            if (! array_key_exists($wireField, $data)) {
                continue;
            }
            $refUuid = $data[$wireField];
            if ($refUuid === null || $refUuid === '') {
                $model->setAttribute($ref['column'], null);

                continue;
            }
            $refQuery = $ref['model']::query()->withoutGlobalScopes()->where('uuid', $refUuid);
            if (\Illuminate\Support\Facades\Schema::hasColumn((new $ref['model'])->getTable(), 'company_id')) {
                $refQuery->where('company_id', $companyId);
            }
            $localId = $refQuery->value('id');
            if ($localId === null) {
                return ['status' => 'rejected', 'code' => 'missing_parent', 'parent' => $wireField];
            }
            $model->setAttribute($ref['column'], $localId);
        }

        $allowed = $config['fields'] ?? [];
        $changed = is_array($op['changed_fields'] ?? null) ? $op['changed_fields'] : null;
        foreach ($allowed as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            if ($changed !== null && $existing !== null && ! in_array($field, $changed, true)) {
                continue;
            }
            $model->setAttribute($field, $data[$field]);
        }
        if ($existing === null) {
            $model->client_created_at = (int) ($op['client_created_at'] ?? $clientUpdatedAt ?: \App\Support\Sync\SyncSequence::nowMs());
            if ($deviceId) {
                $model->device_id = $deviceId;
            }
            $userId = (int) ($op['_user_id'] ?? 0);
            if ($userId > 0 && empty($model->getAttribute('created_by_id')) && \Illuminate\Support\Facades\Schema::hasColumn($model->getTable(), 'created_by_id')) {
                $model->setAttribute('created_by_id', $userId);
            }
        }
        // Who-did-it columns default to the pushing user; a supplied user id must be a teammate.
        $pushUser = (int) ($op['_user_id'] ?? 0);
        foreach ($config['user_fields'] ?? [] as $column) {
            $given = (int) $model->getAttribute($column);
            if ($given > 0 && ! \App\Models\User::withoutGlobalScopes()->where('id', $given)->where('company_id', $companyId)->exists()) {
                return ['status' => 'rejected', 'code' => 'validation', 'message' => 'User '.$given.' is not part of this company.'];
            }
            if ($given <= 0 && $pushUser > 0 && ($existing === null || $column === 'chaned_by_id' || $column === 'changed_by_id')) {
                $model->setAttribute($column, $pushUser);
            }
        }
        if ($clientUpdatedAt > 0) {
            $model->client_updated_at = $clientUpdatedAt;
        }
        if ($existing !== null && $existing->is_deleted && $clientUpdatedAt >= (int) $existing->client_updated_at) {
            $model->is_deleted = 0; // edit after delete resurrects (Appendix E)
        }

        try {
            $model->save();
        } catch (BusinessRuleException $e) {
            return ['status' => 'rejected', 'code' => $e->errorCode() === 'business_rule' ? 'validation' : $e->errorCode(), 'message' => $e->getMessage(), 'errors' => $e->toErrors()];
        }

        return ['status' => 'applied', 'model' => $model];
    }

    public function conflict(int $companyId, ?string $deviceId, string $table, ?string $uuid, string $code, array $local, ?array $server, ?string $title = null): SyncConflict
    {
        return SyncConflict::withoutGlobalScopes()->create([
            'company_id' => $companyId, 'device_id' => $deviceId, 'table_name' => $table, 'row_uuid' => $uuid, 'code' => $code,
            'title' => $title ?? match ($code) {
                'stale_version' => 'This record was changed on another device',
                'delete_vs_edit' => 'Deleted on one device, edited on another',
                'stock_exception' => 'Sold more than was in stock',
                default => 'Needs attention',
            },
            'local_json' => $local, 'server_json' => $server, 'state' => 'open',
        ]);
    }
}
