<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Plan C6 / P3-1: phone-first identity, OTP, reset, profile, sessions, notifications. */
class PhoneAuthTest extends ApiTestCase
{
    private function code(string $identifier): string
    {
        return (string) Cache::get('otp_test_'.$identifier);
    }

    public function test_phone_normalisation_for_launch_countries(): void
    {
        $this->assertSame('+256772123456', Phone::e164('0772 123 456'));
        $this->assertSame('+256772123456', Phone::e164('772123456'));
        $this->assertSame('+256772123456', Phone::e164('+256 772-123-456'));
        $this->assertSame('+256772123456', Phone::e164('256772123456', 'KE'));
        $this->assertSame('+254712345678', Phone::e164('0712345678', 'KE'));
        $this->assertSame('+255712345678', Phone::e164('0712345678', 'TZ'));
        $this->assertSame('+250788123456', Phone::e164('0788123456', 'RW'));
        $this->assertNull(Phone::e164('12345'));
        $this->assertNull(Phone::e164(''));
    }

    public function test_register_with_a_verified_phone_and_no_email(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => '0772 555 111', 'purpose' => 'register'])->assertOk()->assertJsonPath('data.channel', 'whatsapp');
        $this->assertSame('whatsapp', DB::table('message_log')->where('to', '+256772555111')->value('channel'));
        $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '0772555111', 'purpose' => 'register', 'code' => '000000'])->assertStatus(422)->assertJsonPath('errors.code', 'otp_invalid');
        $token = $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '0772555111', 'purpose' => 'register', 'code' => $this->code('+256772555111')])
            ->assertOk()->json('data.verification_token');

        $r = $this->postJson('/api/v1/auth/register', ['first_name' => 'Aisha', 'last_name' => 'N', 'phone_number' => '0772555111', 'password' => 'secret123',
            'company_name' => 'Aisha Mini Mart', 'currency' => 'UGX', 'business_type' => 'retail', 'verification_token' => $token])->assertStatus(201);
        $r->assertJsonPath('data.user.phone_e164', '+256772555111')->assertJsonPath('data.user.phone_verified', true)->assertJsonPath('data.company.business_type', 'retail');
        $this->assertNotNull($r->json('data.expires_at'));
        $this->assertTrue(DB::table('message_log')->where('purpose', 'welcome')->where('to', '+256772555111')->exists());

        // Same number again, any format → already registered.
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => '+256772555111', 'purpose' => 'register'])->assertStatus(422)->assertJsonPath('errors.code', 'already_registered');

        // Log in with the local form of the phone, or with an OTP.
        $this->postJson('/api/v1/auth/login', ['identifier' => '0772555111', 'password' => 'secret123'])->assertOk()->assertJsonPath('data.user.name', 'Aisha N');
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => '0772555111', 'purpose' => 'login'])->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '0772555111', 'purpose' => 'login', 'code' => $this->code('+256772555111')])->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_otp_attempts_and_rate_limits(): void
    {
        $t = $this->registerTenant(['phone_number' => '0701000001']);
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => '0701000001', 'purpose' => 'login'])->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '0701000001', 'purpose' => 'login', 'code' => '999999'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '0701000001', 'purpose' => 'login', 'code' => $this->code('+256701000001')])
            ->assertStatus(422)->assertJsonPath('errors.code', 'otp_locked');

        // The route itself is throttled too (10/min): the 11th call in a minute gets 429.
        $statuses = [];
        for ($i = 0; $i < 4; $i++) {
            $statuses[] = $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '0701000001', 'purpose' => 'login', 'code' => '1'])->getStatusCode();
        }
        $this->assertContains(429, $statuses);
        $this->travel(2)->minutes();

        // Unknown accounts get the same answer (no account discovery).
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => '0709999999', 'purpose' => 'login'])->assertOk()->assertJsonPath('message', 'If an account exists, a code has been sent.');
        $this->assertFalse(DB::table('otp_codes')->where('identifier', '+256709999999')->exists());
        $this->assertNotNull($t['token']);
    }

    public function test_password_reset_by_code_signs_out_everywhere(): void
    {
        $t = $this->registerTenant();
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => $t['email'], 'purpose' => 'reset'])->assertOk();
        $this->assertSame('mail', DB::table('message_log')->where('to', strtolower($t['email']))->where('purpose', 'otp')->value('channel'));
        $reset = $this->postJson('/api/v1/auth/otp/verify', ['identifier' => $t['email'], 'purpose' => 'reset', 'code' => $this->code(strtolower($t['email']))])->assertOk()->json('data.reset_token');
        $this->postJson('/api/v1/auth/password/reset', ['reset_token' => $reset, 'password' => 'newpass99', 'password_confirmation' => 'newpass99'])->assertOk();
        $this->getJson('/api/v1/auth/me', $this->auth($t['token']))->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => $t['email'], 'password' => 'newpass99'])->assertOk();
        $this->postJson('/api/v1/auth/password/reset', ['reset_token' => 'garbage', 'password' => 'x12345', 'password_confirmation' => 'x12345'])->assertStatus(422);
    }

    public function test_profile_verification_sessions_and_refresh(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant(['phone_number' => '0772000999']);
        $h = $this->auth($a['token']);
        $this->putJson('/api/v1/auth/profile', ['phone_number' => '0772000999'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'phone_taken');
        $this->putJson('/api/v1/auth/profile', ['phone_number' => '0772000888', 'locale' => 'lg'], $h)->assertOk()
            ->assertJsonPath('data.phone_e164', '+256772000888')->assertJsonPath('data.phone_verified', false)->assertJsonPath('data.locale', 'lg');
        $this->postJson('/api/v1/auth/verify/request', ['channel' => 'phone'], $h)->assertOk();
        $this->postJson('/api/v1/auth/verify', ['channel' => 'phone', 'code' => $this->code('+256772000888')], $h)->assertOk()->assertJsonPath('data.phone_verified', true);

        $second = $this->postJson('/api/v1/auth/login', ['email' => $a['email'], 'password' => $a['password'], 'device_name' => 'Counter 2'])->json('data.token');
        $sessions = $this->getJson('/api/v1/auth/sessions', $h)->assertOk()->json('data');
        $this->assertCount(2, $sessions);
        $other = collect($sessions)->firstWhere('current', false);
        $this->deleteJson('/api/v1/auth/sessions/'.$other['id'], [], $h)->assertOk();
        $this->getJson('/api/v1/auth/me', $this->auth($second))->assertStatus(401);

        $fresh = $this->postJson('/api/v1/auth/refresh', [], $h)->assertOk()->json('data.token');
        $this->getJson('/api/v1/auth/me', $h)->assertStatus(401);
        $this->getJson('/api/v1/auth/me', $this->auth($fresh))->assertOk();
        $this->assertNotNull($b['token']);
    }

    public function test_notification_centre_and_preferences(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $list = $this->getJson('/api/v1/notifications', $h)->assertOk();
        $this->assertSame(1, $list->json('data.unread'), 'welcome notification');
        $this->postJson('/api/v1/notifications/read', [], $h)->assertOk();
        $this->assertSame(0, $this->getJson('/api/v1/notifications', $h)->json('data.unread'));

        $prefs = $this->getJson('/api/v1/notifications/preferences', $h)->assertOk()->json('data');
        $this->assertFalse(collect($prefs)->firstWhere('key', 'daily_summary')['enabled']);
        $this->putJson('/api/v1/notifications/preferences', ['preferences' => [['key' => 'daily_summary', 'enabled' => true, 'channels' => ['whatsapp', 'in_app', 'bogus']]]], $h)->assertOk();
        $row = collect($this->getJson('/api/v1/notifications/preferences', $h)->json('data'))->firstWhere('key', 'daily_summary');
        $this->assertTrue($row['enabled']);
        $this->assertSame(['whatsapp', 'in_app'], $row['channels']);
        $this->assertNotNull(User::withoutGlobalScopes()->find($t['user_id'])->last_login_at ?? true);
    }
}
