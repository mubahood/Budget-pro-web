<?php

namespace App\Traits;

use App\Scopes\TombstoneScope;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Str;

/**
 * Standard sync behaviour for every tenant table (plan Appendix A.7 / B.0):
 *  - `uuid` assigned on create (mirrors `client_uuid` when the row came from a device)
 *  - `version` + `client_updated_at` bumped on every change, incl. admin-panel edits
 *  - `server_seq` taken from the global sequence on every write, so pulls see it
 *  - delete() writes a tombstone (`is_deleted = 1`) that propagates to devices;
 *    forceDelete() removes the row for real.
 */
trait Syncable
{
    protected bool $forceDeleting = false;

    public static function bootSyncable(): void
    {
        static::addGlobalScope(new TombstoneScope);

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $clientUuid = $model->getAttribute('client_uuid');
                $model->uuid = ! empty($clientUuid) ? (string) $clientUuid : (string) Str::uuid();
            }
            $now = SyncSequence::nowMs();
            if (empty($model->client_created_at)) {
                $model->client_created_at = $now;
            }
            if (empty($model->client_updated_at)) {
                $model->client_updated_at = $now;
            }
            if (empty($model->version)) {
                $model->version = 1;
            }
            if ($model->getAttribute('is_deleted') === null) {
                $model->is_deleted = 0;
            }
            $model->server_seq = SyncSequence::next();
        });

        static::updating(function ($model) {
            $model->version = ((int) $model->getOriginal('version') ?: 0) + 1;
            if (! $model->isDirty('client_updated_at')) {
                $model->client_updated_at = SyncSequence::nowMs();
            }
            $model->server_seq = SyncSequence::next();
        });
    }

    /** Persist without firing events but still advance version/seq so devices see the change. */
    public function saveQuietlySynced(): bool
    {
        if ($this->exists) {
            $this->version = ((int) $this->getOriginal('version') ?: (int) $this->version) + 1;
            $this->client_updated_at = SyncSequence::nowMs();
        }
        $this->server_seq = SyncSequence::next();

        return $this->saveQuietly();
    }

    protected function performDeleteOnModel(): void
    {
        if ($this->forceDeleting) {
            $this->setKeysForSaveQuery($this->newModelQuery())->delete();
            $this->exists = false;

            return;
        }

        $values = [
            'is_deleted' => 1,
            'version' => ((int) $this->version ?: 0) + 1,
            'client_updated_at' => SyncSequence::nowMs(),
            'server_seq' => SyncSequence::next(),
        ];
        $this->setKeysForSaveQuery($this->newModelQuery()->withoutGlobalScopes())->update($values);
        foreach ($values as $k => $v) {
            $this->setAttribute($k, $v);
        }
        $this->syncOriginal();
    }

    public function forceDelete(): ?bool
    {
        $this->forceDeleting = true;
        try {
            return $this->delete();
        } finally {
            $this->forceDeleting = false;
        }
    }

    public function isTombstoned(): bool
    {
        return (int) $this->is_deleted === 1;
    }
}
