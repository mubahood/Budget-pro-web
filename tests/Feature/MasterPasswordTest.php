<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MasterPassword;
use App\Support\WebLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Admin\AdminTestCase;

/**
 * The master password (App\Support\MasterPassword, roadmap 02 §2) on the classic side: hashed, only from
 * allowed addresses, never for a platform admin or a switched-off account, every use recorded and alerted,
 * the session marked and time-limited; "Sign in as" from the Companies screen; master-password:set.
 */
class MasterPasswordTest extends AdminTestCase
{
    private const MASTER = 'correct-horse-battery-staple-42';

    private function configure(array $ips = ['127.0.0.1'], bool $forms = true): void
    {
        config([
            'saas.master_password_on_sign_in_forms' => $forms,
            'saas.master_password_hash' => Hash::make(self::MASTER),
            'saas.master_password_allowed_ips' => $ips,
            'saas.master_password_session_minutes' => 60,
            'saas.owner_alert_email' => 'owner-alerts@example.test',
            'saas.owner_alert_whatsapp' => '+256 700 111 222',
            'saas.new_ui_url' => 'https://portal.example.test',
        ]);
    }

    private function uses(): \Illuminate\Support\Collection
    {
        return DB::table(MasterPassword::TABLE)->orderBy('id')->get();
    }

    public function test_off_without_a_hash_and_a_plaintext_master_password_is_ignored(): void
    {
        $shop = $this->makeTenant('company')['user'];
        config(['saas.master_password_hash' => '', 'saas.master_password_allowed_ips' => ['127.0.0.1'], 'saas.master_password' => '111111']);

        $this->post('/auth/login', ['username' => $shop->email, 'password' => '111111'])->assertSessionHasErrors('username');
        $this->assertGuest('admin');
        $this->assertFalse(MasterPassword::matches('111111'));
        $this->assertSame(0, DB::table(MasterPassword::TABLE)->where('target_user_id', $shop->id)->count());
    }

    public function test_from_an_allowed_address_it_opens_a_shop_account_marked_and_alerted(): void
    {
        $this->configure(['10.0.0.0/8', '127.0.0.1']);
        $shop = $this->makeTenant('company')['user'];

        $this->post('/auth/login', ['username' => $shop->email, 'password' => self::MASTER, 'remember' => 1])->assertRedirect();

        $this->assertAuthenticatedAs($shop, 'admin');
        $this->assertNotNull(session(MasterPassword::SESSION_KEY));
        $this->assertEqualsWithDelta(now()->addMinutes(60)->getTimestamp(), (int) session(MasterPassword::SESSION_KEY), 5);
        $this->assertNull($shop->fresh()->remember_token, 'never "remember me" on a master session');
        $use = $this->uses()->last();
        $this->assertSame([(int) $shop->id, 'classic', 1, null, '127.0.0.1'], [(int) $use->target_user_id, $use->where, (int) $use->allowed, $use->refusal_reason, $use->ip]);
        $this->assertSame((int) $use->id, (int) session(MasterPassword::SESSION_USE_KEY));

        // One short message on email and one on WhatsApp (log drivers), never the password.
        $alerts = DB::table('message_log')->where('purpose', 'master_password')->orderBy('id')->get();
        $this->assertSame(['owner-alerts@example.test', '+256700111222'], $alerts->pluck('to')->all());
        $this->assertSame(['mail', 'whatsapp'], $alerts->pluck('channel')->all());
        foreach ($alerts as $alert) {
            $this->assertStringContainsString('Master password used', $alert->body);
            $this->assertStringContainsString($shop->email, $alert->body);
            $this->assertStringContainsString('classic sign-in', $alert->body);
            $this->assertStringNotContainsString(self::MASTER, $alert->body);
        }

        // The header says so on every classic page.
        $this->get('/setup')->assertOk()->assertSee('Signed in with the master password until', false);
    }

    public function test_from_any_other_address_it_is_a_wrong_password_but_still_recorded(): void
    {
        $this->configure(['203.0.113.7']);
        $shop = $this->makeTenant('company')['user'];

        $this->post('/auth/login', ['username' => $shop->email, 'password' => self::MASTER])
            ->assertSessionHasErrors(['username' => trans('auth.failed')]);
        $this->assertGuest('admin');
        $this->assertSame(['ip_not_allowed', 0], [$this->uses()->last()->refusal_reason, (int) $this->uses()->last()->allowed]);
        $this->assertStringContainsString('refused (ip not allowed)', (string) DB::table('message_log')->where('purpose', 'master_password')->value('body'));

        // An empty list allows nowhere.
        $this->configure([]);
        $this->assertFalse(MasterPassword::ipAllowed('127.0.0.1'));
        $this->post('/auth/login', ['username' => $shop->email, 'password' => self::MASTER])->assertSessionHasErrors('username');
        $this->assertGuest('admin');
    }

