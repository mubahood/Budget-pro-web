<?php

namespace Tests\Feature\Api;

class AuthTest extends ApiTestCase
{
    public function test_register_creates_tenant_and_returns_token(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Ada',
            'last_name' => 'Founder',
            'email' => 'ada_'.uniqid().'@example.com',
            'password' => 'secret123',
            'company_name' => 'Ada Retail',
            'currency' => 'UGX',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('code', 1)
            ->assertJsonStructure(['code', 'message', 'data' => ['token', 'user' => ['id', 'company_id'], 'company' => ['id']]]);

        // The user's company_id must be their own new company, not the placeholder 1.
        $this->assertSame($res->json('data.user.company_id'), $res->json('data.company.id'));
    }

    public function test_register_validates_input(): void
    {
        $this->postJson('/api/v1/auth/register', ['first_name' => 'X'])
            ->assertStatus(422)
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['errors' => ['last_name', 'email', 'password', 'company_name', 'currency']]);
    }

    public function test_register_rejects_unsupported_currency(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'A', 'last_name' => 'B',
            'email' => 'x_'.uniqid().'@example.com', 'password' => 'secret123',
            'company_name' => 'C', 'currency' => 'ZZZ',
        ])->assertStatus(422)->assertJsonValidationErrors('currency');
    }

    public function test_register_does_not_leak_password(): void
    {
        $t = $this->registerTenant();
        $res = $this->getJson('/api/v1/auth/me', $this->auth($t['token']));
        $res->assertOk();
        $this->assertArrayNotHasKey('password', $res->json('data.user'));
    }

    public function test_login_returns_token(): void
    {
        $t = $this->registerTenant();

        $this->postJson('/api/v1/auth/login', ['email' => $t['email'], 'password' => $t['password']])
            ->assertOk()
            ->assertJsonPath('code', 1)
            ->assertJsonStructure(['data' => ['token', 'user', 'company']]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $t = $this->registerTenant();

        $this->postJson('/api/v1/auth/login', ['email' => $t['email'], 'password' => 'wrong'])
            ->assertStatus(422);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('code', 0);
    }

    public function test_forged_user_id_param_does_not_authenticate(): void
    {
        // The old vulnerability: ?logged_in_user_id=N. It must be powerless now.
        $this->getJson('/api/v1/auth/me?logged_in_user_id=1')->assertStatus(401);
        $this->getJson('/api/v1/stock-items?logged_in_user_id=1')->assertStatus(401);
    }

    public function test_logout_revokes_token(): void
    {
        $t = $this->registerTenant();
        $this->postJson('/api/v1/auth/logout', [], $this->auth($t['token']))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->auth($t['token']))->assertStatus(401);
    }

    public function test_password_change_requires_correct_current(): void
    {
        $t = $this->registerTenant();

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong',
            'new_password' => 'newpass123',
            'new_password_confirmation' => 'newpass123',
        ], $this->auth($t['token']))->assertStatus(422);

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'secret123',
            'new_password' => 'newpass123',
            'new_password_confirmation' => 'newpass123',
        ], $this->auth($t['token']))->assertOk();
    }
}
