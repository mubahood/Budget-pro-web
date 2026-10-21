<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoLoanCollateral;
use App\Models\SaccoLoanProduct;
use App\Models\User;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoAudit;
use App\Support\Rules\SaccoLoanProductRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The collateral register (SI 108 reg. 8, 04 §3.4): what a loan is secured on, its value and document, and the
 * demand-notice clock: nothing is sold until 60 days after a written demand. Never a national ID, passport, ATM
 * card or PIN, savings passbook or a signed transfer deed. Released when the loan is paid, never deleted.
 */
final class Collateral
{
    public const DEMAND_DAYS = 60;

    /** @param  array<string, mixed>  $data  type, description, value_minor (whole minor units), document_file_id */
    public function add(Company $company, int $loanId, array $data, User $by): SaccoLoanCollateral
    {
        SaccoAccess::authorize($by, 'sacco_loans');
        $cid = (int) $company->id;
        $type = (string) ($data['type'] ?? '');
        if (in_array($type, SaccoLoanProductRules::FORBIDDEN_COLLATERAL, true)) {
            throw BusinessRuleException::make('collateral_not_allowed', 'A national ID, passport, ATM card or PIN, savings passbook or a signed transfer deed may never be held as security (reg. 8).');
        }
        if (! in_array($type, SaccoLoanProductRules::COLLATERAL_TYPES, true)) {
            throw BusinessRuleException::make('invalid_collateral', "\"{$type}\" is not a kind of security the books know.");
        }
        $value = $data['value_minor'] ?? null;
        if (! is_int($value) || $value < 0 || blank($data['description'] ?? null)) {
            throw BusinessRuleException::make('invalid_collateral', 'Describe the security and give its value in whole minor units.');
        }

        return DB::transaction(function () use ($cid, $loanId, $data, $type, $value, $by) {
            $loan = LoanBooks::lock($cid, $loanId);
            LoanBooks::assertStatus($loan, Guarantors::EDITABLE, 'given security now');
            $accepted = (array) (SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $cid)->find($loan->product_id)?->collateral_types ?? []);
            if ($accepted !== [] && ! in_array($type, $accepted, true)) {
                throw BusinessRuleException::make('collateral_not_accepted', 'This loan product does not take '.str_replace('_', ' ', $type).' as security.');
            }
            $c = SaccoLoanCollateral::create(['company_id' => $cid, 'loan_id' => $loan->id, 'type' => $type, 'description' => mb_substr((string) $data['description'], 0, 255),
                'value_minor' => $value, 'document_file_id' => $data['document_file_id'] ?? null, 'created_by_id' => $by->id]);
            SaccoAudit::record('sacco.loan.collateral_added', $c, $cid, ['loan_id' => $loan->id, 'type' => $type, 'value_minor' => $value], (int) $by->id);

            return $c;
        });
    }

    /** The written demand, served on a day (the 60-day clock starts). */
    public function demandNotice(Company $company, int $collateralId, string $servedOn, User $by): SaccoLoanCollateral
    {
        SaccoAccess::authorize($by, 'sacco_loans');

        return $this->change($company, $collateralId, function (SaccoLoanCollateral $c) use ($servedOn) {
            if ($c->released_on !== null || $c->realised_on !== null) {
                throw BusinessRuleException::make('collateral_closed', 'This security is already released or sold.');
            }

            return ['demand_notice_on' => Carbon::parse($servedOn)->toDateString()];
        }, 'sacco.loan.collateral_demand', $by);
    }

    /** The security sold (reg. 8: at least 60 days after the demand). The proceeds are a repayment, recorded apart. */
    public function realise(Company $company, int $collateralId, string $soldOn, int $realisedMinor, User $by): SaccoLoanCollateral
    {
        SaccoAccess::authorize($by, 'sacco_loans');

        return $this->change($company, $collateralId, function (SaccoLoanCollateral $c) use ($soldOn, $realisedMinor) {
            if ($c->demand_notice_on === null || $c->demand_notice_on->copy()->addDays(self::DEMAND_DAYS)->gt(Carbon::parse($soldOn))) {
                throw BusinessRuleException::make('demand_notice_period', 'Security may be sold only 60 days after the written demand (Lending Conditions Regulations 2024, reg. 8).',
                    ['earliest' => $c->demand_notice_on?->copy()->addDays(self::DEMAND_DAYS)->toDateString()]);
            }

            return ['realised_on' => Carbon::parse($soldOn)->toDateString(), 'realised_minor' => $realisedMinor];
        }, 'sacco.loan.collateral_realised', $by);
    }

    public function release(Company $company, int $collateralId, User $by): SaccoLoanCollateral
    {
        SaccoAccess::authorize($by, 'sacco_loans');

        return $this->change($company, $collateralId, fn () => ['released_on' => LoanBooks::today((int) $company->id)->toDateString()], 'sacco.loan.collateral_released', $by);
    }

    /** Every security of a closed loan goes back. */
    public static function releaseAll(int $companyId, int $loanId, string $on): void
    {
        SaccoLoanCollateral::withoutGlobalScopes()->where('company_id', $companyId)->where('loan_id', $loanId)->whereNull('released_on')->whereNull('realised_on')
            ->get()->each(fn (SaccoLoanCollateral $c) => $c->update(['released_on' => $on]));
    }

    /** @param callable(SaccoLoanCollateral): array<string, mixed> $fn */
    private function change(Company $company, int $collateralId, callable $fn, string $action, User $by): SaccoLoanCollateral
    {
        return DB::transaction(function () use ($company, $collateralId, $fn, $action, $by) {
            $c = SaccoLoanCollateral::withoutGlobalScopes()->where('company_id', $company->id)->lockForUpdate()->find($collateralId)
                ?? throw BusinessRuleException::make('collateral_not_found', 'That security is not in this SACCO.');
            $c->update($fn($c));
            SaccoAudit::record($action, $c, (int) $company->id, ['loan_id' => $c->loan_id], (int) $by->id);

            return $c;
        });
    }
}
