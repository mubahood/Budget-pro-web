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

        // A chair stepping down from a phone meets the same rule as on the web: someone must be left to run the event.
        if ($existing instanceof \App\Models\BudgetProgramMember && ! $existing->is_deleted && ($action === 'delete' || (array_key_exists('role', $op['data'] ?? []) && ($op['data']['role'] ?? null) !== 'chair'))) {
            $by = \App\Models\User::withoutGlobalScopes()->find((int) ($op['_user_id'] ?? 0));
            if ($by !== null) {
                try {
                    \App\Services\Budget\CommitteeService::assertNotLastChair($by, $existing);
                } catch (BusinessRuleException $e) {
                    return ['status' => 'rejected', 'code' => $e->errorCode(), 'message' => $e->getMessage()];
                }
            }
        }

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

        // A row's figures move with its payments and leave its edit time alone, so an older edit of its name or amount still goes through. An op that carries a figure
        // from an older phone is judged by when the row last changed at all: applying it would take money back.
        $carriesFigure = array_intersect(['paid_amount', 'custom_paid_amount', 'invested_amount', 'fully_paid'], array_keys($op['data'] ?? []), is_array($op['changed_fields'] ?? null) ? $op['changed_fields'] : array_keys($op['data'] ?? [])) !== [];
        $lastChanged = (int) ($existing->client_updated_at ?? 0);
        if ($carriesFigure && $existing?->updated_at !== null) {
            $lastChanged = max($lastChanged, (int) $existing->updated_at->getTimestampMs() + 999); // updated_at keeps whole seconds: on a tie, the money stands
        }
        if ($existing !== null && $clientVersion > 0 && $clientVersion < (int) $existing->version
            && $lastChanged > $clientUpdatedAt) {
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
            if (in_array('company_id', SyncRegistry::columns((new $ref['model'])->getTable()), true)) {
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
        // Fields with rules of their own (the supermarket product / customer fields) are checked like the web form checks them.
        if (isset($config['rules']) && is_callable($config['rules'])) {
            $rules = array_intersect_key(($config['rules'])($companyId, $existing?->getKey()), array_flip(array_intersect($allowed, array_keys($data))));
            if ($rules !== []) {
                $v = \Illuminate\Support\Facades\Validator::make($data, $rules);
                if ($v->fails()) {
                    return ['status' => 'rejected', 'code' => 'validation', 'message' => $v->errors()->first(), 'errors' => $v->errors()->toArray()];
                }
            }
        }
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
            if ($userId > 0 && empty($model->getAttribute('created_by_id')) && in_array('created_by_id', SyncRegistry::columns($model->getTable()), true)) {
                $model->setAttribute('created_by_id', $userId);
            }
        }
        // Who-did-it columns default to the pushing user; a supplied user id must be a teammate.
        $pushUser = (int) ($op['_user_id'] ?? 0);
        foreach ($config['user_fields'] ?? [] as $column) {
            // Who changed it is who pushed it: a paid figure from an older phone is credited to this column (ContributionRecord::finalizer).
            if ($pushUser > 0 && in_array($column, ['chaned_by_id', 'changed_by_id'], true)) {
                $model->setAttribute($column, $pushUser);

                continue;
            }
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
            if ($model instanceof \App\Models\BudgetProgramMember && $model->user_id && ($existing === null || $model->isDirty('role'))) {
                \App\Services\Budget\CommitteeService::assertSeatFits($companyId, (int) $model->user_id, (string) $model->role);
            }
            $model->save();
        } catch (BusinessRuleException $e) {
            return ['status' => 'rejected', 'code' => $e->errorCode() === 'business_rule' ? 'validation' : $e->errorCode(), 'message' => $e->getMessage(), 'errors' => $e->toErrors()];
        }
        if ($existing === null) {
            $this->flagDuplicate($companyId, $model);
            if ($model instanceof \App\Models\BudgetProgram) {
                // An event made on a phone is chaired by whoever made it, as on the API and the web.
                \App\Services\Budget\CommitteeService::seatCreator($model, $pushUser ?: null);
            }
        }

        return ['status' => 'applied', 'model' => $model];
    }

    /** A new master row from a phone that looks like one we have: kept, and the owner is told once (Appendix E). */
    private function flagDuplicate(int $companyId, Model $model): void
    {
        $table = $model->getTable();
        if (! isset(\App\Services\Shop\DuplicateService::KINDS[$table])) {
            return;
        }
        [, $key] = \App\Services\Shop\DuplicateService::KINDS[$table];
        $value = \Illuminate\Support\Facades\DB::table($table)->where('id', $model->getKey())->selectRaw("{$key} AS k")->value('k');
        if ($value === null || $value === '') {
            return;
        }
        $twins = \Illuminate\Support\Facades\DB::table($table)->where('company_id', $companyId)->where('is_deleted', false)->whereRaw("{$key} = ?", [$value])->count();
        if ($twins > 1) {
            \App\Services\Notifications\Notices::once($companyId, 'duplicate', substr("{$table}:{$value}", 0, 40), fn () => app(\App\Services\Notifications\Notifier::class)->notify($companyId, 'team',
                'Possible duplicate: '.($model->getAttribute('name') ?? $value), 'Two phones added what looks like the same record. Open Duplicates to merge them — nothing was lost.', ['table' => $table]));
        }
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
