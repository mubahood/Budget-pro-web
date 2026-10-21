<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Team\TeamService;
use App\Support\Rules\PasswordPolicy;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\Feature\Admin\AdminTestCase;

/** One password rule everywhere a password is set (App\Support\Rules\PasswordPolicy, P0-2). */
class PasswordPolicyTest extends AdminTestCase
{
    private function fails(string $password): ?string
    {
        $v = Validator::make(['password' => $password], ['password' => PasswordPolicy::rules()]);

        return $v->fails() ? $v->errors()->first('password') : null;
    }

    public function test_the_rule_wants_six_characters_of_any_kind_in_plain_words(): void
    {
        foreach (['abc12', '1', ''] as $weak) {
            $this->assertNotNull($this->fails($weak), $weak);
        }
        $this->assertSame(PasswordPolicy::error(), $this->fails('abc1'));
        $this->assertNull($this->fails('kampala'), 'letters only is fine');
        $this->assertNull($this->fails('202620'), 'numbers only is fine');
        $this->assertNull($this->fails('shop2026'));
        $this->assertNull($this->fails('Nakawa market 9'));
        $this->assertSame('At least 6 characters.', PasswordPolicy::hint());
        $this->assertInstanceOf(\App\Support\Rules\PasswordRule::class, Password::default());
    }

