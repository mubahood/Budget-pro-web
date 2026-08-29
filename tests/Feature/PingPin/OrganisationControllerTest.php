<?php

namespace Tests\Feature\PingPin;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use Tests\Feature\Api\ApiTestCase;

class OrganisationControllerTest extends ApiTestCase
{
    public function test_index_lists_only_active_memberships(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);

        $revokedUser = User::create(['username' => 'revoked_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'Revoked']);
        CompanyMember::create(['company_id' => $company->id, 'user_id' => $revokedUser->id, 'role' => 'member', 'status' => 'revoked']);
        $revokedToken = $revokedUser->createToken('t')->plainTextToken;

        $res = $this->getJson('/api/v1/pingpin/organisations', $this->auth($t['token']));
        $res->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.role', 'owner');

        $revokedRes = $this->getJson('/api/v1/pingpin/organisations', $this->auth($revokedToken));
        $revokedRes->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_owner_can_invite_via_the_endpoint(): void
    {
        $t = $this->registerTenant();

        $res = $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/members/invite", [
            'identifier' => 'newmember@example.com', 'role' => 'member',
        ], $this->auth($t['token']));

        $res->assertOk()->assertJsonPath('data.status', 'invited');
        $this->assertDatabaseHas('company_members', ['company_id' => $t['company_id'], 'invited_email' => 'newmember@example.com', 'role' => 'member']);
    }

    public function test_non_member_cannot_invite_into_someone_elses_organisation(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();

        // a's token targeting b's organisation — pingpin.member must reject
        // this before OrganisationService ever runs.
        $res = $this->postJson("/api/v1/pingpin/organisations/{$b['company_id']}/members/invite", [
            'identifier' => 'someone@example.com', 'role' => 'member',
        ], $this->auth($a['token']));

        $res->assertStatus(403);
        $this->assertDatabaseMissing('company_members', ['company_id' => $b['company_id'], 'invited_email' => 'someone@example.com']);
    }

    public function test_invitee_can_accept_without_being_a_member_first(): void
    {
        $t = $this->registerTenant();
        $invitee = User::create(['username' => 'invitee_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'Invitee', 'email' => 'accept-ctrl@example.com']);
        $inviteeToken = $invitee->createToken('t')->plainTextToken;

        $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/members/invite", [
            'identifier' => 'accept-ctrl@example.com', 'role' => 'member',
        ], $this->auth($t['token']))->assertOk();

        $membershipId = CompanyMember::where('invited_email', 'accept-ctrl@example.com')->value('id');

        // Proves acceptInvite is genuinely NOT behind pingpin.member: the
        // invitee has no active membership anywhere yet, and still succeeds.
        $res = $this->postJson("/api/v1/pingpin/organisations/members/{$membershipId}/accept", [], $this->auth($inviteeToken));

        $res->assertOk()->assertJsonPath('data.role', 'member');
        $this->assertDatabaseHas('company_members', ['id' => $membershipId, 'status' => 'active', 'user_id' => $invitee->id]);
    }

    public function test_a_different_account_cannot_accept_someone_elses_invite_via_the_endpoint(): void
    {
        $t = $this->registerTenant();
        $wrongUser = User::create(['username' => 'wrong_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'Wrong']);
        $wrongToken = $wrongUser->createToken('t')->plainTextToken;

        $this->postJson("/api/v1/pingpin/organisations/{$t['company_id']}/members/invite", [
            'identifier' => 'targeted@example.com', 'role' => 'member',
        ], $this->auth($t['token']))->assertOk();

        $membershipId = CompanyMember::where('invited_email', 'targeted@example.com')->value('id');

        $this->postJson("/api/v1/pingpin/organisations/members/{$membershipId}/accept", [], $this->auth($wrongToken))
            ->assertStatus(403);
    }

    public function test_owner_can_revoke_change_role_and_transfer_ownership_via_the_endpoints(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $member = User::create(['username' => 'm_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'Member']);
        CompanyMember::create(['company_id' => $company->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);

        $this->postJson("/api/v1/pingpin/organisations/{$company->id}/members/{$member->id}/role", ['role' => 'admin'], $this->auth($t['token']))
            ->assertOk()->assertJsonPath('data.role', 'admin');

        $this->postJson("/api/v1/pingpin/organisations/{$company->id}/transfer-ownership", ['to_user_id' => $member->id], $this->auth($t['token']))
            ->assertOk();
        $this->assertDatabaseHas('company_members', ['company_id' => $company->id, 'user_id' => $member->id, 'role' => 'owner']);
        $this->assertDatabaseHas('company_members', ['company_id' => $company->id, 'user_id' => $t['user_id'], 'role' => 'admin']);

        // Original owner is now 'admin', not 'owner' — still allowed to revoke members.
        $another = User::create(['username' => 'a_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'Another']);
        CompanyMember::create(['company_id' => $company->id, 'user_id' => $another->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);
        $this->postJson("/api/v1/pingpin/organisations/{$company->id}/members/{$another->id}/revoke", [], $this->auth($t['token']))
            ->assertOk();
        $this->assertDatabaseHas('company_members', ['company_id' => $company->id, 'user_id' => $another->id, 'status' => 'revoked']);
    }

    public function test_cross_tenant_revoke_and_role_change_are_rejected(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();
        $bMember = User::create(['username' => 'bm_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'BMember']);
        CompanyMember::create(['company_id' => $b['company_id'], 'user_id' => $bMember->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);

        $this->postJson("/api/v1/pingpin/organisations/{$b['company_id']}/members/{$bMember->id}/revoke", [], $this->auth($a['token']))
            ->assertStatus(403);
        $this->postJson("/api/v1/pingpin/organisations/{$b['company_id']}/members/{$bMember->id}/role", ['role' => 'admin'], $this->auth($a['token']))
            ->assertStatus(403);
        $this->postJson("/api/v1/pingpin/organisations/{$b['company_id']}/transfer-ownership", ['to_user_id' => $bMember->id], $this->auth($a['token']))
            ->assertStatus(403);

        $this->assertDatabaseHas('company_members', ['company_id' => $b['company_id'], 'user_id' => $bMember->id, 'status' => 'active', 'role' => 'member']);
    }
}
