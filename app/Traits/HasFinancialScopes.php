<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Trait HasFinancialScopes
 *
 * Provides financial-specific query scopes for models.
 * Models using this trait should have 'type', 'amount' columns.
 */
trait HasFinancialScopes
{
    /**
     * Scope a query to only include income records.
     *
     * @param  string  $column  The type column to filter on (default: 'type')
     * @return Builder
     */
    public function scopeIncome(Builder $query, $column = 'type')
    {
        return $query->where($column, 'Income');
    }

    /**
     * Scope a query to only include expense records.
     *
     * @param  string  $column  The type column to filter on (default: 'type')
     * @return Builder
     */
    public function scopeExpense(Builder $query, $column = 'type')
    {
        return $query->where($column, 'Expense');
    }

    /**
     * Scope a query to filter by transaction type.
     *
     * @param  string  $type
     * @param  string  $column  The type column to filter on (default: 'type')
     * @return Builder
     */
    public function scopeByType(Builder $query, $type, $column = 'type')
    {
        return $query->where($column, $type);
    }

    /**
     * Scope a query to records with amount greater than a value.
     *
     * @param  float  $amount
     * @param  string  $column  The amount column to filter on (default: 'amount')
     * @return Builder
     */
    public function scopeAmountGreaterThan(Builder $query, $amount, $column = 'amount')
    {
        return $query->where($column, '>', $amount);
    }

    /**
     * Scope a query to records with amount less than a value.
     *
     * @param  float  $amount
     * @param  string  $column  The amount column to filter on (default: 'amount')
     * @return Builder
     */
    public function scopeAmountLessThan(Builder $query, $amount, $column = 'amount')
    {
        return $query->where($column, '<', $amount);
    }

    /**
     * Scope a query to records with amount between two values.
     *
     * @param  float  $min
     * @param  float  $max
     * @param  string  $column  The amount column to filter on (default: 'amount')
     * @return Builder
     */
    public function scopeAmountBetween(Builder $query, $min, $max, $column = 'amount')
    {
        return $query->whereBetween($column, [$min, $max]);
    }

    /**
     * Scope a query to filter by payment method.
     *
     * @param  string  $method
     * @return Builder
     */
    public function scopeByPaymentMethod(Builder $query, $method)
    {
        return $query->where('payment_method', $method);
    }

    /**
     * Scope a query to cash transactions only.
     *
     * @return Builder
     */
    public function scopeCashOnly(Builder $query)
    {
        return $query->where('payment_method', 'Cash');
    }

    /**
     * Scope a query to mobile money transactions only.
     *
     * @return Builder
     */
    public function scopeMobileMoneyOnly(Builder $query)
    {
        return $query->whereIn('payment_method', ['Mobile Money', 'MobileMoney', 'M-Pesa', 'MTN', 'Airtel']);
    }

    /**
     * Scope a query to bank transactions only.
     *
     * @return Builder
     */
    public function scopeBankOnly(Builder $query)
    {
        return $query->whereIn('payment_method', ['Bank', 'Bank Transfer', 'Cheque']);
    }

    /**
     * Scope a query to filter by financial category.
     *
     * @param  int  $categoryId
     * @return Builder
     */
    public function scopeByCategory(Builder $query, $categoryId)
    {
        return $query->where('financial_category_id', $categoryId);
    }

    /**
     * Scope a query to filter by financial period.
     *
     * @param  int  $periodId
     * @return Builder
     */
    public function scopeByPeriod(Builder $query, $periodId)
    {
        return $query->where('financial_period_id', $periodId);
    }

    /**
     * Scope a query to get total income.
     *
     * @return float
     */
    public function scopeTotalIncome(Builder $query)
    {
        return $query->where('type', 'Income')->sum('amount');
    }

    /**
     * Scope a query to get total expenses.
     *
     * @return float
     */
    public function scopeTotalExpense(Builder $query)
    {
        return $query->where('type', 'Expense')->sum('amount');
    }
}
