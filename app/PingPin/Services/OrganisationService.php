<?php

namespace App\PingPin\Services;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Owns every mutation to organisation membership (PLAN.md §2, TASKS.md 1.2).
 * Every method that changes state runs inside a transaction and re-checks
 * the actor's authority under a row lock — the same idempotent-under-
 * concurrency discipline as FlutterwaveService::fulfill(), just for
 * membership instead of payments.
 */
class OrganisationService
{
    /**
     * Invites someone by email or phone. If they already have an
     * admin_users account it's linked immediately (still status 'invited'
     * until they explicitly accept); if not, user_id stays null until
     * acceptIntive() links it after they sign up.
     */
    public function invite(Company $company, User $actor, string $emailOrPhone, string $role): CompanyMember
    {
        $this->assertCanManageMembers($company, $actor);
        $this->assertInvitableRole($role);

        return DB::transaction(function () use ($company, $actor, $emailOrPhone, $role) {
            $isEmail = str_contains($emailOrPhone, '@');

            $duplicate = CompanyMember::where('company_id', $company->id)
                ->whereIn('status', ['active', 'invited'])
                ->where(fn ($q) => $isEmail
                    ? $q->where('invited_email', $emailOrPhone)
                    : $q->where('invited_phone', $emailOrPhone))
                ->exists();
            if ($duplicate) {
                throw new \RuntimeException('This person already has an active or pending membership.');
            }

            $existingUser = $isEmail
                ? User::where('email', $emailOrPhone)->first()
                : User::where('phone_number', $emailOrPhone)->first();

            if ($existingUser) {
                $alreadyMember = CompanyMember::where('company_id', $company->id)
                    ->where('user_id', $existingUser->id)
                    ->whereIn('status', ['active', 'invited'])
                    ->exists();
                if ($alreadyMember) {
                    throw new \RuntimeException('This person already has an active or pending membership.');
                }
            }

            return CompanyMember::create([
                'company_id' => $company->id,
                'user_id' => $existingUser?->id,
                'role' => $role,
                'invited_by_id' => $actor->id,
                'invited_email' => $isEmail ? $emailOrPhone : null,
                'invited_phone' => $isEmail ? null : $emailOrPhone,
                'status' => 'invited',
            ]);
        });
    }

    /**
     * The invited account (which must now exist — sign up first if it
     * didn't at invite time) accepts. If the invite already had a user_id
     * bound, only that exact account may accept it.
     */
    public function acceptInvite(CompanyMember $membership, User $acceptingUser): CompanyMember
    {
        return DB::transaction(function () use ($membership, $acceptingUser) {
            $locked = CompanyMember::whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'invited') {
                throw new \RuntimeException('This invitation is no longer pending.');
            }
            if ($locked->user_id !== null && (int) $locked->user_id !== (int) $acceptingUser->id) {
                throw new \RuntimeException('This invitation was not sent to this account.');
            }

            $locked->user_id = $acceptingUser->id;
            $locked->status = 'active';
            $locked->joined_at = now();
            $locked->save();

            return $locked;
        });
    }

    public function revokeMember(Company $company, User $actor, int $targetUserId): void
    {
        $this->assertCanManageMembers($company, $actor);

        DB::transaction(function () use ($company, $targetUserId) {
            $member = CompanyMember::where('company_id', $company->id)
                ->where('user_id', $targetUserId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($member->role === 'owner') {
                throw new \RuntimeException('The owner cannot be revoked — transfer ownership first.');
            }

            $member->status = 'revoked';
            $member->save();
        });
    }

    public function changeRole(Company $company, User $actor, int $targetUserId, string $role): CompanyMember
    {
        $this->assertCanManageMembers($company, $actor);
        $this->assertInvitableRole($role);

        return DB::transaction(function () use ($company, $targetUserId, $role) {
            $member = CompanyMember::where('company_id', $company->id)
                ->where('user_id', $targetUserId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($member->role === 'owner') {
                throw new \RuntimeException("The owner's role can't be changed directly — transfer ownership first.");
            }
            if (! $member->isActive()) {
                throw new \RuntimeException('Only active members can have their role changed.');
            }

            $member->role = $role;
            $member->save();

            return $member;
        });
    }

    /**
     * The current owner steps down to 'admin'; the target (who must already
     * be an active member) becomes 'owner'. Also updates companies.owner_id
     * so the legacy relation stays consistent — this fires Company::boot()'s
     * updated hook, which syncs the new owner's admin_users.company_id.
     */
    public function transferOwnership(Company $company, User $actor, int $toUserId): void
    {
        DB::transaction(function () use ($company, $actor, $toUserId) {
            $currentOwner = CompanyMember::where('company_id', $company->id)
                ->where('role', 'owner')
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $currentOwner->user_id !== (int) $actor->id) {
                throw new \RuntimeException('Only the current owner can transfer ownership.');
            }

            $newOwner = CompanyMember::where('company_id', $company->id)
                ->where('user_id', $toUserId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $newOwner) {
                throw new \RuntimeException('The new owner must already be an active member of this organisation.');
            }

            $currentOwner->role = 'admin';
            $currentOwner->save();

            $newOwner->role = 'owner';
            $newOwner->save();

            $company->owner_id = $toUserId;
            $company->save();
        });
    }

    private function assertCanManageMembers(Company $company, User $actor): void
    {
        $membership = CompanyMember::where('company_id', $company->id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->first();

        if (! $membership || ! $membership->isOwnerOrAdmin()) {
            throw new \RuntimeException('Only an owner or admin can manage organisation members.');
        }
    }

    private function assertInvitableRole(string $role): void
    {
        if (! in_array($role, ['admin', 'member'], true)) {
            throw new \RuntimeException("Invalid role: {$role}. Ownership is changed via transferOwnership(), not invite/changeRole.");
        }
    }
}
