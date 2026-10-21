<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoApproval;
use App\Models\SaccoJournalEntry;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoLoanScheduleLine;
use App\Models\SaccoPayout;
use App\Models\SaccoSavingsAccount;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Loans\Disbursement;
use App\Services\Sacco\Payouts\PayoutService;
use App\Services\Sacco\SaccoApprovals;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.9 (04 §5.1, §5.3, §11.2; SI 108 reg. 11; AK-10, AK-17, AK-26): a loan is paid out by approval
 * (never by its appraiser), to savings, cash or MoMo, with the fee and insurance taken off; executing twice posts
 * once; the schedule starts one period after payout; MoMo waits in 2160 until the provider answers and a failure
 * reverses; a cancellation inside the cooling-off returns the loan with no interest and the fee given back.
 */
class LoanDisbursementTest extends ApiTestCase
{
    use MakesSaccoLoans;

    private function balance(int $cid, string $key): int
    {
        return (int) DB::table('sacco_account_balances')->where('ledger_account_id', ChartOfAccounts::id($cid, $key))->value('balance_minor');
    }

    public function test_to_savings_with_charges_idempotent_and_never_by_the_appraiser(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $member = $this->loanMember($company, $owner);
        $account = $this->loanSavings($company, $member, 1_000_000, $owner);
        $loan = $this->signedLoan($company, $member, 1_000_000, 12, '2026-10-01');
        $staff = $this->loanStaffOf($company);
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $this->assertSame('forbidden', $this->errorCode(fn () => (new Disbursement())->request($company, (int) $loan->id, $teller, ['channel' => 'savings'])));
        $this->assertSame('channel_not_allowed', $this->errorCode(fn () => (new Disbursement())->request($company, (int) $loan->id, $staff['manager'], ['channel' => 'momo'])));
        $approval = (new Disbursement())->request($company, (int) $loan->id, $this->loanTreasurer($company), ['channel' => 'savings', 'disburse_on' => '2026-10-02']);
        $this->assertSame('forbidden', $this->errorCode(fn () => (new SaccoApprovals())->approve($company, (int) $approval->id, $staff['officer'])), 'a loans officer cannot approve');
        $loan->forceFill(['appraised_by_id' => $staff['manager']->id])->save();
        $this->assertSame('self_approval', $this->errorCode(fn () => (new SaccoApprovals())->approve($company, (int) $approval->id, $staff['manager'])), 'never the appraiser');
        (new SaccoApprovals())->approve($company, (int) $approval->id, $owner);
        $loan->refresh();
        $this->assertSame(['active', '2026-10-02', '2026-11-02', '2027-10-02'], [$loan->status, $loan->disbursed_on->toDateString(), $loan->first_due_on->toDateString(), $loan->maturity_on->toDateString()]);
        $this->assertSame(1_000_000, $loan->principal_outstanding_minor);
        $this->assertSame(1_000_000 + 990_000, (int) SaccoSavingsAccount::find($account->id)->balance_minor, 'paid in less the 1 % fee');
        $this->assertSame(10_000, $this->balance($cid, 'inc.loan_fees'));
        $this->assertSame(12, SaccoLoanScheduleLine::where('loan_id', $loan->id)->count());
        $this->assertSame(1_000_000, (int) SaccoLoanScheduleLine::where('loan_id', $loan->id)->sum('principal_due_minor'));
        $entries = SaccoJournalEntry::where('company_id', $cid)->where('kind', 'loan_disbursement')->count();
        (new SaccoApprovals())->execute($company, (int) $approval->id, $owner);
        $this->assertSame($entries, SaccoJournalEntry::where('company_id', $cid)->where('kind', 'loan_disbursement')->count(), 'executing again posts nothing');
        $this->assertSame('2026-10-02', SaccoJournalEntry::find($loan->disbursement_entry_id)->entry_date->toDateString(), 'the payout keeps its own day (backdated history)');
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
        $this->assertNotNull(DB::table('message_log')->where('company_id', $cid)->where('purpose', 'sacco_notice')->where('body', 'like', '%First instalment%')->first());
        $this->assertSame('invalid_loan_status', $this->errorCode(fn () => (new Disbursement())->request($company, (int) $loan->id, $staff['manager'], ['channel' => 'savings'])));
    }

    public function test_cash_needs_cash_and_a_held_product_waits_for_the_cooling_off(): void
    {
        [$company, $owner] = $this->loanSacco();
        $member = $this->loanMember($company, $owner);
        $this->loanSavings($company, $member, 2_000_000, $owner);
        $product = $this->ordinaryProduct($company);
        SaccoLoanProduct::whereKey($product->id)->update(['hold_disbursement_until_cooling_off_ends' => true]);
        $product->refresh();
        $loan = $this->signedLoan($company, $member, 500_000, 6, '2026-10-19', $product);
        $staff = $this->loanStaffOf($company);
        $a = (new Disbursement())->request($company, (int) $loan->id, $this->loanTreasurer($company), ['channel' => 'cash', 'disburse_on' => '2026-10-20']);
        $this->assertSame('cooling_off_active', $this->errorCode(fn () => (new SaccoApprovals())->approve($company, (int) $a->id, $staff['manager'])));
        $this->assertSame('approved', SaccoApproval::find($a->id)->status, 'the approval waits, with the reason');
        $this->assertSame('2026-10-26', $loan->fresh()->cooling_off_ends_on->toDateString());
    }

