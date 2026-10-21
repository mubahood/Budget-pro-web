<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Plan;
use App\Models\SaccoMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Quotas;
use App\Services\Sacco\Members\MemberService;
use App\Services\Sacco\Members\Nin;
use App\Services\Sacco\OfficerService;
use App\Services\Sacco\SaccoSetupService;
use Database\Seeders\AkibaPlanSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P1.9 (docs/akiba/04-architecture.md §3.1, AK-31, AK-47): members and KYC. The plan's member limit
 * holds (member 31 on Akiba Free is refused; money never is); the NIN is never in the audit log, an array or JSON
 * (only its last four); a second officer verifies identity; an import run twice adds nobody; twins are flagged,
 * not refused; every member is one SACCO's.
 */
class MemberServiceTest extends ApiTestCase
{
    use MakesSaccoTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AkibaPlanSeeder::class);
    }

    /** @return array{0: Company, 1: User, 2: array{token: string}} */
    private function sacco(string $mode = 'office', string $plan = 'akiba-enterprise'): array
    {
        $t = $this->registerTenant(['modules' => ['sacco']]);
        $company = Company::find($t['company_id']);
        Subscription::where('company_id', $company->id)->update(['plan_id' => Plan::where('slug', $plan === 'akiba-free' ? 'akiba-enterprise' : $plan)->value('id')]);
        $owner = User::withoutGlobalScopes()->find($t['user_id']);
        app(SaccoSetupService::class)->setup($company->fresh(), ['mode' => $mode, 'legal_name' => 'Members SACCO', 'licence_no' => 'UMRA/T4/MEM'], $owner); // licensed: the legal checks apply (AK-69)
        if ($plan === 'akiba-free') {
            Subscription::where('company_id', $company->id)->update(['plan_id' => Plan::where('slug', 'akiba-free')->value('id')]);
        }

        return [$company->fresh(), $owner, $t];
    }

    /** @return array<string, mixed> */
    private function person(array $over = []): array
    {
        return array_merge(['first_name' => 'Nakato', 'last_name' => 'Aisha '.uniqid(), 'gender' => 'female', 'date_of_birth' => '1990-04-02',
            'phone' => '0772'.random_int(100000, 999999), 'consent_data' => true], $over);
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

    public function test_the_plan_limit_refuses_member_31_on_free_and_counts_only_current_members(): void
    {
        [$company, $owner] = $this->sacco('group', 'akiba-free');
        $svc = app(MemberService::class);
        for ($i = 0; $i < 30; $i++) {
            $svc->register($company, $this->person(['phone' => null]), $owner);
        }
        $this->assertSame(30, app(Quotas::class)->used($company, 'members'));
        try {
            $svc->register($company, $this->person(), $owner);
            $this->fail('member 31 on Akiba Free');
        } catch (BusinessRuleException $e) {
            $this->assertSame('plan_limit_reached', $e->errorCode());
            $this->assertSame('max_members', $e->meta()['limit']);
            $this->assertSame('akiba-starter', $e->meta()['next_plan']);
        }
        // An exited member frees a place; usage shows members and SMS for a SACCO only.
        $one = SaccoMember::withoutGlobalScopes()->where('company_id', $company->id)->first();
        $svc->setStatus($company, (int) $one->id, 'exited', $owner, 'Moved away');
        $this->assertSame(29, app(Quotas::class)->used($company, 'members'));
        $usage = app(Quotas::class)->usage($company);
        $this->assertSame(['used' => 29, 'max' => 30, 'over' => false], $usage['members']);
        $this->assertArrayHasKey('sms', $usage);
        $this->assertSame(29, $this->getJson('/api/v1/auth/me', $this->auth($this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'secret123'])->json('data.token')))
            ->assertOk()->json('data.entitlements.usage.members.used'));
    }

    public function test_the_nin_never_leaves_and_its_reveal_is_audited(): void
    {
        [$company, $owner] = $this->sacco();
        $r = app(MemberService::class)->register($company, $this->person(['nin' => 'cm 9001 2345 6789 0x', 'consent_messages' => true]), $owner);
        $m = $r['member'];
        $this->assertSame('•••• 890X', $m->nin_masked);
        $this->assertSame(Nin::hash('CM90012345678 90X'), $m->nin_hash);
        $json = json_encode($m->fresh()->toArray());
        $this->assertStringNotContainsString('CM900123', $json);
        $this->assertStringNotContainsString((string) $m->nin_hash, $json);
        $this->assertStringNotContainsString('nin_encrypted', $json);
        $audit = DB::table('audit_logs')->where('company_id', $company->id)->where('model_type', SaccoMember::class)->get()->pluck('new_values')->implode(' ');
        $this->assertStringNotContainsString('CM900123', $audit);
        $this->assertStringNotContainsString((string) $m->nin_hash, $audit);
        $this->assertStringNotContainsString(DB::table('sacco_members')->where('id', $m->id)->value('nin_encrypted'), $audit);
        $this->assertNotNull($m->consent_data_at);
        $this->assertSame(MemberService::CONSENT_VERSION, $m->consent_version);
        $this->assertNotNull($m->consent_messages_at);
        $this->assertSame('M-000001', $m->member_no);

        $this->assertSame('CM9001234567890X', app(MemberService::class)->revealNin($company, (int) $m->id, $owner));
        $view = DB::table('audit_logs')->where('company_id', $company->id)->where('action', 'sacco.sensitive_view')->value('new_values');
        $this->assertStringContainsString('890X', $view);
        $this->assertStringNotContainsString('CM900123', $view);
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $this->assertSame('forbidden', $this->code(fn () => app(MemberService::class)->revealNin($company, (int) $m->id, $teller)));
        $this->assertSame('invalid_nin', $this->code(fn () => app(MemberService::class)->register($company, $this->person(['nin' => '12']), $owner)));
        $this->assertSame('consent_required', $this->code(fn () => app(MemberService::class)->register($company, $this->person(['consent_data' => false]), $owner)), 'consent is required');
    }

    public function test_a_second_officer_verifies_identity_and_status_follows_the_rules(): void
    {
        [$company, $owner] = $this->sacco();
        $secretary = $this->saccoStaff($company, 'sacco_secretary');
        $loans = $this->saccoStaff($company, 'sacco_loans_officer');
        $svc = app(MemberService::class);
        $m = $svc->register($company, $this->person(['nin' => 'CM9001234567891X']), $secretary)['member'];
        $this->assertSame('applicant', $m->status, 'office mode: an applicant first');
        $this->assertSame('kyc_required', $this->code(fn () => $svc->setStatus($company, (int) $m->id, 'active', $owner)));
        $this->assertSame('self_approval', $this->code(fn () => $svc->verifyKyc($company, (int) $m->id, $secretary, 'verified')), 'the capturer cannot verify');
        $this->assertSame('forbidden', $this->code(fn () => $svc->verifyKyc($company, (int) $m->id, $loans, 'verified')), 'needs sacco_kyc');
        $other = $this->saccoStaff($company, 'sacco_secretary');
        $this->assertSame('verified', $svc->verifyKyc($company, (int) $m->id, $other, 'verified', 'ID seen')->kyc_status);
        // P1.11: the entrance fee and the minimum shares come first.
        app(\App\Services\Sacco\Shares\ShareService::class)->payEntranceFee($company, ['member_id' => $m->id], $owner);
        app(\App\Services\Sacco\Shares\ShareService::class)->purchase($company, ['member_id' => $m->id, 'shares_count' => 5], $owner);
        $this->assertSame('active', $svc->setStatus($company, (int) $m->id, 'active', $owner)->status);
        $this->assertSame('invalid_status_change', $this->code(fn () => $svc->setStatus($company, (int) $m->id, 'applicant', $owner)));
        $this->assertSame('reason_required', $this->code(fn () => $svc->setStatus($company, (int) $m->id, 'suspended', $owner)));
        // A new ID number is checked again.
        $this->assertSame('pending', $svc->update($company, (int) $m->id, ['nin' => 'CM9001234567892X'], $owner)->kyc_status);
        $this->assertSame('member_history', $this->code(fn () => SaccoMember::withoutGlobalScopes()->find($m->id)->delete()));

        // Group mode: members are active at once.
        [$group, $gOwner] = $this->sacco('group');
        $this->assertSame('active', $svc->register($group, $this->person(), $gOwner)['member']->status);
        // No age limits, no required guardian (AK-69): a five-year-old joins an unregistered group.
        $this->assertSame('active', $svc->register($group, $this->person(['date_of_birth' => now()->subYears(5)->toDateString()]), $gOwner)['member']->status);
    }

    public function test_nominations_add_up_and_have_two_witnesses(): void
    {
        [$company, $owner] = $this->sacco();
        $svc = app(MemberService::class);
        $m = $svc->register($company, $this->person(), $owner)['member'];
        app(\App\Services\Sacco\Shares\ShareService::class)->purchase($company, ['member_id' => $m->id, 'shares_count' => 2], $owner); // two nominees need two shares (P1.11)
        $this->assertSame('nomination_total', $this->code(fn () => $svc->nominate($company, (int) $m->id, [['name' => 'A', 'share_bp' => 6000], ['name' => 'B', 'share_bp' => 3000]], 'W1', 'W2', null, $owner)));
        $this->assertSame('witnesses_required', $this->code(fn () => $svc->nominate($company, (int) $m->id, [['name' => 'A', 'share_bp' => 10000]], 'W1', 'w1', null, $owner)));
        $first = $svc->nominate($company, (int) $m->id, [['name' => 'Son', 'relationship' => 'son', 'share_bp' => 6000], ['name' => 'Daughter', 'share_bp' => 4000]], 'Okello', 'Achan', null, $owner);
        $this->assertCount(2, $first);
        $svc->nominate($company, (int) $m->id, [['name' => 'Wife', 'share_bp' => 10000, 'nin' => 'CF9001234567890Y']], 'Okello', 'Achan', null, $owner);
        $this->assertSame(1, DB::table('sacco_member_relations')->where('member_id', $m->id)->where('role', 'nominee')->whereNull('ended_on')->count());
        $this->assertSame(3, DB::table('sacco_member_relations')->where('member_id', $m->id)->count(), 'old nominees are ended, not deleted');
        $this->assertStringNotContainsString('CF9001', json_encode(\App\Models\SaccoMemberRelation::withoutGlobalScopes()->where('member_id', $m->id)->get()->toArray()));
        $this->assertNotNull($svc->addNextOfKin($company, (int) $m->id, 'Brother', 'brother', '0772000111', $owner)->id);
    }

    public function test_import_is_idempotent_by_row_dry_run_first_and_twins_are_flagged(): void
    {
        [$company, $owner] = $this->sacco();
        $svc = app(MemberService::class);
        $csv = "first_name,last_name,gender,date_of_birth,nin,phone,occupation,consent\n"
            ."Okello,Peter,male,1985-01-01,CM8501011234567A,0772111001,Teacher,yes\n"
            ."Achan,Grace,female,1988-02-02,,0772111002,Nurse,yes\n"
            ."Okello,Peter,male,1985-01-01,CM8501011234567A,0772111001,Teacher,yes\n"
            ."Bad,,male,1985-01-01,,not-a-phone,,yes\n";
        $dry = $svc->import($company, $csv, $owner, true);
        $this->assertSame(['dry_run' => true, 'rows' => 4, 'created' => 2], array_intersect_key($dry, array_flip(['dry_run', 'rows', 'created'])));
        $this->assertCount(1, $dry['skipped']);
        $this->assertCount(1, $dry['errors']);
        $this->assertSame(0, SaccoMember::withoutGlobalScopes()->where('company_id', $company->id)->count(), 'a dry run writes nothing');
        $this->assertSame(0, $svc->import($company, $csv, $owner, false)['created'], 'nothing is written while a row has an error');

        $good = "first_name,last_name,gender,date_of_birth,nin,phone,occupation,consent\nOkello,Peter,male,1985-01-01,CM8501011234567A,0772111001,Teacher,yes\nAchan,Grace,female,1988-02-02,,0772111002,Nurse,yes\n";
        $this->assertSame(2, $svc->import($company, $good, $owner, false)['created']);
        $again = $svc->import($company, $good, $owner, false);
        $this->assertSame(0, $again['created']);
        $this->assertSame([['row' => 2, 'reason' => 'already imported'], ['row' => 3, 'reason' => 'already imported']], $again['skipped']);
        $this->assertSame(2, SaccoMember::withoutGlobalScopes()->where('company_id', $company->id)->count());

        // A twin registered by hand (same phone, same ID) is kept and flagged.
        $r = $svc->register($company, $this->person(['first_name' => 'Peter', 'last_name' => 'Okello', 'phone' => '0772111001', 'nin' => 'CM8501011234567A']), $owner);
        $this->assertSame('nin', $r['duplicates'][0]['match'], 'the strongest match is named');
        $this->assertSame('M-000001', $r['duplicates'][0]['member_no']);
        $groups = collect($svc->duplicates($company));
        $this->assertNotNull($groups->firstWhere('match', 'nin'));
        $this->assertNotNull($groups->firstWhere('match', 'phone'));
        $this->assertSame('merge_elsewhere', $this->code(fn () => app(\App\Services\Shop\DuplicateService::class)->merge((int) $company->id, 'sacco_members', 1, [2])));
    }

    public function test_officers_who_are_members_are_adults_and_insiders_and_members_are_per_sacco(): void
    {
        [$company, $owner] = $this->sacco('group');
        $svc = app(MemberService::class);
        $m = $svc->register($company, $this->person(), $owner)['member'];
        $minor = $svc->register($company, $this->person(['date_of_birth' => now()->subYears(16)->toDateString()]), $owner)['member'];
        $this->assertFalse((bool) $m->fresh()->is_insider);
        $seat = app(OfficerService::class)->appoint((int) $company->id, ['position' => 'treasurer', 'member_id' => $m->id], (int) $owner->id);
        $this->assertTrue($m->fresh()->is_insider);
        $this->assertSame('secretary', app(OfficerService::class)->appoint((int) $company->id, ['position' => 'secretary', 'member_id' => $minor->id], (int) $owner->id)->position,
            'a 16-year-old may hold an office (AK-69)');
        $this->assertSame(['treasurer'], \App\Models\SaccoOfficer::withoutGlobalScopes()->where('member_id', $m->id)->pluck('position')->all());

        [$other] = $this->sacco();
        $this->assertSame('member_not_found', $this->code(fn () => $svc->find($other, (int) $m->id)));
        $this->assertSame('member_not_found', $this->code(fn () => app(OfficerService::class)->appoint((int) $other->id, ['position' => 'treasurer', 'member_id' => $m->id], (int) $owner->id)));
        $this->assertNull(SaccoMember::forCompany((int) $other->id)->find($m->id));
        $this->assertSame('M-000001', $svc->register($other, $this->person(), $owner)['member']->member_no, 'numbers are per SACCO');
    }
}
