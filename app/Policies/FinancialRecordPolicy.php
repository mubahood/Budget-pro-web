<?php

namespace App\Policies;

use App\Models\FinancialRecord;
use App\Models\User;

class FinancialRecordPolicy
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
    public function view(User $user, FinancialRecord $financialRecord): bool
    {
        return $user->company_id === $financialRecord->company_id;
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
    public function update(User $user, FinancialRecord $financialRecord): bool
    {
        return $user->company_id === $financialRecord->company_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, FinancialRecord $financialRecord): bool
    {
        return $user->company_id === $financialRecord->company_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, FinancialRecord $financialRecord): bool
    {
        return $user->company_id === $financialRecord->company_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, FinancialRecord $financialRecord): bool
    {
        return $user->company_id === $financialRecord->company_id;
    }
}
