<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Hides tombstoned rows (`is_deleted = 1`); `withTombstones()` shows them. */
class TombstoneScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->getTable().'.is_deleted', 0);
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withTombstones', fn (Builder $b) => $b->withoutGlobalScope(static::class));
        $builder->macro('onlyTombstones', fn (Builder $b) => $b->withoutGlobalScope(static::class)->where($b->getModel()->getTable().'.is_deleted', 1));
    }
}