    public function test_the_breach_check_refuses_a_leaked_password_and_fails_open(): void
    {
        // The real verifier, behind a fake of the range API (only the first five hash characters are sent).
        $this->app->forgetInstance(UncompromisedVerifier::class);
        $hash = strtoupper(sha1('shop2026'));
        Http::fake(['api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).":4100\r\n0018A45C4D1DEF81644B54AB7F969B88D65:1")]);
        $this->assertSame(PasswordPolicy::LEAKED, $this->fails('shop2026'));
        $this->assertNull($this->fails('Kampala road 77'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/range/'.substr($hash, 0, 5)));

        config(['saas.password_breach_check' => false]);
        $this->assertNull($this->fails('shop2026'));

        config(['saas.password_breach_check' => true]);
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 500)]);
        $this->assertNull(PasswordPolicy::refusal('Bugolobi shop 4'), 'no answer from the breach API lets the password through');
    }

    public function test_every_api_sign_up_and_change_uses_it(): void
    {
        $base = ['first_name' => 'Weak', 'last_name' => 'Pass', 'company_name' => 'Weak Co', 'currency' => 'UGX'];
        $this->postJson('/api/v1/auth/register', $base + ['email' => 'weak_'.uniqid().'@example.test', 'password' => 'secr'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', PasswordPolicy::error());
        $this->postJson('/api/v1/pingpin/auth/register', ['name' => 'Pin', 'email' => 'pin_'.uniqid().'@example.test', 'password' => 'abc12'])->assertStatus(422);
        // The legacy endpoint and the classic form validate with the same RegistrationService::rules().
        $this->assertTrue(Validator::make($base + ['email' => 'legacy_'.uniqid().'@example.test', 'password' => 'abc12'], \App\Services\Onboarding\RegistrationService::rules())->fails());
        $this->assertFalse(User::withoutGlobalScopes()->where('first_name', 'Weak')->exists());

        $ok = $this->postJson('/api/v1/auth/register', $base + ['email' => 'ok_'.uniqid().'@example.test', 'password' => 'secret123'])->assertStatus(201);
        $auth = ['Authorization' => 'Bearer '.$ok->json('data.token')];
        $this->putJson('/api/v1/auth/password', ['current_password' => 'secret123', 'new_password' => 'pass1', 'new_password_confirmation' => 'pass1'], $auth)
            ->assertStatus(422)->assertJsonPath('errors.new_password.0', PasswordPolicy::error());
        $this->putJson('/api/v1/auth/password', ['current_password' => 'secret123', 'new_password' => 'better2026', 'new_password_confirmation' => 'better2026'], $auth)->assertOk();
    }

    public function test_team_members_invites_and_the_service_use_it(): void
    {
        $t = $this->makeTenant('company');
        // TeamRules (the web portal's Team screen, add with a password and set a password).
        $this->assertTrue(Validator::make(['name' => 'Cashier One', 'password' => 'abc12', 'role' => 'cashier'], \App\Support\Rules\TeamRules::newMember())->fails());
        $this->assertTrue(Validator::make(['password' => 'abc12', 'password_confirmation' => 'abc12'], \App\Support\Rules\TeamRules::password())->fails());
        $this->assertFalse(Validator::make(['password' => 'counter2026', 'password_confirmation' => 'counter2026'], \App\Support\Rules\TeamRules::password())->fails());

        $member = app(TeamService::class)->createMember($t['company'], $t['user'], 'Cashier Two', 'c2_'.uniqid().'@example.test', null, 'counter2026', 'cashier');
        try {
            app(TeamService::class)->setPassword($t['company'], $t['user'], $member, 'abc12');
            $this->fail('a weak password was set');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame(PasswordPolicy::error(), $e->getMessage());
        }

        $invite = app(TeamService::class)->invite($t['user'], 'cashier', null, 'inv_'.uniqid().'@example.test');
        $tokenText = basename((string) $invite['link']);
        $this->post('/invite/'.$tokenText, ['first_name' => 'In', 'last_name' => 'Vite', 'password' => 'abc12', 'password_confirmation' => 'abc12'])->assertSessionHasErrors('password');
        $this->postJson('/api/v1/invites/'.$tokenText.'/accept', ['first_name' => 'In', 'last_name' => 'Vite', 'password' => '12345'])->assertStatus(422);
        $this->postJson('/api/v1/invites/'.$tokenText.'/accept', ['first_name' => 'In', 'last_name' => 'Vite', 'password' => 'joined2026'])->assertSuccessful();
    }

    public function test_classic_forms_use_it_and_show_the_hint(): void
    {
        $t = $this->makeTenant('company');
        $this->get('/auth/register')->assertSee(PasswordPolicy::hint(), false);

        $email = 'emp_'.uniqid().'@example.test';
        $employee = ['first_name' => 'Emp', 'last_name' => 'Loyee', 'sex' => 'Female', 'email' => $email, 'phone_number' => '+256700000002',
            'password' => 'abc12', 'password_confirmation' => 'abc12', 'team_role' => 'cashier', 'status' => 'Active'];
        $this->asAdmin($t['user'])->get('/employees/create')->assertSee(PasswordPolicy::hint(), false);
        $this->asAdmin($t['user'])->post('/employees', $employee);
        $this->assertNull(User::withoutGlobalScopes()->where('email', $email)->first());
        $this->asAdmin($t['user'])->post('/employees', ['password' => 'cashier2026', 'password_confirmation' => 'cashier2026'] + $employee)->assertRedirect();
        $this->assertNotNull(User::withoutGlobalScopes()->where('email', $email)->first());

        $this->asAdmin($t['user'])->get('/auth/setting')->assertSee(PasswordPolicy::hint(), false);
        $this->asAdmin($t['user'])->put('/auth/setting', ['name' => 'Owner', 'password' => 'abc12', 'password_confirmation' => 'abc12'])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('secret123', $t['user']->fresh()->password));
        // The stored hash sent back unchanged (laravel-admin's edit form) is not a new password.
        $hash = $t['user']->fresh()->password;
        $this->asAdmin($t['user'])->put('/auth/setting', ['name' => 'Owner Renamed', 'password' => $hash, 'password_confirmation' => $hash])->assertSessionHasNoErrors();
        $this->assertSame($hash, $t['user']->fresh()->password);
    }

    public function test_a_password_set_before_the_rule_still_signs_in(): void
    {
        $t = $this->makeTenant('company');
        DB::table('admin_users')->where('id', $t['user']->id)->update(['password' => Hash::make('abc')]);
        $this->postJson('/api/v1/auth/login', ['identifier' => $t['user']->email, 'password' => 'abc'])->assertOk();
        $this->post('/auth/login', ['username' => $t['user']->email, 'password' => 'abc'])->assertRedirect();
        $this->assertAuthenticatedAs($t['user'], 'admin');
    }
}
