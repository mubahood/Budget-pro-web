<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoApproval;
use App\Models\SaccoLoanCollateral;
use App\Services\Sacco\Loans\Collateral;
use App\Services\Sacco\Loans\Committee;
use App\Services\Sacco\Loans\Contracts;
use App\Services\Sacco\Loans\Guarantors;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\Loans\LoanProducts;
use App\Services\Sacco\Loans\Restructure;
use App\Services\Sacco\Loans\WriteOffs;
use App\Support\Rules\SaccoLoanProductRules;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * AK-90 (the owner): the checks that enforce a law apply only to a licensed SACCO (an UMRA licence number). The same
 * actions, refused for a licensed SACCO, go through for an unlicensed one: flat interest in office mode, a product
 * above the 100 % cap or the half-rate penalty or under 5 days' cooling-off, an ID as collateral and a sale without
 * the 60 days, an officer as guarantor, an insider's loan decided within a limit, signing without a witness, a
 * reschedule without recorded consent and a write-off without a reason. No refusal message cites a law.
 */
class LoanStrictnessTest extends ApiTestCase
{
    use MakesSaccoLoans;

    /** @return array<string, string> code => message of each refusal */
    private function scenario(bool $licensed): array
    {
        [$company, $owner] = $this->loanSacco('office', 'Strictness', $licensed);
        $cid = (int) $company->id;
        $this->assertSame($licensed, LoanBooks::strict($cid));
        $out = [];
        $try = function (string $label, callable $fn) use (&$out) {
            try {
                $fn();
                $out[$label] = 'ok';
            } catch (\App\Exceptions\BusinessRuleException $e) {
                $out[$label] = $e->errorCode();
                $this->assertDoesNotMatchRegularExpression('/\breg\. ?\d|Regulations|Act\b|s\.\d|SI \d|AK-\d/', $e->getMessage(), 'no law in the message: '.$e->getMessage());
            }
        };
        $base = SaccoLoanProductRules::default('office', 'UGX');
        $products = new LoanProducts();
        $try('flat', fn () => $products->create($cid, ['code' => 'F'.random_int(10, 99), 'interest_method' => 'flat', 'rate_bp' => 1000, 'penalty_rate_bp' => 0, 'max_term' => 3] + $base));
        $try('cost_cap', fn () => $products->create($cid, ['code' => 'C'.random_int(10, 99), 'rate_bp' => 900, 'penalty_rate_bp' => 0, 'min_term' => 1, 'processing_fee_bp' => 300] + $base));
        $try('penalty', fn () => $products->create($cid, ['code' => 'P'.random_int(10, 99), 'penalty_rate_bp' => 150] + $base));
        $try('cooling_off', fn () => $products->create($cid, ['code' => 'K'.random_int(10, 99), 'cooling_off_working_days' => 0] + $base));

        $officerMember = $this->loanMember($company, $owner);
        $this->loanSavings($company, $officerMember, 2_000_000, $owner);
        $this->officerUser($company, 'sacco_committee', 'loans_committee', null, (int) $officerMember->id);
        $borrower = $this->loanMember($company, $owner);
        $this->loanSavings($company, $borrower, 100_000, $owner);
        $loan = $this->application($company, $borrower, 300_000, 6, $owner);
        $try('officer_guarantor', fn () => (new Guarantors())->add($company, (int) $loan->id, (int) $officerMember->id, 50_000, $owner));
        \App\Models\SaccoLoanProduct::whereKey($loan->product_id)->update(['collateral_types' => null]); // the product takes any security
        $try('id_collateral', fn () => (new Collateral())->add($company, (int) $loan->id, ['type' => 'national_id', 'description' => 'ID card', 'value_minor' => 0], $owner));
        $chattel = (new Collateral())->add($company, (int) $loan->id, ['type' => 'chattel', 'description' => 'Fridge', 'value_minor' => 500_000], $owner);
        $try('sale_without_notice', fn () => (new Collateral())->realise($company, (int) $chattel->id, now()->toDateString(), 300_000, $owner));

        $staff = $this->loanStaffOf($company);
        $insiderLoan = $this->application($company, $officerMember, 400_000, 6, $staff['officer'], $staff['officer']);
        $try('insider_delegated', fn () => (new Committee())->requestDelegated($company, (int) $insiderLoan->id, $staff['officer']));

        $m = $this->loanMember($company, $owner);
        $this->loanSavings($company, $m, 1_000_000, $owner);
        $approved = $this->approvedLoan($company, $m, 500_000, 6);
        (new Contracts())->generate($company, (int) $approved->id, $staff['officer']);
        $try('no_witness', fn () => (new Contracts())->sign($company, (int) $approved->id, $staff['officer'], ['witness_name' => '']));

        $m2 = $this->loanMember($company, $owner);
        $this->loanSavings($company, $m2, 1_000_000, $owner);
        $active = $this->activeLoan($company, $m2, 500_000, 6);
        $try('reschedule_no_consent', fn () => (new Restructure())->requestReschedule($company, (int) $active->id, $staff['officer'], ['term' => 9, 'reason' => 'Hard month']));
        $try('write_off_no_reason', fn () => (new WriteOffs())->request($company, (int) $active->id, $staff['officer'], null));

        return $out;
    }

    public function test_a_licensed_sacco_keeps_the_law(): void
    {
        $this->assertSame(['flat' => 'flat_interest_not_allowed', 'cost_cap' => 'cost_cap_exceeded', 'penalty' => 'penalty_rate_too_high', 'cooling_off' => 'cooling_off_too_short',
            'officer_guarantor' => 'guarantor_not_allowed', 'id_collateral' => 'collateral_not_allowed', 'sale_without_notice' => 'demand_notice_period',
            'insider_delegated' => 'insider_to_committee', 'no_witness' => 'witness_required', 'reschedule_no_consent' => 'consent_required',
            'write_off_no_reason' => 'reason_required'], $this->scenario(true));
    }

    public function test_an_unlicensed_sacco_keeps_its_own_rules(): void
    {
        $out = $this->scenario(false);
        $this->assertSame(array_fill_keys(array_keys($out), 'ok'), $out);
        $this->assertSame('flat', \App\Models\SaccoLoanProduct::where('interest_method', 'flat')->latest('id')->value('interest_method'), 'flat interest in office mode');
        $this->assertSame(2, SaccoApproval::whereIn('action', ['reschedule', 'write_off'])->where('status', 'pending')->latest('id')->limit(2)->count());
        $this->assertSame(1, SaccoLoanCollateral::where('type', 'national_id')->count());
    }
}
