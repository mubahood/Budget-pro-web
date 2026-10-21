<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Plan;
use App\Models\SaccoApproval;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoLedgerAccount;
use App\Models\SaccoPeriod;
use App\Models\SaccoSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Sacco\Approvals\HeldReceiptAllocationExecutor;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\PostingRules;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\Members\MemberService;
use App\Services\Sacco\SaccoApprovals;
use App\Services\Sacco\SaccoSetupService;
use App\Support\Sacco\Amount;
use Database\Seeders\AkibaPlanSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoLedger;
use Tests\Concerns\MakesSaccoTeam;
use Tests\Feature\Api\ApiTestCase;
use Tests\Support\Sacco\PotLedger;

/**
 * Akiba Ledger P1.10 (04 §7, AK-22): the maker-checker engine and its P1 executors. Nobody approves their own
 * request (the owner included; a group in single-officer mode may, flagged); the payload is frozen by its hash;
 * executing twice posts once; an expired request never executes; a refused execution waits, approved.
 */
class SaccoApprovalsTest extends ApiTestCase
{
    use MakesSaccoLedger, MakesSaccoTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AkibaPlanSeeder::class);
    }

    protected function tearDown(): void
    {
        $this->restorePots();
        parent::tearDown();
    }

    /** @return array{0: Company, 1: User, 2: User, 3: User} company, owner (chair), treasurer, accountant-like manager */
    private function sacco(string $mode = 'office'): array
    {
        $t = $this->registerTenant(['modules' => ['sacco']]);
        $company = Company::find($t['company_id']);
        Subscription::where('company_id', $company->id)->update(['plan_id' => Plan::where('slug', 'akiba-enterprise')->value('id')]);
        $owner = User::withoutGlobalScopes()->find($t['user_id']);
        app(SaccoSetupService::class)->setup($company->fresh(), ['mode' => $mode, 'legal_name' => 'Approvals SACCO'], $owner);
        $company = $company->fresh();

        return [$company, $owner, $this->saccoStaff($company, 'sacco_treasurer'), $this->saccoStaff($company, 'sacco_manager')];
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

    /** @return array<string, mixed> a balanced manual journal */
    private function journal(int $cid, int $minor = 5000): array
    {
        return ['memo' => 'Bank charges for October', 'lines' => [
            ['account_id' => ChartOfAccounts::id($cid, 'exp.bank'), 'debit_minor' => $minor],
            ['account_id' => ChartOfAccounts::id($cid, 'bank.main'), 'credit_minor' => $minor],
        ]];
    }

    public function test_nobody_approves_their_own_request_the_owner_included(): void
    {
        [$company, $owner, $treasurer, $manager] = $this->sacco();
        $svc = app(SaccoApprovals::class);
        $mine = $svc->request($company, 'manual_journal', $this->journal((int) $company->id), $owner);
        $this->assertSame('self_approval', $this->code(fn () => $svc->approve($company, (int) $mine->id, $owner)));
        $theirs = $svc->request($company, 'manual_journal', $this->journal((int) $company->id, 7000), $treasurer);
        $this->assertSame('self_approval', $this->code(fn () => $svc->approve($company, (int) $theirs->id, $treasurer)));
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $this->assertSame('forbidden', $this->code(fn () => $svc->approve($company, (int) $theirs->id, $teller)), 'a teller is not a checker');
        $this->assertSame('forbidden', $this->code(fn () => $svc->request($company, 'manual_journal', $this->journal((int) $company->id), $teller)), 'nor a maker of journals');

        $done = $svc->approve($company, (int) $theirs->id, $manager, 'Checked the statement');
        $this->assertSame('executed', $done->status);
        $entry = SaccoJournalEntry::withoutGlobalScopes()->find($done->executed_entry_id);
        $this->assertSame('apr:'.$theirs->uuid, $entry->idempotency_key);
        $this->assertSame((int) $theirs->id, (int) $entry->approval_id);
        $this->assertSame((int) $treasurer->id, (int) $entry->posted_by_id, 'the maker posted it, the checker approved it');
        $this->assertFalse($done->self_approved);

        // Single-officer mode is for groups only, and every self-approval is flagged.
        [$group, $gOwner] = $this->sacco('group');
        SaccoSetting::for((int) $group->id)->update(['single_officer_mode' => true]);
        $solo = $svc->request($group, 'manual_journal', $this->journal((int) $group->id), $gOwner);
        $solo = $svc->approve($group, (int) $solo->id, $gOwner);
        $this->assertSame('executed', $solo->status);
        $this->assertTrue($solo->self_approved);
        $this->assertTrue((bool) DB::table('sacco_approval_votes')->where('approval_id', $solo->id)->value('self_approved'));
    }

    public function test_the_payload_is_frozen_by_its_hash(): void
    {
        [$company, , $treasurer, $manager] = $this->sacco();
        $svc = app(SaccoApprovals::class);
        $a = $svc->request($company, 'manual_journal', $this->journal((int) $company->id, 5000), $treasurer);
        $this->assertSame(SaccoApprovals::hash('manual_journal', $a->payload), $a->payload_hash);
        $this->assertSame('approval_frozen', $this->code(fn () => $a->update(['payload' => $this->journal((int) $company->id, 9000)])));
        $this->assertSame('approval_frozen', $this->code(fn () => $a->update(['amount_minor' => 1])));

        // Changed in the database behind the books: refused, nothing posts.
        $raw = $this->journal((int) $company->id, 900000);
        $raw['memo'] = 'Bank charges for October';
        DB::table('sacco_approvals')->where('id', $a->id)->update(['payload' => json_encode($raw)]);
        $this->assertSame('payload_tampered', $this->code(fn () => $svc->approve($company, (int) $a->id, $manager)));
        $this->assertSame(0, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $company->id)->count());

        // The same uuid is the same request; another payload under it is a conflict.
        $uuid = (string) Str::uuid();
        $first = $svc->request($company, 'manual_journal', $this->journal((int) $company->id, 1000), $treasurer, $uuid);
        $this->assertSame((int) $first->id, (int) $svc->request($company, 'manual_journal', $this->journal((int) $company->id, 1000), $treasurer, $uuid)->id);
        $this->assertSame('idempotency_conflict', $this->code(fn () => $svc->request($company, 'manual_journal', $this->journal((int) $company->id, 1001), $treasurer, $uuid)));
        $this->assertSame('unbalanced_entry', $this->code(fn () => $svc->request($company, 'manual_journal',
            ['memo' => 'x', 'lines' => [['account_id' => ChartOfAccounts::id((int) $company->id, 'exp.bank'), 'debit_minor' => 2], ['account_id' => ChartOfAccounts::id((int) $company->id, 'bank.main'), 'credit_minor' => 1]]], $treasurer)));
        $this->assertSame('invalid_journal', $this->code(fn () => $svc->request($company, 'manual_journal',
            ['memo' => 'x', 'lines' => [['account_id' => 1, 'debit_minor' => 2.5], ['account_id' => 2, 'credit_minor' => 2.5]]], $treasurer)), 'money is whole minor units');
    }

    public function test_double_execute_posts_once_and_a_refused_execution_waits_approved(): void
    {
        [$company, , $treasurer, $manager] = $this->sacco();
        $svc = app(SaccoApprovals::class);
        $a = $svc->approve($company, (int) $svc->request($company, 'manual_journal', $this->journal((int) $company->id), $treasurer)->id, $manager);
        $again = $svc->execute($company, (int) $a->id, $manager);
        $this->assertSame((int) $a->executed_entry_id, (int) $again->executed_entry_id);
        $this->assertSame(1, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame('approval_closed', $this->code(fn () => $svc->approve($company, (int) $a->id, $this->saccoStaff($company, 'sacco_chair'))));

        // The account is switched off between the request and the approval: approved, not executed, with the reason.
        $b = $svc->request($company, 'manual_journal', $this->journal((int) $company->id, 3000), $treasurer);
        SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '5720')->update(['is_active' => false]);
        $this->assertSame('account_not_postable', $this->code(fn () => $svc->approve($company, (int) $b->id, $manager)));
        $b = $b->fresh();
        $this->assertSame('approved', $b->status);
        $this->assertNotNull($b->last_error);
        SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '5720')->update(['is_active' => true]);
        $this->assertSame('executed', $svc->execute($company, (int) $b->id, $manager)->status);
    }

    public function test_an_expired_request_never_executes(): void
    {
        [$company, , $treasurer, $manager] = $this->sacco();
        $svc = app(SaccoApprovals::class);
        $a = $svc->request($company, 'manual_journal', $this->journal((int) $company->id), $treasurer);
        $b = $svc->request($company, 'manual_journal', $this->journal((int) $company->id, 6000), $treasurer);
        $this->travel(SaccoApprovals::EXPIRY_DAYS + 1)->days();
        $this->assertSame('approval_expired', $this->code(fn () => $svc->approve($company, (int) $a->id, $manager)));
        $this->assertSame('expired', $a->fresh()->status);
        $this->artisan('sacco:approvals-expire')->assertSuccessful();
        $this->assertSame('expired', $b->fresh()->status);
        $this->assertSame('approval_closed', $this->code(fn () => $svc->approve($company, (int) $b->id, $manager)));
        $this->assertSame('approval_not_approved', $this->code(fn () => $svc->execute($company, (int) $b->id, $manager)));
        $this->assertSame(0, SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }

    public function test_reject_cancel_and_tenancy(): void
    {
        [$company, $owner, $treasurer, $manager] = $this->sacco();
        $svc = app(SaccoApprovals::class);
        $a = $svc->request($company, 'manual_journal', $this->journal((int) $company->id), $treasurer);
        $this->assertSame('reason_required', $this->code(fn () => $svc->reject($company, (int) $a->id, $manager, ' ')));
        $this->assertSame('rejected', $svc->reject($company, (int) $a->id, $manager, 'Wrong month')->status);
        $b = $svc->request($company, 'manual_journal', $this->journal((int) $company->id, 2000), $treasurer);
        $this->assertSame('forbidden', $this->code(fn () => $svc->cancel($company, (int) $b->id, $owner)), 'only the maker takes it back');
        $this->assertSame('cancelled', $svc->cancel($company, (int) $b->id, $treasurer)->status);
        [$other, $otherOwner] = $this->sacco();
        $c = $svc->request($company, 'manual_journal', $this->journal((int) $company->id, 4000), $treasurer);
        $this->assertSame('approval_not_found', $this->code(fn () => $svc->approve($other, (int) $c->id, $otherOwner)));
        $this->assertNull(SaccoApproval::forCompany((int) $other->id)->find($c->id));
        $this->assertSame('approval_history', $this->code(fn () => SaccoApproval::withoutGlobalScopes()->find($c->id)->delete()));
        $this->assertTrue(DB::table('audit_logs')->where('company_id', $company->id)->where('action', 'sacco.approval.rejected')->exists());
    }

    public function test_the_p1_executors(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 10:00:00'));
        [$company, $owner, $treasurer, $manager] = $this->sacco();
        $cid = (int) $company->id;
        $svc = app(SaccoApprovals::class);
        $sept = SaccoPeriod::withoutGlobalScopes()->where('company_id', $cid)->where('month', 9)->first();

        // Reversal.
        $entry = (new PostingService())->post($this->simple($cid, 'txn:fee', 20000));
        $rev = $svc->approve($company, (int) $svc->request($company, 'reversal', ['entry_id' => $entry->id, 'reason' => 'Paid twice'], $treasurer)->id, $manager);
        $this->assertSame((int) $entry->id, (int) SaccoJournalEntry::withoutGlobalScopes()->find($rev->executed_entry_id)->reverses_entry_id);
        $this->assertSame('already_reversed', $this->code(fn () => $svc->request($company, 'reversal', ['entry_id' => $entry->id, 'reason' => 'Again'], $treasurer)));

        // A new account.
        $acc = $svc->approve($company, (int) $svc->request($company, 'ledger_account_create', ['code' => '1201', 'name' => 'Balance with Kampala SACCO', 'type' => 'asset'], $treasurer)->id, $manager);
        $this->assertSame('1201', $acc->result['code']);

        // KYC: captured by the treasurer, checked by someone with sacco_kyc.
        $member = app(MemberService::class)->register($company, ['first_name' => 'Okello', 'last_name' => 'Peter', 'nin' => 'CM8501011234567A', 'consent_data' => true], $treasurer)['member'];
        $kyc = $svc->request($company, 'member_kyc', ['member_id' => $member->id, 'decision' => 'verified'], $treasurer);
        $this->assertSame('forbidden', $this->code(fn () => $svc->approve($company, (int) $kyc->id, $manager)), 'the manager has no sacco_kyc');
        $this->assertSame('verified', $svc->approve($company, (int) $kyc->id, $this->saccoStaff($company, 'sacco_secretary'))->result['kyc_status']);

        // Held receipt: asked by the system, allocated once, never over what is left.
        $this->usePots();
        $savings = ChartOfAccounts::id($cid, 'savings.voluntary');
        $pot = PotLedger::open($cid, $savings, (int) $member->id);
        $held = (new PostingService())->post((new PostingRules($cid, 'office'))->heldReceipt('txn:held', Amount::of(50000, 'UGX')));
        $target = ['type' => 'savings', 'id' => $pot, 'account_id' => $savings];
        $alloc = $svc->request($company, 'held_receipt_allocation', ['held_entry_id' => $held->id, 'amount_minor' => 30000, 'target' => $target], null);
        $this->assertNull($alloc->requested_by_id, 'the system asked');
        $svc->approve($company, (int) $alloc->id, $manager);
        $this->assertSame(30000, PotLedger::balance($pot));
        $this->assertSame(20000, HeldReceiptAllocationExecutor::unallocated($cid, (int) $held->id));
        $this->assertSame('over_allocation', $this->code(fn () => $svc->request($company, 'held_receipt_allocation', ['held_entry_id' => $held->id, 'amount_minor' => 20001, 'target' => $target], $treasurer)));
        $this->assertSame('forbidden', $this->code(fn () => $svc->request($company, 'manual_journal', $this->journal($cid), null)), 'only the system actions may have no maker');

        // A pending request from September blocks its close; the close itself goes through an approval.
        $pending = $svc->request($company, 'manual_journal', $this->journal($cid, 1500), $treasurer);
        $this->travelTo(Carbon::parse('2026-10-02 10:00:00'));
        $close = $svc->request($company, 'period_close', ['period_id' => $sept->id], $treasurer);
        $this->assertSame('approvals_pending', $this->code(fn () => $svc->approve($company, (int) $close->id, $manager)));
        $this->assertSame('approved', $close->fresh()->status, 'approved, waiting for the month to be clear');
        $svc->cancel($company, (int) $pending->id, $treasurer);
        $this->assertSame('executed', $svc->execute($company, (int) $close->id, $manager)->status);
        $this->assertSame('closed', $sept->fresh()->status);
        $this->assertSame((int) $close->id, (int) $sept->fresh()->approval_id);

        // Reopen: sacco_approve and the chair's or the auditor's office.
        $reopen = $svc->request($company, 'period_reopen', ['period_id' => $sept->id, 'reason' => 'Bank charge missed'], $treasurer);
        $this->assertSame('position_required', $this->code(fn () => $svc->approve($company, (int) $reopen->id, $manager)));
        $this->assertSame('executed', $svc->approve($company, (int) $reopen->id, $owner)->status, 'the founder sits as chairperson');
        $this->assertSame('open', $sept->fresh()->status);
        $p1 = ['reversal', 'manual_journal', 'period_close', 'period_reopen', 'ledger_account_create', 'member_kyc', 'held_receipt_allocation', 'withdrawal', 'product_terms_change'];
        $this->assertSame([], array_values(array_diff($p1, SaccoApprovals::actions())), 'every P1 action has its executor (later phases add theirs)');
    }
}
