<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Plan;
use App\Models\SaccoMemberLogin;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Sacco\Members\MemberLogin;
use App\Services\Sacco\Members\MemberService;
use App\Services\Sacco\SaccoSetupService;
use App\Services\Team\Permissions;
use Database\Seeders\AkibaPlanSeeder;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P1.9 (AK-19, AK-61): every SACCO role signs in like any other team login, and a member signs in to
 * the web self-service with their own sign-in, by password or by an email code. A member's sign-in opens their
 * memberships only (one person may be in two SACCOs), sees their own records only, and never holds a staff
 * permission.
 */
class MemberLoginTest extends ApiTestCase
{
    use MakesSaccoTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AkibaPlanSeeder::class);
    }

    /** @return array{0: Company, 1: User} */
    private function sacco(string $name): array
    {
        $t = $this->registerTenant(['modules' => ['sacco'], 'company_name' => $name]);
        $company = Company::find($t['company_id']);
        Subscription::where('company_id', $company->id)->update(['plan_id' => Plan::where('slug', 'akiba-enterprise')->value('id')]);
        $owner = User::withoutGlobalScopes()->find($t['user_id']);
        app(SaccoSetupService::class)->setup($company->fresh(), ['mode' => 'group', 'legal_name' => $name], $owner);

        return [$company->fresh(), $owner];
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return $e->errorCode();
        }

        return 'none';
    }

    public function test_every_sacco_role_signs_in_like_any_team_login(): void
    {
        [$company] = $this->sacco('Roles SACCO');
        foreach (self::SACCO_ROLES as $role) {
            $user = $this->saccoStaff($company, $role);
            $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret-pass-123'])->assertOk()->json('data.token');
            $me = $this->getJson('/api/v1/auth/me', $this->auth($token))->assertOk();
            $me->assertJsonPath('data.role', $role);
            Permissions::flush();
            $this->assertEqualsCanonicalizing(Permissions::forRole((int) $company->id, $role), $me->json('data.permissions'), $role);
            $this->assertContains('sacco_view', $me->json('data.permissions'));
            // The `web` guard (App\Models\User on admin_users, the portal's sign-in) accepts the same email and password.
            $this->assertTrue(auth('web')->validate(['email' => $user->email, 'password' => 'Secret-pass-123']), $role);
        }
    }

    public function test_a_member_signs_in_by_password_or_code_and_sees_only_their_own(): void
    {
        [$a, $ownerA] = $this->sacco('Alpha SACCO');
        [$b, $ownerB] = $this->sacco('Beta SACCO');
        $svc = app(MemberService::class);
        $email = 'member_'.uniqid().'@example.com';
        $inA = $svc->register($a, ['first_name' => 'Nakato', 'last_name' => 'Aisha', 'email' => $email, 'consent_data' => true], $ownerA)['member'];
        $inB = $svc->register($b, ['first_name' => 'Nakato', 'last_name' => 'Aisha', 'email' => $email, 'consent_data' => true], $ownerB)['member'];
        $other = $svc->register($a, ['first_name' => 'Okello', 'last_name' => 'Peter', 'consent_data' => true], $ownerA)['member'];
        $logins = app(MemberLogin::class);
        $logins->enable($a, (int) $inA->id, $ownerA, null, null, 'Member-pass-123');
        $logins->enable($b, (int) $inB->id, $ownerB);

        // Password: opens the membership whose password matches.
        $opened = $logins->attempt(strtoupper($email), 'Member-pass-123');
        $this->assertSame([[(int) $a->id, (int) $inA->id]], array_map(fn ($s) => [$s['company_id'], $s['member_id']], $opened));
        $this->assertSame('Alpha SACCO', $opened[0]['sacco']);
        $this->assertSame('invalid_credentials', $this->code(fn () => $logins->attempt($email, 'wrong-password')));
        $this->assertSame('invalid_credentials', $this->code(fn () => $logins->attempt('nobody@example.com', 'x')));

        // A code by email opens both memberships; the person picks one.
        $sent = $logins->requestCode($email);
        $this->assertTrue($sent['sent']);
        $code = cache()->get('otp_test_'.MemberLogin::otpKey($email));
        $both = $logins->verifyCode($email, (string) $code);
        $this->assertEqualsCanonicalizing([(int) $inA->id, (int) $inB->id], array_column($both, 'member_id'));
        $this->assertSame(['sent' => true, 'sent_to' => null], $logins->requestCode('nobody@example.com'), 'an unknown address looks the same');

        // The session resolves to the member, who owns their records only.
        $member = $logins->resolve((int) $a->id, (int) $inA->id);
        $this->assertTrue(MemberLogin::owns($member, $member));
        $this->assertTrue(MemberLogin::owns($member, ['company_id' => $a->id, 'member_id' => $inA->id]));
        $this->assertFalse(MemberLogin::owns($member, $other));
        $this->assertFalse(MemberLogin::owns($member, ['company_id' => $a->id, 'member_id' => $other->id]));
        $this->assertFalse(MemberLogin::owns($member, ['company_id' => $b->id, 'member_id' => $inA->id]));
        $this->assertSame('member_session_invalid', $this->code(fn () => $logins->resolve((int) $b->id, (int) $inA->id)), 'a membership of another SACCO');
        $this->assertSame('member_session_invalid', $this->code(fn () => $logins->resolve((int) $a->id, (int) $other->id)), 'no sign-in opened');

        // Never a staff login, never a staff permission.
        $this->assertNull(User::withoutGlobalScopes()->where('email', $email)->first());
        $this->assertStringNotContainsString('Member-pass', json_encode(SaccoMemberLogin::withoutGlobalScopes()->first()->toArray()));

        // A member who leaves, or a disabled sign-in, is signed out.
        $logins->disable($a, (int) $inA->id, $ownerA);
        $this->assertSame('member_session_invalid', $this->code(fn () => $logins->resolve((int) $a->id, (int) $inA->id)));
        $svc->setStatus($b, (int) $inB->id, 'suspended', $ownerB, 'Arrears');
        $this->assertSame('member_session_invalid', $this->code(fn () => $logins->resolve((int) $b->id, (int) $inB->id)));
    }

    public function test_wrong_passwords_lock_the_sign_in_and_only_an_officer_opens_one(): void
    {
        [$a, $owner] = $this->sacco('Lock SACCO');
        $m = app(MemberService::class)->register($a, ['first_name' => 'Achan', 'last_name' => 'Grace', 'email' => 'lock_'.uniqid().'@example.com', 'consent_data' => true], $owner)['member'];
        $teller = $this->saccoStaff($a, 'sacco_teller');
        $logins = app(MemberLogin::class);
        $this->assertSame('forbidden', $this->code(fn () => $logins->enable($a, (int) $m->id, $teller, null, null, 'Member-pass-123')));
        $this->assertSame('weak_password', $this->code(fn () => $logins->enable($a, (int) $m->id, $owner, null, null, 'short')));
        $logins->enable($a, (int) $m->id, $owner, null, null, 'Member-pass-123');
        for ($i = 0; $i < MemberLogin::MAX_FAILURES; $i++) {
            $this->code(fn () => $logins->attempt($m->email, 'nope'));
        }
        $this->assertSame('invalid_credentials', $this->code(fn () => $logins->attempt($m->email, 'Member-pass-123')), 'locked for a while');
        $this->travel(MemberLogin::LOCK_MINUTES + 1)->minutes();
        $this->assertCount(1, $logins->attempt($m->email, 'Member-pass-123'));

        $applicantSacco = $this->sacco('Office SACCO');
        $applicant = app(MemberService::class)->register($applicantSacco[0], ['first_name' => 'New', 'last_name' => 'Person', 'email' => 'new_'.uniqid().'@example.com', 'consent_data' => true], $applicantSacco[1])['member'];
        \App\Models\SaccoSetting::for((int) $applicantSacco[0]->id)->update(['mode' => 'office']);
        app(MemberService::class)->setStatus($applicantSacco[0], (int) $applicant->id, 'suspended', $applicantSacco[1], 'Test');
        $this->assertSame('member_not_active', $this->code(fn () => $logins->enable($applicantSacco[0], (int) $applicant->id, $applicantSacco[1])));
    }
}
