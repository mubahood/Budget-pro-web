<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoSetting;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\SaccoAudit;
use App\Support\LocalDate;
use App\Support\Rules\SaccoLoanProductRules;
use App\Support\Sacco\Amount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A SACCO's loan products (04 §3.4, P2.3): create, change and switch off, always through SaccoLoanProductRules, and
 * the preview a member is shown before applying (the schedule, the total cost of credit, the rate per year and the
 * cost a year, SI 108 reg. 4(3), 5). A change to a term bumps terms_version; running loans keep their snapshot.
 */
final class LoanProducts
{
    /** @param  array<string, mixed>  $data */
    public function create(int $companyId, array $data, ?int $byUserId = null): SaccoLoanProduct
    {
        $settings = self::settings($companyId);
        if (isset($data['preset']) && ! isset($data['interest_method'])) {
            $data = array_merge(SaccoLoanProductRules::preset((string) $data['preset'], $settings->currency), array_filter($data, fn ($v) => $v !== null));
        }
        $data = $this->validated($data, true);
        SaccoLoanProductRules::check($settings->mode, $data, $settings->currency);

        return DB::transaction(function () use ($companyId, $data, $byUserId, $settings) {
            if (SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $companyId)->where('code', $data['code'])->exists()) {
                throw BusinessRuleException::make('duplicate_code', "There is already a loan product {$data['code']}.");
            }
            $product = SaccoLoanProduct::create($data + ['company_id' => $companyId, 'uuid' => (string) Str::uuid(), 'terms_version' => 1, 'version' => 1, 'created_by_id' => $byUserId,
                'portfolio_account_id' => ChartOfAccounts::id($companyId, 'loans.principal'), 'interest_income_account_id' => ChartOfAccounts::id($companyId, 'inc.interest'),
                'fee_income_account_id' => ChartOfAccounts::id($companyId, 'inc.loan_fees'),
                'penalty_income_account_id' => $settings->mode === 'office' ? ChartOfAccounts::id($companyId, 'inc.penalty') : null]);
            SaccoAudit::record('sacco.loan_product.created', $product, $companyId, ['code' => $product->code], $byUserId);

            return $product;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(int $companyId, int $productId, array $data, ?int $byUserId = null): SaccoLoanProduct
    {
        $settings = self::settings($companyId);
        $changes = $this->validated($data, false);
        unset($changes['code'], $changes['preset']);

        return DB::transaction(function () use ($companyId, $productId, $changes, $byUserId, $settings) {
            $product = SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->find($productId)
                ?? throw BusinessRuleException::make('product_not_found', 'That loan product is not in this SACCO.');
            $merged = array_merge($product->only(array_filter(array_keys(SaccoLoanProductRules::fields()), fn ($k) => ! str_contains($k, '.'))), $changes);
            SaccoLoanProductRules::check($settings->mode, $merged, $settings->currency);
            $termChanged = false;
            foreach (SaccoLoanProductRules::TERM_FIELDS as $f) {
                if (array_key_exists($f, $changes) && $changes[$f] != $product->{$f}) {
                    $termChanged = true;
                }
            }
            $product->fill($changes + ['updated_by_id' => $byUserId, 'version' => (int) $product->version + 1]);
            if ($termChanged) {
                $product->terms_version = (int) $product->terms_version + 1;
            }
            $product->save();

            return $product;
        });
    }

    public function setActive(int $companyId, int $productId, bool $active, ?int $byUserId = null): SaccoLoanProduct
    {
        return $this->update($companyId, $productId, ['is_active' => $active], $byUserId);
    }

    /** A new SACCO's own loan product (SaccoSetupService::extend), once. */
    public function ensureDefaults(Company $company): void
    {
        $cid = (int) $company->id;
        if (SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $cid)->exists()) {
            return;
        }
        $settings = self::settings($cid);
        $this->create($cid, SaccoLoanProductRules::default($settings->mode, $settings->currency));
    }

    /**
     * What a member sees before applying: the schedule, the charges taken at payout, the total cost of credit and the
     * rates (per year, and the all-in cost a year), for an amount and term the product allows.
     *
     * @return array<string, mixed>
     */
    public function preview(int $companyId, int $productId, int $amountMinor, int $term, ?string $disburseOn = null): array
    {
        $product = SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $companyId)->find($productId)
            ?? throw BusinessRuleException::make('product_not_found', 'That loan product is not in this SACCO.');
        self::assertFits($product, $amountMinor, $term);
        $settings = self::settings($companyId);
        $terms = $product->terms($term, (string) $settings->allocation_order);
        $on = $disburseOn !== null ? Carbon::parse($disburseOn) : LocalDate::today($companyId);

        return self::keyFacts($amountMinor, $terms, $on, Schedule::firstDueAfter($on, $terms->frequency), PostingService::currency($companyId));
    }

