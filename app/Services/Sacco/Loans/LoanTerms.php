<?php

namespace App\Services\Sacco\Loans;

use App\Exceptions\BusinessRuleException;

/**
 * A loan's terms as snapshotted onto it from its product at application (the MobiSave KEEP: later product changes
 * never reach a running loan). Rates are whole basis points (AK-08): the contract rate per `rate_period`, the
 * penalty per month (SI 108 reg. 4(5)). Everything a schedule, an accrual or a key-facts sheet needs is here.
 */
final class LoanTerms implements \JsonSerializable
{
    public const METHODS = ['reducing_annuity', 'reducing_equal_principal', 'flat'];

    public const RATE_PERIODS = ['month', 'year'];

    /**
     * Repayment frequencies of P2. `meeting` (a group's meeting cycle) arrives with group meetings (P5, AK-80);
     * until then a group lends weekly, fortnightly or monthly.
     */
    public const FREQUENCIES = ['weekly', 'fortnightly', 'monthly'];

    /** Days in one period of each frequency, for the instalments test of classification (AK-40). */
    public const PERIOD_DAYS = ['weekly' => 7, 'fortnightly' => 14, 'monthly' => 30];

    public const ALLOCATION = ['penalty', 'fees', 'interest', 'principal'];

    /** @param  list<string>  $allocationOrder */
    public function __construct(
        public readonly string $method,
        public readonly int $rateBp,
        public readonly string $ratePeriod,
        public readonly string $frequency,
        public readonly int $term,
        public readonly int $principalGracePeriods = 0,
        public readonly int $processingFeeBp = 0,
        public readonly int $processingFeeMinor = 0,
        public readonly int $insuranceBp = 0,
        public readonly int $penaltyRateBp = 0,
        public readonly int $graceDays = 0,
        public readonly int $coolingOffWorkingDays = 5,
        public readonly int $cancellationFeeBp = 0,
        public readonly bool $holdDisbursementUntilCoolingOffEnds = false,
        public readonly array $allocationOrder = self::ALLOCATION,
        public readonly ?int $productId = null,
        public readonly ?int $productVersion = null,
    ) {
        if (! in_array($method, self::METHODS, true)) {
            throw BusinessRuleException::make('invalid_terms', "\"{$method}\" is not an interest method.");
        }
        if (! in_array($ratePeriod, self::RATE_PERIODS, true)) {
            throw BusinessRuleException::make('invalid_terms', 'The rate is per month or per year.');
        }
        if (! in_array($frequency, self::FREQUENCIES, true)) {
            throw BusinessRuleException::make('frequency_not_available', $frequency === 'meeting'
                ? 'Loans repaid at each meeting arrive with group meetings; choose weekly, fortnightly or monthly for now.'
                : "\"{$frequency}\" is not a repayment frequency.");
        }
        if ($term < 1 || $term > 600) {
            throw BusinessRuleException::make('invalid_terms', 'The term is between 1 and 600 instalments.');
        }
        if ($principalGracePeriods < 0 || $principalGracePeriods >= $term) {
            throw BusinessRuleException::make('invalid_terms', 'The principal grace must leave at least one instalment that repays principal.');
        }
        foreach (['rateBp' => $rateBp, 'processingFeeBp' => $processingFeeBp, 'processingFeeMinor' => $processingFeeMinor, 'insuranceBp' => $insuranceBp,
            'penaltyRateBp' => $penaltyRateBp, 'graceDays' => $graceDays, 'coolingOffWorkingDays' => $coolingOffWorkingDays, 'cancellationFeeBp' => $cancellationFeeBp] as $k => $v) {
            if ($v < 0) {
                throw BusinessRuleException::make('invalid_terms', "{$k} cannot be negative.");
            }
        }
        $order = array_values($allocationOrder);
        $sorted = $order;
        sort($sorted);
        $expected = self::ALLOCATION;
        sort($expected);
        if ($sorted !== $expected) {
            throw BusinessRuleException::make('invalid_terms', 'The allocation order names penalty, fees, interest and principal, once each.');
        }
    }

