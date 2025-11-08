<?php

namespace App\Policies;

use App\Models\StockCategory;
use App\Models\User;

class StockCategoryPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view categories from their company
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, StockCategory $stockCategory): bool
    {
        // Users can only view categories from their own company
        return $user->company_id === $stockCategory->company_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // All authenticated users can create categories
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, StockCategory $stockCategory): bool
    {
        // Users can only update categories from their own company
        return $user->company_id === $stockCategory->company_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, StockCategory $stockCategory): bool
    {
        // Users can only delete categories from their own company
        return $user->company_id === $stockCategory->company_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, StockCategory $stockCategory): bool
    {
        // Users can only restore categories from their own company
        return $user->company_id === $stockCategory->company_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, StockCategory $stockCategory): bool
    {
        // Users can only force delete categories from their own company
        return $user->company_id === $stockCategory->company_id;
    }
}
