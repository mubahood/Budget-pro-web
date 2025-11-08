<?php

namespace App\Policies;

use App\Models\FinancialPeriod;
use App\Models\User;

class FinancialPeriodPolicy
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
    public function view(User $user, FinancialPeriod $financialPeriod): bool
    {
        return $user->company_id === $financialPeriod->company_id;
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
    public function update(User $user, FinancialPeriod $financialPeriod): bool
    {
        return $user->company_id === $financialPeriod->company_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, FinancialPeriod $financialPeriod): bool
    {
        return $user->company_id === $financialPeriod->company_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, FinancialPeriod $financialPeriod): bool
    {
        return $user->company_id === $financialPeriod->company_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, FinancialPeriod $financialPeriod): bool
    {
        return $user->company_id === $financialPeriod->company_id;
    }
}
