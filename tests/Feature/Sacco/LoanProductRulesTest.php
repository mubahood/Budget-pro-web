<?php

namespace Tests\Feature\Sacco;

use App\Models\SaccoLoanProduct;
use App\Services\Sacco\Loans\LoanProducts;
use App\Support\Rules\SaccoLoanProductRules;
use Tests\Concerns\MakesSaccoLoans;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P2.3 (02 §1.4 SI 108 of 2024, AK-07, AK-26, AK-28): loan products keep the law. Each guard has its
 * refusal; the PDM, Emyooga and VSLA presets pass and give their published figures; a new SACCO gets its own loan
 * product; changing a term bumps the version (a running loan keeps its snapshot).
 */
class LoanProductRulesTest extends ApiTestCase
{
    use MakesSaccoLoans;

    /** @return array<string, mixed> */
    private function product(array $over = []): array
    {
        return array_merge(SaccoLoanProductRules::default('office', 'UGX'), ['code' => 'T'.random_int(100, 999)], $over);
    }

    public function test_every_legal_guard_refuses(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $svc = new LoanProducts();
        $cases = [
            'flat_interest_not_allowed' => ['interest_method' => 'flat'],
            'penalty_rate_too_high' => ['rate_bp' => 200, 'penalty_rate_bp' => 101],
            'cost_cap_exceeded' => ['rate_bp' => 800, 'penalty_rate_bp' => 0, 'min_term' => 1, 'processing_fee_bp' => 300],
            'cooling_off_too_short' => ['cooling_off_working_days' => 4],
            'cancellation_fee_too_high' => ['cancellation_fee_bp' => 201],
            'collateral_not_allowed' => ['collateral_types' => ['land_title', 'national_id']],
            'invalid_product' => ['min_term' => 12, 'max_term' => 6],
            'frequency_not_available' => ['repayment_frequency' => 'meeting'],
        ];
        foreach ($cases as $code => $over) {
            $this->assertSame($code, $this->errorCode(fn () => $svc->create($cid, $this->product($over), (int) $owner->id)), $code);
        }
        foreach (['passport', 'atm_card', 'pin', 'savings_passbook', 'transfer_deed'] as $forbidden) {
            $this->assertSame('collateral_not_allowed', $this->errorCode(fn () => $svc->create($cid, $this->product(['collateral_types' => [$forbidden]]), (int) $owner->id)));
        }
        $this->assertSame('invalid_product', $this->errorCode(fn () => $svc->create($cid, $this->product(['rate_bp' => '1.5']), (int) $owner->id)), 'a decimal rate is refused');
        // At the limits the law allows: 8 % a month with the penalty at exactly half, and a 2 % cancellation fee.
        $ok = $svc->create($cid, $this->product(['rate_bp' => 800, 'penalty_rate_bp' => 400, 'cancellation_fee_bp' => 200, 'processing_fee_bp' => 0, 'min_term' => 6]), (int) $owner->id);
        $this->assertSame(1, $ok->terms_version);
        // A yearly rate's monthly half rounds down: 6 % a year = 50 bp a month, so 25 bp is the ceiling.
        $this->assertSame('penalty_rate_too_high', $this->errorCode(fn () => $svc->create($cid, $this->product(['rate_bp' => 600, 'rate_period' => 'year', 'penalty_rate_bp' => 26]), (int) $owner->id)));
        $this->assertSame('none', $this->errorCode(fn () => $svc->create($cid, $this->product(['rate_bp' => 600, 'rate_period' => 'year', 'penalty_rate_bp' => 25]), (int) $owner->id)));
    }

