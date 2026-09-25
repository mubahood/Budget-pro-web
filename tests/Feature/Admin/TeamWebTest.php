<?php

namespace Tests\Feature\Admin;

use App\Models\Subscription;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Services\Team\TeamService;
use Illuminate\Support\Facades\DB;

/** Plan C5 (P3-4): the web admin enforces the same permissions as the API, not just hidden menus. */
class TeamWebTest extends AdminTestCase
{
    public function test_cashier_is_kept_out_of_sections_their_role_lacks(): void
    {
        $t = $this->makeTenant('company');
        Subscription::create(['company_id' => $t['company']->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        $cashier = new User();
        $cashier->forceFill(['name' => 'Cash Ier', 'first_name' => 'Cash', 'last_name' => 'Ier', 'email' => 'c'.uniqid().'@example.com', 'username' => 'c'.uniqid(), 'password' => bcrypt('x'), 'status' => 'Active', 'company_id' => $t['company']->id])->save();
        app(TeamService::class)->setRole($t['company'], $cashier, 'cashier');
        Permissions::flush();

        $this->assertTrue(DB::table('admin_role_users')->join('admin_roles', 'admin_roles.id', '=', 'admin_role_users.role_id')->where('user_id', $cashier->id)->where('slug', 'shop_cashier')->exists());
        $this->asAdmin($cashier->fresh())->get('/stock-items')->assertOk();
        $this->asAdmin($cashier->fresh())->get('/stock-items/create')->assertForbidden();
        $this->asAdmin($cashier->fresh())->get('/stock-records')->assertForbidden();
        $this->asAdmin($cashier->fresh())->get('/financial-records')->assertForbidden();
        $this->asAdmin($cashier->fresh())->get('/employees')->assertForbidden();

        // The owner is unaffected and still sees every menu (a menu with role rows hides from unlisted roles).
        \App\Support\AdminAccess::ensureShopRoles();
        $this->asAdmin($t['user'])->get('/stock-records')->assertOk()->assertSee('stock-records"', false)->assertSee('My plan &amp; billing', false);
        $this->asAdmin($cashier->fresh())->get('/sale-records')->assertOk()->assertDontSee('/stock-records"', false);

        // Billing page: owner can choose plans, cashier can only look.
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->asAdmin($t['user'])->get('/billing')->assertOk()->assertSee('Usage')->assertSee('billing/checkout', false);
        $this->asAdmin($cashier->fresh())->get('/billing')->assertOk()->assertDontSee('billing/checkout', false)->assertSee('Only the owner can change the plan');
        $this->asAdmin($cashier->fresh())->post('/billing/cancel')->assertForbidden();
        $this->asAdmin($t['user'])->get('/employees/create')->assertOk()->assertSee('Stock keeper');
    }
}
