<?php

namespace Tests\Feature\Sacco;

use App\Models\Company;
use App\Models\SaccoLoan;
use App\Models\User;
use App\Services\Sacco\Demo\SaccoDemo;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Members\MemberLogin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Api\ApiTestCase;

/**
 * The Akiba Ledger demo (P1.21, AK-35): "Wakiso Teachers' SACCO (Demo)" built through the services with every case
 * the brief lists, books that verify, sub-ledgers equal to their control accounts, PAR in every bucket, a sign-in per
 * role and the member's, no word of any other app; then healed after a visitor's damage, and rebuilt beside itself.
 * One build and one rebuild (each runs 18 months of books), so the three are one test.
 */
class SaccoDemoTest extends ApiTestCase
{
    /** What may never appear in the demo's text (AkibaIndependenceTest's list). */
    private const FORBIDDEN = ['Budget Pro', 'BudgetPro', 'budget-pro', 'Shelfie', 'Kuku Tally', 'kuku-tally', 'Pledge', 'Fresh Corner', 'family', 'schooldynamics', 'public-demo'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['demo.sacco.enabled' => true, 'demo.sacco.counter_per_month' => 3, 'edition.name' => 'saas']);
    }

    public function test_build_heal_and_rebuild(): void
    {
        $demo = new SaccoDemo();
        $first = $demo->run(true);
        $this->assertContains($first['action'], ['built', 'rebuilt']);
        $this->assertSame([], $first['skipped'], 'every sample entry went through the services');
        $company = SaccoDemo::company();
        $this->assertNotNull($company);
        $cid = (int) $company->id;
        $this->assertSame("Wakiso Teachers' SACCO (Demo)", $company->name);
        $this->assertTrue((bool) $company->is_demo);
        $this->assertSame('office', DB::table('sacco_settings')->where('company_id', $cid)->value('mode'));

        $this->assertBooks($cid);
        $this->assertEveryCase($cid);
        $this->assertSignIns($cid);
        $this->assertIndependent($cid);

        // A visitor's damage, then the hourly heal puts it back.
        $teller = SaccoDemo::login('teller');
        DB::table('admin_users')->where('id', SaccoDemo::login('chair')->id)->update(['password' => Hash::make('changed-by-visitor-1')]);
        DB::table('admin_users')->where('id', $teller->id)->update(['status' => 'Inactive']);
        DB::table('company_members')->where('company_id', $cid)->where('user_id', $teller->id)->update(['status' => 'removed']);
        DB::table('sacco_member_logins')->where('company_id', $cid)->update(['disabled_at' => now()]);
        Company::withoutGlobalScopes()->whereKey($cid)->update(['name' => 'Renamed by a visitor']);
        $this->assertSame('demo_locked', $this->code(fn () => app(\App\Services\Team\TeamService::class)->createMember(Company::find($cid), SaccoDemo::login('chair'), 'Visitor', 'v@x.example', null, 'Secret-pass-123', 'sacco_teller')));
        $this->assertSame('demo_locked', $this->code(fn () => \App\Services\Onboarding\PublicDemo::guardAccount(SaccoDemo::login('manager'), 'changing the password')));
        $this->assertSame('demo_locked', $this->code(fn () => app(\App\Services\Ops\TenantDataService::class)->requestDeletion(Company::find($cid), SaccoDemo::login('chair'), SaccoDemo::password())));

        $healed = $demo->run();
        $this->assertSame('healed', $healed['action']);
        $this->assertSame($cid, $healed['company_id']);
        $this->assertSame("Wakiso Teachers' SACCO (Demo)", Company::find($cid)->name);
        $this->assertTrue(Hash::check(SaccoDemo::password(), (string) SaccoDemo::login('chair')->fresh()->password));
        $this->assertSame('Active', SaccoDemo::login('teller')->fresh()->status);
        $this->assertSame('active', DB::table('company_members')->where('company_id', $cid)->where('user_id', $teller->id)->value('status'));
        $this->assertNotEmpty((new MemberLogin())->attempt(SaccoDemo::memberEmail(), SaccoDemo::password()));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
        $this->assertSame(0, (int) DB::table('sacco_periods')->where('company_id', $cid)->where('status', 'open')->where('ends_on', '<', now('Africa/Kampala')->startOfMonth()->toDateString())->count(), 'every ended month closed');

        // The rebuild: a new SACCO, the published sign-ins moved to it, the old one gone (its journals too).
        $again = $demo->run(true);
        $this->assertSame('rebuilt', $again['action']);
        $new = SaccoDemo::company();
        $this->assertNotSame($cid, (int) $new->id);
        $this->assertNull(Company::withoutGlobalScopes()->find($cid));
        $this->assertSame(0, DB::table('sacco_journal_entries')->where('company_id', $cid)->count());
        $this->assertSame(0, DB::table('admin_users')->where('company_id', $cid)->count());
        foreach (array_keys(SaccoDemo::roles()) as $key) {
            $this->assertSame((int) $new->id, (int) SaccoDemo::login($key)->company_id, $key);
        }
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify((int) $new->id)));
        $this->assertSame((int) $new->id, (int) (new MemberLogin())->attempt(SaccoDemo::memberEmail(), SaccoDemo::password())[0]['company_id']);
    }

    private function assertBooks(int $cid): void
    {
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)), 'the ledger verifier is green');
        $bal = fn (string $key) => (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($cid, $key))->value('balance_minor');
        $savings = (int) DB::table('sacco_savings_accounts')->where('company_id', $cid)->sum('balance_minor');
        $this->assertSame($savings, $bal('savings.voluntary') + $bal('savings.compulsory') + $bal('savings.fixed'), 'Σ savings accounts = 2110 + 2120 + 2130');
        $this->assertSame((int) DB::table('sacco_share_accounts')->where('company_id', $cid)->sum('balance_minor'), $bal('shares.capital'), 'Σ share accounts = 3100');
        $this->assertSame((int) SaccoLoan::withoutGlobalScopes()->where('company_id', $cid)->sum('principal_outstanding_minor'), $bal('loans.principal'), 'Σ loans = 1300');
        $this->assertSame((int) SaccoLoan::withoutGlobalScopes()->where('company_id', $cid)->sum('interest_due_minor'), $bal('loans.interest'), 'Σ interest due = 1320');
        $this->assertSame(0, $bal('opening'), 'the opening balances net to zero');
        $this->assertSame((int) config('demo.sacco.months'), DB::table('sacco_periods')->where('company_id', $cid)->where('status', 'closed')->count(), 'every past month closed');
        $this->assertGreaterThan(0, $bal('loans.allowance'), 'provisioning ran');
        $this->assertSame(0, (int) DB::table('message_log')->where('company_id', $cid)->where('status', '!=', 'skipped')->count(), 'nothing is ever sent from the demo');
        $this->assertSame(0, (int) DB::table('sacco_payouts')->where('company_id', $cid)->count(), 'nothing is ever paid out');
    }

    private function assertEveryCase(int $cid): void
    {
        $loans = SaccoLoan::withoutGlobalScopes()->where('company_id', $cid)->get();
        foreach (['submitted', 'in_committee', 'approved', 'rejected', 'active', 'closed', 'written_off'] as $status) {
            $this->assertTrue($loans->contains('status', $status), "a loan that is {$status}");
        }
        $active = $loans->where('status', 'active');
        $this->assertGreaterThanOrEqual(8, $active->where('days_past_due', 0)->count(), 'current loans');
        $this->assertTrue($active->contains(fn ($l) => $l->days_past_due >= 1 && $l->days_past_due <= 30), 'PAR 1–30');
        $this->assertTrue($active->contains(fn ($l) => $l->days_past_due >= 31 && $l->days_past_due <= 90), 'PAR 31–90');
        $this->assertTrue($active->contains(fn ($l) => $l->days_past_due > 90), 'PAR 90+');
        $this->assertTrue($loans->contains(fn ($l) => $l->reschedule_count > 0 && $l->status === 'active'), 'a rescheduled loan');
        $this->assertTrue($loans->contains(fn ($l) => $l->top_up_of_loan_id !== null && $l->status === 'active'), 'a top-up');
        $this->assertTrue($loans->contains(fn ($l) => $l->topped_up_by_loan_id !== null && $l->status === 'closed'), 'the loan it settled');
        $this->assertGreaterThan(0, (int) $loans->firstWhere('status', 'written_off')->recovered_minor, 'a recovery after the write-off');
        $this->assertTrue(DB::table('sacco_loan_repayments')->where('company_id', $cid)->where('kind', 'guarantor_offset')->exists(), 'a guarantor paid');
        $this->assertGreaterThanOrEqual(4, DB::table('sacco_loan_votes')->where('company_id', $cid)->whereNotNull('note')->count(), 'the committee sitting, minuted');

        $this->assertSame(60, DB::table('sacco_members')->where('company_id', $cid)->count());
        $this->assertTrue(DB::table('sacco_members')->where('company_id', $cid)->where('status', 'exited')->exists());
        $this->assertTrue(DB::table('sacco_members')->where('company_id', $cid)->where('status', 'deceased')->exists());
        $this->assertSame(0, DB::table('sacco_members')->where('company_id', $cid)->where('kyc_status', '!=', 'verified')->count());
        foreach (['ORD', 'CMP', 'FXD', 'HOL'] as $code) {
            $this->assertGreaterThan(0, (int) DB::table('sacco_savings_accounts as a')->join('sacco_savings_products as p', 'p.id', '=', 'a.product_id')
                ->where('a.company_id', $cid)->where('p.code', $code)->sum('a.balance_minor'), "savings in {$code}");
        }
        $kinds = DB::table('sacco_transactions')->where('company_id', $cid)->distinct()->pluck('kind')->all();
        foreach (['deposit', 'withdrawal', 'share_purchase', 'share_transfer', 'entrance_fee', 'fee', 'loan_repayment', 'loan_disbursement', 'reversal'] as $kind) {
            $this->assertContains($kind, $kinds);
        }
        $this->assertTrue(DB::table('sacco_transactions')->where('company_id', $cid)->where('kind', 'reversal')->where('narration', 'like', 'Reversal of%')->exists(), 'the reversed MoMo receipt');
    }

    private function assertSignIns(int $cid): void
    {
        foreach (SaccoDemo::roles() as $key => $r) {
            $res = $this->postJson('/api/v1/auth/login', ['email' => SaccoDemo::email($key), 'password' => SaccoDemo::password(), 'device_name' => 'test']);
            $res->assertOk();
            $user = User::withoutGlobalScopes()->where('username', SaccoDemo::email($key))->firstOrFail();
            $this->assertSame($cid, (int) $user->company_id, $key);
            $this->assertSame($r['role'], DB::table('company_members')->where('company_id', $cid)->where('user_id', $user->id)->value('role'), $key);
        }
        $open = (new MemberLogin())->attempt(SaccoDemo::memberEmail(), SaccoDemo::password());
        $this->assertCount(1, $open);
        $this->assertSame($cid, (int) $open[0]['company_id']);
        $status = SaccoDemo::status();
        $this->assertTrue($status['ready']);
        $this->assertCount(8, array_filter($status['logins'], fn ($l) => $l['user_id'] !== null));
        $this->assertNotNull($status['member']['member_id']);
    }

    private function assertIndependent(int $cid): void
    {
        $text = [];
        $cols = ['companies' => ['id', ['name', 'address', 'email']], 'admin_users' => ['company_id', ['name', 'email', 'username']],
            'sacco_settings' => ['company_id', ['legal_name', 'short_name']], 'sacco_members' => ['company_id', ['first_name', 'last_name', 'email', 'employer_name', 'exit_reason', 'kyc_note']],
            'sacco_transactions' => ['company_id', ['narration', 'reference', 'reason']], 'sacco_loans' => ['company_id', ['purpose', 'appraisal', 'decision_note', 'witness_name']],
            'sacco_loan_votes' => ['company_id', ['note']], 'sacco_approvals' => ['company_id', ['payload', 'decision_note']], 'sacco_journal_entries' => ['company_id', ['memo']],
            'sacco_journal_lines' => ['company_id', ['memo']], 'sacco_savings_products' => ['company_id', ['name']], 'sacco_loan_products' => ['company_id', ['name']],
            'sacco_member_relations' => ['company_id', ['name', 'relationship']], 'sacco_member_logins' => ['company_id', ['email']], 'message_log' => ['company_id', ['body', 'to']]];
        foreach ($cols as $table => [$key, $fields]) {
            $fields = array_values(array_filter($fields, fn ($f) => \Illuminate\Support\Facades\Schema::hasColumn($table, $f)));
            foreach (DB::table($table)->where($key, $cid)->get($fields) as $row) {
                $text[] = implode(' | ', array_map('strval', (array) $row));
            }
        }
        $all = implode("\n", $text);
        $this->assertGreaterThan(1000, strlen($all));
        foreach (self::FORBIDDEN as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $all, "the demo's text never says {$word}");
        }
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $e->errorCode();
        }

        return 'none';
    }
}
