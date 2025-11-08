<?php

namespace App\Policies;

use App\Models\ContributionRecord;
use App\Models\User;

class ContributionRecordPolicy
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
    public function view(User $user, ContributionRecord $contributionRecord): bool
    {
        return $user->company_id === $contributionRecord->company_id;
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
    public function update(User $user, ContributionRecord $contributionRecord): bool
    {
        return $user->company_id === $contributionRecord->company_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ContributionRecord $contributionRecord): bool
    {
        return $user->company_id === $contributionRecord->company_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ContributionRecord $contributionRecord): bool
    {
        return $user->company_id === $contributionRecord->company_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ContributionRecord $contributionRecord): bool
    {
        return $user->company_id === $contributionRecord->company_id;
    }
}
