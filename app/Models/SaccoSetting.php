<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use App\Scopes\CompanyScope;
use App\Traits\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * A SACCO's own settings (docs/akiba/04-architecture.md §3.1): its names, licence, mode and thresholds. Created
 * once by SaccoSetupService; the mode never changes after the first journal (AK-05) and the currency never
 * changes at all. The regulated line prints only with a licence number (AK-14).
 */
class SaccoSetting extends Model
{
    use AuditLogger;

    public const MODES = ['office', 'group'];

    public const RECEIPT_CHANNELS = ['sms', 'whatsapp', 'app', 'none'];

    public const EMPLOYEE_LOAN_POLICIES = ['salary_advance_only', 'committee'];

    protected $guarded = ['id'];

    protected $casts = ['licence_expires_on' => 'date:Y-m-d', 'show_regulated_line' => 'boolean', 'single_officer_mode' => 'boolean',
        'share_par_minor' => 'integer', 'min_shares' => 'integer', 'max_holding_bp' => 'integer', 'entrance_fee_minor' => 'integer',
        'withdrawal_approval_above_minor' => 'integer', 'offline_receipt_max_minor' => 'integer', 'dividend_cap_bp' => 'integer',
        'reserve_min_bp' => 'integer', 'education_fund_bp' => 'integer', 'dormancy_months' => 'integer', 'fiscal_year_start_month' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
        static::saving(function (self $s) {
            if ($s->exists && $s->isDirty('mode') && self::hasPostings((int) $s->company_id)) {
                throw BusinessRuleException::make('mode_locked', 'The mode is fixed once the books have postings. Changing it needs a new chart: ask support.');
            }
            if ($s->exists && $s->isDirty('currency')) {
                throw BusinessRuleException::make('currency_locked', 'A SACCO\'s currency is fixed when its books are set up.');
            }
            if ($s->show_regulated_line && blank($s->licence_no)) {
                throw BusinessRuleException::make('licence_required', 'Only a licensed SACCO may print "Regulated by": add the licence number first.');
            }
            if ($s->single_officer_mode && $s->mode !== 'group') {
                throw BusinessRuleException::make('single_officer_not_allowed', 'One officer approving their own requests is allowed only in a savings group (group mode).');
            }
        });
        static::deleting(function () {
            throw BusinessRuleException::make('ledger_immutable', 'A SACCO\'s settings are never deleted.');
        });
    }

    public static function hasPostings(int $companyId): bool
    {
        return SaccoJournalEntry::withoutGlobalScopes()->where('company_id', $companyId)->exists();
    }

    public static function for(int $companyId): ?self
    {
        return self::withoutGlobalScopes()->where('company_id', $companyId)->first();
    }

    public function isGroup(): bool
    {
        return $this->mode === 'group';
    }

    /** The regulator named on receipts and returns: this SACCO's override, else the platform's (AK-14). */
    public function regulator(): array
    {
        return ['name' => $this->regulator_name ?: (string) config('sacco.regulator.name'), 'short' => $this->regulator_short ?: (string) config('sacco.regulator.short'),
            'portal' => $this->regulator_portal_url ?: (string) config('sacco.regulator.portal')];
    }

    /** "Regulated by …" only for a licensed SACCO that asked for it. */
    public function regulatedLine(): ?string
    {
        return $this->show_regulated_line && filled($this->licence_no) ? 'Regulated by '.$this->regulator()['name'].' · Licence '.$this->licence_no : null;
    }
}
