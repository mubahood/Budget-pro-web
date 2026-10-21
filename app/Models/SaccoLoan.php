<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Services\Sacco\Loans\LoanTerms;
use App\Traits\AuditLogger;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A member's loan (04 §3.4, §5.3). Its status is workflow, moved only by the App\Services\Sacco\Loans services; its
 * money is the ledger's. The four outstanding figures are caches of the loan's journal lines, written only by
 * PostingService through LoanLedger (a model save that touches them is refused), and a loan is never deleted: a
 * phone's draft is marked is_deleted, everything later is cancelled, rejected, closed or written off.
 *
 * A draft pushed by a phone (sync, AK-23: only drafts) copies its product's terms here when it is created, exactly as
 * LoanApplications::create does, so the snapshot never depends on the path the draft came by.
 */
class SaccoLoan extends Model
{
    use AuditLogger, Syncable;

    /** Fields an application may still change, until it is appraised. */
    public const APPLICATION_FIELDS = ['product_id', 'applied_minor', 'term', 'member_id'];

    public const STATUSES = ['draft', 'submitted', 'appraised', 'in_committee', 'approved', 'rejected', 'appealed', 'signed', 'cancelled', 'disbursing',
        'active', 'closed', 'written_off', 'restructured', 'pending_guarantor', 'pending_chair', 'declined_by_guarantor', 'declined_by_chair', 'not_disbursed'];

    /** The simple flow (docs/akiba/flows-plan.md): a request waits for its guarantor, then the chairperson. */
    public const SIMPLE_PENDING = ['pending_guarantor', 'pending_chair'];

    /** A loan that still owes, or may soon (one open loan per member, MobiSave). */
    public const OPEN = ['approved', 'signed', 'disbursing', 'active'];

    /** An application still on its way to a decision (one pending application per member). */
    public const PENDING = ['submitted', 'appraised', 'in_committee', 'appealed', 'pending_guarantor', 'pending_chair'];

    public const CLASSES = ['performing', 'watch', 'substandard', 'doubtful', 'loss'];

    /** Non-performing (Form 7): interest goes to suspense, the NPL cap applies (SI 51 reg. 41, SI 108 reg. 7). */
    public const NPL = ['substandard', 'doubtful', 'loss'];

    public const CHANNELS = ['savings', 'cash', 'bank', 'momo'];

    /** Written only by PostingService (LoanLedger::project). */
    public const LEDGER_CACHES = ['principal_outstanding_minor', 'interest_due_minor', 'penalty_due_minor', 'fees_due_minor'];

    protected $guarded = ['id'];

    protected $casts = ['terms' => 'array', 'appraisal' => 'array', 'is_insider' => 'boolean', 'is_deleted' => 'boolean',
        'submitted_at' => 'datetime', 'appraised_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'signed_at' => 'datetime',
        'signed_on' => 'date:Y-m-d', 'cooling_off_ends_on' => 'date:Y-m-d', 'disbursed_on' => 'date:Y-m-d', 'first_due_on' => 'date:Y-m-d',
        'maturity_on' => 'date:Y-m-d', 'closed_on' => 'date:Y-m-d', 'cancelled_on' => 'date:Y-m-d', 'written_off_on' => 'date:Y-m-d',
        'arrears_on' => 'date:Y-m-d', 'npl_since' => 'date:Y-m-d',
        'applied_minor' => 'integer', 'approved_minor' => 'integer', 'principal_outstanding_minor' => 'integer', 'interest_due_minor' => 'integer',
        'penalty_due_minor' => 'integer', 'fees_due_minor' => 'integer', 'interest_in_suspense_minor' => 'integer', 'penalty_in_suspense_minor' => 'integer',
        'arrears_principal_minor' => 'integer', 'arrears_interest_minor' => 'integer', 'days_past_due' => 'integer', 'npl_cap_minor' => 'integer',
        'npl_charged_minor' => 'integer', 'written_off_minor' => 'integer', 'recovered_minor' => 'integer', 'schedule_version' => 'integer', 'term' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::creating(function (self $loan) {
            if (empty($loan->terms)) {
                $product = SaccoLoanProduct::withoutGlobalScopes()->where('company_id', $loan->company_id)->find($loan->product_id)
                    ?? throw BusinessRuleException::make('product_not_found', 'That loan product is not in this SACCO.');
                \App\Services\Sacco\Loans\LoanProducts::assertFits($product, (int) $loan->applied_minor, (int) $loan->term);
                $settings = SaccoSetting::for((int) $loan->company_id) ?? throw BusinessRuleException::make('not_set_up', 'Set up the SACCO first.');
                $loan->terms = $product->terms((int) $loan->term, (string) $settings->allocation_order)->toArray();
                $loan->currency ??= $settings->currency;
                $loan->status ??= 'draft';
                $loan->source = $loan->device_id !== null ? 'phone' : ($loan->source ?? 'staff');
            }
        });
        static::updating(function (self $loan) {
            if (! in_array($loan->getOriginal('status'), ['draft', 'submitted'], true) && $loan->isDirty(self::APPLICATION_FIELDS)) {
                throw BusinessRuleException::make('loan_locked', 'This application is past appraisal: its amount, term and product no longer change.');
            }
            foreach (self::LEDGER_CACHES as $column) {
                if ($loan->isDirty($column)) {
                    throw BusinessRuleException::make('ledger_cache', 'A loan\'s balances move only by posting to the books.');
                }
            }
        });
        static::deleting(function (self $loan) {
            if ($loan->status !== 'draft') {
                throw BusinessRuleException::make('loan_history', 'A loan is never deleted: it is cancelled, rejected, closed or written off.');
            }
        });
    }

    public function loanTerms(): LoanTerms
    {
        return LoanTerms::fromArray((array) $this->terms);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isNpl(): bool
    {
        return in_array($this->classification, self::NPL, true);
    }

    /** Everything owed now, ledger and memorandum. */
    public function owedMinor(): int
    {
        return (int) $this->principal_outstanding_minor + (int) $this->interest_due_minor + (int) $this->penalty_due_minor + (int) $this->fees_due_minor
            + (int) $this->interest_in_suspense_minor + (int) $this->penalty_in_suspense_minor;
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SaccoLoanProduct::class, 'product_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaccoLoanScheduleLine::class, 'loan_id');
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(SaccoLoanGuarantor::class, 'loan_id');
    }

    public function collateral(): HasMany
    {
        return $this->hasMany(SaccoLoanCollateral::class, 'loan_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(SaccoLoanVote::class, 'loan_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SaccoLoanDocument::class, 'loan_id');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(SaccoLoanRepayment::class, 'loan_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SaccoLoanChange::class, 'loan_id');
    }
}
