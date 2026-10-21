<?php

namespace App\Support\Rules;

use App\Exceptions\BusinessRuleException;
use App\Models\SaccoLoanProduct;
use App\Services\Sacco\Loans\LoanTerms;
use App\Services\Sacco\Loans\Schedule;
use App\Support\Sacco\Amount;
use App\Support\Sacco\Rate;
use Illuminate\Validation\Rule;

/**
 * A loan product's fields and the law it must keep (the CustomerRules pattern; 02 §1.4 SI 108 of 2024, AK-07, AK-26,
 * AK-28). In office mode every guard holds; a savings group in group mode is a self-help group, which SI 108 exempts,
 * so flat interest and a shorter cooling-off are allowed there, but a group's chart has no penalty account (AK-58).
 *
 * | Guard | Code | Law |
 * |---|---|---|
 * | flat interest in office mode | flat_interest_not_allowed | reg. 9 (monthly outstanding principal) |
 * | penalty above half the monthly rate | penalty_rate_too_high | reg. 4(5) |
 * | interest + fees + insurance above 100 % of principal a year | cost_cap_exceeded | reg. 4(2), (7) |
 * | cooling-off under 5 working days | cooling_off_too_short | reg. 11 |
 * | cancellation fee above 2 % | cancellation_fee_too_high | reg. 11 |
 * | ID, passport, ATM card, PIN, passbook or a signed transfer deed as collateral | collateral_not_allowed | reg. 8 |
 */
final class SaccoLoanProductRules
{
    /** Collateral a product may accept (04 §3.4). */
    public const COLLATERAL_TYPES = ['land_title', 'logbook', 'chattel', 'salary_assignment', 'deposit_lien', 'other'];

    /** Never collateral (SI 108 reg. 8): refused by name, so a product cannot list them under another spelling. */
    public const FORBIDDEN_COLLATERAL = ['national_id', 'passport', 'atm_card', 'pin', 'savings_passbook', 'passbook', 'transfer_deed'];

    public const MAX_CANCELLATION_FEE_BP = 200;

    public const MIN_COOLING_OFF_DAYS = 5;

    public const MAX_COST_PER_YEAR_BP = 10000;

    /** Fields that are terms: changing one bumps terms_version (loans keep the snapshot they were given). */
    public const TERM_FIELDS = ['interest_method', 'rate_bp', 'rate_period', 'repayment_frequency', 'processing_fee_bp', 'processing_fee_minor', 'insurance_bp',
        'penalty_rate_bp', 'grace_days', 'principal_grace_periods', 'cooling_off_working_days', 'cancellation_fee_bp', 'hold_disbursement_until_cooling_off_ends'];

