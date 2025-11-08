<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Trait HasBudgetScopes
 * 
 * Provides budget-specific query scopes for models.
 * Models using this trait should have budget-related columns.
 */
trait HasBudgetScopes
{
    /**
     * Scope a query to filter by budget program.
     *
     * @param Builder $query
     * @param int $programId
     * @return Builder
     */
    public function scopeByProgram(Builder $query, $programId)
    {
        return $query->where('budget_program_id', $programId);
    }

    /**
     * Scope a query to filter by budget item category.
     *
     * @param Builder $query
     * @param int $categoryId
     * @return Builder
     */
    public function scopeByCategory(Builder $query, $categoryId)
    {
        return $query->where('budget_item_category_id', $categoryId);
    }

    /**
     * Scope a query to items that are over budget.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOverBudget(Builder $query)
    {
        return $query->whereColumn('spent_amount', '>', 'target_amount');
    }

    /**
     * Scope a query to items that are under budget.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeUnderBudget(Builder $query)
    {
        return $query->whereColumn('spent_amount', '<', 'target_amount');
    }

    /**
     * Scope a query to items at exactly their budget.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeAtBudget(Builder $query)
    {
        return $query->whereColumn('spent_amount', '=', 'target_amount');
    }

    /**
     * Scope a query to items within budget (spent <= target).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeWithinBudget(Builder $query)
    {
        return $query->whereColumn('spent_amount', '<=', 'target_amount');
    }

    /**
     * Scope a query to pending approval items.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePending(Builder $query)
    {
        return $query->where('status', 'Pending');
    }

    /**
     * Scope a query to approved items.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApproved(Builder $query)
    {
        return $query->where('status', 'Approved');
    }

    /**
     * Scope a query to rejected items.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeRejected(Builder $query)
    {
        return $query->where('status', 'Rejected');
    }

    /**
     * Scope a query to fully paid contributions.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeFullyPaid(Builder $query)
    {
        return $query->where('fully_paid', 'Yes');
    }

    /**
     * Scope a query to not fully paid contributions.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeNotFullyPaid(Builder $query)
    {
        return $query->where('fully_paid', 'No');
    }

    /**
     * Scope a query to contributions with outstanding balance.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeWithBalance(Builder $query)
    {
        return $query->where('not_paid_amount', '>', 0);
    }

    /**
     * Scope a query to filter by treasurer.
     *
     * @param Builder $query
     * @param int $treasurerId
     * @return Builder
     */
    public function scopeByTreasurer(Builder $query, $treasurerId)
    {
        return $query->where('treasurer_id', $treasurerId);
    }

    /**
     * Scope a query to items with target amount greater than a value.
     *
     * @param Builder $query
     * @param float $amount
     * @return Builder
     */
    public function scopeTargetGreaterThan(Builder $query, $amount)
    {
        return $query->where('target_amount', '>', $amount);
    }

    /**
     * Scope a query to items with target amount less than a value.
     *
     * @param Builder $query
     * @param float $amount
     * @return Builder
     */
    public function scopeTargetLessThan(Builder $query, $amount)
    {
        return $query->where('target_amount', '<', $amount);
    }

    /**
     * Scope a query to items with spent amount greater than a value.
     *
     * @param Builder $query
     * @param float $amount
     * @return Builder
     */
    public function scopeSpentGreaterThan(Builder $query, $amount)
    {
        return $query->where('spent_amount', '>', $amount);
    }

    /**
     * Scope a query to items with spent amount less than a value.
     *
     * @param Builder $query
     * @param float $amount
     * @return Builder
     */
    public function scopeSpentLessThan(Builder $query, $amount)
    {
        return $query->where('spent_amount', '<', $amount);
    }

    /**
     * Scope a query to items with budget utilization percentage greater than a value.
     *
     * @param Builder $query
     * @param float $percentage (0-100)
     * @return Builder
     */
    public function scopeUtilizationGreaterThan(Builder $query, $percentage)
    {
        return $query->whereRaw('(spent_amount / target_amount * 100) > ?', [$percentage]);
    }

    /**
     * Scope a query to items with budget utilization percentage less than a value.
     *
     * @param Builder $query
     * @param float $percentage (0-100)
     * @return Builder
     */
    public function scopeUtilizationLessThan(Builder $query, $percentage)
    {
        return $query->whereRaw('(spent_amount / target_amount * 100) < ?', [$percentage]);
    }
}