    /** @param  array<string, mixed>  $a  the snapshot as stored on sacco_loans.terms */
    public static function fromArray(array $a): self
    {
        $order = $a['allocation_order'] ?? self::ALLOCATION;

        return new self(
            (string) ($a['method'] ?? ''), (int) ($a['rate_bp'] ?? 0), (string) ($a['rate_period'] ?? 'month'), (string) ($a['frequency'] ?? 'monthly'),
            (int) ($a['term'] ?? 0), (int) ($a['principal_grace_periods'] ?? 0), (int) ($a['processing_fee_bp'] ?? 0), (int) ($a['processing_fee_minor'] ?? 0),
            (int) ($a['insurance_bp'] ?? 0), (int) ($a['penalty_rate_bp'] ?? 0), (int) ($a['grace_days'] ?? 0), (int) ($a['cooling_off_working_days'] ?? 5),
            (int) ($a['cancellation_fee_bp'] ?? 0), (bool) ($a['hold_disbursement_until_cooling_off_ends'] ?? false),
            is_string($order) ? array_map('trim', explode(',', $order)) : array_values((array) $order),
            isset($a['product_id']) ? (int) $a['product_id'] : null, isset($a['product_version']) ? (int) $a['product_version'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['method' => $this->method, 'rate_bp' => $this->rateBp, 'rate_period' => $this->ratePeriod, 'frequency' => $this->frequency, 'term' => $this->term,
            'principal_grace_periods' => $this->principalGracePeriods, 'processing_fee_bp' => $this->processingFeeBp, 'processing_fee_minor' => $this->processingFeeMinor,
            'insurance_bp' => $this->insuranceBp, 'penalty_rate_bp' => $this->penaltyRateBp, 'grace_days' => $this->graceDays,
            'cooling_off_working_days' => $this->coolingOffWorkingDays, 'cancellation_fee_bp' => $this->cancellationFeeBp,
            'hold_disbursement_until_cooling_off_ends' => $this->holdDisbursementUntilCoolingOffEnds, 'allocation_order' => $this->allocationOrder,
            'product_id' => $this->productId, 'product_version' => $this->productVersion];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** The same terms with another term (instalments) or interest-only start: a reschedule or an officer's choice. */
    public function with(?int $term = null, ?int $principalGracePeriods = null): self
    {
        $a = $this->toArray();
        $a['term'] = $term ?? $this->term;
        $a['principal_grace_periods'] = $principalGracePeriods ?? $this->principalGracePeriods;

        return self::fromArray($a);
    }

    /** The contract rate per year, in basis points (shown to members as % per year, SI 108 reg. 5(2)(m)). */
    public function annualRateBp(): int
    {
        return $this->ratePeriod === 'month' ? $this->rateBp * 12 : $this->rateBp;
    }

    /** The contract rate per month, in basis points (the penalty cap is half of it, rounded down). */
    public function monthlyRateBp(): int
    {
        return $this->ratePeriod === 'month' ? $this->rateBp : intdiv($this->rateBp, 12);
    }

    /** One period's share of the yearly rate, exact at bcmath scale 20 (AK-39: the yearly rate ÷ 12, 26 or 52). */
    public function periodicRatio(): string
    {
        if ($this->ratePeriod === 'month' && $this->frequency === 'monthly') {
            return bcdiv((string) $this->rateBp, '10000', Schedule::SCALE);
        }

        return bcdiv((string) $this->annualRateBp(), (string) (10000 * $this->periodsPerYear()), Schedule::SCALE);
    }

    /** Periods in a year for the frequency (52, 26, 12). */
    public function periodsPerYear(): int
    {
        return ['weekly' => 52, 'fortnightly' => 26, 'monthly' => 12][$this->frequency];
    }

    /**
     * The term in days on the nominal year the rate is quoted on (12 months, 52 weeks = 365 days), rounded up: the
     * span the 100 % a year cost ceiling is measured over (SI 108 reg. 4(2)), so a February never breaks the cap.
     */
    public function nominalTermDays(): int
    {
        $ppy = $this->periodsPerYear();

        return intdiv($this->term * 365 + $ppy - 1, $ppy);
    }

    public function isReducing(): bool
    {
        return $this->method !== 'flat';
    }
}
