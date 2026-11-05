<?php

namespace Tests\Feature\Rentals;

use App\Models\Company;
use App\Models\Plan;
use App\Models\RentalActivity;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalLogin;
use App\Models\RentalMaintenance;
use App\Models\RentalPayment;
use App\Models\RentalTenant;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Messaging\Messenger;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Payments\Payments;
use App\Services\Team\TeamService;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * Tenants' and landlords' own sign-in and records (KR-16, docs/estate-ledger/04-architecture.md §8, §9, §18): staff tokens and
 * person tokens never cross, closed sign-ins stop at once, wrong passwords lock, codes are limited, no answer says
 * whether a sign-in exists, a tenant's ID number or phone never reaches a landlord, and signed links expire and cannot
 * be altered or borrowed.
 */
class RentalsPersonApiTest extends ApiTestCase
{
    use MakesRentals;

    private const PASS = 'Tenant-pass-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An agency with a landlord, two tenants on it (one with an ID number and phone), payments and a payout. */
    private function agency(): array
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$p, $units] = $this->building($owner, 2, 300000);
        $a = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0, 'tenant' => ['name' => 'Asha', 'phone' => '0701111111', 'email' => 'asha'.uniqid().'@example.com', 'id_number' => 'CF90SECRET789Z', 'notes' => 'Staff note about Asha']]);
        $b = $this->tenantIn($owner, $units[1], '2026-03-01', ['deposit_minor' => 0, 'tenant' => ['name' => 'Ben', 'phone' => '0702222222', 'email' => 'ben'.uniqid().'@example.com', 'id_number' => 'CM80SECRET123B']]);
        $landlord = RentalLandlord::findOrFail($p->landlord_id);
        $landlord->forceFill(['email' => 'landlord'.uniqid().'@example.com', 'notes' => 'Staff note about the landlord'])->save();
        $payA = app(Payments::class)->receive($owner, $a, ['amount_minor' => 300000, 'method' => 'cash', 'notes' => 'Staff: paid late']);
        $payB = app(Payments::class)->receive($owner, $b, ['amount_minor' => 100000, 'method' => 'cash']);
        app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 5000, 'method' => 'cash']);

        return compact('company', 'owner', 'p', 'units', 'a', 'b', 'landlord', 'payA', 'payB');
    }

    private function open(User $by, string $type, int $id, array $over = []): RentalLogin
    {
        return app(RentalLogins::class)->enable($by, $type, $id, $over['email'] ?? null, $over['phone'] ?? null, $over['password'] ?? self::PASS);
    }

    private function signIn(string $login, string $password = self::PASS): string
    {
        return $this->postJson('/api/v1/rentals/portal-auth/login', ['login' => $login, 'password' => $password])->assertStatus(201)->json('data.token');
    }

    private function tenantToken(array $w, string $who = 'a'): array
    {
        $t = RentalTenant::findOrFail($w[$who]->tenant_id);
        $login = $this->open($w['owner'], 'tenant', (int) $t->id);

        return $this->auth($this->signIn((string) $login->email));
    }

    private function landlordToken(array $w): array
    {
        $login = $this->open($w['owner'], 'landlord', (int) $w['landlord']->id);

        return $this->auth($this->signIn((string) $login->email));
    }

    public function test_staff_tokens_and_person_tokens_never_cross(): void
    {
        $w = $this->agency();
        $person = $this->tenantToken($w);
        $staff = $this->auth($w['owner']->createToken('t')->plainTextToken);

        $this->getJson('/api/v1/rentals/me', $person)->assertOk()->assertJsonPath('data.type', 'tenant');
        $res = $this->getJson('/api/v1/rentals/me', $staff)->assertStatus(401);
        $this->assertSame([0, 'portal_session_invalid'], [$res->json('code'), $res->json('errors.code')]);
        $this->getJson('/api/v1/rentals/me')->assertStatus(401);
        $this->getJson('/api/v1/rentals/me', $this->auth('1|nonsense'))->assertStatus(401);

        foreach (['/api/v1/rentals/overview', '/api/v1/rentals/leases', '/api/v1/rentals/payments', '/api/v1/auth/me', '/api/v1/company'] as $path) {
            $this->getJson($path, $person)->assertStatus(401);
        }
        $this->postJson('/api/v1/rentals/payments', ['lease_id' => $w['a']->id, 'amount_minor' => 1, 'method' => 'cash'], $person)->assertStatus(401);

        // A token without the portal ability is not a person's token, whoever it belongs to.
        $login = RentalLogin::where('company_id', $w['company']->id)->firstOrFail();
        $this->getJson('/api/v1/rentals/me', $this->auth($login->createToken('x', ['other'])->plainTextToken))->assertStatus(401);
        $expired = $login->createToken('x', [RentalLogin::ABILITY], now()->subMinute())->plainTextToken;
        $this->getJson('/api/v1/rentals/me', $this->auth($expired))->assertStatus(401);
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_a_closed_sign_in_stops_at_once_and_opening_it_again_needs_a_new_sign_in(): void
    {
        $w = $this->agency();
        $tenant = RentalTenant::findOrFail($w['a']->tenant_id);
        $login = $this->open($w['owner'], 'tenant', (int) $tenant->id);
        $h = $this->auth($this->signIn((string) $login->email));
        $this->getJson('/api/v1/rentals/me', $h)->assertOk();
        // Staff's words.
        $this->getJson('/api/v1/rentals/tenants/'.$tenant->id, $this->auth($w['owner']->createToken('t')->plainTextToken))->assertJsonPath('data.has_login', true);

        app(RentalLogins::class)->disable($w['owner'], 'tenant', (int) $tenant->id);
        $this->getJson('/api/v1/rentals/me', $h)->assertStatus(401);
        $this->postJson('/api/v1/rentals/portal-auth/login', ['login' => $login->email, 'password' => self::PASS])->assertStatus(401)->assertJsonPath('errors.code', 'invalid_credentials');
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_type', RentalLogin::class)->where('tokenable_id', $login->id)->count());
        $this->postJson('/api/v1/rentals/portal-auth/code', ['login' => $login->email])->assertOk()->assertJsonPath('data.sent_to', null);

        // Open again: signing in works, the old token does not.
        $this->open($w['owner'], 'tenant', (int) $tenant->id);
        $this->getJson('/api/v1/rentals/me', $h)->assertStatus(401);
        $this->signIn((string) $login->email);

        // A person whose record is gone, or an account switched off, is signed out too.
        Company::withoutGlobalScopes()->whereKey($w['company']->id)->update(['status' => 'Inactive']);
        $t = $this->auth($login->createToken('x', [RentalLogin::ABILITY])->plainTextToken);
        $this->getJson('/api/v1/rentals/me', $t)->assertStatus(401);
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_five_wrong_passwords_lock_and_no_refusal_says_whether_a_sign_in_exists(): void
    {
        $w = $this->agency();
        $login = $this->open($w['owner'], 'tenant', (int) $w['a']->tenant_id);
        $unknown = $this->postJson('/api/v1/rentals/portal-auth/login', ['login' => 'nobody@example.com', 'password' => 'wrong-pass-1'])->assertStatus(401);
        for ($i = 0; $i < 5; $i++) {
            $res = $this->postJson('/api/v1/rentals/portal-auth/login', ['login' => $login->email, 'password' => 'wrong-pass-1'])->assertStatus(401);
            $this->assertSame($unknown->json(), $res->json(), 'the same answer as for someone who does not exist');
        }
        $this->assertTrue($login->fresh()->locked_until->isFuture());
        $locked = $this->postJson('/api/v1/rentals/portal-auth/login', ['login' => $login->email, 'password' => self::PASS])->assertStatus(401);
        $this->assertSame($unknown->json(), $locked->json(), 'locked looks the same too');
        $this->assertSame(1, RentalActivity::where('company_id', $w['company']->id)->where('action', 'login.locked')->count());

        Carbon::setTestNow(now()->addMinutes(16));
        $this->signIn((string) $login->email);
        $this->assertSame(0, (int) $login->fresh()->failures);

        // A phone number signs in as well, with its own lock counting per sign-in.
        $phone = $this->open($w['owner'], 'tenant', (int) $w['b']->tenant_id);
        $this->signIn('0702222222');
        $this->assertSame((string) $phone->phone_e164, '+256702222222');
        $this->postJson('/api/v1/rentals/portal-auth/login', [])->assertStatus(422);
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_codes_are_limited_and_their_answers_say_nothing_about_who_exists(): void
    {
        $w = $this->agency();
        $login = $this->open($w['owner'], 'tenant', (int) $w['a']->tenant_id);
        $email = (string) $login->email;
        $key = RentalLogins::otpKey($email);
        $max = (int) config('messaging.otp.max_per_hour');

        $nobody = $this->postJson('/api/v1/rentals/portal-auth/code', ['login' => 'nobody@example.com'])->assertOk()->json();
        for ($i = 0; $i < $max + 3; $i++) {
            $this->assertSame($nobody, $this->postJson('/api/v1/rentals/portal-auth/code', ['login' => $email])->assertOk()->json(), 'sent, limited or unknown: one answer');
        }
        $this->assertSame($max, DB::table('otp_codes')->where('identifier', $key)->count(), 'no more codes than the limit');
        $this->assertSame(0, DB::table('otp_codes')->where('identifier', RentalLogins::otpKey('nobody@example.com'))->count());

        // A wrong code, an old code and no sign-in at all are one refusal.
        $unknown = $this->postJson('/api/v1/rentals/portal-auth/code/verify', ['login' => 'nobody@example.com', 'code' => '000000'])->assertStatus(422)->json();
        $this->assertSame('otp_invalid', $unknown['errors']['code']);
        $wrong = $this->postJson('/api/v1/rentals/portal-auth/code/verify', ['login' => $email, 'code' => '000000'])->assertStatus(422)->json();
        $this->assertSame($unknown, $wrong);

        // The real code opens it, once; its token is a person's.
        $code = Cache::get('otp_test_'.$key);
        $res = $this->postJson('/api/v1/rentals/portal-auth/code/verify', ['login' => $email, 'code' => $code])->assertStatus(201);
        $this->assertSame('tenant', $res->json('data.person.type'));
        $this->assertSame($unknown, $this->postJson('/api/v1/rentals/portal-auth/code/verify', ['login' => $email, 'code' => $code])->assertStatus(422)->json());
        // Too many wrong tries burn the code.
        DB::table('otp_codes')->where('identifier', $key)->delete();
        $this->postJson('/api/v1/rentals/portal-auth/code', ['login' => $email])->assertOk();
        $fresh = Cache::get('otp_test_'.$key);
        for ($i = 0; $i < (int) config('messaging.otp.max_attempts') + 1; $i++) {
            $this->postJson('/api/v1/rentals/portal-auth/code/verify', ['login' => $email, 'code' => 'bad'.$i])->assertStatus(422);
        }
        $this->assertSame($unknown, $this->postJson('/api/v1/rentals/portal-auth/code/verify', ['login' => $email, 'code' => $fresh])->assertStatus(422)->json());
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_a_code_sent_for_a_phone_opens_only_the_sign_ins_with_the_email_it_went_to(): void
    {
        // The victim, in one agency; another agency types the same phone with its own email, and asks for a code.
        $w = $this->agency();
        [$other, $otherOwner] = $this->rentalsAccount();
        [, $units] = $this->building($otherOwner, 1);
        $trap = $this->tenantIn($otherOwner, $units[0], '2026-03-01', ['deposit_minor' => 0, 'tenant' => ['name' => 'Trap', 'phone' => '0709999999']]);
        $attacker = 'attacker'.uniqid().'@example.com';
        $this->open($otherOwner, 'tenant', (int) $trap->tenant_id, ['email' => $attacker, 'phone' => '0709999999', 'password' => 'Attacker-pass-2026']);
        // The agency that came first owns the row the code goes to; the victim signed up later.
        $victim = $this->open($w['owner'], 'tenant', (int) $w['a']->tenant_id, ['phone' => '0709999999']);

        $this->postJson('/api/v1/rentals/portal-auth/code', ['login' => '0709999999'])->assertOk();
        $code = Cache::get('otp_test_'.RentalLogins::otpKey('0709999999'));
        $res = $this->postJson('/api/v1/rentals/portal-auth/code/verify', ['login' => '0709999999', 'code' => $code])->assertStatus(201);
        $this->assertSame((int) $other->id, $res->json('data.person.account.id'), 'the attacker gets their own login only');
        $this->assertNull($res->json('data.choices'));
        $this->assertSame((int) $trap->tenant_id, $res->json('data.person.tenant.id'));
        $this->assertNull($victim->fresh()->last_login_at);
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_a_demo_account_never_sends_a_code(): void
    {
        $w = $this->agency();
        $spy = new class() extends Messenger
        {
            public int $sent = 0;

            public function send(string $to, string $text, array $channels, array $meta = [], bool $now = false): int
            {
                $this->sent++;

                return 0;
            }
        };
        $this->app->instance(Messenger::class, $spy);
        $login = $this->open($w['owner'], 'tenant', (int) $w['a']->tenant_id);
        Company::withoutGlobalScopes()->whereKey($w['company']->id)->update(['is_demo' => 1]);
        $this->postJson('/api/v1/rentals/portal-auth/code', ['login' => $login->email])->assertOk()->assertJsonPath('data.sent', true);
        $this->assertSame(0, $spy->sent);
        // A password still gets a visitor in; without the demo flag the code goes.
        $this->signIn((string) $login->email);
        Company::withoutGlobalScopes()->whereKey($w['company']->id)->update(['is_demo' => 0, 'onboarding_state' => json_encode(['rentals_demo' => true])]);
        $this->postJson('/api/v1/rentals/portal-auth/code', ['login' => $login->email])->assertOk();
        $this->assertSame(0, $spy->sent, 'the Estate Ledger demo marker counts');
        Company::withoutGlobalScopes()->whereKey($w['company']->id)->update(['onboarding_state' => null]);
        $this->postJson('/api/v1/rentals/portal-auth/code', ['login' => $login->email])->assertOk();
        $this->assertSame(1, $spy->sent);
    }

    public function test_one_login_with_several_accounts_chooses_with_a_single_use_ticket(): void
    {
        $w = $this->agency();
        [$other, $otherOwner] = $this->rentalsAccount();
        [, $units] = $this->building($otherOwner, 1);
        $second = $this->tenantIn($otherOwner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        $email = 'shared'.uniqid().'@example.com';
        $this->open($w['owner'], 'tenant', (int) $w['a']->tenant_id, ['email' => $email]);
        $this->open($otherOwner, 'tenant', (int) $second->tenant_id, ['email' => $email]);

        $res = $this->postJson('/api/v1/rentals/portal-auth/login', ['login' => $email, 'password' => self::PASS])->assertOk()->json('data');
        $this->assertCount(2, $res['choices']);
        $this->assertSame(['company_id', 'company_name', 'subject_type', 'subject_id', 'name'], array_keys($res['choices'][0]));
        $pick = ['ticket' => $res['ticket'], 'company_id' => (int) $other->id, 'subject_type' => 'tenant', 'subject_id' => (int) $second->tenant_id];
        $this->postJson('/api/v1/rentals/portal-auth/token', ['subject_id' => (int) $w['b']->tenant_id] + $pick)->assertStatus(401);
        $this->postJson('/api/v1/rentals/portal-auth/token', $pick)->assertStatus(401);

        $again = $this->postJson('/api/v1/rentals/portal-auth/login', ['login' => $email, 'password' => self::PASS])->json('data');
        $pick['ticket'] = $again['ticket'];
        $this->postJson('/api/v1/rentals/portal-auth/token', $pick + ['device_name' => 'Phone'])->assertStatus(201)->assertJsonPath('data.person.account.id', (int) $other->id);
        $this->postJson('/api/v1/rentals/portal-auth/token', $pick)->assertStatus(401);
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_a_tenant_sees_their_own_and_nothing_of_anyone_elses(): void
    {
        $w = $this->agency();
        $h = $this->tenantToken($w);
        $me = $this->getJson('/api/v1/rentals/me', $h)->assertOk()->json('data');
        $this->assertSame(['type', 'account', 'tenant'], array_keys($me));
        $this->assertSame(['id', 'name', 'currency', 'payment_instructions', 'phone'], array_keys($me['account']));
        $this->assertSame('CF••••••••789Z', $me['tenant']['id_number_masked']);
        $this->assertNull($me['tenant']['notes'], 'no staff notes');
        $this->assertStringNotContainsString('CF90SECRET789Z', (string) json_encode($me));

        // Their own.
        $lease = $this->getJson("/api/v1/rentals/me/lease/{$w['a']->id}", $h)->assertOk()->json('data');
        $this->assertNull($lease['landlord']);
        $this->assertNull($lease['commission']);
        $this->assertSame([], $lease['follow_ups']);
        $this->assertSame((int) $w['a']->id, $this->getJson('/api/v1/rentals/me/charges', $h)->assertOk()->json('data.0.lease_id'));
        $list = $this->getJson('/api/v1/rentals/me/payments', $h)->assertOk()->json();
        $this->assertSame(1, $list['meta']['pagination']['total']);
        $pay = $this->getJson("/api/v1/rentals/me/payments/{$w['payA']->id}", $h)->assertOk()->json('data');
        $this->assertSame(['charge_id', 'description', 'source', 'amount_minor'], array_keys($pay['allocations'][0]), 'what it settled, not how the agency and the landlord share it');
        $this->assertNull($pay['notes'], 'staff notes stay with the staff');
        $this->getJson("/api/v1/rentals/me/payments/{$w['payA']->id}/receipt", $h)->assertOk()->assertJsonStructure(['data' => ['text', 'pdf_url']]);

        // Someone else's, same account: as if it did not exist.
        foreach (["lease/{$w['b']->id}", "payments/{$w['payB']->id}", "payments/{$w['payB']->id}/receipt"] as $path) {
            $this->getJson("/api/v1/rentals/me/{$path}", $h)->assertStatus(404)->assertJsonPath('errors.code', 'not_found');
        }
        // A request parameter cannot steer it.
        $this->assertSame([], $this->getJson("/api/v1/rentals/me/charges?lease_id={$w['b']->id}", $h)->assertOk()->json('data'));
        $this->assertSame([], $this->getJson("/api/v1/rentals/me/payments?lease_id={$w['b']->id}", $h)->assertOk()->json('data'));
        // Another account's.
        [, $otherOwner] = $this->rentalsAccount();
        [, $units] = $this->building($otherOwner, 1);
        $foreign = $this->tenantIn($otherOwner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        $this->getJson("/api/v1/rentals/me/lease/{$foreign->id}", $h)->assertStatus(404);
        // A landlord's endpoints.
        foreach (['properties', 'statement', 'payouts', 'arrears'] as $path) {
            $this->getJson("/api/v1/rentals/me/{$path}", $h)->assertStatus(404)->assertJsonPath('errors.code', 'not_found');
        }
        $this->postJson('/api/v1/rentals/me/logout', [], $h)->assertOk();
        $this->getJson('/api/v1/rentals/me', $h)->assertStatus(401);
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_a_landlord_sees_their_own_and_never_a_tenants_id_number_or_phone(): void
    {
        $w = $this->agency();
        Carbon::setTestNow('2026-03-20 09:00:00'); // overdue, so the arrears list has Ben
        $h = $this->landlordToken($w);
        $bodies = [];
        foreach (['', '/properties', '/statement?month=2026-03', '/payouts', '/arrears'] as $path) {
            $bodies[$path] = $this->getJson('/api/v1/rentals/me'.$path, $h)->assertOk()->getContent();
        }
        $all = implode(' ', $bodies);
        $this->assertStringContainsString('Ben', $bodies['/arrears']);
        $this->assertStringContainsString('Asha', $bodies['/properties'], 'who pays, by name');
        foreach (['0701111111', '0702222222', '+256701111111', '256701', 'CF90SECRET789Z', 'CM80SECRET123B', 'CF••', 'id_number', 'Staff note', 'next_of_kin'] as $secret) {
            $this->assertStringNotContainsString($secret, $all, $secret);
        }
        $me = json_decode($bodies[''], true)['data'];
        $this->assertSame(['type', 'account', 'landlord', 'totals'], array_keys($me));
        $this->assertSame(['currency', 'owed_minor', 'collected_month_minor', 'occupancy'], array_keys($me['totals']));
        $this->assertSame(['units' => 2, 'occupied' => 2, 'vacant' => 0], $me['totals']['occupancy']);
        // Rent collected 400000 less 10 %, less the 5000 payout.
        $this->assertSame(355000, $me['totals']['owed_minor']);
        $units = json_decode($bodies['/properties'], true)['data'][0]['units'];
        $this->assertSame(['A1', 'A2'], array_column($units, 'code'));
        $this->assertArrayNotHasKey('phone', $units[0]['lease']['tenant']);
        $this->assertNull(json_decode($bodies['/properties'], true)['data'][0]['staff'] ?: null, 'no staff list');
        $this->getJson('/api/v1/rentals/me/statement?month=banana', $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_invalid');
        $this->getJson('/api/v1/rentals/me/statement?month=2030-01', $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_in_future');

        // Another landlord's, in the same account: nothing crosses, and a tenant's endpoints are not theirs.
        [$q] = $this->building($w['owner'], 1);
        $this->assertNotContains((int) $q->id, array_column($this->getJson('/api/v1/rentals/me/properties', $h)->json('data'), 'id'));
        foreach (['charges', 'payments', 'maintenance', "lease/{$w['a']->id}", "payments/{$w['payA']->id}"] as $path) {
            $this->getJson("/api/v1/rentals/me/{$path}", $h)->assertStatus(404);
        }
        $this->postJson('/api/v1/rentals/me/notice', ['move_out_on' => '2026-04-01'], $h)->assertStatus(404);
        $this->postJson('/api/v1/rentals/me/maintenance', ['title' => 'x'], $h)->assertStatus(404);
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_signed_download_links_expire_refuse_tampering_and_follow_the_sign_in(): void
    {
        $w = $this->agency();
        $tenant = $this->tenantToken($w);
        $url = $this->getJson("/api/v1/rentals/me/payments/{$w['payA']->id}/receipt", $tenant)->json('data.pdf_url');
        $res = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());

        // Tampering: any changed parameter breaks the signature, and the signature alone opens nothing.
        $this->get(str_replace('payment_id='.$w['payA']->id, 'payment_id='.$w['payB']->id, $url))->assertStatus(403)->assertJsonPath('errors.code', 'link_expired');
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature=abc', $url))->assertStatus(403);
        $this->get(preg_replace('/&?signature=[0-9a-f]+/', '', $url))->assertStatus(403);

        // Wrong account: a signed link for a person who is not this payment's tenant opens nothing.
        $ben = RentalLogin::find($this->open($w['owner'], 'tenant', (int) $w['b']->tenant_id)->id);
        $link = \App\Services\Rentals\RentalLinks::receiptPdf($w['payA'], 'portal', (int) $ben->id);
        $this->get($link['url'])->assertStatus(404);
        $landlord = $this->open($w['owner'], 'landlord', (int) $w['landlord']->id);
        $this->get(\App\Services\Rentals\RentalLinks::receiptPdf($w['payA'], 'portal', (int) $landlord->id)['url'])->assertStatus(404);
        [, $stranger] = $this->rentalsAccount();
        $this->get(\App\Services\Rentals\RentalLinks::receiptPdf($w['payA'], 'staff', (int) $stranger->id)['url'])->assertStatus(404);

        // Expiry: half an hour.
        Carbon::setTestNow(now()->addMinutes(29));
        $this->get($url)->assertOk();
        Carbon::setTestNow(now()->addMinutes(2));
        $this->get($url)->assertStatus(403)->assertJsonPath('errors.code', 'link_expired');
        Carbon::setTestNow('2026-03-01 09:00:00');

        // A closed sign-in ends its links.
        app(RentalLogins::class)->disable($w['owner'], 'tenant', (int) $w['a']->tenant_id);
        $this->get($url)->assertStatus(404);

        // A landlord's statement link.
        $lh = $this->auth($this->signIn((string) $landlord->email));
        $st = $this->getJson('/api/v1/rentals/me/statement?month=2026-03&format=pdf', $lh)->assertOk()->json('data');
        $this->assertSame(1800, $st['expires_in']);
        $this->assertStringStartsWith('%PDF', $this->get($st['url'])->assertOk()->getContent());
        $this->get(str_replace('landlord_id='.$w['landlord']->id, 'landlord_id=1', $st['url']))->assertStatus(403);
        $this->get(\App\Services\Rentals\RentalLinks::statementPdf($w['landlord'], '2026-03-01', '2026-03-31', 'portal', (int) $ben->id)['url'])->assertStatus(404);

        // A staff link follows the staff member's role: a caretaker's asked-for statement is refused.
        $caretaker = $this->rentalsStaff($w['company'], 'rentals_caretaker');
        $this->get(\App\Services\Rentals\RentalLinks::statementPdf($w['landlord'], '2026-03-01', '2026-03-31', 'staff', (int) $caretaker->id)['url'])->assertStatus(404);
        $this->get(\App\Services\Rentals\RentalLinks::receiptPdf($w['payA'], 'staff', (int) $caretaker->id)['url'])->assertStatus(404);

        // A file link: of this account only, for its week.
        $uuid = (string) Str::uuid();
        \App\Services\Rentals\PrivateFiles::put("files/{$w['company']->id}/{$uuid}.pdf", '%PDF-1.4 x');
        StoredFile::withoutGlobalScopes()->create(['company_id' => $w['company']->id, 'uuid' => $uuid, 'purpose' => 'rental_photo', 'path' => "files/{$w['company']->id}/{$uuid}.pdf", 'mime' => 'application/pdf', 'size_bytes' => 10]);
        $this->assertNull(\App\Services\Rentals\RentalLinks::file((int) $w['company']->id, $uuid), 'a private file is linked for a named person only');
        $file = \App\Services\Rentals\RentalLinks::file((int) $w['company']->id, $uuid, 'staff', (int) $w['owner']->id);
        $this->get($file)->assertOk();
        $this->assertNull(\App\Services\Rentals\RentalLinks::file((int) $stranger->company_id, $uuid, 'staff', (int) $stranger->id), 'another account gets no link');
        $this->get(str_replace('company_id='.$w['company']->id, 'company_id='.$stranger->company_id, $file))->assertStatus(403);
        Carbon::setTestNow(now()->addDays(9));
        $this->get($file)->assertStatus(403);
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_a_tenant_reports_a_problem_gives_notice_and_changes_their_password(): void
    {
        $w = $this->agency();
        $h = $this->tenantToken($w);
        $bh = $this->tenantToken($w, 'b');

        // Photos: their own, uploaded here; never another tenant's file.
        $uuid = (string) Str::uuid();
        $this->post('/api/v1/rentals/me/files', ['uuid' => $uuid, 'file' => UploadedFile::fake()->image('tap.jpg')], $h + ['Accept' => 'application/json'])->assertStatus(201);
        $this->post('/api/v1/rentals/me/files', ['uuid' => $uuid, 'file' => UploadedFile::fake()->image('tap.jpg')], $h + ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/v1/rentals/me/files', ['uuid' => $uuid, 'file' => UploadedFile::fake()->image('tap.jpg')], $bh + ['Accept' => 'application/json'])->assertStatus(409);
        $this->post('/api/v1/rentals/me/files', ['uuid' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('x.exe', 10)], $h + ['Accept' => 'application/json'])->assertStatus(422);
        $bens = (string) Str::uuid();
        $this->post('/api/v1/rentals/me/files', ['uuid' => $bens, 'file' => UploadedFile::fake()->image('b.jpg')], $bh + ['Accept' => 'application/json'])->assertStatus(201);

        $ticket = (string) Str::uuid();
        $m = $this->postJson('/api/v1/rentals/me/maintenance', ['uuid' => $ticket, 'title' => 'Tap leaking', 'priority' => 'high', 'photos' => [$uuid, $bens]], $h)->assertStatus(201)->json('data');
        $this->assertSame([$uuid], array_column($m['photos'], 'uuid'), 'only their own photo');
        $this->assertNull($m['cost_minor']);
        $this->assertSame('tenant', $m['reported_by']);
        $this->postJson('/api/v1/rentals/me/maintenance', ['uuid' => $ticket, 'title' => 'Tap leaking'], $h)->assertOk()->assertJsonPath('data.id', $m['id']);
        $this->postJson('/api/v1/rentals/me/maintenance', ['uuid' => $ticket, 'title' => 'Tap leaking'], $bh)->assertStatus(409);
        $this->postJson('/api/v1/rentals/me/maintenance', ['title' => ''], $h)->assertStatus(422)->assertJsonPath('errors.code', 'title_required');
        $this->assertSame((int) $w['a']->tenant_id, (int) RentalMaintenance::findOrFail($m['id'])->tenant_id);
        $this->assertSame($m['id'], $this->getJson('/api/v1/rentals/me/maintenance', $h)->assertOk()->json('data.0.id'));
        $this->assertSame([], $this->getJson('/api/v1/rentals/me/maintenance', $bh)->assertOk()->json('data'));

        // Notice is a request: the lease and its bills do not change until the office confirms it.
        $n = (string) Str::uuid();
        $this->postJson('/api/v1/rentals/me/notice', ['uuid' => $n, 'move_out_on' => '2026-02-01'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_invalid');
        $this->postJson('/api/v1/rentals/me/notice', ['uuid' => $n, 'move_out_on' => 'soon'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_required');
        $res = $this->postJson('/api/v1/rentals/me/notice', ['uuid' => $n, 'move_out_on' => '2026-05-31', 'notes' => 'Moving town'], $h)->assertStatus(201);
        $this->assertSame('requested', $res->json('data.status'));
        $this->postJson('/api/v1/rentals/me/notice', ['uuid' => $n, 'move_out_on' => '2026-05-31'], $h)->assertOk();
        $this->assertSame('active', $w['a']->fresh()->status);
        $this->assertSame('2026-05-31', $this->getJson("/api/v1/rentals/me/lease/{$w['a']->id}", $h)->json('data.notice_request.move_out_on'));
        $this->assertSame(1, DB::table('rental_follow_ups')->where('lease_id', $w['a']->id)->count());
        // Confirmed, then withdrawn: it is no longer waiting on anyone.
        app(Leases::class)->notice($w['owner'], $w['a']->fresh(), null, '2026-05-31');
        $this->assertNull($this->getJson("/api/v1/rentals/me/lease/{$w['a']->id}", $h)->json('data.notice_request'));
        app(Leases::class)->withdrawNotice($w['owner'], $w['a']->fresh());
        $this->assertNull($this->getJson("/api/v1/rentals/me/lease/{$w['a']->id}", $h)->json('data.notice_request'));

        // Their own password.
        $this->postJson('/api/v1/rentals/me/password', ['password' => '123'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'weak_password');
        $this->postJson('/api/v1/rentals/me/password', ['password' => 'New-Tenant-pass-77'], $h)->assertOk();
        $email = RentalLogin::where('subject_type', 'tenant')->where('subject_id', $w['a']->tenant_id)->value('email');
        $this->postJson('/api/v1/rentals/portal-auth/login', ['login' => $email, 'password' => self::PASS])->assertStatus(401);
        $this->signIn((string) $email, 'New-Tenant-pass-77');

        // A landlord in owner mode has nothing to sign in to.
        [, $self] = $this->rentalsAccount('owner');
        [$sp] = $this->building($self, 1);
        try {
            app(RentalLogins::class)->enable($self, 'landlord', (int) $sp->landlord_id, 'x@example.com');
            $this->fail('owner mode');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('owner_mode', $e->errorCode());
        }
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_a_plan_without_the_portal_cannot_open_a_sign_in_and_a_caretaker_cannot_at_all(): void
    {
        $w = $this->agency();
        $h = $this->auth($w['owner']->createToken('t')->plainTextToken);
        $caretaker = $this->rentalsStaff($w['company'], 'rentals_caretaker');
        $this->postJson("/api/v1/rentals/tenants/{$w['a']->tenant_id}/login", ['email' => 'a@example.com'], $this->auth($caretaker->createToken('t')->plainTextToken))->assertStatus(403);
        $this->postJson("/api/v1/rentals/tenants/{$w['a']->tenant_id}/login", ['email' => 'not an email'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'email_invalid');
        $this->postJson("/api/v1/rentals/tenants/{$w['a']->tenant_id}/login", ['phone' => '12'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'phone_invalid');
        $this->postJson("/api/v1/rentals/tenants/{$w['a']->tenant_id}/login", ['password' => '123'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'weak_password');
        Subscription::where('company_id', $w['company']->id)->update(['plan_id' => Plan::where('slug', 'estate-ledger-free')->value('id')]);
        Company::withoutGlobalScopes()->find($w['company']->id)->unsetRelation('subscription');
        $this->postJson("/api/v1/rentals/tenants/{$w['a']->tenant_id}/login", ['email' => 'a@example.com'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'plan_limit_reached');
        $this->assertBooksAgree((int) $w['company']->id);
    }
}
