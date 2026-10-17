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

    private function configure(array $ips = ['127.0.0.1']): void
    {
        config([
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

    public function test_the_api_never_takes_the_master_password(): void
    {
        $this->configure();
        $shop = $this->makeTenant('company')['user'];
        $this->postJson('/api/v1/auth/login', ['identifier' => $shop->email, 'password' => self::MASTER])->assertStatus(422);
        $this->assertSame(0, $this->uses()->count());
        $this->assertInstanceOf(User::class, $shop);
    }
}