    public function test_presets_default_product_and_versioning(): void
    {
        [$company, $owner] = $this->loanSacco();
        $cid = (int) $company->id;
        $ord = SaccoLoanProduct::where('company_id', $cid)->firstOrFail();
        $this->assertSame(['ORD', 'reducing_annuity', 200, 100, 30000, 2, 5], [$ord->code, $ord->interest_method, $ord->rate_bp, (int) $ord->penalty_rate_bp, (int) $ord->savings_multiplier_bp, (int) $ord->guarantors_required, (int) $ord->cooling_off_working_days],
            'set-up gives the SACCO its ordinary loan');

        $svc = new LoanProducts();
        $pdm = $svc->create($cid, ['preset' => 'pdm'], (int) $owner->id);
        $this->assertSame([600, 'year', 1_000_000, 5_000, 9000, 25], [$pdm->rate_bp, $pdm->rate_period, (int) $pdm->max_amount_minor, (int) $pdm->processing_fee_minor, (int) $pdm->inputs_share_min_bp, (int) $pdm->penalty_rate_bp]);
        // The PRF figures: UGX 1 m over 12 months at 6 % a year on the reducing balance, a UGX 5,000 fee.
        $facts = $svc->preview($cid, (int) $pdm->id, 1_000_000, 12, '2026-11-02');
        $this->assertSame(600, $facts['rate_per_year_bp']);
        $this->assertSame(86_066, $facts['instalment_minor'], 'A = 1 m × 0.005 ÷ (1 − 1.005^−12) = 86,066.43');
        $this->assertSame(32_796, $facts['total_interest_minor']);
        $this->assertSame(5_000, $facts['processing_fee_minor']);
        $this->assertSame(995_000, $facts['net_disbursed_minor']);
        $this->assertSame(37_796, $facts['total_cost_of_credit_minor']);
        $this->assertSame(378, $facts['cost_per_year_bp'], 'the all-in cost a year: 3.78 %');
        $this->assertSame(0, $facts['early_settlement_fee_minor']);
        $this->assertSame(['2026-12-02', '2027-11-02'], [$facts['first_due_on'], $facts['maturity_on']]);
        $this->assertSame('amount_out_of_range', $this->errorCode(fn () => $svc->preview($cid, (int) $pdm->id, 1_000_001, 12)), 'UGX 1 m including charges at most');
        $this->assertSame('term_out_of_range', $this->errorCode(fn () => $svc->preview($cid, (int) $pdm->id, 500_000, 6)));
        $emy = $svc->create($cid, ['preset' => 'emyooga'], (int) $owner->id);
        $this->assertSame([800, 'year', 33], [$emy->rate_bp, $emy->rate_period, (int) $emy->penalty_rate_bp]);
        $this->assertSame('flat_interest_not_allowed', $this->errorCode(fn () => $svc->create($cid, ['preset' => 'vsla'], (int) $owner->id)), 'VSLA is for group mode');

        $v = $svc->update($cid, (int) $ord->id, ['name' => 'Ordinary loan (development)'], (int) $owner->id);
        $this->assertSame(1, $v->terms_version, 'a name is not a term');
        $v = $svc->update($cid, (int) $ord->id, ['rate_bp' => 175, 'penalty_rate_bp' => 87], (int) $owner->id);
        $this->assertSame(2, $v->terms_version);
        $this->assertSame('penalty_rate_too_high', $this->errorCode(fn () => $svc->update($cid, (int) $ord->id, ['rate_bp' => 150], (int) $owner->id)), 'a rate cut under the penalty is refused');
        $svc->setActive($cid, (int) $ord->id, false, (int) $owner->id);
        $this->assertSame('product_inactive', $this->errorCode(fn () => $svc->preview($cid, (int) $ord->id, 100_000, 6)));
        $this->assertSame('product_in_use', $this->errorCode(fn () => $ord->fresh()->delete()));
    }

    public function test_group_mode_allows_flat_and_refuses_penalty(): void
    {
        [$company, $owner] = $this->loanSacco('group');
        $cid = (int) $company->id;
        $vsla = SaccoLoanProduct::where('company_id', $cid)->firstOrFail();
        $this->assertSame(['VSLA', 'flat', 1000, ['cash']], [$vsla->code, $vsla->interest_method, $vsla->rate_bp, $vsla->allowed_channels]);
        $facts = (new LoanProducts())->preview($cid, (int) $vsla->id, 300_000, 3, '2026-11-02');
        $this->assertSame(90_000, $facts['total_interest_minor'], '10 % a month on the principal, 3 months');
        $this->assertSame('not_in_group_mode', $this->errorCode(fn () => (new LoanProducts())->update($cid, (int) $vsla->id, ['penalty_rate_bp' => 100], (int) $owner->id)));
        $this->assertSame('invalid_product', $this->errorCode(fn () => (new LoanProducts())->update($cid, (int) $vsla->id, ['allowed_channels' => ['momo']], (int) $owner->id)));
    }

    public function test_tenancy(): void
    {
        [$a] = $this->loanSacco();
        [$b, $ownerB] = $this->loanSacco();
        $productA = SaccoLoanProduct::where('company_id', $a->id)->firstOrFail();
        $this->assertSame('product_not_found', $this->errorCode(fn () => (new LoanProducts())->update((int) $b->id, (int) $productA->id, ['name' => 'x'], (int) $ownerB->id)));
        $this->assertSame('product_not_found', $this->errorCode(fn () => (new LoanProducts())->preview((int) $b->id, (int) $productA->id, 100_000, 6)));
    }
}