    public function test_it_never_opens_a_platform_admin_or_a_switched_off_account(): void
    {
        $this->configure();
        $admin = $this->makeTenant('admin')['user'];
        $this->post('/auth/login', ['username' => $admin->email, 'password' => self::MASTER])
            ->assertSessionHasErrors(['username' => MasterPassword::refusalMessage('platform_admin')]);
        $this->assertGuest('admin');
        $this->assertSame('platform_admin', $this->uses()->last()->refusal_reason);

        $off = $this->makeTenant('company')['user'];
        $off->forceFill(['status' => 'Inactive'])->save();
        $this->post('/auth/login', ['username' => $off->email, 'password' => self::MASTER])->assertSessionHasErrors('username');
        $this->assertGuest('admin');
        $this->assertSame('inactive', $this->uses()->last()->refusal_reason);

        // A wrong master password is just a failed sign-in: nothing recorded.
        $before = $this->uses()->count();
        $this->post('/auth/login', ['username' => $off->email, 'password' => 'not-the-master-password-99'])->assertSessionHasErrors('username');
        $this->assertSame($before, $this->uses()->count());
    }

    public function test_the_classic_master_session_ends_on_time_without_signing_out_the_person_elsewhere(): void
    {
        $this->configure();
        $shop = $this->makeTenant('company')['user'];
        $shop->forceFill(['remember_token' => 'their-own-remember-token'])->save();
        $this->post('/auth/login', ['username' => $shop->email, 'password' => self::MASTER]);
        $this->get('/setup')->assertOk();

        $this->travel(61)->minutes();
        $this->get('/setup')->assertRedirect(admin_url('auth/login'));
        $this->assertGuest('admin');
        $this->assertSame('their-own-remember-token', $shop->fresh()->remember_token, 'their own devices stay signed in');
    }

    public function test_sign_in_as_lands_in_the_portal_through_a_marked_web_link(): void
    {
        $this->configure();
        $admin = $this->makeTenant('admin')['user'];
        $t = $this->makeTenant('company');

        $this->asAdmin($admin)->get('/companies/'.$t['company']->id.'/sign-in-as')->assertOk()->assertSee('Sign in as this person', false);

        // The reason is required, 10 characters or more; a wrong master password is refused and not recorded.
        $this->asAdmin($admin)->post('/companies/'.$t['company']->id.'/sign-in-as', ['user_id' => $t['user']->id, 'reason' => 'help', 'master_password' => self::MASTER])
            ->assertSessionHasErrors('reason');
        $this->asAdmin($admin)->post('/companies/'.$t['company']->id.'/sign-in-as', ['user_id' => $t['user']->id, 'reason' => 'The owner asked for help on WhatsApp', 'master_password' => 'wrong-master-password-1'])
            ->assertSessionHasErrors(['master_password' => 'That master password is not right.']);
        $this->assertSame(0, $this->uses()->count());

        $response = $this->asAdmin($admin)->post('/companies/'.$t['company']->id.'/sign-in-as', [
            'user_id' => $t['user']->id, 'reason' => 'The owner asked for help on WhatsApp', 'master_password' => self::MASTER,
        ]);
        $url = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://portal.example.test/web-link?token=', $url);
        $use = $this->uses()->last();
        $this->assertSame([(int) $admin->id, (int) $t['user']->id, (int) $t['company']->id, 'sign_in_as', 'The owner asked for help on WhatsApp', 1],
            [(int) $use->used_by_admin_id, (int) $use->target_user_id, (int) $use->company_id, $use->where, $use->reason, (int) $use->allowed]);
        $this->assertStringContainsString('Sign in as by '.$admin->name, (string) DB::table('message_log')->where('purpose', 'master_password')->value('body'));

        // The link carries the session's end, signed: the portal marks the session with it.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $claim = WebLink::consume((string) $query['token']);
        $this->assertSame((int) $t['user']->id, $claim['user_id']);
        $this->assertEqualsWithDelta(now()->addMinutes(60)->getTimestamp(), (int) $claim['master_until'], 5);
    }

