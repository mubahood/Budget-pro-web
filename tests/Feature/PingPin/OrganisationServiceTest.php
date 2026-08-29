<?php

namespace Tests\Feature\PingPin;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\PingPin\Services\OrganisationService;
use Tests\Feature\Api\ApiTestCase;

class OrganisationServiceTest extends ApiTestCase
{
    private OrganisationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrganisationService();
    }

    private function makeMember(Company $company, string $role, string $status = 'active'): User
    {
        $user = User::create([
            'username' => 'member_'.uniqid('', true),
            'password' => bcrypt('secret123'),
            'name' => 'Test Member',
            'email' => uniqid('member_', true).'@example.com',
        ]);
        CompanyMember::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => $status,
            'joined_at' => now(),
        ]);

        return $user;
    }

    public function test_owner_can_invite_a_new_member_by_email(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $owner = User::find($t['user_id']);

        $membership = $this->service->invite($company, $owner, 'invitee@example.com', 'member');

        $this->assertSame('invited', $membership->status);
        $this->assertSame('member', $membership->role);
        $this->assertNull($membership->user_id);
        $this->assertSame('invitee@example.com', $membership->invited_email);
    }

    public function test_invite_links_user_id_immediately_if_the_account_already_exists(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $owner = User::find($t['user_id']);

        $existing = User::create([
            'username' => 'existing_'.uniqid('', true),
            'password' => bcrypt('secret123'),
            'name' => 'Existing User',
            'email' => 'existing@example.com',
        ]);

        $membership = $this->service->invite($company, $owner, 'existing@example.com', 'admin');

        $this->assertSame($existing->id, $membership->user_id);
        $this->assertSame('invited', $membership->status); // still requires explicit acceptance
    }

    public function test_non_member_cannot_invite(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $outsider = User::create(['username' => 'outsider_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'Outsider']);

        $this->expectException(\RuntimeException::class);
        $this->service->invite($company, $outsider, 'someone@example.com', 'member');
    }

    public function test_plain_member_cannot_invite(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $plainMember = $this->makeMember($company, 'member');

        $this->expectException(\RuntimeException::class);
        $this->service->invite($company, $plainMember, 'someone@example.com', 'member');
    }

    public function test_cannot_double_invite_the_same_email(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $owner = User::find($t['user_id']);

        $this->service->invite($company, $owner, 'dup@example.com', 'member');

        $this->expectException(\RuntimeException::class);
        $this->service->invite($company, $owner, 'dup@example.com', 'admin');
    }

    public function test_cannot_invite_directly_as_owner(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $owner = User::find($t['user_id']);

        $this->expectException(\RuntimeException::class);
        $this->service->invite($company, $owner, 'someone@example.com', 'owner');
    }

    public function test_accept_invite_activates_membership(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $owner = User::find($t['user_id']);
        $invitee = User::create(['username' => 'invitee_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'Invitee', 'email' => 'accept@example.com']);

        $membership = $this->service->invite($company, $owner, 'accept@example.com', 'member');
        $accepted = $this->service->acceptInvite($membership, $invitee);

        $this->assertSame('active', $accepted->status);
        $this->assertSame($invitee->id, $accepted->user_id);
        $this->assertNotNull($accepted->joined_at);
    }

    public function test_a_different_account_cannot_accept_someone_elses_invite(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $owner = User::find($t['user_id']);
        $targetUser = User::create(['username' => 'target_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'Target', 'email' => 'target@example.com']);
        $wrongUser = User::create(['username' => 'wrong_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'Wrong']);

        $membership = $this->service->invite($company, $owner, 'target@example.com', 'member');

        $this->expectException(\RuntimeException::class);
        $this->service->acceptInvite($membership, $wrongUser);
    }

    public function test_already_accepted_invite_cannot_be_accepted_again(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $owner = User::find($t['user_id']);
        $invitee = User::create(['username' => 'invitee2_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'Invitee', 'email' => 'accept2@example.com']);

        $membership = $this->service->invite($company, $owner, 'accept2@example.com', 'member');
        $this->service->acceptInvite($membership, $invitee);

        $this->expectException(\RuntimeException::class);
        $this->service->acceptInvite($membership->fresh(), $invitee);
    }

    public function test_owner_can_revoke_a_member(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $owner = User::find($t['user_id']);
        $member = $this->makeMember($company, 'member');

        $this->service->revokeMember($company, $owner, $member->id);

        $this->assertSame('revoked', CompanyMember::where('company_id', $company->id)->where('user_id', $member->id)->first()->status);
    }

    public function test_owner_cannot_be_revoked(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $owner = User::find($t['user_id']);

        $this->expectException(\RuntimeException::class);
        $this->service->revokeMember($company, $owner, $owner->id);
    }

    public function test_owner_can_change_a_members_role(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $owner = User::find($t['user_id']);
        $member = $this->makeMember($company, 'member');

        $updated = $this->service->changeRole($company, $owner, $member->id, 'admin');

        $this->assertSame('admin', $updated->role);
    }

    public function test_owners_role_cannot_be_changed_via_change_role(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $owner = User::find($t['user_id']);

        $this->expectException(\RuntimeException::class);
        $this->service->changeRole($company, $owner, $owner->id, 'admin');
    }

    public function test_transfer_ownership_swaps_roles_and_updates_company_owner_id(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $owner = User::find($t['user_id']);
        $admin = $this->makeMember($company, 'admin');

        $this->service->transferOwnership($company, $owner, $admin->id);

        $company->refresh();
        $this->assertSame($admin->id, $company->owner_id);
        $this->assertSame('owner', CompanyMember::where('company_id', $company->id)->where('user_id', $admin->id)->first()->role);
        $this->assertSame('admin', CompanyMember::where('company_id', $company->id)->where('user_id', $owner->id)->first()->role);
    }

    public function test_only_the_current_owner_can_transfer_ownership(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $admin = $this->makeMember($company, 'admin');
        $anotherAdmin = $this->makeMember($company, 'admin');

        $this->expectException(\RuntimeException::class);
        $this->service->transferOwnership($company, $admin, $anotherAdmin->id);
    }

    public function test_ownership_cannot_transfer_to_a_non_member(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $owner = User::find($t['user_id']);
        $outsider = User::create(['username' => 'outsider2_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'Outsider']);

        $this->expectException(\RuntimeException::class);
        $this->service->transferOwnership($company, $owner, $outsider->id);
    }
}
