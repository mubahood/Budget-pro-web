<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FinancialCategory;
use App\Models\FinancialPeriod;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P0-13: every registration channel goes through RegistrationService and
 * produces the same complete tenant.
 */
class RegistrationChannelsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\AdminRolesSeeder::class);
        $this->seed(\Database\Seeders\PlanSeeder::class);
    }

    private function assertCompleteTenant(User $user): Company
    {
        $company = Company::withoutGlobalScopes()->where('owner_id', $user->id)->first();
        $this->assertNotNull($company, 'company created');
        $this->assertSame((int) $company->id, (int) $user->fresh()->company_id, 'owner linked to company');
        $this->assertTrue(FinancialPeriod::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'Active')->exists(), 'default active period');
        $this->assertTrue(FinancialCategory::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Sales')->exists(), 'account categories seeded');
        $this->assertTrue(DB::table('company_members')->where('company_id', $company->id)->where('user_id', $user->id)->where('role', 'owner')->exists(), 'membership row');
        $this->assertTrue(DB::table('admin_role_users')->where('user_id', $user->id)->where('role_id', 2)->exists(), 'owner role');

        return $company;
    }

    public function test_web_form_registers_a_complete_tenant_and_logs_in(): void
    {
        $email = 'web_'.uniqid().'@example.com';
        $res = $this->post('/auth/register', [
            'first_name' => 'Web', 'last_name' => 'Owner', 'email' => $email, 'phone_number' => '0700000001',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'company_name' => 'Web Shop', 'currency' => 'UGX',
        ]);
        $res->assertRedirect();
        $this->assertTrue(auth('admin')->check(), 'logged in after registering');

        $user = User::withoutGlobalScopes()->where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertSame($email, $user->username);
        $company = $this->assertCompleteTenant($user);
        $this->assertSame('trialing', Subscription::where('company_id', $company->id)->value('status'));
    }

    public function test_web_form_uses_the_shared_rules(): void
    {
        $this->from('/auth/register')->post('/auth/register', [
            'first_name' => 'Web', 'last_name' => 'Owner', 'email' => 'x_'.uniqid().'@example.com',
            'password' => 'secret123', 'password_confirmation' => 'different',
            'company_name' => 'Web Shop', 'currency' => 'XXX',
        ])->assertRedirect('/auth/register')->assertSessionHasErrors(['password', 'currency']);
    }

    public function test_api_v1_register_produces_the_same_tenant(): void
    {
        $email = 'api_'.uniqid().'@example.com';
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Api', 'last_name' => 'Owner', 'email' => $email, 'password' => 'secret123',
            'company_name' => 'Api Shop', 'currency' => 'KES',
        ])->assertStatus(201)->assertJsonPath('data.company.currency', 'KES');

        $user = User::withoutGlobalScopes()->where('email', $email)->first();
        $this->assertCompleteTenant($user);
    }

    public function test_pingpin_phone_only_signup_keeps_the_phone_as_username(): void
    {
        $phone = '+2567'.random_int(10000000, 99999999);
        $this->postJson('/api/v1/pingpin/auth/register', ['name' => 'Phone Person', 'phone_number' => $phone, 'password' => 'secret123'])
            ->assertStatus(201);

        $user = User::withoutGlobalScopes()->where('phone_number', $phone)->first();
        $this->assertNotNull($user);
        $this->assertSame($phone, $user->username, 'username must not be clobbered with a null email');
        $this->assertNull($user->email);
        $this->assertCompleteTenant($user);

        // Same phone again is rejected cleanly.
        $this->postJson('/api/v1/pingpin/auth/register', ['name' => 'Again', 'phone_number' => $phone, 'password' => 'secret123'])->assertStatus(422);
    }

    public function test_duplicate_email_is_a_validation_error_on_every_channel(): void
    {
        $email = 'dup_'.uniqid().'@example.com';
        $payload = ['first_name' => 'A', 'last_name' => 'B', 'email' => $email, 'password' => 'secret123', 'company_name' => 'C', 'currency' => 'UGX'];
        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);
        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->from('/auth/register')->post('/auth/register', $payload + ['password_confirmation' => 'secret123'])->assertSessionHasErrors(['email']);
    }
}
