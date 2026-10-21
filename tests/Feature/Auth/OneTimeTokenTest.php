<?php

namespace Tests\Feature\Auth;

use App\Services\Auth\OneTimeToken;
use App\Services\Onboarding\RegistrationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/**
 * The API's reset token and the sign-up's verification token (P0-2, found): random, single-use, kept
 * hashed with their purpose and owner. A value encrypted with the app key, the old format, is refused.
 */
class OneTimeTokenTest extends ApiTestCase
{
    private function resetToken(string $email): string
    {
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => $email, 'purpose' => 'reset'])->assertOk();

        return (string) $this->postJson('/api/v1/auth/otp/verify', ['identifier' => $email, 'purpose' => 'reset', 'code' => (string) Cache::get('otp_test_'.strtolower($email))])
            ->assertOk()->json('data.reset_token');
    }

    public function test_a_reset_token_built_with_the_app_key_is_refused(): void
    {
        $t = $this->registerTenant();
        $forged = Crypt::encryptString((string) json_encode(['u' => $t['user_id'], 'exp' => now()->addMinutes(15)->timestamp]));

        $this->postJson('/api/v1/auth/password/reset', ['reset_token' => $forged, 'password' => 'taken0ver', 'password_confirmation' => 'taken0ver'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'reset_expired');
        $this->postJson('/api/v1/auth/login', ['identifier' => $t['email'], 'password' => 'taken0ver'])->assertStatus(422);
    }

    public function test_a_real_reset_token_works_once_and_is_stored_only_as_a_hash(): void
    {
        $t = $this->registerTenant();
        $token = $this->resetToken($t['email']);
        $this->assertGreaterThanOrEqual(43, strlen($token));
        $this->assertFalse(DB::table(OneTimeToken::TABLE)->where('token_hash', $token)->exists());
        $this->assertTrue(DB::table(OneTimeToken::TABLE)->where('token_hash', hash('sha256', $token))->where('user_id', $t['user_id'])->where('purpose', OneTimeToken::RESET)->exists());

        // A refused password leaves it usable.
        $this->postJson('/api/v1/auth/password/reset', ['reset_token' => $token, 'password' => 'abc12', 'password_confirmation' => 'abc12'])->assertStatus(422);
        $this->postJson('/api/v1/auth/password/reset', ['reset_token' => $token, 'password' => 'newpass99', 'password_confirmation' => 'newpass99'])->assertOk();
        $this->postJson('/api/v1/auth/password/reset', ['reset_token' => $token, 'password' => 'again2026', 'password_confirmation' => 'again2026'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'reset_expired');
        $this->postJson('/api/v1/auth/login', ['identifier' => $t['email'], 'password' => 'newpass99'])->assertOk();
    }

    public function test_an_expired_reset_token_fails(): void
    {
        $t = $this->registerTenant();
        $token = $this->resetToken($t['email']);
        $this->travel(OneTimeToken::RESET_MINUTES + 1)->minutes();
        $this->postJson('/api/v1/auth/password/reset', ['reset_token' => $token, 'password' => 'newpass99', 'password_confirmation' => 'newpass99'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'reset_expired');
    }

    public function test_the_verification_token_marks_only_its_own_contact_once_and_a_forged_one_marks_nothing(): void
    {
        config(['messaging.otp.phone_codes' => true]); // phone-only sign-ups exist only with phone codes on
        $forged = Crypt::encryptString((string) json_encode(['i' => '+256772555222', 'p' => 'register', 'exp' => now()->addMinutes(30)->timestamp]));
        $this->assertNull(RegistrationService::verifiedIdentifier($forged));
        $r = $this->postJson('/api/v1/auth/register', ['first_name' => 'Forged', 'last_name' => 'Token', 'phone_number' => '0772555222', 'password' => 'secret123',
            'company_name' => 'Forged Co', 'currency' => 'UGX', 'verification_token' => $forged])->assertStatus(201);
        $r->assertJsonPath('data.user.phone_verified', false);

        $token = RegistrationService::verificationToken('+256772555333');
        // Another phone cannot use it.
        $this->postJson('/api/v1/auth/register', ['first_name' => 'Other', 'last_name' => 'Phone', 'phone_number' => '0772555444', 'password' => 'secret123',
            'company_name' => 'Other Co', 'currency' => 'UGX', 'verification_token' => $token])->assertStatus(201)->assertJsonPath('data.user.phone_verified', false);
        $this->assertSame('+256772555333', RegistrationService::verifiedIdentifier($token), 'still open: nothing used it');

        $this->postJson('/api/v1/auth/register', ['first_name' => 'Real', 'last_name' => 'Owner', 'phone_number' => '0772555333', 'password' => 'secret123',
            'company_name' => 'Real Co', 'currency' => 'UGX', 'verification_token' => $token])->assertStatus(201)->assertJsonPath('data.user.phone_verified', true);
        $this->assertNull(RegistrationService::verifiedIdentifier($token), 'used up');
    }
}
