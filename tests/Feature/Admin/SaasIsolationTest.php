<?php

namespace Tests\Feature\Admin;

use App\Models\StockCategory;
use App\Models\User;

/** P0-3: EnforceSaasIsolation now actually runs for admin sessions. */
class SaasIsolationTest extends AdminTestCase
{
    public function test_admin_session_user_without_a_company_is_logged_out(): void
    {
        $user = new User();
        $user->name = 'Orphan';
        $user->email = 'orphan_'.uniqid('', true).'@example.com';
        $user->password = bcrypt('secret123');
        $user->status = 'Active';
        $user->save();

        $this->asAdmin($user)->get('/stock-items')->assertRedirect('/auth/login');
    }

    public function test_forged_company_id_in_a_form_post_is_overridden(): void
    {
        $a = $this->makeTenant('company');
        $b = $this->makeTenant('company');

        $this->asAdmin($a['user'])->post('/stock-categories', [
            'name' => 'Forged '.uniqid(), 'status' => 'Active', 'company_id' => $b['company']->id,
        ]);

        $created = StockCategory::withoutGlobalScopes()->where('name', 'like', 'Forged %')->latest('id')->first();
        $this->assertNotNull($created);
        $this->assertSame((int) $a['company']->id, (int) $created->company_id);
    }
}
