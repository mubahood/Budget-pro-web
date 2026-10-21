<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoLoan;
use App\Services\Sacco\Loans\Eligibility;
use App\Services\Sacco\Loans\LoanApplications;
use App\Services\Sacco\Loans\LoanProducts;
use App\Services\Sacco\Members\MemberService;
use App\Services\Sacco\SaccoSettingsService;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.5 (04 §4 Loans\Eligibility): the matrix. Each rule refuses with its code when broken and passes
 * when kept: active verified member, membership days, one open loan, one pending application, the savings multiple
 * (shares excluded), the employee policy, the cost cap, repayment capacity (with an override), liquidity and the
 * single-borrower limit.
 */
class LoanEligibilityTest extends ApiTestCase
{
    use MakesSaccoLoans;

    /** @return list<string> the codes of failing findings */
    private function failing(SaccoLoan $loan, string $stage, array $appraisal = []): array
    {
        return array_values(array_map(fn ($f) => $f['code'], array_filter((new Eligibility())->check($loan->fresh(), $stage, $appraisal), fn ($f) => ! $f['ok'])));
    }

    public function test_submission_rules(): void
    {
        [$company, $owner] = $this->loanSacco();
        $svc = new LoanApplications();
        $product = $this->ordinaryProduct($company);
        $make = fn ($member, int $amount, array $extra = []) => $svc->create($company, ['member_id' => $member->id, 'product_id' => $product->id, 'applied_minor' => $amount, 'term' => 12] + $extra, $owner);

        $member = $this->loanMember($company, $owner);
        $this->loanSavings($company, $member, 200_000, $owner);
        $this->assertSame('savings_multiple', $this->errorCode(fn () => $svc->submit($company, (int) $make($member, 600_001)->id, $owner)), '3 × 200,000');
        $loan = $make($member, 600_000);
        $svc->submit($company, (int) $loan->id, $owner);
        $this->assertSame('application_pending', $this->errorCode(fn () => $svc->submit($company, (int) $make($member, 100_000)->id, $owner)));
        $loan->refresh()->forceFill(['status' => 'active'])->save();
        $this->assertSame('loan_open', $this->errorCode(fn () => $svc->submit($company, (int) $make($member, 100_000)->id, $owner)), 'one open loan per member');

        $young = $this->loanMember($company, $owner, 30);
        $this->loanSavings($company, $young, 500_000, $owner);
        $this->assertSame('membership_too_new', $this->errorCode(fn () => $svc->submit($company, (int) $make($young, 100_000)->id, $owner)), '90 days first');

        $unverified = $this->loanMember($company, $owner);
        $unverified->forceFill(['kyc_status' => 'pending'])->save();
        $this->loanSavings($company, $unverified, 500_000, $owner);
        $this->assertSame('kyc_required', $this->errorCode(fn () => $svc->submit($company, (int) $make($unverified, 100_000)->id, $owner)));

        $staff = $this->loanMember($company, $owner);
        $this->loanSavings($company, $staff, 500_000, $owner);
        $this->officerUser($company, 'sacco_teller', 'teller', null, (int) $staff->id);
        $this->assertSame('employee_loan_policy', $this->errorCode(fn () => $svc->submit($company, (int) $make($staff, 100_000)->id, $owner)), 'salary advance only by default (AK-43)');
        app(SaccoSettingsService::class)->update((int) $company->id, ['employee_loans_policy' => 'committee'], (int) $owner->id);
        $staffLoan = $svc->submit($company, (int) SaccoLoan::where('member_id', $staff->id)->where('status', 'draft')->value('id'), $owner);
        $this->assertTrue($staffLoan->is_insider, 'an employee\'s loan goes to the committee like an insider\'s');

        $member2 = $this->loanMember($company, $owner);
        $this->loanSavings($company, $member2, 500_000, $owner);
        $loan2 = $make($member2, 100_000);
        $terms = $loan2->terms;
        $terms['rate_bp'] = 900;
        $terms['processing_fee_bp'] = 2000;
        $loan2->forceFill(['terms' => $terms, 'term' => 1])->save();
        $terms['term'] = 1;
        $loan2->forceFill(['terms' => $terms])->save();
        $this->assertSame('cost_cap_exceeded', $this->errorCode(fn () => $svc->submit($company, (int) $loan2->id, $owner)));

        (new LoanProducts())->update((int) $company->id, (int) $product->id, ['min_shares' => 2], (int) $owner->id);
        $member3 = $this->loanMember($company, $owner);
        $this->loanSavings($company, $member3, 500_000, $owner);
        $this->assertSame('min_shares', $this->errorCode(fn () => $svc->submit($company, (int) $make($member3, 100_000)->id, $owner)), 'no shares yet');
        app(\App\Services\Sacco\Shares\ShareService::class)->purchase($company, ['member_id' => $member3->id, 'shares_count' => 2, 'channel' => 'cash'], $owner);
        $this->assertSame('submitted', $svc->submit($company, (int) SaccoLoan::where('member_id', $member3->id)->where('status', 'draft')->value('id'), $owner)->status);
    }