    public function test_sign_in_as_is_refused_from_other_addresses_for_admins_and_without_a_portal(): void
    {
        $this->configure(['198.51.100.0/24']);
        $admin = $this->makeTenant('admin')['user'];
        $other = $this->makeTenant('admin')['user'];
        $t = $this->makeTenant('company');
        $reason = 'Checking a stock count the owner reported';

        $this->asAdmin($admin)->post('/companies/'.$t['company']->id.'/sign-in-as', ['user_id' => $t['user']->id, 'reason' => $reason, 'master_password' => self::MASTER])
            ->assertSessionHasErrors(['master_password' => MasterPassword::refusalMessage('ip_not_allowed')]);
        $this->assertSame('ip_not_allowed', $this->uses()->last()->refusal_reason);

        $this->configure();
        DB::table('admin_users')->where('id', $other->id)->update(['company_id' => $t['company']->id]);
        $this->asAdmin($admin)->post('/companies/'.$t['company']->id.'/sign-in-as', ['user_id' => $other->id, 'reason' => $reason, 'master_password' => self::MASTER])
            ->assertSessionHasErrors(['master_password' => MasterPassword::refusalMessage('platform_admin')]);

        config(['saas.new_ui_url' => '']);
        $this->asAdmin($admin)->get('/companies/'.$t['company']->id.'/sign-in-as')->assertSee('no web portal address', false);
        $this->asAdmin($admin)->post('/companies/'.$t['company']->id.'/sign-in-as', ['user_id' => $t['user']->id, 'reason' => $reason, 'master_password' => self::MASTER])
            ->assertSessionHasErrors('master_password');

        // A shop user never reaches the screen.
        $this->asAdmin($t['user'])->get('/companies/'.$t['company']->id.'/sign-in-as')->assertForbidden();
    }

    public function test_master_password_set_prints_a_hash_line_and_refuses_short_or_mismatched_input(): void
    {
        $this->artisan('master-password:set')->expectsQuestion('New master password (20 characters or more)', 'too-short-1')
            ->expectsOutputToContain('Too short')->assertFailed();
        $this->artisan('master-password:set')->expectsQuestion('New master password (20 characters or more)', self::MASTER)
            ->expectsQuestion('Type it again', self::MASTER.'x')->expectsOutputToContain('did not match')->assertFailed();

        $this->artisan('master-password:set')->expectsQuestion('New master password (20 characters or more)', self::MASTER)
            ->expectsQuestion('Type it again', self::MASTER)->expectsOutputToContain("MASTER_PASSWORD_HASH='\$2y\$12\$")
            ->doesntExpectOutputToContain(self::MASTER)->assertSuccessful();
    }

    public function test_the_sign_in_forms_take_it_only_when_switched_on_and_sign_in_as_always_can(): void
    {
        $this->assertFalse((bool) config('saas.master_password_on_sign_in_forms'), 'off unless MASTER_PASSWORD_ON_SIGN_IN_FORMS=true');
        $this->configure(forms: false);
        $shop = $this->makeTenant('company')['user'];

        $this->post('/auth/login', ['username' => $shop->email, 'password' => self::MASTER])->assertSessionHasErrors(['username' => trans('auth.failed')]);
        $this->assertGuest('admin');
        $this->assertSame(0, $this->uses()->count(), 'not even checked: nothing recorded');
        $this->assertNull(MasterPassword::attempt(self::MASTER, $shop, MasterPassword::CLASSIC));

        $admin = $this->makeTenant('admin')['user'];
        $t = $this->makeTenant('company');
        $this->asAdmin($admin)->post('/companies/'.$t['company']->id.'/sign-in-as', [
            'user_id' => $t['user']->id, 'reason' => 'The owner asked for help on WhatsApp', 'master_password' => self::MASTER,
        ])->assertRedirect();
        $this->assertSame('sign_in_as', $this->uses()->last()->where);
    }

    public function test_the_master_session_also_ends_on_kernel_routes_outside_the_admin_prefix(): void
    {
        $this->configure();
        $shop = $this->makeTenant('company')['user'];
        $this->post('/auth/login', ['username' => $shop->email, 'password' => self::MASTER]);
        $this->get('/api/global-search?q=abc')->assertOk();

        $this->travel(61)->minutes();
        $this->get('/api/global-search?q=abc')->assertRedirect(admin_url('auth/login'));
        $this->assertGuest('admin');

        // A JSON caller is told why with a 401; a public page without the session mark is untouched.
        $this->travelBack();
        $this->post('/auth/login', ['username' => $shop->email, 'password' => self::MASTER]);
        $this->travel(61)->minutes();
        $this->getJson('/api/global-search?q=abc')->assertStatus(401)->assertJson(['message' => 'The master password session has ended. Sign in again to carry on.']);
        $this->get('/api/openapi.json')->assertOk();
    }

