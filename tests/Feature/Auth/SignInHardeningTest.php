<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\AccountAccess;
use App\Services\Auth\OneTimeToken;
use App\Services\Auth\SignInPassword;
use App\Services\Team\TeamService;
use App\Support\Rules\PasswordPolicy;
use App\Support\Rules\QuietNotPwnedVerifier;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Api\ApiTestCase;

/**
 * Fresh-eyes review of Phase 0 sign-in: the pre-v1 sign-in (limited, one answer, switched-off accounts
 * refused), API tokens of switched-off people stop working, a new password ends other browsers' "remember
 * me", a new reset code cancels the earlier ones, and an offline breach check stays quiet.
 */
class SignInHardeningTest extends ApiTestCase
{
    public function test_the_pre_v1_sign_in_gives_one_answer_refuses_switched_off_accounts_and_is_limited(): void
    {
        $t = $this->registerTenant();
        $this->travel(61)->seconds(); // a fresh minute for the per-address limit sign-up shares

        $unknown = $this->postJson('/api/auth/login', ['email' => 'nobody-'.uniqid().'@example.com', 'password' => 'whatever1'])->assertOk()->json();
        $wrong = $this->postJson('/api/auth/login', ['email' => $t['email'], 'password' => 'wrong-pass-1'])->assertOk()->json();
        $this->assertSame(['code' => 0, 'message' => 'These credentials do not match our records.', 'data' => null], $unknown);
        $this->assertSame($unknown, $wrong, 'an unknown email and a wrong password look the same');

        $ok = $this->postJson('/api/auth/login', ['email' => $t['email'], 'password' => $t['password']])->assertOk();
        $this->assertSame(1, $ok->json('code'));
        $this->assertSame((int) $t['user_id'], (int) $ok->json('data.user.id'));
        $this->assertNotEmpty($ok->json('data.token'));
        $this->assertArrayNotHasKey('password', $ok->json('data.user'));

        DB::table('admin_users')->where('id', $t['user_id'])->update(['status' => 'Inactive']);
        $this->postJson('/api/auth/login', ['email' => $t['email'], 'password' => $t['password']])
            ->assertOk()->assertJson(['code' => 0, 'message' => AccountAccess::SWITCHED_OFF]);

        // The `login` limiter: the account has had 3 tries, so the 7th answers 429.
        $this->travel(61)->seconds();
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/login', ['email' => $t['email'], 'password' => 'wrong-'.$i])->assertOk();
        }
        $this->postJson('/api/auth/login', ['email' => $t['email'], 'password' => $t['password']])->assertStatus(429);
        $this->assertNotNull(app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/auth/login', 'POST')));
        $this->assertContains('throttle:10,1', app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/auth/login', 'POST'))->gatherMiddleware());
    }

    public function test_a_token_stops_working_when_its_person_is_switched_off_or_removed(): void
    {
        $t = $this->registerTenant();
        $this->getJson('/api/v1/auth/me', $this->auth($t['token']))->assertOk();
        DB::table('admin_users')->where('id', $t['user_id'])->update(['status' => 'Inactive']);
        $this->getJson('/api/v1/auth/me', $this->auth($t['token']))->assertForbidden()
            ->assertJson(['code' => 0, 'message' => AccountAccess::SWITCHED_OFF, 'errors' => ['code' => 'account_inactive']]);
        DB::table('admin_users')->where('id', $t['user_id'])->update(['status' => 'Active']);

        // A member removed from the shop (membership not active) is refused; the owner never is.
        $company = \App\Models\Company::withoutGlobalScopes()->findOrFail($t['company_id']);
        $owner = User::withoutGlobalScopes()->findOrFail($t['user_id']);
        $member = app(TeamService::class)->createMember($company, $owner, 'Cash Ier', 'cashier_'.uniqid().'@example.com', null, 'cashier2026', 'cashier');
        $token = $member->createToken('phone')->plainTextToken;
        $this->getJson('/api/v1/auth/me', $this->auth($token))->assertOk();
        DB::table('company_members')->where('company_id', $company->id)->where('user_id', $member->id)->update(['status' => 'inactive']);
        $this->getJson('/api/v1/auth/me', $this->auth($token))->assertForbidden()->assertJson(['message' => AccountAccess::REMOVED, 'errors' => ['code' => 'account_inactive']]);

        DB::table('company_members')->updateOrInsert(['company_id' => $company->id, 'user_id' => $owner->id], ['role' => 'owner', 'status' => 'inactive']);
        $this->getJson('/api/v1/auth/me', $this->auth($t['token']))->assertOk();
    }

    public function test_a_new_password_ends_remember_me_elsewhere_on_every_path(): void
    {
        $t = $this->registerTenant();
        DB::table('admin_users')->where('id', $t['user_id'])->update(['remember_token' => 'old-remember-token']);

        $this->putJson('/api/v1/auth/password', ['current_password' => $t['password'], 'new_password' => 'brandnew2026', 'new_password_confirmation' => 'brandnew2026'], $this->auth($t['token']))->assertOk();
        $user = User::withoutGlobalScopes()->findOrFail($t['user_id']);
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertSame(60, strlen((string) $user->remember_token));

        // Any other save that leaves the password alone keeps it.
        $token = $user->remember_token;
        $user->first_name = 'Renamed';
        $user->save();
        $this->assertSame($token, $user->fresh()->remember_token);
        $user->password = Hash::make('another2026');
        $user->save();
        $this->assertNotSame($token, $user->fresh()->remember_token);
    }

    public function test_a_new_reset_code_cancels_the_earlier_open_tokens(): void
    {
        $t = $this->registerTenant();
        $first = OneTimeToken::issue(OneTimeToken::RESET, (int) $t['user_id'], $t['email'], OneTimeToken::RESET_MINUTES);
        $second = OneTimeToken::issue(OneTimeToken::RESET, (int) $t['user_id'], $t['email'], OneTimeToken::RESET_MINUTES);
        $this->assertNull(OneTimeToken::find(OneTimeToken::RESET, $first));
        $this->assertNotNull(OneTimeToken::find(OneTimeToken::RESET, $second));
        $this->postJson('/api/v1/auth/password/reset', ['reset_token' => $first, 'password' => 'newpass2026', 'password_confirmation' => 'newpass2026'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'reset_expired');

        // Sign-up tokens belong to an identifier; another identifier's token is left alone.
        $a = OneTimeToken::issue(OneTimeToken::REGISTER, null, '+256700111001', 30);
        $b = OneTimeToken::issue(OneTimeToken::REGISTER, null, '+256700111002', 30);
        $a2 = OneTimeToken::issue(OneTimeToken::REGISTER, null, '+256700111001', 30);
        $this->assertNull(OneTimeToken::find(OneTimeToken::REGISTER, $a));
        $this->assertNotNull(OneTimeToken::find(OneTimeToken::REGISTER, $b));
        $this->assertNotNull(OneTimeToken::find(OneTimeToken::REGISTER, $a2));
        // Another purpose is not touched either.
        $this->assertNotNull(OneTimeToken::find(OneTimeToken::RESET, $second));
    }

    /** The real verifier (tests fake it at the container, see Tests\TestCase). */
    private function realBreachCheck(): void
    {
        config(['saas.password_breach_check' => true]);
        $this->app->forgetInstance(UncompromisedVerifier::class);
    }

    public function test_an_offline_breach_check_lets_the_password_through_quietly(): void
    {
        $this->realBreachCheck();
        $this->assertInstanceOf(QuietNotPwnedVerifier::class, app(UncompromisedVerifier::class));
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));
        $handler = $this->spy(ExceptionHandler::class);
        Log::spy();

        $this->assertNull(PasswordPolicy::refusal('offline2026pass'));
        $this->assertNull(PasswordPolicy::refusal('offline2026again'));

        $handler->shouldNotHaveReceived('report');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'could not reach'))->once();
    }

    public function test_breach_api_errors_are_said_once(): void
    {
        $this->realBreachCheck();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);
        Log::spy();
        $this->assertNull(PasswordPolicy::refusal('offline2026pass'));
        $this->assertNull(PasswordPolicy::refusal('offline2026again'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'answered with an error') && ($context['status'] ?? null) === 503)->once();

        // A leaked password is still refused by this verifier: PasswordPolicyTest::test_the_breach_check_refuses_a_leaked_password_and_fails_open.
    }

    public function test_an_unknown_account_costs_a_password_check_too(): void
    {
        $master = Hash::make('a-master-password-of-20-chars');
        \Illuminate\Support\Facades\Cache::forever('signin:stand-in-hash:'.config('hashing.driver').':'.config('hashing.bcrypt.rounds').':'.config('hashing.argon.time'), Hash::make('stand-in'));
        Hash::spy();
        $this->assertFalse(SignInPassword::matches(null, 'whatever1'));
        Hash::shouldHaveReceived('check')->once();

        config(['saas.master_password_hash' => $master, 'saas.master_password_on_sign_in_forms' => true]);
        $this->assertFalse(SignInPassword::matches(null, 'whatever1'));
        Hash::shouldHaveReceived('check')->times(3); // one more for the master password a known account would get
    }
}
