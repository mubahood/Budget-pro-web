<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\AdminRolesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base for admin-panel (session/`admin` guard) tests. The test schema has no
 * roles/permissions/menus of its own, so the baseline is seeded inside the
 * transaction for every test.
 */
abstract class AdminTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AdminRolesSeeder::class);
    }

    /** @return array{user: User, company: Company} */
    protected function makeTenant(string $roleSlug = 'company', string $currency = 'UGX'): array
    {
        $user = new User();
        $user->name = 'Tenant '.$roleSlug;
        $user->first_name = 'Tenant';
        $user->last_name = ucfirst($roleSlug);
        $user->email = 'tenant_'.uniqid('', true).'@example.com';
        $user->password = bcrypt('secret123');
        $user->status = 'Active';
        $user->save();

        $company = new Company();
        $company->owner_id = $user->id;
        $company->name = 'Co '.uniqid();
        $company->status = 'Active';
        $company->currency = $currency;
        $company->save();

        $user->company_id = $company->id;
        $user->save();

        $roleId = DB::table('admin_roles')->where('slug', $roleSlug)->value('id');
        DB::table('admin_role_users')->where('user_id', $user->id)->delete();
        DB::table('admin_role_users')->insert(['role_id' => $roleId, 'user_id' => $user->id]);

        return ['user' => $user->fresh(), 'company' => $company->fresh()];
    }

    protected function asAdmin(User $user): static
    {
        return $this->actingAs($user, 'admin');
    }
}
