<?php

namespace App\Policies;

use App\Models\StockRecord;
use App\Models\User;

class StockRecordPolicy
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
    public function view(User $user, StockRecord $stockRecord): bool
    {
        return $user->company_id === $stockRecord->company_id;
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
    public function update(User $user, StockRecord $stockRecord): bool
    {
        return $user->company_id === $stockRecord->company_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, StockRecord $stockRecord): bool
    {
        return $user->company_id === $stockRecord->company_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, StockRecord $stockRecord): bool
    {
        return $user->company_id === $stockRecord->company_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, StockRecord $stockRecord): bool
    {
        return $user->company_id === $stockRecord->company_id;
    }
}
