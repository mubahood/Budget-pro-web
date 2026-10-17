<?php

namespace Tests\Feature\Auth;

use App\Providers\RouteServiceProvider;
use Tests\Feature\Api\ApiTestCase;

/** The API's `login` limiter: per account and address, on top of the 10-a-minute limit per address (P0-2). */
class ApiLoginLimiterTest extends ApiTestCase
{
    public function test_the_seventh_try_for_one_account_is_429_while_another_account_still_signs_in(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();
        $this->travel(61)->seconds(); // a fresh minute for the per-address limit, which sign-up shares

        for ($i = 0; $i < RouteServiceProvider::LOGIN_TRIES; $i++) {
            $this->postJson('/api/v1/auth/login', ['identifier' => $a['email'], 'password' => 'wrong-guess-'.$i])->assertStatus(422);
        }
        // The same account written in another case is the same account.
        $this->postJson('/api/v1/auth/login', ['identifier' => strtoupper($a['email']), 'password' => $a['password']])->assertStatus(429);

        $this->postJson('/api/v1/auth/login', ['identifier' => $b['email'], 'password' => $b['password']])->assertOk();
    }

    public function test_codes_and_reset_are_limited_per_account_too(): void
    {
        $a = $this->registerTenant(['phone_number' => '0701000123']);
        $this->travel(61)->seconds();
        for ($i = 0; $i < RouteServiceProvider::LOGIN_TRIES; $i++) {
            $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '0701000123', 'purpose' => 'login', 'code' => '00000'.$i]);
        }
        // Every way of writing the phone is one account.
        $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '+256 701 000123', 'purpose' => 'login', 'code' => '123456'])->assertStatus(429);
        $this->travel(61)->seconds();

        for ($i = 0; $i < RouteServiceProvider::LOGIN_TRIES; $i++) {
            $this->postJson('/api/v1/auth/password/reset', ['reset_token' => 'same-token', 'password' => 'newpass99', 'password_confirmation' => 'newpass99'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/password/reset', ['reset_token' => 'same-token', 'password' => 'newpass99', 'password_confirmation' => 'newpass99'])->assertStatus(429);
        $this->assertNotNull($a['token']);
    }
}
