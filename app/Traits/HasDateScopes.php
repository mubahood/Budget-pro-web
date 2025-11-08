<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * Trait HasDateScopes
 * 
 * Provides common date-based query scopes for models.
 * Models using this trait should have a 'date' or 'created_at' column.
 */
trait HasDateScopes
{
    /**
     * Scope a query to records within a date range.
     *
     * @param Builder $query
     * @param string $startDate
     * @param string $endDate
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeByDateRange(Builder $query, $startDate, $endDate, $column = 'date')
    {
        return $query->whereBetween($column, [$startDate, $endDate]);
    }

    /**
     * Scope a query to records from today.
     *
     * @param Builder $query
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeToday(Builder $query, $column = 'date')
    {
        return $query->whereDate($column, Carbon::today());
    }

    /**
     * Scope a query to records from yesterday.
     *
     * @param Builder $query
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeYesterday(Builder $query, $column = 'date')
    {
        return $query->whereDate($column, Carbon::yesterday());
    }

    /**
     * Scope a query to records from this week.
     *
     * @param Builder $query
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeThisWeek(Builder $query, $column = 'date')
    {
        return $query->whereBetween($column, [
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek()
        ]);
    }

    /**
     * Scope a query to records from last week.
     *
     * @param Builder $query
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeLastWeek(Builder $query, $column = 'date')
    {
        return $query->whereBetween($column, [
            Carbon::now()->subWeek()->startOfWeek(),
            Carbon::now()->subWeek()->endOfWeek()
        ]);
    }

    /**
     * Scope a query to records from this month.
     *
     * @param Builder $query
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeThisMonth(Builder $query, $column = 'date')
    {
        return $query->whereMonth($column, Carbon::now()->month)
                    ->whereYear($column, Carbon::now()->year);
    }

    /**
     * Scope a query to records from last month.
     *
     * @param Builder $query
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeLastMonth(Builder $query, $column = 'date')
    {
        $lastMonth = Carbon::now()->subMonth();
        return $query->whereMonth($column, $lastMonth->month)
                    ->whereYear($column, $lastMonth->year);
    }

    /**
     * Scope a query to records from this quarter.
     *
     * @param Builder $query
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeThisQuarter(Builder $query, $column = 'date')
    {
        return $query->whereBetween($column, [
            Carbon::now()->firstOfQuarter(),
            Carbon::now()->lastOfQuarter()
        ]);
    }

    /**
     * Scope a query to records from this year.
     *
     * @param Builder $query
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeThisYear(Builder $query, $column = 'date')
    {
        return $query->whereYear($column, Carbon::now()->year);
    }

    /**
     * Scope a query to records from last year.
     *
     * @param Builder $query
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeLastYear(Builder $query, $column = 'date')
    {
        return $query->whereYear($column, Carbon::now()->subYear()->year);
    }

    /**
     * Scope a query to records from a specific year.
     *
     * @param Builder $query
     * @param int $year
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeByYear(Builder $query, $year, $column = 'date')
    {
        return $query->whereYear($column, $year);
    }

    /**
     * Scope a query to records from a specific month and year.
     *
     * @param Builder $query
     * @param int $month
     * @param int $year
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeByMonth(Builder $query, $month, $year, $column = 'date')
    {
        return $query->whereMonth($column, $month)
                    ->whereYear($column, $year);
    }

    /**
     * Scope a query to records between two dates (inclusive).
     *
     * @param Builder $query
     * @param string $startDate
     * @param string $endDate
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeBetweenDates(Builder $query, $startDate, $endDate, $column = 'date')
    {
        return $query->whereDate($column, '>=', $startDate)
                    ->whereDate($column, '<=', $endDate);
    }

    /**
     * Scope a query to records before a specific date.
     *
     * @param Builder $query
     * @param string $date
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeBeforeDate(Builder $query, $date, $column = 'date')
    {
        return $query->whereDate($column, '<', $date);
    }

    /**
     * Scope a query to records after a specific date.
     *
     * @param Builder $query
     * @param string $date
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeAfterDate(Builder $query, $date, $column = 'date')
    {
        return $query->whereDate($column, '>', $date);
    }

    /**
     * Scope a query to records from the last N days.
     *
     * @param Builder $query
     * @param int $days
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeLastDays(Builder $query, $days, $column = 'date')
    {
        return $query->where($column, '>=', Carbon::now()->subDays($days));
    }

    /**
     * Scope a query to records from the last N months.
     *
     * @param Builder $query
     * @param int $months
     * @param string $column The date column to filter on (default: 'date')
     * @return Builder
     */
    public function scopeLastMonths(Builder $query, $months, $column = 'date')
    {
        return $query->where($column, '>=', Carbon::now()->subMonths($months));
    }
}
