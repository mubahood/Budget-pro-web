<?php

namespace Tests\Feature\PingPin;

use App\Models\CompanyMember;
use App\Models\PingPinPlan;
use App\Models\PingPinSubscription;
use App\Models\User;
use Tests\Feature\Api\ApiTestCase;

class AuthControllerTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PingPinPlanSeeder::class);
    }

    public function test_register_with_email_creates_user_organisation_membership_and_trial(): void
    {
        $res = $this->postJson('/api/v1/pingpin/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane_'.uniqid('', true).'@example.com',
            'password' => 'secret123',
        ]);

        $res->assertStatus(201)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name'], 'company' => ['id', 'name']]]);

        $userId = $res->json('data.user.id');
        $companyId = $res->json('data.company.id');

        $this->assertDatabaseHas('company_members', ['company_id' => $companyId, 'user_id' => $userId, 'role' => 'owner', 'status' => 'active']);

        $subscription = PingPinSubscription::where('company_id', $companyId)->first();
        $this->assertNotNull($subscription);
        $this->assertSame('trialing', $subscription->status);
        $this->assertSame(PingPinPlan::where('slug', 'trial')->value('id'), $subscription->plan_id);
        $this->assertTrue($subscription->isActive());
    }

    public function test_register_with_phone_number_only_works(): void
    {
        $res = $this->postJson('/api/v1/pingpin/auth/register', [
            'name' => 'Phone User',
            'phone_number' => '+256700'.rand(100000, 999999),
            'password' => 'secret123',
        ]);

        $res->assertStatus(201);
    }

    public function test_register_requires_email_or_phone(): void
    {
        $this->postJson('/api/v1/pingpin/auth/register', ['name' => 'No Contact', 'password' => 'secret123'])
            ->assertStatus(422);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $email = 'dup_'.uniqid('', true).'@example.com';
        $this->postJson('/api/v1/pingpin/auth/register', ['name' => 'First', 'email' => $email, 'password' => 'secret123'])->assertStatus(201);
        $this->postJson('/api/v1/pingpin/auth/register', ['name' => 'Second', 'email' => $email, 'password' => 'secret123'])->assertStatus(422);
    }

    public function test_register_rejects_duplicate_phone(): void
    {
        $phone = '+256700'.rand(100000, 999999);
        $this->postJson('/api/v1/pingpin/auth/register', ['name' => 'First', 'phone_number' => $phone, 'password' => 'secret123'])->assertStatus(201);
        $this->postJson('/api/v1/pingpin/auth/register', ['name' => 'Second', 'phone_number' => $phone, 'password' => 'secret123'])->assertStatus(422);
    }

    public function test_login_with_email_succeeds(): void
    {
        $email = 'login_'.uniqid('', true).'@example.com';
        $this->postJson('/api/v1/pingpin/auth/register', ['name' => 'Login User', 'email' => $email, 'password' => 'secret123'])->assertStatus(201);

        $res = $this->postJson('/api/v1/pingpin/auth/login', ['identifier' => $email, 'password' => 'secret123']);
        $res->assertOk()->assertJsonStructure(['data' => ['token', 'user', 'company']]);
    }

    public function test_login_with_phone_succeeds(): void
    {
        $phone = '+256700'.rand(100000, 999999);
        $this->postJson('/api/v1/pingpin/auth/register', ['name' => 'Login Phone', 'phone_number' => $phone, 'password' => 'secret123'])->assertStatus(201);

        $this->postJson('/api/v1/pingpin/auth/login', ['identifier' => $phone, 'password' => 'secret123'])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'user', 'company']]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $email = 'wrongpass_'.uniqid('', true).'@example.com';
        $this->postJson('/api/v1/pingpin/auth/register', ['name' => 'X', 'email' => $email, 'password' => 'secret123'])->assertStatus(201);

        $this->postJson('/api/v1/pingpin/auth/login', ['identifier' => $email, 'password' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_login_rejects_unknown_identifier(): void
    {
        $this->postJson('/api/v1/pingpin/auth/login', ['identifier' => 'nobody@example.com', 'password' => 'x'])
            ->assertStatus(401);
    }

    public function test_login_prefers_owned_organisation_over_an_invited_one(): void
    {
        $owner = $this->postJson('/api/v1/pingpin/auth/register', [
            'name' => 'Multi Org', 'email' => 'multi_'.uniqid('', true).'@example.com', 'password' => 'secret123',
        ]);
        $ownCompanyId = $owner->json('data.company.id');
        $userId = $owner->json('data.user.id');

        // Also an active (invited-and-accepted) member of a different org.
        // owner_id isn't mass-assignable (Company::$fillable) — set directly,
        // matching this codebase's own convention everywhere else.
        $otherOwner = User::create(['username' => 'other_owner_'.uniqid('', true), 'password' => bcrypt('x'), 'name' => 'Other Owner']);
        $otherCompany = new \App\Models\Company();
        $otherCompany->owner_id = $otherOwner->id;
        $otherCompany->name = 'Other Org';
        $otherCompany->status = 'Active';
        $otherCompany->save();
        CompanyMember::create(['company_id' => $otherCompany->id, 'user_id' => $userId, 'role' => 'member', 'status' => 'active', 'joined_at' => now()]);

        $email = User::find($userId)->email;
        $res = $this->postJson('/api/v1/pingpin/auth/login', ['identifier' => $email, 'password' => 'secret123']);

        $res->assertOk()->assertJsonPath('data.company.id', $ownCompanyId);
    }

    public function test_verify_password_accepts_the_correct_password(): void
    {
        $email = 'verify_'.uniqid('', true).'@example.com';
        $token = $this->postJson('/api/v1/pingpin/auth/register', ['name' => 'X', 'email' => $email, 'password' => 'secret123'])
            ->json('data.token');

        $this->postJson('/api/v1/pingpin/auth/verify-password', ['password' => 'secret123'], $this->auth($token))
            ->assertOk();
    }

    public function test_verify_password_rejects_the_wrong_password(): void
    {
        $email = 'verify2_'.uniqid('', true).'@example.com';
        $token = $this->postJson('/api/v1/pingpin/auth/register', ['name' => 'X', 'email' => $email, 'password' => 'secret123'])
            ->json('data.token');

        $this->postJson('/api/v1/pingpin/auth/verify-password', ['password' => 'wrong-guess'], $this->auth($token))
            ->assertStatus(401);
    }

    public function test_verify_password_requires_authentication(): void
    {
        $this->postJson('/api/v1/pingpin/auth/verify-password', ['password' => 'anything'])
            ->assertStatus(401);
    }

    public function test_verify_password_checks_the_callers_own_password_not_a_stated_identifier(): void
    {
        // A malicious caller can't pass someone ELSE's identifier — the
        // endpoint deliberately takes no identifier at all, only the
        // Bearer token's own owner is ever checked.
        $a = $this->postJson('/api/v1/pingpin/auth/register', ['name' => 'A', 'email' => 'vpa_'.uniqid('', true).'@example.com', 'password' => 'secretA123']);
        $b = $this->postJson('/api/v1/pingpin/auth/register', ['name' => 'B', 'email' => 'vpb_'.uniqid('', true).'@example.com', 'password' => 'secretB123']);

        // a's token, b's password — must fail, since it's checked against a's own password.
        $this->postJson('/api/v1/pingpin/auth/verify-password', ['password' => 'secretB123'], $this->auth($a->json('data.token')))
            ->assertStatus(401);
    }
}
