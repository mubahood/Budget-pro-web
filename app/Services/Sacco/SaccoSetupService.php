<?php

namespace App\Services\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoSetting;
use App\Models\User;
use App\Services\Billing\Quotas;
use App\Services\Onboarding\OnboardingService;
use App\Services\Sacco\Ledger\ChartOfAccounts;
use App\Services\Sacco\Ledger\PeriodService;
use App\Support\LocalDate;
use App\Support\Rules\SaccoRules;
use App\Support\Sacco\Amount;
use Illuminate\Support\Facades\DB;

/**
 * A SACCO's first run (docs/akiba/04-architecture.md §4, P1.7): in one transaction it writes the settings (the
 * company's currency, the mode's defaults, what the owner chose), seeds the chart of accounts with its ledger head,
 * opens this month and the next two, and marks the onboarding `sacco` step done. It runs once; the mode can still
 * change until the first posting (SaccoSetting), the currency never.
 *
 * The person who sets it up is seated in the founder's office (chairperson, or treasurer: OfficerService). The
 * default savings products (P1.12) hook in through extend() as their tables arrive (AK-59).
 */
final class SaccoSetupService
{
    /** @var list<callable(Company, SaccoSetting, User): void> what other modules add to a new SACCO */
    private static array $extras = [];

    /** @param  callable(Company, SaccoSetting, User): void  $extra */
    public static function extend(string $key, callable $extra): void
    {
        self::$extras[$key] = $extra;
    }

    public static function isSetUp(int $companyId): bool
    {
        return SaccoSetting::withoutGlobalScopes()->where('company_id', $companyId)->exists();
    }

    /** The founder's office at setup (§4): chairperson by default, or treasurer. */
    public const FOUNDER_POSITIONS = ['chairperson', 'treasurer'];

    /** @param  array<string, mixed>  $data  SaccoRules::setup(), plus `founder_position` */
    public function setup(Company $company, array $data, User $by): SaccoSetting
    {
        \App\Services\Onboarding\PublicDemo::guard((int) $company->id, 'setting up the SACCO books');
        if (! in_array('sacco', $company->modules(), true)) {
            throw BusinessRuleException::make('module_off', 'This account does not keep SACCO books.', ['module' => 'sacco']);
        }
        $validator = validator($data, SaccoRules::setup());
        if ($validator->fails()) {
            throw BusinessRuleException::make('invalid_settings', (string) $validator->errors()->first(), ['errors' => $validator->errors()->toArray()]);
        }
        $data = $validator->validated();
        $founder = in_array($data['founder_position'] ?? null, self::FOUNDER_POSITIONS, true) ? $data['founder_position'] : 'chairperson';
        unset($data['founder_position']);
        $mode = (string) $data['mode'];
        if ($mode === 'office' && ! app(Quotas::class)->featureOn($company, 'sacco_office_mode')) {
            throw BusinessRuleException::make('feature_not_in_plan', 'Office mode (a SACCO with tellers and an office) is on the Starter plan and above. On Akiba Free, choose group mode.',
                ['feature' => 'sacco_office_mode']);
        }

        return DB::transaction(function () use ($company, $data, $mode, $by, $founder) {
            Company::withoutGlobalScopes()->whereKey($company->id)->lockForUpdate()->first();
            if (self::isSetUp((int) $company->id)) {
                throw BusinessRuleException::make('already_set_up', 'This SACCO is already set up. Change its settings instead.');
            }
            $currency = PostingCurrency::forCompany($company);
            $d = config("sacco.defaults.{$mode}");
            $minor = fn (string $major) => Amount::fromMajor($major, $currency)->minor;
            $settings = SaccoSetting::create(array_merge([
                'company_id' => $company->id, 'mode' => $mode, 'short_name' => mb_substr((string) ($data['short_name'] ?? '') ?: (string) $data['legal_name'], 0, 60),
                'currency' => $currency, 'share_par_minor' => $minor($d['share_par']), 'min_shares' => $d['min_shares'], 'entrance_fee_minor' => $minor($d['entrance_fee']),
                'withdrawal_approval_above_minor' => $minor($d['withdrawal_approval_above']), 'offline_receipt_max_minor' => $minor($d['offline_receipt_max']),
                'receipts_channel' => $d['receipts_channel'], 'max_holding_bp' => config('sacco.defaults.max_holding_bp'), 'dividend_cap_bp' => config('sacco.defaults.dividend_cap_bp'),
                'reserve_min_bp' => config('sacco.defaults.reserve_min_bp'), 'education_fund_bp' => config('sacco.defaults.education_fund_bp'),
                'dormancy_months' => config('sacco.defaults.dormancy_months'), 'set_up_by_id' => $by->id,
            ], array_diff_key($data, array_flip(['mode', 'short_name']))));
            (new ChartOfAccounts())->seed((int) $company->id, $mode);
            (new PeriodService())->ensureAhead((int) $company->id, LocalDate::today((int) $company->id), (int) config('sacco.defaults.periods_ahead', 3));
            (new OfficerService())->appoint((int) $company->id, ['position' => $founder, 'user_id' => (int) $by->id], (int) $by->id);
            foreach (self::$extras as $extra) {
                $extra($company, $settings, $by);
            }
            app(OnboardingService::class)->markStep($company->fresh(), 'sacco');
            SaccoAudit::record('sacco.setup', $settings, (int) $company->id, ['mode' => $mode, 'currency' => $currency], (int) $by->id);

            return $settings;
        });
    }
}
