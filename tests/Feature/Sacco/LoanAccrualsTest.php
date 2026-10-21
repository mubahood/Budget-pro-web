<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoJournalEntry;
use App\Models\SaccoLoanChange;
use App\Models\SaccoLoanScheduleLine;
use App\Services\Sacco\Ledger\LedgerVerifier;
use App\Services\Sacco\Loans\Accruals;
use App\Services\Sacco\Loans\Arrears;
use App\Services\Sacco\Loans\LoanJobs;
use App\Services\Sacco\Loans\Repayments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.11 (04 §5.3, AK-28, AK-39, AK-40; SI 108 reg. 4(5), 7): interest falls due on each due date on the
 * principal still owed, penalty is charged monthly on overdue principal only, pro rata by day; a catch-up after
 * missed months charges each period once; classification follows the days and instalments in arrears; from
 * substandard interest goes to suspense and stops at the NPL cap; a paid-up loan stops accruing; a non-performing
 * loan keeps its class until six instalments are paid on time.
 */
class LoanAccrualsTest extends ApiTestCase
{
    use MakesSaccoLoans;

    public function test_catch_up_after_months_charges_each_period_once_and_suspends_from_substandard(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $member = $this->loanMember($company, $owner, 800);
        $this->loanSavings($company, $member, 1_200_000, $owner, 'ORD', now()->subDays(200)->toDateString());
        $on = now()->subDays(150)->toDateString();
        $loan = $this->activeLoan($company, $member, 1_200_000, 12, ['channel' => 'savings'], $on);
        $run = (new LoanJobs())->run($cid);
        $loan->refresh();
        $due = SaccoLoanScheduleLine::where('loan_id', $loan->id)->where('due_on', '<=', now()->toDateString())->get();
        $this->assertCount(4, $due);
        foreach ($due as $l) {
            $this->assertSame(24_000, $l->interest_charged_minor, 'nothing repaid: each period charges 2 % on the full 1.2 m');
        }
        $this->assertSame(4 * 24_000, $run['interest_minor']);
        $gl = (int) SaccoJournalEntry::where('company_id', $cid)->where('kind', 'interest_due')->sum('total_minor');
        $this->assertSame(4 * 24_000, $gl + $loan->interest_in_suspense_minor, 'every period charged once, in the books or in suspense');
        $this->assertGreaterThan(0, $loan->interest_in_suspense_minor, 'the last period was charged once the loan was non-performing');
        $this->assertContains($loan->classification, ['substandard', 'doubtful']);
        $this->assertSame(1_200_000, $loan->npl_cap_minor);
        $this->assertGreaterThan(0, $loan->penalty_due_minor + $loan->penalty_in_suspense_minor);
        $this->assertSame((int) Carbon::parse($due[0]->due_on)->diffInDays(now()->startOfDay()), $loan->days_past_due);
        $this->assertSame(4, $loan->instalments_in_arrears);
        $this->assertTrue(SaccoLoanChange::where('loan_id', $loan->id)->where('kind', 'downgrade')->exists());

        $entries = SaccoJournalEntry::where('company_id', $cid)->count();
        (new LoanJobs())->run($cid);
        $this->assertSame($entries, SaccoJournalEntry::where('company_id', $cid)->count(), 'running again charges nothing');
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));

        // The arrears paid: the class stays non-performing until six instalments are paid on time.
        (new Repayments())->record($company, (int) $loan->id, ['amount_minor' => 600_000, 'channel' => 'cash'], $owner);
        (new Arrears())->refresh($loan->fresh(), now()->startOfDay());
        $loan->refresh();
        $this->assertSame(0, $loan->days_past_due);
        $this->assertContains($loan->classification, ['substandard', 'doubtful'], 'sticky (SI 51)');
    }

    public function test_penalty_on_overdue_principal_pro_rata_and_the_npl_cap(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $member = $this->loanMember($company, $owner, 800);
        $this->loanSavings($company, $member, 1_200_000, $owner, 'ORD', '2026-05-01');
        $loan = $this->activeLoan($company, $member, 1_200_000, 12, ['channel' => 'savings'], '2026-06-15');
        $line1 = SaccoLoanScheduleLine::where('loan_id', $loan->id)->where('installment_no', 1)->first();
        $this->assertSame(['2026-07-15', 89_472], [$line1->due_on->toDateString(), $line1->principal_due_minor]);
        (new LoanJobs())->run($cid, '2026-08-01');
        // July: 89,472 overdue for 16 days (16–31 July) × 1 % a month ÷ 31 days = 461.79.
        $pen = SaccoJournalEntry::where('company_id', $cid)->where('idempotency_key', 'pen:'.$loan->id.':202607')->firstOrFail();
        $this->assertSame(462, (int) $pen->total_minor);
        $this->assertSame('2026-07-31', $pen->value_date->toDateString());
        $loan->refresh();
        $this->assertSame([462, '2026-07'], [$loan->penalty_due_minor, $loan->penalty_through]);
        $this->assertSame([89_472, 'watch'], [$loan->arrears_principal_minor, $loan->classification]);
        $this->assertSame(0, (new Accruals())->penalty($loan, '2026-07', Carbon::parse('2026-08-01')), 'once a month');

        // The NPL cap: once non-performing, interest and fees charged stop at the principal owed then.
        DB::table('sacco_loans')->where('id', $loan->id)->update(['classification' => 'substandard', 'npl_since' => '2026-08-01', 'npl_cap_minor' => 30_000, 'npl_charged_minor' => 0]);
        (new LoanJobs())->run($cid, '2026-10-01');
        $loan->refresh();
        $this->assertSame(30_000, $loan->npl_charged_minor, 'capped');
        $this->assertSame(30_000, $loan->interest_in_suspense_minor + $loan->penalty_in_suspense_minor);
        $this->assertSame([], LedgerVerifier::failed((new LedgerVerifier())->verify($cid)));
    }

    public function test_a_paid_up_loan_stops_accruing(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $member = $this->loanMember($company, $owner, 800);
        $this->loanSavings($company, $member, 600_000, $owner, 'ORD', '2026-05-01');
        $loan = $this->activeLoan($company, $member, 600_000, 6, ['channel' => 'savings'], '2026-06-10');
        (new LoanJobs())->run($cid, '2026-07-10');
        $quote = (new Repayments())->quote($cid, (int) $loan->id, '2026-07-20');
        (new Repayments())->record($company, (int) $loan->id, ['amount_minor' => $quote['total_minor'], 'channel' => 'cash', 'value_date' => '2026-07-20'], $owner, ['entry_date' => '2026-07-20']);
        $this->assertSame('closed', $loan->fresh()->status);
        $charges = fn () => SaccoJournalEntry::where('company_id', $cid)->whereIn('kind', ['interest_due', 'penalty'])->count();
        $entries = $charges();
        (new LoanJobs())->run($cid);
        $this->assertSame($entries, $charges(), 'no interest or penalty after it is paid (the MobiSave bug)');
        $this->assertSame(0, $loan->fresh()->owedMinor());
    }
}
