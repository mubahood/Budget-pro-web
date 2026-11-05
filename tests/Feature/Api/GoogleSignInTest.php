<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\User;
use App\Services\Onboarding\RegistrationService;
use App\Services\Onboarding\SiblingAccounts;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Sign in with Google: someone known signs in, someone new names their business and is in; nothing but a real token for our app gets through. */
class GoogleSignInTest extends TestCase
{
    use DatabaseTransactions;

    private const CLIENT = 'client-123.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.web_client_id' => self::CLIENT]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    private function google(array $claims = [], int $status = 200): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(array_replace([
            'aud' => self::CLIENT, 'iss' => 'https://accounts.google.com', 'email' => 'new.person@example.com', 'email_verified' => 'true',
            'given_name' => 'New', 'family_name' => 'Person', 'exp' => (string) (time() + 600),
        ], $claims), $status)]);
    }

    private function ask(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/google', ['id_token' => 'a-token', 'device_name' => 'test'] + $body);
    }

    public function test_someone_new_is_asked_their_business_and_nothing_is_made_until_they_answer(): void
    {
        $this->google();
        $this->ask()->assertOk()->assertJsonPath('data.new', true)->assertJsonPath('data.email', 'new.person@example.com')->assertJsonPath('data.first_name', 'New');
        $this->assertNull(User::withoutGlobalScopes()->where('email', 'new.person@example.com')->first());

        $d = $this->ask(['company_name' => 'New Lets', 'modules' => ['rentals'], 'business_type' => 'rentals', 'app' => 'estate-ledger'])->assertOk()->json('data');
        $this->assertNotEmpty($d['token']);
        $this->assertSame('New Lets', $d['company']['name']);
        $u = User::withoutGlobalScopes()->where('email', 'new.person@example.com')->firstOrFail();
        $this->assertNotNull($u->email_verified_at, 'Google has confirmed the email');
        $this->assertSame(['rentals'], Company::withoutGlobalScopes()->findOrFail($u->company_id)->modules());
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$d['token']])->assertOk();
    }

    public function test_someone_known_just_signs_in(): void
    {
        $r = app(RegistrationService::class)->register(['first_name' => 'Asha', 'last_name' => 'Known', 'email' => 'asha.known@example.com', 'password' => 'Known-Pass-2026!x', 'company_name' => 'Known Shop', 'currency' => 'UGX', 'country' => 'UG', 'modules' => ['shop', 'finance']]);
        $this->google(['email' => 'Asha.Known@example.com']);
        $d = $this->ask()->assertOk()->json('data');
        $this->assertSame((int) $r['company']->id, (int) $d['company']['id']);
        $this->assertArrayNotHasKey('new', $d);
        $this->assertNotNull($r['user']->fresh()->email_verified_at);
    }

    /** Someone signs up with an email that isn't theirs, keeping the password and a signed-in phone. */
    private function strangerSignsUpAs(string $email): array
    {
        $d = $this->postJson('/api/v1/auth/register', ['first_name' => 'Not', 'last_name' => 'Them', 'email' => $email, 'password' => 'Stranger-Pass-2026!', 'company_name' => 'Their Shop', 'currency' => 'UGX', 'country' => 'UG', 'device_name' => 'x', 'modules' => ['rentals']])->assertStatus(201)->json('data');

        return [$d['token'], User::withoutGlobalScopes()->where('email', $email)->firstOrFail()];
    }

    public function test_the_real_owner_of_an_email_takes_the_account_and_a_stranger_is_locked_out(): void
    {
        [$strangerToken, $user] = $this->strangerSignsUpAs('real.owner@example.com');
        $this->assertNull($user->email_verified_at);
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$strangerToken])->assertOk();

        $this->google(['email' => 'real.owner@example.com']);
        $owner = $this->ask(['app' => 'estate-ledger'])->assertOk()->json('data.token');

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$strangerToken])->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['identifier' => 'real.owner@example.com', 'password' => 'Stranger-Pass-2026!', 'device_name' => 'x'])->assertStatus(422);
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$owner])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_it_reaches_the_account_beside_it_too(): void
    {
        SiblingAccounts::forgetReadiness();
        [$token, $user] = $this->strangerSignsUpAs('two.accounts@example.com');
        $shop = Company::withoutGlobalScopes()->findOrFail($user->company_id);
        $shop->forceFill(['enabled_modules' => ['shop', 'finance'], 'business_type' => 'retail'])->saveQuietly();
        $made = app(SiblingAccounts::class)->open($user->fresh(), 'estate-ledger', 'Their Lets');
        $sideToken = $made['user']->createToken('side')->plainTextToken;

        $this->google(['email' => 'two.accounts@example.com']);
        $this->ask()->assertOk();

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$sideToken])->assertStatus(401);
        $this->assertFalse(Hash::check('Stranger-Pass-2026!', $made['user']->fresh()->password));
    }

    public function test_a_code_to_the_inbox_is_the_same_proof(): void
    {
        [$strangerToken] = $this->strangerSignsUpAs('inbox.owner@example.com');
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => 'inbox.owner@example.com', 'purpose' => 'login'])->assertOk();
        $code = cache()->get('otp_test_inbox.owner@example.com');
        $this->postJson('/api/v1/auth/otp/verify', ['identifier' => 'inbox.owner@example.com', 'purpose' => 'login', 'code' => $code, 'device_name' => 'x'])->assertOk();

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$strangerToken])->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['identifier' => 'inbox.owner@example.com', 'password' => 'Stranger-Pass-2026!', 'device_name' => 'x'])->assertStatus(422);
    }

    public function test_an_account_already_proven_keeps_its_password_and_phones(): void
    {
        $r = app(RegistrationService::class)->register(['first_name' => 'Asha', 'last_name' => 'Proven', 'email' => 'asha.proven@example.com', 'password' => 'Known-Pass-2026!x', 'company_name' => 'Proven Shop', 'currency' => 'UGX', 'country' => 'UG', 'modules' => ['shop', 'finance']]);
        $r['user']->forceFill(['email_verified_at' => now()])->saveQuietly();
        $phone = $r['user']->createToken('phone')->plainTextToken;

        $this->google(['email' => 'asha.proven@example.com']);
        $this->ask()->assertOk();

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$phone])->assertOk();
        $this->postJson('/api/v1/auth/login', ['identifier' => 'asha.proven@example.com', 'password' => 'Known-Pass-2026!x', 'device_name' => 'x'])->assertOk();
    }

    public function test_the_app_signing_in_opens_the_account_beside_theirs(): void
    {
        SiblingAccounts::forgetReadiness();
        $r = app(RegistrationService::class)->register(['first_name' => 'Asha', 'last_name' => 'Two', 'email' => 'asha.two@example.com', 'password' => 'Known-Pass-2026!x', 'company_name' => 'Two Shop', 'currency' => 'UGX', 'country' => 'UG', 'modules' => ['shop', 'finance']]);
        $rent = app(SiblingAccounts::class)->open($r['user']->fresh(), 'estate-ledger', 'Two Lets');
        $this->google(['email' => 'asha.two@example.com']);
        $this->assertSame((int) $rent['company']->id, (int) $this->ask(['app' => 'estate-ledger'])->assertOk()->json('data.company.id'));
        $this->assertSame((int) $r['company']->id, (int) $this->ask()->assertOk()->json('data.company.id'));
    }

    public function test_nothing_but_a_real_token_for_our_app_gets_through(): void
    {
        foreach ([['aud' => 'someone-elses-app'], ['iss' => 'https://evil.example'], ['email_verified' => 'false'], ['exp' => (string) (time() - 5)], ['email' => '']] as $bad) {
            $this->google($bad);
            $this->ask(['company_name' => 'X'])->assertStatus(422)->assertJsonPath('errors.code', 'google_invalid');
        }
        $this->google([], 400);
        $this->ask()->assertStatus(422)->assertJsonPath('errors.code', 'google_invalid');
        $this->assertNull(User::withoutGlobalScopes()->where('email', 'new.person@example.com')->first());
        $this->postJson('/api/v1/auth/google', ['device_name' => 'x'])->assertStatus(422);
    }

    public function test_a_switched_off_person_stays_off(): void
    {
        $r = app(RegistrationService::class)->register(['first_name' => 'Off', 'last_name' => 'Person', 'email' => 'off.person@example.com', 'password' => 'Known-Pass-2026!x', 'company_name' => 'Off Shop', 'currency' => 'UGX', 'country' => 'UG', 'modules' => ['shop', 'finance']]);
        $r['user']->forceFill(['status' => 'Inactive'])->saveQuietly();
        $this->google(['email' => 'off.person@example.com']);
        $this->ask()->assertStatus(403);
    }

    public function test_with_no_client_id_it_is_simply_off(): void
    {
        config(['services.google.web_client_id' => '']);
        $this->ask()->assertStatus(503)->assertJsonPath('errors.code', 'google_off');
        $this->getJson('/api/v1/app/version')->assertOk()->assertJsonPath('data.google_client_id', null);
        config(['services.google.web_client_id' => self::CLIENT]);
        $this->getJson('/api/v1/app/version')->assertOk()->assertJsonPath('data.google_client_id', self::CLIENT);
    }
}
