<?php

namespace Tests\Feature\PingPin;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Api\ApiTestCase;

/**
 * The mandatory "fails closed" proof for EnsurePingPinMembership
 * (TASKS.md 1.3/1.4, DECISIONS.md D4) — every non-active-membership outcome
 * must be an explicit denial, never a silent pass-through.
 */
class EnsurePingPinMembershipTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ad-hoc routes for isolating the middleware, independent of any
        // real Ping Pin feature endpoint — one with a {company} route
        // binding, one relying purely on the X-Organisation-Id header.
        Route::middleware(['api', 'auth:sanctum', 'pingpin.member'])->group(function () {
            Route::get('/__test/pingpin/org/{company}', function () {
                return response()->json([
                    'company_id' => request()->attributes->get('company')?->id,
                    'membership_role' => request()->attributes->get('membership')?->role,
                ]);
            });
            Route::get('/__test/pingpin/org-by-header', function () {
                return response()->json([
                    'company_id' => request()->attributes->get('company')?->id,
                ]);
            });
        });
    }

    public function test_no_token_is_rejected(): void
    {
        $t = $this->registerTenant();
        $this->getJson("/__test/pingpin/org/{$t['company_id']}")->assertStatus(401);
    }

    public function test_nonexistent_organisation_is_rejected(): void
    {
        $t = $this->registerTenant();
        $this->getJson('/__test/pingpin/org/999999999', $this->auth($t['token']))->assertStatus(404);
    }

    public function test_user_with_no_membership_row_is_rejected(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();

        // a's token, b's organisation — a has no company_members row for b at all.
        $this->getJson("/__test/pingpin/org/{$b['company_id']}", $this->auth($a['token']))->assertStatus(403);
    }

    public function test_revoked_membership_is_rejected(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $outsider = User::create(['username' => 'revoked_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'Revoked']);
        CompanyMember::create(['company_id' => $company->id, 'user_id' => $outsider->id, 'role' => 'member', 'status' => 'revoked']);
        $token = $outsider->createToken('test')->plainTextToken;

        $this->getJson("/__test/pingpin/org/{$company->id}", $this->auth($token))->assertStatus(403);
    }

    public function test_invited_but_not_yet_accepted_membership_is_rejected(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $invitee = User::create(['username' => 'invited_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'Invited']);
        CompanyMember::create(['company_id' => $company->id, 'user_id' => $invitee->id, 'role' => 'member', 'status' => 'invited']);
        $token = $invitee->createToken('test')->plainTextToken;

        $this->getJson("/__test/pingpin/org/{$company->id}", $this->auth($token))->assertStatus(403);
    }

    public function test_active_member_is_allowed_and_attributes_are_populated(): void
    {
        $t = $this->registerTenant();

        $res = $this->getJson("/__test/pingpin/org/{$t['company_id']}", $this->auth($t['token']));

        $res->assertOk()
            ->assertJsonPath('company_id', $t['company_id'])
            ->assertJsonPath('membership_role', 'owner');
    }

    public function test_x_organisation_id_header_resolves_the_company_when_no_route_binding_exists(): void
    {
        $t = $this->registerTenant();

        $res = $this->getJson('/__test/pingpin/org-by-header', [
            ...$this->auth($t['token']),
            'X-Organisation-Id' => (string) $t['company_id'],
        ]);

        $res->assertOk()->assertJsonPath('company_id', $t['company_id']);
    }

    public function test_missing_organisation_identifier_entirely_is_rejected(): void
    {
        $t = $this->registerTenant();
        $this->getJson('/__test/pingpin/org-by-header', $this->auth($t['token']))->assertStatus(404);
    }
}
