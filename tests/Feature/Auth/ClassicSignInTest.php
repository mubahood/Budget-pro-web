<?php

namespace Tests\Feature\Auth;

use App\Admin\Controllers\AuthController;
use App\Support\SignInThrottle;
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
        $this->assertSame(0, RateLimiter::attempts(SignInThrottle::accountKey(SignInThrottle::CLASSIC, $shop, $shop->email)), 'a success clears that account');
        $this->assertSame(2, RateLimiter::attempts(SignInThrottle::addressKey(SignInThrottle::CLASSIC, '127.0.0.1')), 'the address keeps every try');
        $this->get('/auth/logout');

        for ($i = 3; $i <= AuthController::IP_TRIES; $i++) {
            $this->post('/auth/login', ['username' => "nobody{$i}@example.test", 'password' => 'whatever-1']);
        }
        $this->post('/auth/login', ['username' => $shop->email, 'password' => 'secret123'])
            ->assertSessionHasErrors(['username' => 'Too many sign-in tries. Wait 1 minute, then try again.']);
        $this->assertGuest('admin');

        // A different address is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/auth/login', ['username' => $shop->email, 'password' => 'secret123'])->assertRedirect();
        $this->assertAuthenticatedAs($shop, 'admin');
    }

    public function test_every_way_of_writing_one_account_shares_one_count(): void
    {
        $shop = $this->makeTenant('company')['user'];
        $shop->forceFill(['phone_e164' => '+256772123456', 'phone_number' => '0772123456', 'username' => 'shop-'.$shop->id])->save();

        // Email in another case, the username, and the phone written five ways: one account, one count.
        $ways = [strtoupper($shop->email), 'shop-'.$shop->id, 'a0772123456', 'b0772123456', '0772-123-456', '+256 772 123456'];
        foreach ($ways as $i => $login) {
            $this->post('/auth/login', ['username' => $login, 'password' => 'wrong-guess-'.$i])->assertSessionHasErrors('username');
        }
        $this->post('/auth/login', ['username' => '256772123456', 'password' => 'secret123'])
            ->assertSessionHasErrors(['username' => 'Too many sign-in tries. Wait 1 minute, then try again.']);
        $this->assertGuest('admin');

        // A login that opens nothing is counted by its normalised form, so its spellings share a count too.
        $this->assertSame(SignInThrottle::accountKey('x', null, 'c0701-999-888'), SignInThrottle::accountKey('x', null, '+256701999888'));
        $this->assertSame(SignInThrottle::accountKey('x', null, 'Someone@Example.TEST'), SignInThrottle::accountKey('x', null, 'someone@example.test'));
    }

    public function test_tries_are_counted_before_the_password_is_checked_in_the_database_store(): void
    {
        // Production counts in the `limiter` store: database rows, whose increments are atomic.
        $this->assertSame('database', config('cache.stores.limiter.driver'));
        $this->assertSame('cache', config('cache.stores.limiter.table'));
        $limiter = new \Illuminate\Cache\RateLimiter(\Illuminate\Support\Facades\Cache::store('limiter'));
        $key = 'signin:test:'.uniqid();
        $this->assertSame([1, 2, 3], [$limiter->hit($key), $limiter->hit($key), $limiter->hit($key)]);
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('cache')->where('key', 'budget_pro_limiter:'.$key)->exists());

        // The form counts the try before any password work: the count is there even when the password was right.
        $shop = $this->makeTenant('company')['user'];
        RateLimiter::shouldReceive('hit')->twice()->andReturn(SignInThrottle::ACCOUNT_TRIES + 1, 1);
        RateLimiter::shouldReceive('availableIn')->andReturn(40);
        $this->post('/auth/login', ['username' => $shop->email, 'password' => 'secret123'])
            ->assertSessionHasErrors(['username' => 'Too many sign-in tries. Wait 1 minute, then try again.']);
        $this->assertGuest('admin');
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
