<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\User;
use App\Services\Auth\GoogleIdentities;
use App\Services\Onboarding\RegistrationService;
use App\Services\Onboarding\SiblingAccounts;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * One person, many apps (budget-pro-new/docs/accounts/01-one-person-many-apps.md): a sign-in says whether the app is there and how
 * to add it; joining adds, never merges or deletes; Google is found by its sub; a known email is never "already registered".
 */
class AccountsJoinTest extends TestCase
{
    use DatabaseTransactions;

    private const CLIENT = 'client-123.apps.googleusercontent.com';

    private const PASS = 'Join-Pass-2026!x';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.web_client_id' => self::CLIENT]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        SiblingAccounts::forgetReadiness();
        GoogleIdentities::forgetReadiness();
    }

    private function person(string $email, array $modules, string $type = 'retail'): array
    {
        return app(RegistrationService::class)->register(['first_name' => 'Amina', 'last_name' => 'One', 'email' => $email, 'password' => self::PASS,
            'company_name' => 'Amina Hardware', 'currency' => 'UGX', 'country' => 'UG', 'business_type' => $type, 'modules' => $modules]);
    }

    private function login(string $email, ?string $app): array
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => self::PASS, 'device_name' => 't'] + ($app ? ['app' => $app] : []))->assertOk()->json('data');
    }

    private function google(string $email, string $sub): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['aud' => self::CLIENT, 'iss' => 'https://accounts.google.com', 'sub' => $sub, 'email' => $email,
            'email_verified' => 'true', 'given_name' => 'Amina', 'family_name' => 'One', 'exp' => (string) (time() + 600)])]);
    }

    /** Sanctum keeps the first user for the whole test unless told to look again. */
    private function bearer(string $token): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function counts(): array
    {
        return ['companies' => DB::table('companies')->count(), 'users' => DB::table('admin_users')->count()];
    }

    public function test_a_family_app_is_added_to_the_books_the_person_has(): void
    {
        $r = $this->person('amina.join@example.com', ['shop', 'finance']);
        $d = $this->login('amina.join@example.com', 'pledge-and-budget');
        $this->assertSame(['app' => 'pledge-and-budget', 'name' => 'Pledge & Budget', 'has' => false, 'handoff' => true, 'add' => 'module', 'can_add' => true, 'books' => 'Amina Hardware'], $d['access']);

        $before = $this->counts();
        $j = $this->postJson('/api/v1/auth/join', ['app' => 'pledge-and-budget', 'device_name' => 't'], $this->bearer($d['token']))->assertOk()->json('data');
        $this->assertSame((int) $r['company']->id, (int) $j['company']['id'], 'the same books');
        $this->assertTrue($j['access']['has']);
        $this->assertSame($before, $this->counts(), 'nothing made, nothing removed');
        $this->assertContains('budget', Company::withoutGlobalScopes()->find($r['company']->id)->modules());
        $this->assertContains('shop', Company::withoutGlobalScopes()->find($r['company']->id)->modules(), 'what they had stays');
        // Asked again: nothing changes.
        $this->postJson('/api/v1/auth/join', ['app' => 'pledge-and-budget'], $this->bearer($j['token']))->assertOk()->assertJsonPath('message', 'You already have it.');
        // Budget Pro opens any family books.
        $this->assertTrue($this->login('amina.join@example.com', 'budget-pro')['access']['has']);
    }

    public function test_akiba_opens_its_own_books_beside_and_each_app_opens_its_own(): void
    {
        $r = $this->person('amina.sacco@example.com', ['shop', 'finance']);
        $d = $this->login('amina.sacco@example.com', 'akiba-ledger');
        $this->assertSame('sibling', $d['access']['add']);
        $this->assertNull($d['access']['books'], 'Akiba never names the other books');
        $this->postJson('/api/v1/auth/join', ['app' => 'akiba-ledger'], $this->bearer($d['token']))->assertStatus(422)->assertJsonPath('errors.code', 'name_required')->assertJsonPath('message', 'Name your SACCO.');

        $j = $this->postJson('/api/v1/auth/join', ['app' => 'akiba-ledger', 'name' => 'Amina Teachers SACCO'], $this->bearer($this->login('amina.sacco@example.com', 'akiba-ledger')['token']))->assertOk()->json('data');
        $this->assertNotSame((int) $r['company']->id, (int) $j['company']['id']);
        $this->assertContains('sacco', Company::withoutGlobalScopes()->find($j['company']['id'])->modules());
        $this->assertNotContains('shop', Company::withoutGlobalScopes()->find($j['company']['id'])->modules());
        $this->assertSame(['shop', 'finance'], Company::withoutGlobalScopes()->find($r['company']->id)->modules(), 'the shop is untouched');

        $this->assertSame((int) $j['company']['id'], (int) $this->login('amina.sacco@example.com', 'akiba-ledger')['company']['id']);
        $this->assertTrue($this->login('amina.sacco@example.com', 'akiba')['access']['has'], 'the old name works too');
        $this->assertSame((int) $r['company']->id, (int) $this->login('amina.sacco@example.com', 'shelfie')['company']['id']);
    }

    public function test_someone_whose_books_stand_alone_gets_family_books_beside_them(): void
    {
        $r = $this->person('amina.rent@example.com', ['rentals'], 'rentals');
        $d = $this->login('amina.rent@example.com', 'pledge-and-budget');
        $this->assertSame('sibling', $d['access']['add']);
        $j = $this->postJson('/api/v1/auth/join', ['app' => 'pledge-and-budget', 'name' => 'Wedding 2027'], $this->bearer($d['token']))->assertOk()->json('data');
        $this->assertContains('budget', Company::withoutGlobalScopes()->find($j['company']['id'])->modules());
        $this->assertNotContains('rentals', Company::withoutGlobalScopes()->find($j['company']['id'])->modules());

        // Kuku Tally finds those family books and offers to add poultry to them.
        $k = $this->login('amina.rent@example.com', 'kuku-tally');
        $this->assertSame((int) $j['company']['id'], (int) $k['company']['id']);
        $this->assertSame('module', $k['access']['add']);
        $this->assertSame((int) $r['company']->id, (int) $this->login('amina.rent@example.com', 'estate-ledger')['company']['id']);
    }

    public function test_a_known_email_is_never_already_registered(): void
    {
        $this->person('amina.known@example.com', ['shop', 'finance']);
        $res = $this->postJson('/api/v1/auth/register', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'amina.known@example.com', 'password' => self::PASS,
            'company_name' => 'X', 'currency' => 'UGX', 'country' => 'UG', 'device_name' => 't', 'app' => 'pledge-and-budget'])->assertStatus(422);
        $res->assertJsonPath('errors.code', 'email_taken')->assertJsonPath('errors.known_identity', true)->assertJsonPath('errors.proofs', ['password', 'code', 'google']);
        $this->assertSame('You already have an account with this email. Log in to add Pledge & Budget.', $res->json('message'));
        $this->assertStringNotContainsString('Amina', $res->getContent(), 'nothing about the account before proof');

        $this->postJson('/api/v1/auth/otp/request', ['identifier' => 'amina.known@example.com', 'purpose' => 'register'])->assertStatus(422)
            ->assertJsonPath('errors.code', 'already_registered')->assertJsonPath('errors.known_identity', true);
    }

    public function test_google_is_found_by_its_sub_and_can_be_connected_and_disconnected(): void
    {
        $r = $this->person('amina.g@example.com', ['shop', 'finance']);
        $d = $this->login('amina.g@example.com', 'shelfie');
        $this->assertTrue($d['connect_google']);
        $this->google('amina.personal@gmail.com', 'sub-amina');
        $this->postJson('/api/v1/auth/google/link', ['id_token' => 'x'], $this->bearer($d['token']))->assertOk()->assertJsonPath('data.google.0.email', 'amina.personal@gmail.com');
        $this->assertSame('amina.g@example.com', $r['user']->fresh()->email, 'their sign-in email is unchanged');

        // A different Gmail address on the same Google account still opens their books.
        $this->google('amina.renamed@gmail.com', 'sub-amina');
        $g = $this->postJson('/api/v1/auth/google', ['id_token' => 'x', 'device_name' => 't', 'app' => 'shelfie'])->assertOk()->json('data');
        $this->assertSame((int) $r['company']->id, (int) $g['company']['id']);
        $this->assertFalse($g['connect_google']);

        // Someone else can't take it.
        $other = $this->person('someone.else@example.com', ['shop', 'finance']);
        $o = $this->login('someone.else@example.com', null);
        $this->postJson('/api/v1/auth/google/link', ['id_token' => 'x'], $this->bearer($o['token']))->assertStatus(422)->assertJsonPath('errors.code', 'google_taken');

        $this->deleteJson('/api/v1/auth/google/link', [], $this->bearer($d['token']))->assertOk();
        $this->getJson('/api/v1/auth/sign-in-methods', $this->bearer($d['token']))->assertOk()->assertJsonPath('data.google', [])->assertJsonPath('data.email', 'amina.g@example.com');
        $this->assertNotNull($other);
    }

    public function test_connect_google_snoozes_and_stops_after_three(): void
    {
        $this->person('amina.n@example.com', ['shop', 'finance']);
        $t = $this->login('amina.n@example.com', null)['token'];
        $this->postJson('/api/v1/auth/google/nudge/dismiss', [], $this->bearer($t))->assertOk();
        $this->assertFalse($this->login('amina.n@example.com', null)['connect_google']);
        $this->travel(15)->days();
        $this->assertTrue($this->login('amina.n@example.com', null)['connect_google']);
        DB::table('admin_users')->where('email', 'amina.n@example.com')->update(['google_nudge_count' => 3, 'google_nudge_until' => null]);
        $this->assertFalse($this->login('amina.n@example.com', null)['connect_google']);
    }

    public function test_one_login_per_email_in_the_database(): void
    {
        $this->assertNotEmpty(DB::select("SHOW INDEX FROM admin_users WHERE Key_name = 'admin_users_email_unique'"));
    }
}
