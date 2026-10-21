<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoJournalEntry;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanChange;
use App\Models\SaccoLoanRepayment;
use App\Models\SaccoLoanScheduleLine;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Loans\Committee;
use App\Services\Sacco\Loans\Contracts;
use App\Services\Sacco\Loans\Disbursement;
use App\Services\Sacco\Loans\LoanApplications;
use App\Services\Sacco\Loans\LoanJobs;
use App\Services\Sacco\Loans\Restructure;
use App\Services\Sacco\SaccoApprovals;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.13 (SI 108 reg. 6, 12; SI 51 Form 1): a reschedule needs the borrower's written consent and the
 * committee, makes a new schedule version and keeps the old one; the reschedule count is kept; a top-up is a new loan
 * that settles the old one at payout with interest to the day and no early-settlement charge.
 */
class LoanRestructureTest extends ApiTestCase
{
    use MakesSaccoLoans;

    public function test_reschedule_with_consent_keeps_history(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $m = $this->loanMember($company, $owner, 900);
        $this->loanSavings($company, $m, 1_200_000, $owner, 'ORD', now()->subDays(200)->toDateString());
        $loan = $this->activeLoan($company, $m, 1_200_000, 12, ['channel' => 'savings'], now()->subDays(100)->toDateString());
        (new LoanJobs())->run($cid);
        $loan->refresh();
        $officer = $this->loanStaffOf($company)['officer'];
        $svc = new Restructure();
        $this->assertSame('consent_required', $this->errorCode(fn () => $svc->requestReschedule($company, (int) $loan->id, $officer, ['term' => 18, 'reason' => 'Lost job'])));
        $a = $svc->requestReschedule($company, (int) $loan->id, $officer, ['term' => 18, 'reason' => 'Lost job, new contract from December',
            'consent_signed_on' => now()->toDateString(), 'consent_witness' => 'Okello Peter']);
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $this->assertSame('position_required', $this->errorCode(fn () => (new SaccoApprovals())->approve($company, (int) $a->id, $this->loanStaffOf($company)['manager'])), 'the committee or the board');
        $member = $this->officerUser($company, 'sacco_committee', 'loans_committee');
        (new SaccoApprovals())->approve($company, (int) $a->id, $member);
        $loan->refresh();
        $this->assertSame([2, 1, 18], [$loan->schedule_version, $loan->reschedule_count, $loan->loanTerms()->term]);
        $this->assertSame(18, SaccoLoanScheduleLine::where('loan_id', $loan->id)->where('schedule_version', 2)->count());
        $this->assertSame($loan->principal_outstanding_minor, (int) SaccoLoanScheduleLine::where('loan_id', $loan->id)->where('schedule_version', 2)->sum('principal_due_minor'));
        $this->assertGreaterThan(0, SaccoLoanScheduleLine::where('loan_id', $loan->id)->where('schedule_version', 1)->where('status', 'superseded')->count(), 'the old schedule is kept');
        $change = SaccoLoanChange::where('loan_id', $loan->id)->where('kind', 'reschedule')->firstOrFail();
        $this->assertSame([1, 2, 'Okello Peter'], [$change->from_version, $change->to_version, $change->after['consent_witness']]);
        $this->assertNotNull($change->borrower_consented_at);
        $this->assertGreaterThan(0, $loan->interest_due_minor + $loan->interest_in_suspense_minor, 'arrears of interest stay owed, never capitalised');
        $this->assertSame(0, SaccoJournalEntry::where('company_id', $cid)->where('kind', 'fee')->count(), 'no fee');
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_top_up_settles_the_old_loan_without_a_fee(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $m = $this->loanMember($company, $owner, 900);
        $acct = $this->loanSavings($company, $m, 2_000_000, $owner, 'ORD', now()->subDays(200)->toDateString());
        $old = $this->activeLoan($company, $m, 600_000, 12, ['channel' => 'savings'], now()->subDays(45)->toDateString());
        (new LoanJobs())->run($cid);
        $staff = $this->loanStaffOf($company);
        $svc = new Restructure();
        $this->assertSame('top_up_too_small', $this->errorCode(fn () => $svc->applyTopUp($company, (int) $old->id, ['applied_minor' => 100_000, 'term' => 12], $staff['officer'])));
        $new = $svc->applyTopUp($company, (int) $old->id, ['applied_minor' => 1_500_000, 'term' => 18, 'purpose' => 'Expand the shop'], $staff['officer']);
        $this->assertSame(['submitted', (int) $old->id], [$new->status, (int) $new->top_up_of_loan_id], 'one open loan, but a top-up replaces it');
        $this->assertSame('application_pending', $this->errorCode(fn () => (new LoanApplications())->create($company, ['member_id' => $m->id, 'product_id' => $new->product_id, 'applied_minor' => 100_000, 'term' => 6], $staff['officer'], ['submit' => true])));
        (new LoanApplications())->appraise($company, (int) $new->id, $staff['officer'], ['monthly_income_minor' => 9_000_000]);
        (new Committee())->requestDelegated($company, (int) $new->id, $staff['officer']);
        (new Committee())->decide($company, (int) $new->id, $staff['manager'], 'approve');
        (new Contracts())->generate($company, (int) $new->id, $staff['officer']);
        (new Contracts())->sign($company, (int) $new->id, $staff['officer'], ['witness_name' => 'Witness One']);
        $quote = (new \App\Services\Sacco\Loans\Repayments())->quote($cid, (int) $old->id);
        $before = (int) \App\Models\SaccoSavingsAccount::find($acct->id)->balance_minor;
        $a = (new Disbursement())->request($company, (int) $new->id, $this->loanTreasurer($company), ['channel' => 'savings']);
        (new SaccoApprovals())->approve($company, (int) $a->id, $staff['manager']);
        $old->refresh();
        $new->refresh();
        $this->assertSame(['closed', (int) $new->id, 0], [$old->status, (int) $old->topped_up_by_loan_id, $old->owedMinor()]);
        $settle = SaccoLoanRepayment::where('loan_id', $old->id)->where('kind', 'top_up_settlement')->firstOrFail();
        $this->assertSame($quote['total_minor'], $settle->amount_minor, 'interest to the day and no early-settlement charge');
        $this->assertSame('active', $new->status);
        $this->assertSame($before + 1_500_000 - 15_000 - $quote['total_minor'], (int) \App\Models\SaccoSavingsAccount::find($acct->id)->balance_minor, 'only the new money reaches the member');
        $this->assertSame(1, SaccoLoan::where('member_id', $m->id)->where('status', 'active')->count());
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }
}
