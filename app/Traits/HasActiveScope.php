<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Trait HasActiveScope
 * 
 * Provides common status-based query scopes for models.
 * Models using this trait should have a 'status' column.
 */
trait HasActiveScope
{
    /**
     * Scope a query to only include active records.
     *
     * @param Builder $query
     * @param string $column The status column to filter on (default: 'status')
     * @return Builder
     */
    public function scopeActive(Builder $query, $column = 'status')
    {
        return $query->where($column, 'Active');
    }

    /**
     * Scope a query to only include inactive records.
     *
     * @param Builder $query
     * @param string $column The status column to filter on (default: 'status')
     * @return Builder
     */
    public function scopeInactive(Builder $query, $column = 'status')
    {
        return $query->where($column, 'Inactive');
    }

    /**
     * Scope a query to only include closed records.
     *
     * @param Builder $query
     * @param string $column The status column to filter on (default: 'status')
     * @return Builder
     */
    public function scopeClosed(Builder $query, $column = 'status')
    {
        return $query->where($column, 'Closed');
    }

    /**
     * Scope a query to only include pending records.
     *
     * @param Builder $query
     * @param string $column The status column to filter on (default: 'status')
     * @return Builder
     */
    public function scopePending(Builder $query, $column = 'status')
    {
        return $query->where($column, 'Pending');
    }

    /**
     * Scope a query to only include approved records.
     *
     * @param Builder $query
     * @param string $column The status column to filter on (default: 'status')
     * @return Builder
     */
    public function scopeApproved(Builder $query, $column = 'status')
    {
        return $query->where($column, 'Approved');
    }

    /**
     * Scope a query to only include rejected records.
     *
     * @param Builder $query
     * @param string $column The status column to filter on (default: 'status')
     * @return Builder
     */
    public function scopeRejected(Builder $query, $column = 'status')
    {
        return $query->where($column, 'Rejected');
    }

    /**
     * Scope a query to filter by a specific status.
     *
     * @param Builder $query
     * @param string $status
     * @param string $column The status column to filter on (default: 'status')
     * @return Builder
     */
    public function scopeByStatus(Builder $query, $status, $column = 'status')
    {
        return $query->where($column, $status);
    }

    /**
     * Scope a query to exclude specific statuses.
     *
     * @param Builder $query
     * @param array|string $statuses
     * @param string $column The status column to filter on (default: 'status')
     * @return Builder
     */
    public function scopeExcludeStatus(Builder $query, $statuses, $column = 'status')
    {
        if (is_array($statuses)) {
            return $query->whereNotIn($column, $statuses);
        }
        return $query->where($column, '!=', $statuses);
    }

    /**
     * Scope a query to include multiple statuses.
     *
     * @param Builder $query
     * @param array $statuses
     * @param string $column The status column to filter on (default: 'status')
     * @return Builder
     */
    public function scopeInStatuses(Builder $query, array $statuses, $column = 'status')
    {
        return $query->whereIn($column, $statuses);
    }

    /**
     * Scope a query to include active or pending records.
     *
     * @param Builder $query
     * @param string $column The status column to filter on (default: 'status')
     * @return Builder
     */
    public function scopeActiveOrPending(Builder $query, $column = 'status')
    {
        return $query->whereIn($column, ['Active', 'Pending']);
    }

    /**
     * Scope a query to include non-closed records (Active, Inactive, Pending).
     *
     * @param Builder $query
     * @param string $column The status column to filter on (default: 'status')
     * @return Builder
     */
    public function scopeNotClosed(Builder $query, $column = 'status')
    {
        return $query->where($column, '!=', 'Closed');
    }
}