    /** @return array<string, array<int, mixed>> */
    public static function fields(bool $creating = true): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$req, 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => [$req, 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preset' => ['sometimes', 'nullable', Rule::in(SaccoLoanProduct::PRESETS)],
            'interest_method' => [$req, Rule::in(SaccoLoanProduct::METHODS)],
            'rate_bp' => SaccoRules::bp(100000, $creating),
            'rate_period' => ['sometimes', Rule::in(SaccoLoanProduct::RATE_PERIODS)],
            'repayment_frequency' => ['sometimes', 'string', 'max:12'],
            'min_term' => [$req, 'integer', 'min:1', 'max:600'],
            'max_term' => [$req, 'integer', 'min:1', 'max:600'],
            'min_amount_minor' => SaccoRules::minor($creating, 1),
            'max_amount_minor' => SaccoRules::minor($creating, 1),
            'savings_multiplier_bp' => SaccoRules::bp(1000000),
            'min_membership_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'min_shares' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'guarantors_required' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'guarantee_cover_bp' => SaccoRules::bp(10000),
            'max_guarantees_per_guarantor' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'processing_fee_bp' => SaccoRules::bp(10000),
            'processing_fee_minor' => SaccoRules::minor(false),
            'insurance_bp' => SaccoRules::bp(10000),
            'penalty_rate_bp' => SaccoRules::bp(10000),
            'grace_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'principal_grace_periods' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'cooling_off_working_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'cancellation_fee_bp' => SaccoRules::bp(10000),
            'hold_disbursement_until_cooling_off_ends' => ['sometimes', 'boolean'],
            'allowed_channels' => [$req, 'array', 'min:1'],
            'allowed_channels.*' => [Rule::in(SaccoLoanProduct::CHANNELS)],
            'collateral_types' => ['sometimes', 'nullable', 'array'],
            'collateral_types.*' => ['string', 'max:24'],
            'inputs_share_min_bp' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'is_salary_advance' => ['sometimes', 'boolean'],
            'allow_top_up' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The legal and arithmetic guards on a product's complete attributes. Throws the first refusal with its code.
     *
     * @param  array<string, mixed>  $p
     */
    public static function check(string $mode, array $p, string $currency): void
    {
        $method = (string) $p['interest_method'];
        $frequency = (string) ($p['repayment_frequency'] ?? 'monthly');
        if ($mode === 'office' && $method === 'flat') {
            throw BusinessRuleException::make('flat_interest_not_allowed', 'A SACCO charges interest on the reducing balance (Lending Conditions Regulations 2024, reg. 9). Flat interest is for savings groups only.');
        }
        if ((int) $p['min_term'] > (int) $p['max_term']) {
            throw BusinessRuleException::make('invalid_product', 'The shortest term cannot be longer than the longest.');
        }
        if ((int) $p['min_amount_minor'] > (int) $p['max_amount_minor']) {
            throw BusinessRuleException::make('invalid_product', 'The smallest loan cannot be larger than the largest.');
        }
        if ((int) ($p['principal_grace_periods'] ?? 0) >= (int) $p['min_term']) {
            throw BusinessRuleException::make('invalid_product', 'The interest-only start must be shorter than the shortest term.');
        }
        foreach ((array) ($p['collateral_types'] ?? []) as $type) {
            if (in_array($type, self::FORBIDDEN_COLLATERAL, true)) {
                throw BusinessRuleException::make('collateral_not_allowed', 'A national ID, passport, ATM card or PIN, savings passbook or a signed transfer deed may never be held as security (reg. 8).', ['type' => $type]);
            }
            if (! in_array($type, self::COLLATERAL_TYPES, true)) {
                throw BusinessRuleException::make('invalid_product', "\"{$type}\" is not a kind of security the books know.", ['type' => $type]);
            }
        }
        $channels = (array) $p['allowed_channels'];
        if ($mode === 'group' && array_diff($channels, ['cash', 'savings', 'bank']) !== []) {
            throw BusinessRuleException::make('invalid_product', 'A savings group pays loans out in cash, to savings or by bank.');
        }
        $terms = new LoanTerms($method, (int) $p['rate_bp'], (string) ($p['rate_period'] ?? 'month'), $frequency, (int) $p['min_term'], (int) ($p['principal_grace_periods'] ?? 0),
            (int) ($p['processing_fee_bp'] ?? 0), (int) ($p['processing_fee_minor'] ?? 0), (int) ($p['insurance_bp'] ?? 0), (int) ($p['penalty_rate_bp'] ?? 0),
            (int) ($p['grace_days'] ?? 0), (int) ($p['cooling_off_working_days'] ?? 5), (int) ($p['cancellation_fee_bp'] ?? 0));
        $penaltyCap = Rate::bp($terms->monthlyRateBp())->halfOf()->bp;
        if ($mode === 'group' && $terms->penaltyRateBp > 0) {
            throw BusinessRuleException::make('not_in_group_mode', 'A savings group charges fines, not penalty interest: set the penalty to 0.');
        }
        if ($terms->penaltyRateBp > $penaltyCap) {
            throw BusinessRuleException::make('penalty_rate_too_high', 'The penalty may be at most half the monthly interest rate ('.Rate::bp($penaltyCap)->percentString().' a month), on overdue principal only (reg. 4(5)).',
                ['max_bp' => $penaltyCap]);
        }
        if ($mode !== 'office') {
            return;
        }
        if ($terms->coolingOffWorkingDays < self::MIN_COOLING_OFF_DAYS) {
            throw BusinessRuleException::make('cooling_off_too_short', 'A borrower may cancel for at least 5 working days after signing (reg. 11).');
        }
        if ($terms->cancellationFeeBp > self::MAX_CANCELLATION_FEE_BP) {
            throw BusinessRuleException::make('cancellation_fee_too_high', 'The cancellation fee is at most 2 % of the loan (reg. 11).');
        }
        foreach ([(int) $p['min_amount_minor'], (int) $p['max_amount_minor']] as $amount) {
            foreach (array_unique([(int) $p['min_term'], (int) $p['max_term']]) as $term) {
                $cost = self::costPerYearBp($amount, $terms->with($term), $currency);
                if ($cost > self::MAX_COST_PER_YEAR_BP) {
                    throw BusinessRuleException::make('cost_cap_exceeded', 'Interest and all fees may not pass 100 % of the principal a year (reg. 4(2)): a loan of '
                        .Amount::of($amount, $currency)->toMajorString()." over {$term} instalments would cost ".bcdiv((string) $cost, '100', 2).' % a year.',
                        ['cost_bp' => $cost, 'amount_minor' => $amount, 'term' => $term]);
                }
            }
        }
    }

    /** The yearly cost of credit of one loan on these terms (interest at schedule, fees and insurance), in basis points. */
    public static function costPerYearBp(int $principal, LoanTerms $terms, string $currency): int
    {
        $lines = Schedule::build($principal, $terms, '2026-01-31', $currency);
        $charges = Schedule::upfrontCharges($principal, $terms, $currency);

        return Schedule::costPerYearBp($principal, Schedule::totals($lines)['interest'] + $charges['fee'] + $charges['insurance'], $terms->nominalTermDays());
    }

    /**
     * The presets (02 §7.2), money in the SACCO's currency. PDM: the Parish Revolving Fund's UGX 1 m including charges at
     * 6 % a year, a UGX 5,000 fee, 90 % on inputs, 1 to 3 years; its 0.5 % a month surcharge is above SI 108's half-rate
     * ceiling for a 6 % loan, so the preset carries the legal maximum (AK-71). Emyooga: 8 % a year. VSLA (group mode):
     * the 10 % a month service charge, flat, up to 3 months, cash.
     *
     * @return array<string, mixed>
     */
    public static function preset(string $preset, string $currency): array
    {
        $m = fn (string $major) => Amount::fromMajor($major, $currency)->minor;

        return match ($preset) {
            'pdm' => ['code' => 'PDM', 'name' => 'Parish Development Model loan', 'preset' => 'pdm', 'interest_method' => 'reducing_annuity', 'rate_bp' => 600, 'rate_period' => 'year',
                'repayment_frequency' => 'monthly', 'min_term' => 12, 'max_term' => 36, 'min_amount_minor' => $m('100000'), 'max_amount_minor' => $m('1000000'),
                'savings_multiplier_bp' => 0, 'min_membership_days' => 0, 'min_shares' => 0, 'guarantors_required' => 2, 'guarantee_cover_bp' => 10000,
                'processing_fee_bp' => 0, 'processing_fee_minor' => $m('5000'), 'insurance_bp' => 0, 'penalty_rate_bp' => 25, 'grace_days' => 0, 'principal_grace_periods' => 0,
                'cooling_off_working_days' => 5, 'cancellation_fee_bp' => 0, 'allowed_channels' => ['bank', 'momo', 'cash'], 'collateral_types' => ['chattel', 'other'],
                'inputs_share_min_bp' => 9000],
            'emyooga' => ['code' => 'EMY', 'name' => 'Emyooga loan', 'preset' => 'emyooga', 'interest_method' => 'reducing_annuity', 'rate_bp' => 800, 'rate_period' => 'year',
                'repayment_frequency' => 'monthly', 'min_term' => 6, 'max_term' => 24, 'min_amount_minor' => $m('100000'), 'max_amount_minor' => $m('10000000'),
                'savings_multiplier_bp' => 30000, 'min_membership_days' => 90, 'min_shares' => 0, 'guarantors_required' => 2, 'guarantee_cover_bp' => 10000,
                'processing_fee_bp' => 100, 'processing_fee_minor' => 0, 'insurance_bp' => 0, 'penalty_rate_bp' => 33, 'grace_days' => 0, 'principal_grace_periods' => 0,
                'cooling_off_working_days' => 5, 'cancellation_fee_bp' => 0, 'allowed_channels' => ['savings', 'bank', 'cash'], 'collateral_types' => ['chattel', 'logbook', 'land_title', 'other']],
            'vsla' => ['code' => 'VSLA', 'name' => 'Group loan', 'preset' => 'vsla', 'interest_method' => 'flat', 'rate_bp' => 1000, 'rate_period' => 'month',
                'repayment_frequency' => 'monthly', 'min_term' => 1, 'max_term' => 3, 'min_amount_minor' => $m('1000'), 'max_amount_minor' => $m('5000000'),
                'savings_multiplier_bp' => 30000, 'min_membership_days' => 0, 'min_shares' => 0, 'guarantors_required' => 0, 'guarantee_cover_bp' => 0,
                'processing_fee_bp' => 0, 'processing_fee_minor' => 0, 'insurance_bp' => 0, 'penalty_rate_bp' => 0, 'grace_days' => 0, 'principal_grace_periods' => 0,
                'cooling_off_working_days' => 0, 'cancellation_fee_bp' => 0, 'allowed_channels' => ['cash'], 'collateral_types' => []],
            default => throw BusinessRuleException::make('unknown_preset', "\"{$preset}\" is not a preset."),
        };
    }

    /**
     * A new SACCO's own loan product: office mode, the ordinary loan at 2 % a month on the reducing balance (the
     * community default, 02 §7.3), 3 × free savings, 2 guarantors, a 1 % fee, the penalty at its legal maximum;
     * group mode, the VSLA preset.
     *
     * @return array<string, mixed>
     */
    public static function default(string $mode, string $currency): array
    {
        if ($mode === 'group') {
            return self::preset('vsla', $currency);
        }
        $m = fn (string $major) => Amount::fromMajor($major, $currency)->minor;
        $rate = (int) config('sacco.defaults.loan_rate_bp_monthly.community', 200);

        return ['code' => 'ORD', 'name' => 'Ordinary loan', 'interest_method' => 'reducing_annuity', 'rate_bp' => $rate, 'rate_period' => 'month',
            'repayment_frequency' => 'monthly', 'min_term' => 1, 'max_term' => 24, 'min_amount_minor' => $m('50000'), 'max_amount_minor' => $m('20000000'),
            'savings_multiplier_bp' => (int) config('sacco.defaults.loan_multiplier_bp', 30000), 'min_membership_days' => 90, 'min_shares' => 0,
            'guarantors_required' => (int) config('sacco.defaults.guarantors', 2), 'guarantee_cover_bp' => 10000, 'processing_fee_bp' => (int) config('sacco.defaults.processing_fee_bp', 100),
            'processing_fee_minor' => 0, 'insurance_bp' => 0, 'penalty_rate_bp' => intdiv($rate, 2), 'grace_days' => 0, 'principal_grace_periods' => 0,
            'cooling_off_working_days' => 5, 'cancellation_fee_bp' => 0, 'allowed_channels' => ['savings', 'cash', 'bank'],
            'collateral_types' => ['land_title', 'logbook', 'chattel', 'salary_assignment', 'deposit_lien', 'other']];
    }
}
