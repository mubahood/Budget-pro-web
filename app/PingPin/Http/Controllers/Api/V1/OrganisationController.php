<?php

namespace App\PingPin\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyMember;
use App\PingPin\Services\OrganisationService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * The HTTP surface OrganisationService (Task 1.2) never had — see
 * DECISIONS.md D13 for why this replaced "namespace the device API" as
 * Task 1.7's actual content.
 */
class OrganisationController extends Controller
{
    use ApiResponse;

    public function __construct(private OrganisationService $organisations)
    {
    }

    /** Every organisation the current user is an active member of. */
    public function index(Request $r)
    {
        $memberships = CompanyMember::where('user_id', $r->user()->id)
            ->where('status', 'active')
            ->with('company:id,name,status')
            ->get(['id', 'company_id', 'role']);

        return $this->success($memberships->map(fn ($m) => [
            'company_id' => $m->company_id,
            'name' => $m->company?->name,
            'role' => $m->role,
        ]));
    }

    /** {company} is route-bound and membership-checked by the pingpin.member middleware. */
    public function inviteMember(Request $r, Company $company)
    {
        $data = $r->validate([
            'identifier' => 'required|string|max:191', // email or phone
            'role' => 'required|string|in:admin,member',
        ]);

        try {
            $membership = $this->organisations->invite($company, $r->user(), $data['identifier'], $data['role']);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(['membership_id' => $membership->id, 'status' => $membership->status], 'Invitation sent.');
    }

    /**
     * Deliberately NOT behind pingpin.member — accepting an invite is how
     * you become a member, so requiring membership first would be circular
     * (DECISIONS.md D13). Only auth:sanctum applies; authority to accept
     * THIS SPECIFIC invite is checked here against its own user_id/
     * invited_email/invited_phone, matching OrganisationService::acceptInvite's
     * own guard.
     */
    public function acceptInvite(Request $r, int $membershipId)
    {
        $membership = CompanyMember::find($membershipId);
        if (! $membership) {
            return $this->error('Invitation not found.', 404);
        }

        $user = $r->user();
        // A null-vs-null comparison must never count as a match — an invite
        // made by email has a null invited_phone, and plenty of accounts
        // have no phone_number on file either, so without these explicit
        // non-null guards two unrelated nulls would silently "match".
        $isForThisUser = $membership->user_id === null
            ? (($membership->invited_email !== null && $membership->invited_email === $user->email)
                || ($membership->invited_phone !== null && $membership->invited_phone === $user->phone_number))
            : (int) $membership->user_id === (int) $user->id;

        if (! $isForThisUser) {
            return $this->error('This invitation was not sent to your account.', 403);
        }

        try {
            $accepted = $this->organisations->acceptInvite($membership, $user);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(['company_id' => $accepted->company_id, 'role' => $accepted->role], 'Invitation accepted.');
    }

    public function revokeMember(Request $r, Company $company, int $userId)
    {
        try {
            $this->organisations->revokeMember($company, $r->user(), $userId);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(null, 'Member revoked.');
    }

    public function changeRole(Request $r, Company $company, int $userId)
    {
        $data = $r->validate(['role' => 'required|string|in:admin,member']);

        try {
            $membership = $this->organisations->changeRole($company, $r->user(), $userId, $data['role']);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(['user_id' => $membership->user_id, 'role' => $membership->role], 'Role updated.');
    }

    public function transferOwnership(Request $r, Company $company)
    {
        $data = $r->validate(['to_user_id' => 'required|integer']);

        try {
            $this->organisations->transferOwnership($company, $r->user(), (int) $data['to_user_id']);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(null, 'Ownership transferred.');
    }
}
