<?php

namespace Tests\Feature\Auth;

use App\Admin\Controllers\AuthController;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Admin\AdminTestCase;

/** The classic sign-in's attempt limits, its lockout message and its refusals (roadmap 02 §2, P0-2). */
class ClassicSignInTest extends AdminTestCase
{
    public function test_six_wrong_tries_lock_that_login_with_the_minutes_and_the_support_link(): void
    {
        config(['support.whatsapp' => '256700000123']);
        $shop = $this->makeTenant('company')['user'];

        for ($i = 0; $i < AuthController::LOGIN_TRIES; $i++) {
            $this->post('/auth/login', ['username' => $shop->email, 'password' => 'wrong-guess-'.$i])->assertSessionHasErrors('username');
        }
        // Locked now, even with the right password.
        $locked = $this->post('/auth/login', ['username' => $shop->email, 'password' => 'secret123']);
        $locked->assertSessionHasErrors(['username' => 'Too many sign-in tries. Wait 1 minute, then try again.']);
        $this->assertStringStartsWith('https://wa.me/', (string) session('signin_support_url'));
        $this->assertGuest('admin');
        $this->get('/auth/login')->assertSee('Chat with support on WhatsApp', false);

        // Another login from the same address still signs in, and the lock passes after a minute.
        $other = $this->makeTenant('company')['user'];
        $this->post('/auth/login', ['username' => $other->email, 'password' => 'secret123'])->assertRedirect();
        $this->assertAuthenticatedAs($other, 'admin');
        $this->get('/auth/logout');
        $this->travel(61)->seconds();
        $this->post('/auth/login', ['username' => $shop->email, 'password' => 'secret123'])->assertRedirect();
        $this->assertAuthenticatedAs($shop, 'admin');
    }

    public function test_one_address_is_capped_across_logins_and_a_success_clears_that_login(): void
    {
        $shop = $this->makeTenant('company')['user'];
        $this->post('/auth/login', ['username' => $shop->email, 'password' => 'nope-1']);
        $this->post('/auth/login', ['username' => $shop->email, 'password' => 'secret123'])->assertRedirect();
        [$loginKey] = AuthController::throttleKeys($shop->email, '127.0.0.1');
        $this->assertSame(0, RateLimiter::attempts($loginKey), 'a success clears that login');
        $this->get('/auth/logout');

        for ($i = 1; $i < AuthController::IP_TRIES; $i++) {
            $this->post('/auth/login', ['username' => "nobody{$i}@example.test", 'password' => 'whatever-1']);
        }
        $this->post('/auth/login', ['username' => 'nobody-last@example.test', 'password' => 'whatever-1']);
        $this->post('/auth/login', ['username' => $shop->email, 'password' => 'secret123'])
            ->assertSessionHasErrors(['username' => 'Too many sign-in tries. Wait 1 minute, then try again.']);
        $this->assertGuest('admin');

        // A different address is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/auth/login', ['username' => $shop->email, 'password' => 'secret123'])->assertRedirect();
        $this->assertAuthenticatedAs($shop, 'admin');
    }

    public function test_a_switched_off_account_is_refused_and_a_platform_admin_signs_in(): void
    {
        $off = $this->makeTenant('company')['user'];
        $off->forceFill(['status' => 'Inactive'])->save();
        $this->post('/auth/login', ['username' => $off->email, 'password' => 'secret123'])
            ->assertSessionHasErrors(['username' => 'This account has been switched off. Ask the shop owner to switch it back on.']);
        $this->assertGuest('admin');

        $admin = $this->makeTenant('admin')['user'];
        $admin->forceFill(['username' => 'platform-'.$admin->id])->save();
        $this->post('/auth/login', ['username' => 'platform-'.$admin->id, 'password' => 'secret123'])->assertRedirect();
        $this->assertAuthenticatedAs($admin, 'admin');
    }
}
