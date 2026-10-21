<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoOfficer;
use App\Models\SaccoSetting;
use App\Models\User;
use App\Services\Sacco\OfficerService;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoSetupService;
use App\Services\Team\Permissions;
use App\Services\Team\TeamService;
use App\Support\Rules\TeamRules;
use Database\Seeders\AkibaPlanSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P1.8 (docs/akiba/04-architecture.md §6, §7, AK-21, AK-22, AK-23): a shop's team is offered exactly
 * today's roles and permissions; a SACCO's the owner, the eight sacco_* roles and the viewer. Each sacco role is an
 * ordinary team login with its permissions; positions say who holds an office; nobody checks their own request.
 */
class SaccoAccessTest extends ApiTestCase
{
    use MakesSaccoTeam;

    private const FAMILY_ROLES = ['owner', 'manager', 'cashier', 'stock_keeper', 'accountant', 'viewer'];

    private const FAMILY_PERMISSIONS = ['sell', 'discount', 'void', 'refund', 'approve', 'restock', 'adjust', 'stock_take', 'manage_products', 'view_cost', 'view_profit',
        'view_reports', 'manage_finance', 'manage_budget', 'resolve_conflicts', 'manage_team', 'manage_settings', 'billing'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AkibaPlanSeeder::class);
    }

    /** @return array{0: Company, 1: User, 2: array{token: string}} */
    private function sacco(string $mode = 'office'): array
    {
        $t = $this->registerTenant(['modules' => ['sacco']]);
        $company = Company::find($t['company_id']);
        $owner = User::withoutGlobalScopes()->find($t['user_id']);
        // Enterprise: a team of a dozen (the 30-day trial allows 5 logins).
        \App\Models\Subscription::where('company_id', $company->id)->update(['plan_id' => \App\Models\Plan::where('slug', 'akiba-enterprise')->value('id')]);
        app(SaccoSetupService::class)->setup($company, ['mode' => $mode, 'legal_name' => 'Access SACCO'], $owner);

        return [$company->fresh(), $owner, $t];
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

    public function test_a_shop_is_offered_exactly_todays_roles_and_permissions(): void
    {
        $t = $this->registerTenant(['business_type' => 'retail']);
        $company = Company::find($t['company_id']);
        $this->assertSame(self::FAMILY_ROLES, TeamService::rolesFor($company));
        $res = $this->getJson('/api/v1/team/roles', $this->auth($t['token']))->assertOk();
        $this->assertSame(self::FAMILY_ROLES, array_column($res->json('data.roles'), 'key'));
        $this->assertSame(self::FAMILY_PERMISSIONS, array_keys($res->json('data.permissions')));
        $this->assertSame(self::FAMILY_PERMISSIONS, $res->json('data.roles.0.permissions'), 'the owner holds exactly the family\'s permissions');
        $this->assertSame(['manager', 'cashier', 'stock_keeper', 'accountant', 'viewer'], array_keys(TeamRules::editableRoles($company)));
        $this->assertSame(array_keys(TeamRules::editableRoles($company)), array_keys(TeamRules::editableRoles()), 'without a company: the family\'s roles');
        $this->assertSame(self::FAMILY_PERMISSIONS, $this->getJson('/api/v1/auth/me', $this->auth($t['token']))->assertOk()->json('data.permissions'), 'auth/me unchanged');
        $this->postJson('/api/v1/team/invites', ['role' => 'sacco_teller', 'email' => 'x_'.uniqid().'@example.com'], $this->auth($t['token']))
            ->assertStatus(422)->assertJsonPath('errors.code', 'invalid_role');
    }

    public function test_a_sacco_is_offered_its_roles_and_each_is_an_ordinary_login_with_its_permissions(): void
    {
        [$company, $owner, $t] = $this->sacco();
        $this->assertSame(['owner', 'viewer', ...self::SACCO_ROLES], TeamService::rolesFor($company));
        $res = $this->getJson('/api/v1/team/roles', $this->auth($t['token']))->assertOk();
        $this->assertContains('sacco_approve', array_keys($res->json('data.permissions')));
        $this->assertNotContains('sell', array_keys($res->json('data.permissions')), 'no shop permissions in a SACCO');
        $this->assertSame('invalid_role', $this->code(fn () => app(TeamService::class)->createMember($company, $owner, 'Cashier', 'c_'.uniqid().'@example.com', null, 'Secret-pass-123', 'cashier')));

        $expected = [
            'sacco_manager' => ['sacco_view', 'sacco_members', 'sacco_teller', 'sacco_withdraw', 'sacco_approve', 'sacco_loans', 'sacco_disburse', 'sacco_accounts', 'sacco_reports', 'sacco_settings', 'sacco_officers'],
            'sacco_chair' => ['sacco_view', 'sacco_approve', 'sacco_loans_vote', 'sacco_reports', 'sacco_officers', 'sacco_audit_view'],
            'sacco_treasurer' => ['sacco_view', 'sacco_members', 'sacco_teller', 'sacco_withdraw', 'sacco_approve', 'sacco_disburse', 'sacco_accounts', 'sacco_reports'],
            'sacco_secretary' => ['sacco_view', 'sacco_members', 'sacco_kyc', 'sacco_reports', 'sacco_meetings'],
            'sacco_loans_officer' => ['sacco_view', 'sacco_members', 'sacco_teller', 'sacco_loans', 'sacco_reports'],
            'sacco_committee' => ['sacco_view', 'sacco_loans_vote', 'sacco_reports'],
            'sacco_auditor' => ['sacco_view', 'sacco_reports', 'sacco_audit_view'],
            'sacco_teller' => ['sacco_view', 'sacco_teller', 'sacco_withdraw'],
        ];
        foreach ($expected as $role => $perms) {
            $user = $this->saccoStaff($company, $role);
            Permissions::flush();
            $this->assertSame($role, Permissions::roleOf($user));
            $this->assertEqualsCanonicalizing($perms, Permissions::of($user), $role);
            $this->assertTrue(SaccoAccess::can($user, 'sacco_view'));
            $this->assertSame('shop_viewer', config("permissions.admin_roles.{$role}"), 'the classic panel only reads');
            // An ordinary team login (admin_users + company_members), active in this company: the web and API sign-ins accept it.
            $this->assertSame('active', DB::table('company_members')->where('company_id', $company->id)->where('user_id', $user->id)->value('status'));
            $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Secret-pass-123', (string) $user->password));
        }
        $viewer = $this->saccoStaff($company, 'viewer');
        Permissions::flush();
        $this->assertEqualsCanonicalizing(['view_reports', 'sacco_view'], Permissions::of($viewer), 'a SACCO\'s viewer reads its books');
        $this->assertTrue(SaccoAccess::can($owner, 'sacco_approve'));
        $this->assertContains('sacco_audit_view', Permissions::of($owner));
        $this->assertNotContains('sell', Permissions::of($owner));
    }

    public function test_per_company_overrides_work_for_the_new_permissions(): void
    {
        [$company, , $t] = $this->sacco();
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $this->putJson('/api/v1/team/roles/sacco_teller', ['permissions' => ['sacco_view', 'sacco_teller', 'sacco_kyc']], $this->auth($t['token']))->assertOk();
        Permissions::flush();
        $this->assertEqualsCanonicalizing(['sacco_view', 'sacco_teller', 'sacco_kyc'], Permissions::of($teller), 'withdraw taken away, kyc given');
        $this->putJson('/api/v1/team/roles/sacco_teller', ['permissions' => ['sell']], $this->auth($t['token']))->assertStatus(422);
        $this->putJson('/api/v1/team/roles/cashier', ['permissions' => ['sell']], $this->auth($t['token']))->assertStatus(422)->assertJsonPath('errors.code', 'invalid_role');
        // Another SACCO's teller keeps the defaults.
        [$other] = $this->sacco();
        $theirs = $this->saccoStaff($other, 'sacco_teller');
        Permissions::flush();
        $this->assertEqualsCanonicalizing(['sacco_view', 'sacco_teller', 'sacco_withdraw'], Permissions::of($theirs));
    }

    public function test_the_checker_is_never_the_maker_the_owner_included(): void
    {
        [$company, $owner] = $this->sacco();
        $treasurer = $this->saccoStaff($company, 'sacco_treasurer');
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $this->assertSame('self_approval', $this->code(fn () => SaccoAccess::assertChecker($owner, (int) $owner->id)));
        $this->assertSame('self_approval', $this->code(fn () => SaccoAccess::assertChecker($treasurer, (int) $treasurer->id)));
        $this->assertFalse(SaccoAccess::assertChecker($treasurer, (int) $teller->id));
        $this->assertSame('forbidden', $this->code(fn () => SaccoAccess::assertChecker($teller, (int) $treasurer->id)), 'a teller cannot approve');

        // Single-officer mode: group mode only, and flagged.
        $this->assertSame('single_officer_not_allowed', $this->code(fn () => SaccoSetting::for((int) $company->id)->update(['single_officer_mode' => true])));
        [$group, $groupOwner] = $this->sacco('group');
        SaccoSetting::for((int) $group->id)->update(['single_officer_mode' => true]);
        $this->assertTrue(SaccoAccess::assertChecker($groupOwner, (int) $groupOwner->id), 'allowed and marked as a self-approval');
    }

    public function test_positions_terms_and_who_may_reopen_or_vote(): void
    {
        [$company, $owner] = $this->sacco();
        $this->assertSame(['chairperson'], OfficerService::positionsOf((int) $company->id, (int) $owner->id), 'the founder sits as chairperson');
        $auditorLogin = $this->saccoStaff($company, 'sacco_auditor');
        $treasurer = $this->saccoStaff($company, 'sacco_treasurer');
        $committee = $this->saccoStaff($company, 'sacco_committee');
        $svc = app(OfficerService::class);

        $this->assertFalse(SaccoAccess::canReopenPeriod($treasurer), 'sacco_approve alone is not enough');
        $this->assertTrue(SaccoAccess::canReopenPeriod($owner), 'the chair may');
        $this->assertFalse(SaccoAccess::canReopenPeriod($auditorLogin), 'the auditor role has no sacco_approve');

        $seat = $svc->appoint((int) $company->id, ['position' => 'loans_committee', 'user_id' => $committee->id, 'starts_on' => now()->subDays(10)->toDateString()], (int) $owner->id);
        $this->assertSame('already_in_office', $this->code(fn () => $svc->appoint((int) $company->id, ['position' => 'loans_committee', 'user_id' => $committee->id], (int) $owner->id)));
        $this->assertTrue(SaccoAccess::canVote($committee, 5, 9));
        $this->assertFalse(SaccoAccess::canVote($committee, 9, 9), 'not on their own application');
        $this->assertFalse(SaccoAccess::canVote($committee, 5, 9, [9]), 'recused when related');
        $this->assertFalse(SaccoAccess::canVote($owner, 5, null), 'the chair has the permission but no committee seat');
        $this->assertCount(1, OfficerService::committee((int) $company->id));

        $svc->endTerm((int) $company->id, (int) $seat->id, (int) $owner->id, 'Term over', now()->subDay()->toDateString());
        $this->assertFalse(SaccoAccess::canVote($committee, 5, 9));
        $this->assertSame('term_ended', $this->code(fn () => $svc->endTerm((int) $company->id, (int) $seat->id, (int) $owner->id)));
        $this->assertSame('officer_history', $this->code(fn () => SaccoOfficer::withoutGlobalScopes()->find($seat->id)->delete()));
        $keyHolder = $svc->appoint((int) $company->id, ['position' => 'key_holder', 'name' => 'Nakato (no login)'], (int) $owner->id);
        $this->assertNull($keyHolder->user_id);
        $this->assertSame('invalid_officer', $this->code(fn () => $svc->appoint((int) $company->id, ['position' => 'custom', 'user_id' => $treasurer->id], (int) $owner->id)));
        [$other] = $this->sacco();
        $this->assertSame('invalid_officer', $this->code(fn () => $svc->appoint((int) $other->id, ['position' => 'treasurer', 'user_id' => $treasurer->id], (int) $owner->id)), 'not on that team');

        $this->assertGreaterThan(0, DB::table('app_notifications')->where('company_id', $company->id)->where('type', 'sacco_officers')->count(), 'officers are told');
        $this->assertTrue(DB::table('audit_logs')->where('company_id', $company->id)->where('action', 'sacco.officer.ended')->exists());
    }

    public function test_a_phone_never_moves_money_out_or_approves(): void
    {
        $this->assertNull(SaccoAccess::refuseSyncOp('sacco_transactions', 'insert', ['kind' => 'deposit', 'channel' => 'cash']));
        $this->assertNull(SaccoAccess::refuseSyncOp('sacco_transactions', 'insert', ['kind' => 'share_purchase']));
        foreach ([['withdrawal', 'cash'], ['loan_disbursement', 'cash'], ['internal_transfer', 'internal'], ['share_transfer', 'internal'], ['reversal', 'cash'], ['deposit', 'momo'], ['deposit', 'bank']] as [$kind, $channel]) {
            $this->assertNotNull(SaccoAccess::refuseSyncOp('sacco_transactions', 'insert', ['kind' => $kind, 'channel' => $channel]), "{$kind} by {$channel}");
        }
        $this->assertNotNull(SaccoAccess::refuseSyncOp('sacco_transactions', 'update', ['kind' => 'deposit']));
        $this->assertNotNull(SaccoAccess::refuseSyncOp('sacco_loans', 'insert', ['status' => 'submitted']));
        $this->assertNull(SaccoAccess::refuseSyncOp('sacco_loans', 'insert', ['status' => 'draft']));
        $this->assertNotNull(SaccoAccess::refuseSyncOp('sacco_members', 'update', ['kyc_status' => 'verified']));
        $this->assertNull(SaccoAccess::refuseSyncOp('products', 'insert', []));
        $this->assertSame('sacco_teller', \App\Services\Sync\SyncApplier::permissionFor('sacco_transactions', 'insert', []));
        $this->assertSame('sacco_members', \App\Services\Sync\SyncApplier::permissionFor('sacco_members', 'insert', []));
    }
}
