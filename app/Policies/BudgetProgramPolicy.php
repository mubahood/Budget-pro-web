<?php

namespace App\Policies;

use App\Models\BudgetProgram;
use App\Models\User;

class BudgetProgramPolicy
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
    public function view(User $user, BudgetProgram $budgetProgram): bool
    {
        return $user->company_id === $budgetProgram->company_id;
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
    public function update(User $user, BudgetProgram $budgetProgram): bool
    {
        return $user->company_id === $budgetProgram->company_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, BudgetProgram $budgetProgram): bool
    {
        return $user->company_id === $budgetProgram->company_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, BudgetProgram $budgetProgram): bool
    {
        return $user->company_id === $budgetProgram->company_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, BudgetProgram $budgetProgram): bool
    {
        return $user->company_id === $budgetProgram->company_id;
    }
}