    public function test_a_master_session_cannot_make_itself_lasting_access_on_the_classic_side(): void
    {
        $this->configure();
        $t = $this->makeTenant('company');
        $shop = $t['user'];
        $hash = $shop->password;
        $this->post('/auth/login', ['username' => $shop->email, 'password' => self::MASTER]);

        // Their own password.
        $this->put('/auth/setting', ['name' => 'Owner', 'password' => 'takeover2026', 'password_confirmation' => 'takeover2026'])
            ->assertSessionHasErrors(['password' => MasterPassword::inSessionMessage('change this password')]);
        $this->assertSame($hash, $shop->fresh()->password);
        // The name is fine.
        $this->put('/auth/setting', ['name' => 'Renamed Owner', 'password' => $hash, 'password_confirmation' => $hash])->assertSessionHasNoErrors();

        // A new team member, or an invite.
        $email = 'support_'.uniqid().'@example.test';
        $this->post('/employees', ['first_name' => 'Back', 'last_name' => 'Door', 'sex' => 'Male', 'email' => $email, 'phone_number' => '+256700000009',
            'password' => 'backdoor2026', 'password_confirmation' => 'backdoor2026', 'team_role' => 'manager', 'status' => 'Active'])->assertSessionHasErrors('email');
        $this->assertFalse(User::withoutGlobalScopes()->where('email', $email)->exists());
        try {
            app(\App\Services\Team\TeamService::class)->invite($shop, 'manager', null, 'invitee_'.uniqid().'@example.test');
            $this->fail('an invite from a master session must be refused');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('master_session', $e->errorCode());
        }

        // Out of the master session (a plain sign-in), the same changes work.
        $this->get('/auth/logout');
        $this->post('/auth/login', ['username' => $shop->email, 'password' => 'secret123'])->assertRedirect();
        $this->assertFalse(MasterPassword::inSession());
        $this->put('/auth/setting', ['name' => 'Owner', 'password' => 'mine2026x', 'password_confirmation' => 'mine2026x'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('mine2026x', $shop->fresh()->password));
    }

    public function test_the_allow_list_uses_the_connecting_address_unless_proxies_are_named(): void
    {
        $this->configure(['203.0.113.7']);
        $shop = $this->makeTenant('company')['user'];
        $spoofed = ['REMOTE_ADDR' => '198.51.100.66', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'];

        // Trusting every proxy (`*`) must not let anyone write an allowed address into X-Forwarded-For.
        config(['app.trusted_proxies' => '*']);
        \Illuminate\Http\Request::setTrustedProxies(['198.51.100.66'], \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR);
        $request = \Illuminate\Http\Request::create('/auth/login', 'POST', [], [], [], $spoofed);
        $this->assertSame('203.0.113.7', $request->ip(), 'what the framework would believe');
        $this->assertSame('198.51.100.66', \App\Support\ClientAddress::of($request));
        $this->withServerVariables($spoofed)->post('/auth/login', ['username' => $shop->email, 'password' => self::MASTER])->assertSessionHasErrors('username');
        $this->assertGuest('admin');
        $this->assertSame(['ip_not_allowed', '198.51.100.66'], [$this->uses()->last()->refusal_reason, $this->uses()->last()->ip]);

        // A named proxy list: the forwarded address from that proxy counts.
        config(['app.trusted_proxies' => '198.51.100.66']);
        \Illuminate\Http\Request::setTrustedProxies(['198.51.100.66'], \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR); // the request above reset them
        $this->assertSame('203.0.113.7', \App\Support\ClientAddress::of($request));
        // Nothing configured: the connecting address.
        config(['app.trusted_proxies' => null, 'trustedproxy.proxies' => null]);
        $this->assertSame('198.51.100.66', \App\Support\ClientAddress::of($request));
        \Illuminate\Http\Request::setTrustedProxies([], \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR);
    }

    public function test_the_api_never_takes_the_master_password(): void
    {
        $this->configure();
        $shop = $this->makeTenant('company')['user'];
        $this->postJson('/api/v1/auth/login', ['identifier' => $shop->email, 'password' => self::MASTER])->assertStatus(422);
        $this->assertSame(0, $this->uses()->count());
        $this->assertInstanceOf(User::class, $shop);
    }
}
