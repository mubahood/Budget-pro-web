<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Trait HasStockScopes
 * 
 * Provides stock-specific query scopes for models.
 * Models using this trait should have stock-related columns.
 */
trait HasStockScopes
{
    /**
     * Scope a query to items with low stock (below reorder level).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeLowStock(Builder $query)
    {
        return $query->whereColumn('current_quantity', '<', 'reorder_level')
                    ->where('current_quantity', '>', 0);
    }

    /**
     * Scope a query to items that are out of stock.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOutOfStock(Builder $query)
    {
        return $query->where('current_quantity', '<=', 0);
    }

    /**
     * Scope a query to items that are in stock.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeInStock(Builder $query)
    {
        return $query->where('current_quantity', '>', 0);
    }

    /**
     * Scope a query to items with sufficient stock (above reorder level).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSufficientStock(Builder $query)
    {
        return $query->whereColumn('current_quantity', '>=', 'reorder_level');
    }

    /**
     * Scope a query to filter by stock category.
     *
     * @param Builder $query
     * @param int $categoryId
     * @return Builder
     */
    public function scopeByCategory(Builder $query, $categoryId)
    {
        return $query->where('stock_category_id', $categoryId);
    }

    /**
     * Scope a query to filter by stock sub-category.
     *
     * @param Builder $query
     * @param int $subCategoryId
     * @return Builder
     */
    public function scopeBySubCategory(Builder $query, $subCategoryId)
    {
        return $query->where('stock_sub_category_id', $subCategoryId);
    }

    /**
     * Scope a query to items with quantity greater than a value.
     *
     * @param Builder $query
     * @param int $quantity
     * @return Builder
     */
    public function scopeQuantityGreaterThan(Builder $query, $quantity)
    {
        return $query->where('current_quantity', '>', $quantity);
    }

    /**
     * Scope a query to items with quantity less than a value.
     *
     * @param Builder $query
     * @param int $quantity
     * @return Builder
     */
    public function scopeQuantityLessThan(Builder $query, $quantity)
    {
        return $query->where('current_quantity', '<', $quantity);
    }

    /**
     * Scope a query to items with quantity between two values.
     *
     * @param Builder $query
     * @param int $min
     * @param int $max
     * @return Builder
     */
    public function scopeQuantityBetween(Builder $query, $min, $max)
    {
        return $query->whereBetween('current_quantity', [$min, $max]);
    }

    /**
     * Scope a query to stock records by transaction type.
     *
     * @param Builder $query
     * @param string $type
     * @return Builder
     */
    public function scopeByType(Builder $query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to stock-in records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeStockIn(Builder $query)
    {
        return $query->where('type', 'Stock In');
    }

    /**
     * Scope a query to stock-out records (Sales + Stock Out).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeStockOut(Builder $query)
    {
        return $query->whereIn('type', ['Sale', 'Stock Out']);
    }

    /**
     * Scope a query to sales records only.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSales(Builder $query)
    {
        return $query->where('type', 'Sale');
    }

    /**
     * Scope a query to items with profit greater than a value.
     *
     * @param Builder $query
     * @param float $amount
     * @return Builder
     */
    public function scopeProfitGreaterThan(Builder $query, $amount)
    {
        return $query->where('profit', '>', $amount);
    }

    /**
     * Scope a query to items with profit less than a value.
     *
     * @param Builder $query
     * @param float $amount
     * @return Builder
     */
    public function scopeProfitLessThan(Builder $query, $amount)
    {
        return $query->where('profit', '<', $amount);
    }

    /**
     * Scope a query to profitable items (profit > 0).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeProfitable(Builder $query)
    {
        return $query->where('profit', '>', 0);
    }

    /**
     * Scope a query to items with loss (profit < 0).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeWithLoss(Builder $query)
    {
        return $query->where('profit', '<', 0);
    }

    /**
     * Scope a query to filter by stock item.
     *
     * @param Builder $query
     * @param int $itemId
     * @return Builder
     */
    public function scopeByStockItem(Builder $query, $itemId)
    {
        return $query->where('stock_item_id', $itemId);
    }
}
