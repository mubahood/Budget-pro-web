<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Services\Sacco\Loans\LoanTerms;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * A SACCO's loan product (04 §3.4): written only by App\Services\Sacco\Loans\LoanProducts, which checks the legal
 * guards (SaccoLoanProductRules). Switched off, never deleted: loans point at it.
 */
class SaccoLoanProduct extends Model
{
    use AuditLogger;

    public const METHODS = LoanTerms::METHODS;

    public const RATE_PERIODS = LoanTerms::RATE_PERIODS;

    public const FREQUENCIES = LoanTerms::FREQUENCIES;

    public const CHANNELS = ['savings', 'cash', 'bank', 'momo'];

    public const PRESETS = ['pdm', 'emyooga', 'vsla'];

    protected $guarded = ['id'];

    protected $casts = ['allowed_channels' => 'array', 'collateral_types' => 'array', 'hold_disbursement_until_cooling_off_ends' => 'boolean',
        'is_salary_advance' => 'boolean', 'allow_top_up' => 'boolean', 'is_active' => 'boolean', 'is_deleted' => 'boolean',
        'rate_bp' => 'integer', 'min_amount_minor' => 'integer', 'max_amount_minor' => 'integer', 'processing_fee_minor' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::deleting(function () {
            throw BusinessRuleException::make('product_in_use', 'A loan product is switched off, never deleted: its loans keep pointing at it.');
        });
    }

    /** The terms a loan copies from this product (its snapshot), for a term the applicant chose. */
    public function terms(int $term, string $allocationOrder = 'penalty,fees,interest,principal'): LoanTerms
    {
        return new LoanTerms((string) $this->interest_method, (int) $this->rate_bp, (string) $this->rate_period, (string) $this->repayment_frequency, $term,
            (int) $this->principal_grace_periods, (int) $this->processing_fee_bp, (int) $this->processing_fee_minor, (int) $this->insurance_bp,
            (int) $this->penalty_rate_bp, (int) $this->grace_days, (int) $this->cooling_off_working_days, (int) $this->cancellation_fee_bp,
            (bool) $this->hold_disbursement_until_cooling_off_ends, array_map('trim', explode(',', $allocationOrder)), (int) $this->id, (int) $this->terms_version);
    }
}
