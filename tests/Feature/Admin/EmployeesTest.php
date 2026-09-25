<?php

namespace Tests\Feature\Admin;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * P0-2: adding a cashier must require a real password, assign a role the
 * route permission check accepts, and never show credentials.
 */
class EmployeesTest extends AdminTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Cash',
            'last_name' => 'Ier',
            'sex' => 'Female',
            'email' => 'cashier_'.uniqid('', true).'@example.com',
            'phone_number' => '+256700000001',
            'password' => 'strongpass1',
            'password_confirmation' => 'strongpass1',
            'roles' => [DB::table('admin_roles')->where('slug', 'worker')->value('id')],
            'team_role' => 'cashier',
            'status' => 'Active',
        ], $overrides);
    }

    public function test_creating_an_employee_requires_a_password(): void
    {
        $a = $this->makeTenant('company');
        $payload = $this->payload(['password' => '', 'password_confirmation' => '']);

        $this->asAdmin($a['user'])->post('/employees', $payload);

        $this->assertNull(User::where('email', $payload['email'])->first());
    }

    public function test_owner_creates_a_worker_with_password_and_role_in_their_own_company(): void
    {
        $a = $this->makeTenant('company');
        $payload = $this->payload(['company_id' => 999999]); // forged company id must be ignored

        $this->asAdmin($a['user'])->post('/employees', $payload)->assertRedirect();

        $worker = User::where('email', $payload['email'])->firstOrFail();
        $this->assertSame((int) $a['company']->id, (int) $worker->company_id);
        $this->assertSame($payload['email'], $worker->username);
        $this->assertTrue(Hash::check('strongpass1', $worker->password));
        // The company role (plan C5) sets both app permissions and the matching web role.
        $this->assertSame('cashier', \App\Services\Team\Permissions::roleOf($worker->fresh()));
        $this->assertTrue($worker->fresh()->isRole('shop_cashier'));
        $this->assertFalse($worker->fresh()->isRole('admin'));
    }

    public function test_tenant_cannot_grant_the_platform_admin_role(): void
    {
        $a = $this->makeTenant('company');
        $adminRole = DB::table('admin_roles')->where('slug', 'admin')->value('id');
        $payload = $this->payload(['roles' => [$adminRole]]);

        $this->asAdmin($a['user'])->post('/employees', $payload);

        $worker = User::where('email', $payload['email'])->first();
        $this->assertTrue($worker === null || ! $worker->isRole('admin'));
    }

    public function test_employee_detail_never_shows_the_password_hash(): void
    {
        $a = $this->makeTenant('company');
        $payload = $this->payload();
        $this->asAdmin($a['user'])->post('/employees', $payload);
        $worker = User::where('email', $payload['email'])->firstOrFail();

        $response = $this->asAdmin($a['user'])->get('/employees/'.$worker->id)->assertOk();
        $response->assertDontSee($worker->password);
        $response->assertDontSee('remember_token');
    }

    public function test_model_refuses_to_create_a_user_without_a_password(): void
    {
        $this->expectException(BusinessRuleException::class);

        $user = new User();
        $user->first_name = 'No';
        $user->last_name = 'Password';
        $user->email = 'nopass_'.uniqid('', true).'@example.com';
        $user->save();
    }

    public function test_leaving_password_blank_on_edit_keeps_the_existing_one(): void
    {
        $a = $this->makeTenant('company');
        $payload = $this->payload();
        $this->asAdmin($a['user'])->post('/employees', $payload);
        $worker = User::where('email', $payload['email'])->firstOrFail();
        $hash = $worker->password;

        $this->asAdmin($a['user'])->put('/employees/'.$worker->id, array_merge($payload, [
            'first_name' => 'Renamed', 'password' => '', 'password_confirmation' => '',
        ]))->assertRedirect();

        $worker->refresh();
        $this->assertSame('Renamed', $worker->first_name);
        $this->assertSame($hash, $worker->password);
    }
}
