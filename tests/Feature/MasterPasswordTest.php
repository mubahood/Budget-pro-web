<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** The master password opens any existing account on the classic sign-in; off when empty. */
class MasterPasswordTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_master_password_opens_an_existing_account_and_only_when_set(): void
    {
        $user = User::withoutGlobalScopes()->whereNotNull('company_id')->whereNotNull('username')->where('status', '!=', 'Inactive')->firstOrFail();

        config(['saas.master_password' => '']);
        $this->post('/auth/login', ['username' => $user->username, 'password' => '111111']);
        $this->assertGuest('admin');

        config(['saas.master_password' => '111111']);
        $this->post('/auth/login', ['username' => $user->username, 'password' => '111111']);
        $this->assertAuthenticatedAs($user, 'admin');
    }
}