    public function test_appraisal_capacity_liquidity_single_borrower_and_guarantees(): void
    {
        [$company, $owner] = $this->loanSacco();
        $svc = new LoanApplications();
        $member = $this->loanMember($company, $owner);
        $this->loanSavings($company, $member, 1_000_000, $owner);
        $loan = $this->application($company, $member, 1_200_000, 12, $owner);
        // 1.2 m over 12 months at 2 %: an instalment of 113,472 (94,559.60 × 1.2, half-up).
        $this->assertSame('repayment_capacity', $this->errorCode(fn () => $svc->appraise($company, (int) $loan->id, $owner, ['monthly_income_minor' => 200_000, 'monthly_expenses_minor' => 150_000])));
        $this->assertSame('repayment_capacity', $this->errorCode(fn () => $svc->appraise($company, (int) $loan->id, $owner, [])), 'no income recorded');
        $ok = $svc->appraise($company, (int) $loan->id, $owner, ['monthly_income_minor' => 200_000, 'monthly_expenses_minor' => 150_000, 'override_reason' => 'Harvest income in March, letter on file']);
        $this->assertSame('appraised', $ok->status);
        $this->assertSame(1_000_000, $ok->appraisal['free_savings_minor']);
        $this->assertSame('Harvest income in March, letter on file', $ok->appraisal['override_reason']);
        $this->assertSame(113_472, (new Eligibility())->monthlyInstalment(1_200_000, $ok->loanTerms(), 'UGX'));

        // The SACCO holds 1 m in cash (the member's deposit): 1.2 m paid in cash is more than it has, and more than 10 % of its assets.
        $ok->forceFill(['disbursement_channel' => 'cash'])->save();
        $failing = $this->failing($ok, 'approve');
        $this->assertContains('liquidity', $failing);
        $this->assertContains('single_borrower_limit', $failing);
        $this->assertContains('guarantees_incomplete', $failing, 'the 200,000 above own savings needs guarantors');
        $this->assertSame('single_borrower_limit', $this->errorCode(fn () => (new Eligibility())->assert($ok->fresh(), 'approve')), 'liquidity and guarantees are rechecked at payout');
        $this->assertSame('liquidity', $this->errorCode(fn () => (new Eligibility())->assert($ok->fresh(), 'disburse')));
        $this->assertSame(100_000, Eligibility::singleBorrowerLimit((int) $company->id), '10 % of 1 m in assets; no institutional capital yet');
        $this->assertSame(1_000_000, Eligibility::liquidity((int) $company->id));

        // Recommending less is allowed, more is not.
        $this->assertSame('invalid_appraisal', $this->errorCode(fn () => $svc->appraise($company, (int) $loan->id, $owner, ['recommended_minor' => 1_300_000, 'monthly_income_minor' => 9_000_000])));
        $less = $svc->appraise($company, (int) $loan->id, $owner, ['recommended_minor' => 90_000, 'term' => 6, 'monthly_income_minor' => 9_000_000]);
        $this->assertSame([90_000, 6], [$less->approved_minor, $less->loanTerms()->term]);
        $this->assertSame([], array_values(array_diff($this->failing($less, 'approve'), [])), 'within every limit now');
    }

