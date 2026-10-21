<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoApproval;
use App\Models\SaccoLoanGuarantor;
use App\Models\SaccoProvisionRun;
use App\Models\SaccoSavingsAccount;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Loans\GuarantorRecovery;
use App\Services\Sacco\Loans\Guarantors;
use App\Services\Sacco\Loans\LoanJobs;
use App\Services\Sacco\Loans\Provisioning;
use App\Services\Sacco\Loans\WriteOffs;
use App\Services\Sacco\SaccoApprovals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.12 (04 §5.1, §7; SI 51, AK-40, AK-88): provisioning moves 1390 to the table × outstanding each month;
 * a write-off needs two officers in the chair's or treasurer's office and reverses through 1390 (5200 for the
 * unprovided rest); money recovered afterwards is income; a guarantor's held savings pay a loan in arrears by
 * approval, the lien coming off by what is taken.
 */
class LoanWriteOffTest extends ApiTestCase
{
    use MakesSaccoLoans;

    private function bal(int $cid, string $key): int
    {
        return (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($cid, $key))->value('balance_minor');
    }

    public function test_provisioning_write_off_and_recovery(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $m1 = $this->loanMember($company, $owner, 900);
        $this->loanSavings($company, $m1, 1_000_000, $owner, 'ORD', now()->subDays(300)->toDateString());
        $bad = $this->activeLoan($company, $m1, 1_000_000, 12, ['channel' => 'savings'], now()->subDays(250)->toDateString());
        $m2 = $this->loanMember($company, $owner, 900);
        $this->loanSavings($company, $m2, 500_000, $owner, 'ORD', now()->subDays(300)->toDateString());
        $good = $this->activeLoan($company, $m2, 500_000, 12, ['channel' => 'savings'], now()->subDays(20)->toDateString());
        (new LoanJobs())->run($cid);
        $bad->refresh();
        $this->assertSame('loss', $bad->classification, 'over 180 days');
        $month = now()->subMonthNoOverflow()->format('Y-m');
        $run = SaccoProvisionRun::where('company_id', $cid)->where('month', $month)->firstOrFail();
        $this->assertSame(1_000_000, $run->required_minor, '100 % of the loss loan; the new loan was paid out after that month');
        $this->assertEquals(['loss' => ['loans' => 1, 'principal_minor' => 1_000_000, 'rate_bp' => 10000, 'required_minor' => 1_000_000]], $run->by_class);
        $this->assertSame(1_000_000, $this->bal($cid, 'loans.allowance'));
        $this->assertSame($run->id, (new Provisioning())->run($cid, $month)->id, 'once a month');

        $wo = new WriteOffs();
        $officer = $this->loanStaffOf($company)['officer'];
        $approval = $wo->request($company, (int) $bad->id, $officer, 'Member emigrated; demand notices returned');
        $treasurer = $this->officerUser($company, 'sacco_treasurer', 'treasurer');
        $teller = $this->officerUser($company, 'sacco_teller', 'teller');
        $this->assertSame('forbidden', $this->errorCode(fn () => (new SaccoApprovals())->approve($company, (int) $approval->id, $teller)));
        (new SaccoApprovals())->approve($company, (int) $approval->id, $treasurer);
        $this->assertSame('pending', SaccoApproval::find($approval->id)->status, 'two approvals');
        (new SaccoApprovals())->approve($company, (int) $approval->id, $owner); // the founder, seated as chairperson
        $bad->refresh();
        $this->assertSame(['written_off', 0, 0, 0], [$bad->status, $bad->principal_outstanding_minor, $bad->interest_due_minor, $bad->interest_in_suspense_minor]);
        $this->assertGreaterThan(1_000_000, $bad->written_off_minor, 'principal and the interest and penalty owed');
        $this->assertSame(0, $this->bal($cid, 'loans.allowance'), 'the allowance took the principal; 5200 the unprovided interest and penalty');
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));

        $change = $wo->recover($company, (int) $bad->id, ['amount_minor' => 200_000, 'channel' => 'cash'], $owner);
        $this->assertSame(200_000, $change->amount_minor);
        $this->assertSame(200_000, $this->bal($cid, 'inc.recoveries'));
        $this->assertSame('recovery_exceeds', $this->errorCode(fn () => $wo->recover($company, (int) $bad->id, ['amount_minor' => $bad->written_off_minor, 'channel' => 'cash'], $owner)));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_guarantor_recovery_by_approval(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $borrower = $this->loanMember($company, $owner, 900);
        $this->loanSavings($company, $borrower, 100_000, $owner, 'ORD', now()->subDays(200)->toDateString());
        $guar = $this->loanMember($company, $owner, 900, ['first_name' => 'Gertrude']);
        $gAcct = $this->loanSavings($company, $guar, 800_000, $owner, 'ORD', now()->subDays(200)->toDateString());
        \App\Models\SaccoLoanProduct::whereKey($this->ordinaryProduct($company)->id)->update(['guarantors_required' => 1]);
        $loan = $this->signedLoan($company, $borrower, 300_000, 6, now()->subDays(100)->toDateString());
        $officer = $this->loanStaffOf($company)['officer'];
        // Guarantors after approval are fine while it is signed; the cover check runs at payout.
        $this->assertSame('guarantees_incomplete', $this->errorCode(function () use ($company, $loan) {
            $a = (new \App\Services\Sacco\Loans\Disbursement())->request($company, (int) $loan->id, $this->loanTreasurer($company), ['channel' => 'savings', 'disburse_on' => now()->subDays(100)->toDateString()]);
            (new SaccoApprovals())->approve($company, (int) $a->id, $this->loanStaffOf($company)['manager']);
        }));
        $g = (new Guarantors())->add($company, (int) $loan->id, (int) $guar->id, 200_000, $officer);
        $file = \App\Models\StoredFile::create(['company_id' => $cid, 'uuid' => (string) Str::uuid(), 'purpose' => 'sacco_guarantee', 'path' => 'g.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1]);
        (new Guarantors())->consentWithForm($company, (int) $g->id, (int) $file->id, $officer);
        $approvalId = (int) SaccoApproval::where('company_id', $cid)->where('action', 'loan_disbursement')->latest('id')->value('id');
        (new SaccoApprovals())->execute($company, $approvalId, $this->loanStaffOf($company)['manager']);
        $loan->refresh();
        $this->assertSame('active', $loan->status, 'the waiting approval runs once the cover is there');
        $rec = new GuarantorRecovery();
        $this->assertSame('not_in_arrears', $this->errorCode(fn () => $rec->request($company, (int) $g->id, 50_000, $officer, 'x')));
        (new LoanJobs())->run($cid);
        $this->assertGreaterThan(0, $loan->fresh()->days_past_due);
        $this->assertSame('guarantee_capacity', $this->errorCode(fn () => $rec->request($company, (int) $g->id, 200_001, $officer, 'x')));
        $a = $rec->request($company, (int) $g->id, 150_000, $officer, 'Borrower 60 days late; notice served');
        (new SaccoApprovals())->approve($company, (int) $a->id, $owner);
        $g->refresh();
        $this->assertSame([50_000, 150_000], [$g->held_minor, $g->recovered_minor]);
        $this->assertSame(800_000 - 150_000, (int) SaccoSavingsAccount::find($gAcct->id)->balance_minor);
        $this->assertSame(50_000, (int) SaccoSavingsAccount::find($gAcct->id)->held_minor);
        $this->assertNotNull(DB::table('message_log')->where('company_id', $cid)->where('purpose', 'sacco_notice')->where('body', 'like', '%taken from your savings%')->first());
        $this->assertLessThan(300_000, $loan->fresh()->principal_outstanding_minor);
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
        $this->assertSame('consented', SaccoLoanGuarantor::find($g->id)->consent_status);
    }
}