    /**
     * The key facts of a loan (SI 108 reg. 4(3), 5): the schedule, the charges, the total cost of credit, the rate a
     * year and the cost a year. Shared by the product preview, the appraisal and the contract.
     *
     * @return array<string, mixed>
     */
    public static function keyFacts(int $amountMinor, LoanTerms $terms, \Carbon\CarbonInterface $disburseOn, \Carbon\CarbonInterface $firstDue, string $currency): array
    {
        $lines = Schedule::build($amountMinor, $terms, $firstDue, $currency);
        $totals = Schedule::totals($lines);
        $charges = Schedule::upfrontCharges($amountMinor, $terms, $currency);
        $cost = $totals['interest'] + $charges['fee'] + $charges['insurance'];
        $maturity = end($lines)->dueOn;

        return [
            'principal_minor' => $amountMinor, 'currency' => $currency, 'method' => $terms->method, 'frequency' => $terms->frequency, 'term' => $terms->term,
            'rate_per_year_bp' => $terms->annualRateBp(), 'rate_per_month_bp' => $terms->monthlyRateBp(), 'penalty_rate_per_month_bp' => $terms->penaltyRateBp,
            'processing_fee_minor' => $charges['fee'], 'insurance_minor' => $charges['insurance'], 'net_disbursed_minor' => $amountMinor - $charges['fee'] - $charges['insurance'],
            'total_interest_minor' => $totals['interest'], 'total_cost_of_credit_minor' => $cost, 'total_repayable_minor' => $totals['total'],
            'cost_per_year_bp' => Schedule::costPerYearBp($amountMinor, $cost, $terms->nominalTermDays()), 'early_settlement_fee_minor' => 0,
            'cooling_off_working_days' => $terms->coolingOffWorkingDays, 'cancellation_fee_minor' => Amount::of($amountMinor, $currency)->times(bcdiv((string) $terms->cancellationFeeBp, '10000', 4))->minor,
            'disburse_on' => $disburseOn->toDateString(), 'first_due_on' => $firstDue->toDateString(), 'maturity_on' => $maturity,
            'instalment_minor' => $lines[min(count($lines) - 1, $terms->principalGracePeriods)]->instalment(),
            'schedule' => array_map(fn (ScheduleLine $l) => $l->jsonSerialize(), $lines),
        ];
    }

    /** The amount and term are within the product's limits. */
    public static function assertFits(SaccoLoanProduct $product, int $amountMinor, int $term): void
    {
        if (! $product->is_active) {
            throw BusinessRuleException::make('product_inactive', "{$product->name} is switched off.");
        }
        if ($amountMinor < $product->min_amount_minor || $amountMinor > $product->max_amount_minor) {
            throw BusinessRuleException::make('amount_out_of_range', "{$product->name} lends between ".$product->min_amount_minor.' and '.$product->max_amount_minor.' (minor units).',
                ['min_minor' => (int) $product->min_amount_minor, 'max_minor' => (int) $product->max_amount_minor]);
        }
        if ($term < $product->min_term || $term > $product->max_term) {
            throw BusinessRuleException::make('term_out_of_range', "{$product->name} runs {$product->min_term} to {$product->max_term} instalments.",
                ['min' => (int) $product->min_term, 'max' => (int) $product->max_term]);
        }
    }

    public static function settings(int $companyId): SaccoSetting
    {
        return SaccoSetting::for($companyId) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data, bool $creating): array
    {
        $v = validator($data, SaccoLoanProductRules::fields($creating));
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_product', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }
        $out = $v->validated();
        if (isset($out['code'])) {
            $out['code'] = strtoupper((string) $out['code']);
        }
        if ($creating) {
            $out += ['rate_period' => 'month', 'repayment_frequency' => 'monthly'];
        }

        return $out;
    }
}