    public function test_momo_off_success_failure_and_unknown(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $product = $this->ordinaryProduct($company);
        SaccoLoanProduct::whereKey($product->id)->update(['allowed_channels' => json_encode(['savings', 'cash', 'momo'])]);
        $member = $this->loanMember($company, $owner);
        $this->loanSavings($company, $member, 3_000_000, $owner);
        $staff = $this->loanStaffOf($company);
        $loan = $this->signedLoan($company, $member, 500_000, 6, null, $product->fresh());
        config(['sacco.payouts.driver' => 'off']);
        $this->assertSame('payouts_off', $this->errorCode(fn () => (new Disbursement())->request($company, (int) $loan->id, $this->loanTreasurer($company), ['channel' => 'momo', 'momo_phone_e164' => '+256772123456'])));

        config(['sacco.payouts.driver' => 'simulated']);
        $a = (new Disbursement())->request($company, (int) $loan->id, $this->loanTreasurer($company), ['channel' => 'momo', 'momo_phone_e164' => '+256772123000']);
        (new SaccoApprovals())->approve($company, (int) $a->id, $staff['manager']);
        $loan->refresh();
        $this->assertSame(['signed', 0, 2], [$loan->status, $loan->principal_outstanding_minor, $loan->schedule_version], 'a refused payout is reversed and the loan waits');
        $this->assertSame('failed', SaccoPayout::where('subject_id', $loan->id)->value('status'));
        $this->assertSame(0, $this->balance($cid, 'payouts.in_transit'));

        $a2 = (new Disbursement())->request($company, (int) $loan->id, $this->loanTreasurer($company), ['channel' => 'momo', 'momo_phone_e164' => '+256772123456']);
        (new SaccoApprovals())->approve($company, (int) $a2->id, $staff['manager']);
        $loan->refresh();
        $this->assertSame(['active', 500_000], [$loan->status, $loan->principal_outstanding_minor]);
        $this->assertSame(0, $this->balance($cid, 'payouts.in_transit'), 'settled out of 2160');
        $this->assertSame(-495_000, $this->balance($cid, 'momo.float'), 'paid out of the float, less the fee');
        $this->assertSame(6, SaccoLoanScheduleLine::where('loan_id', $loan->id)->where('schedule_version', 2)->count());

        $other = $this->loanMember($company, $owner);
        $this->loanSavings($company, $other, 3_000_000, $owner);
        $l3 = $this->signedLoan($company, $other, 300_000, 6, null, $product->fresh());
        $a3 = (new Disbursement())->request($company, (int) $l3->id, $this->loanTreasurer($company), ['channel' => 'momo', 'momo_phone_e164' => '+256772123999']);
        (new SaccoApprovals())->approve($company, (int) $a3->id, $staff['manager']);
        $this->assertSame('disbursing', $l3->fresh()->status);
        $this->assertSame(0, (new PayoutService())->poll($cid), 'still unknown');
        $this->assertSame(297_000, $this->balance($cid, 'payouts.in_transit'));
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_cancellation_in_the_cooling_off(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $member = $this->loanMember($company, $owner);
        $account = $this->loanSavings($company, $member, 1_000_000, $owner);
        $product = $this->ordinaryProduct($company);
        SaccoLoanProduct::whereKey($product->id)->update(['cancellation_fee_bp' => 200]);
        $today = now()->toDateString();
        $loan = $this->activeLoan($company, $member, 1_000_000, 12, ['channel' => 'savings'], null, $product->fresh());
        $this->assertSame(1_990_000, (int) SaccoSavingsAccount::find($account->id)->balance_minor);
        $loan = (new Disbursement())->cancel($company, (int) $loan->id, $owner, ['channel' => 'savings', 'value_date' => $today]);
        $this->assertSame(['cancelled', 0, 0], [$loan->status, $loan->principal_outstanding_minor, $loan->interest_due_minor]);
        // Returned: 1,000,000 − 10,000 fee + 20,000 cancellation fee = 1,010,000; the fee income nets to the 2 % only.
        $this->assertSame(1_990_000 - 1_010_000, (int) SaccoSavingsAccount::find($account->id)->balance_minor);
        $this->assertSame(20_000, $this->balance($cid, 'inc.loan_fees'));
        $this->assertSame(['superseded'], SaccoLoanScheduleLine::where('loan_id', $loan->id)->distinct()->pluck('status')->all());
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));

        $m2 = $this->loanMember($company, $owner);
        $this->loanSavings($company, $m2, 1_000_000, $owner);
        $old = $this->activeLoan($company, $m2, 300_000, 6, ['channel' => 'savings'], '2026-09-01');
        $this->assertSame('cooling_off_over', $this->errorCode(fn () => (new Disbursement())->cancel($company, (int) $old->id, $owner, ['channel' => 'cash'])));
    }
}
