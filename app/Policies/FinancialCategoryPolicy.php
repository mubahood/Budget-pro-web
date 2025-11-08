<?php

namespace App\Policies;

use App\Models\FinancialCategory;
use App\Models\User;

class FinancialCategoryPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, FinancialCategory $financialCategory): bool
    {
        return $user->company_id === $financialCategory->company_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, FinancialCategory $financialCategory): bool
    {
        return $user->company_id === $financialCategory->company_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, FinancialCategory $financialCategory): bool
    {
        return $user->company_id === $financialCategory->company_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, FinancialCategory $financialCategory): bool
    {
        return $user->company_id === $financialCategory->company_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, FinancialCategory $financialCategory): bool
    {
        return $user->company_id === $financialCategory->company_id;
    }
}