    public function test_members_apply_and_withdraw_and_drafts_change(): void
    {
        [$company, $owner] = $this->loanSacco();
        $member = $this->loanMember($company, $owner);
        $this->loanSavings($company, $member, 300_000, $owner);
        $product = $this->ordinaryProduct($company);
        $svc = new LoanApplications();
        $mine = $svc->applyAsMember($company, $member, ['product_id' => $product->id, 'applied_minor' => 250_000, 'term' => 6, 'purpose' => 'School fees']);
        $this->assertSame(['submitted', 'member', (int) $member->id], [$mine->status, $mine->source, (int) $mine->member_actor_id]);
        $this->assertMatchesRegularExpression('/^LN-\d{4}-\d{6}$/', (string) $mine->loan_no);
        $other = $this->loanMember($company, $owner);
        $this->assertSame('loan_not_found', $this->errorCode(fn () => $svc->withdraw($company, (int) $mine->id, null, 'not mine', (int) $other->id)));
        $this->assertSame('cancelled', $svc->withdraw($company, (int) $mine->id, null, 'Found the money elsewhere', (int) $member->id)->status);

        $uuid = '7d4f3b7e-3a3d-4a2a-9a55-2b1e0e0f0a01';
        $draft = $svc->create($company, ['uuid' => $uuid, 'member_id' => $member->id, 'product_id' => $product->id, 'applied_minor' => 200_000, 'term' => 6], $owner);
        $this->assertSame((int) $draft->id, (int) $svc->create($company, ['uuid' => $uuid, 'member_id' => $member->id, 'product_id' => $product->id, 'applied_minor' => 200_000, 'term' => 6], $owner)->id, 'the same uuid is the same draft');
        $this->assertSame('idempotency_conflict', $this->errorCode(fn () => $svc->create($company, ['uuid' => $uuid, 'member_id' => $member->id, 'product_id' => $product->id, 'applied_minor' => 1, 'term' => 6], $owner)));
        $changed = $svc->update($company, (int) $draft->id, ['term' => 9, 'applied_minor' => 210_000], $owner);
        $this->assertSame([9, 210_000], [$changed->loanTerms()->term, $changed->applied_minor], 'the snapshot follows the term');
        $this->assertSame('term_out_of_range', $this->errorCode(fn () => $svc->update($company, (int) $draft->id, ['term' => 99], $owner)));
        $this->assertSame('channel_not_allowed', $this->errorCode(fn () => $svc->update($company, (int) $draft->id, ['disbursement_channel' => 'momo'], $owner)));
        $this->assertSame('invalid_loan', $this->errorCode(fn () => $svc->update($company, (int) $draft->id, ['applied_minor' => '1500.50'], $owner)), 'never a decimal');
        $teller = $this->saccoStaff($company, 'sacco_teller');
        $this->assertSame('forbidden', $this->errorCode(fn () => $svc->update($company, (int) $draft->id, ['term' => 8], $teller)), 'sacco_loans only');

        $pdm = (new LoanProducts())->create((int) $company->id, ['preset' => 'pdm'], (int) $owner->id);
        $this->assertSame('inputs_share_too_low', $this->errorCode(fn () => $svc->create($company, ['member_id' => $member->id, 'product_id' => $pdm->id, 'applied_minor' => 500_000, 'term' => 12, 'inputs_share_bp' => 8000], $owner)));
    }

    public function test_group_mode_counts_shares_and_skips_kyc(): void
    {
        [$company, $owner] = $this->loanSacco('group');
        $member = app(MemberService::class)->register($company, ['first_name' => 'Group', 'last_name' => 'Member', 'consent_data' => true, 'joined_on' => now()->subDays(10)->toDateString()], $owner)['member'];
        $this->loanSavings($company, $member, 100_000, $owner, 'GRP');
        $vsla = \App\Models\SaccoLoanProduct::where('company_id', $company->id)->where('code', 'VSLA')->firstOrFail();
        $svc = new LoanApplications();
        $loan = $svc->create($company, ['member_id' => $member->id, 'product_id' => $vsla->id, 'applied_minor' => 300_000, 'term' => 3], $owner, ['submit' => true]);
        $this->assertSame('submitted', $loan->status, 'no KYC in a group; 3 × savings');
        $this->assertNotContains('single_borrower_limit', array_column((new Eligibility())->check($loan, 'approve'), 'code'), 'a group has no single-borrower rule');
    }
}
