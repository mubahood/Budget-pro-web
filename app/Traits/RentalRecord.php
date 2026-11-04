<?php

namespace App\Traits;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;

/**
 * Every Estate Ledger (rentals) model: company-scoped (CompanyScope), and for posted money rows, locked. A model that lists
 * `LOCKED_AFTER_CREATE` may afterwards change only the fields in `MUTABLE` (its caches and its reversal), and is never
 * deleted (ADR 0010 §5). The services are the only writers; the demo purge deletes with the query builder.
 */
trait RentalRecord
{
    public static function bootRentalRecord(): void
    {
        static::addGlobalScope(new CompanyScope);

        if (! defined(static::class.'::MUTABLE')) {
            return;
        }
        static::updating(function ($model) {
            $dirty = array_diff(array_keys($model->getDirty()), array_merge(static::MUTABLE, ['updated_at']));
            if ($dirty !== []) {
                throw BusinessRuleException::make('record_locked', 'A posted record never changes: reverse it and record it again.', ['fields' => array_values($dirty)]);
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('record_locked', 'A posted record is never deleted: reverse it.');
        });
    }
}
