<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoLedgerAccount;
use App\Models\SaccoPeriod;
use App\Models\SaccoSetting;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;
use App\Services\Sacco\Ledger\PostingService;
use App\Services\Sacco\SaccoSettingsService;
use App\Services\Sacco\SaccoSetupService;
use App\Support\Rules\CompanyRules;
use Database\Seeders\AkibaPlanSeeder;
use Tests\Concerns\MakesSaccoLedger;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P1.7: a SACCO's first run. Setup writes the settings with the mode's defaults, seeds the chart and
 * opens three months, once; the mode is fixed by the first posting and the currency by setup; "Regulated by"
 * needs a licence; office mode needs a plan that has it; only SACCO accounts see the setup step.
 */
class SaccoSetupTest extends ApiTestCase
{
    use MakesSaccoLedger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AkibaPlanSeeder::class);
    }

    /** @return array{0: Company, 1: User} */
    private function sacco(): array
    {
        $t = $this->registerTenant(['modules' => ['sacco']]);

        return [Company::find($t['company_id']), User::withoutGlobalScopes()->find($t['user_id'])];
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return $e->errorCode();
        }

        return 'none';
    }

    public function test_setup_builds_the_books_once(): void
    {
        [$company, $owner] = $this->sacco();
        $this->assertContains('sacco', app(OnboardingService::class)->stepsFor($company));
        $this->assertSame(['account', 'business', 'sacco', 'team'], app(OnboardingService::class)->stepsFor($company));

        $settings = app(SaccoSetupService::class)->setup($company, ['mode' => 'office', 'legal_name' => 'Kisoro Teachers\' SACCO Ltd', 'registration_no' => 'CS 1234'], $owner);
        $this->assertSame('office', $settings->mode);
        $this->assertSame('Kisoro Teachers\' SACCO Ltd', $settings->legal_name);
        $this->assertSame('Kisoro Teachers\' SACCO Ltd', $settings->short_name, 'the short name defaults to the legal one');
        $this->assertSame('UGX', $settings->currency);
        $this->assertSame(10000, $settings->share_par_minor);
        $this->assertSame(1000000, $settings->withdrawal_approval_above_minor);
        $this->assertSame(3333, $settings->max_holding_bp);
        $this->assertSame(count(\App\Services\Sacco\Ledger\ChartOfAccounts::rows('office')), SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(3, SaccoPeriod::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertContains('sacco', app(OnboardingService::class)->state($company->fresh())['completed_steps']);
        $this->assertSame('team', app(OnboardingService::class)->state($company->fresh())['step']);
        $this->assertSame('already_set_up', $this->code(fn () => app(SaccoSetupService::class)->setup($company, ['mode' => 'group', 'legal_name' => 'Again'], $owner)));
        $this->assertTrue(CompanyRules::currencyLocked($company->fresh()), 'the currency is fixed with the books');
    }

    public function test_the_mode_is_locked_by_the_first_posting_and_the_regulated_line_needs_a_licence(): void
    {
        [$company, $owner] = $this->sacco();
        app(SaccoSetupService::class)->setup($company, ['mode' => 'office', 'legal_name' => 'Bwindi SACCO'], $owner);
        $service = app(SaccoSettingsService::class);
        $this->assertSame('group', $service->update((int) $company->id, ['mode' => 'group'], $owner->id)->mode, 'free to change before any posting');
        $service->update((int) $company->id, ['mode' => 'office'], $owner->id);
        (new PostingService())->post($this->simple((int) $company->id, 'txn:first', 20000));
        $this->assertSame('mode_locked', $this->code(fn () => $service->update((int) $company->id, ['mode' => 'group'], $owner->id)));

        $this->assertSame('licence_required', $this->code(fn () => $service->update((int) $company->id, ['show_regulated_line' => true], $owner->id)));
        $this->assertNull(SaccoSetting::for((int) $company->id)->regulatedLine());
        $s = $service->update((int) $company->id, ['licence_no' => 'UMRA/T4/0099', 'show_regulated_line' => true], $owner->id);
        $this->assertSame('Regulated by Uganda Microfinance Regulatory Authority · Licence UMRA/T4/0099', $s->regulatedLine());
        $this->assertSame('single_officer_not_allowed', $this->code(fn () => $service->update((int) $company->id, ['single_officer_mode' => true], $owner->id)));
        $this->assertSame('invalid_settings', $this->code(fn () => $service->update((int) $company->id, ['entrance_fee_minor' => 2.5], $owner->id)), 'money is whole minor units');
        $this->assertSame('currency_locked', $this->code(fn () => SaccoSetting::for((int) $company->id)->update(['currency' => 'KES'])));
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('audit_logs')->where('company_id', $company->id)->where('action', 'sacco.settings.changed')->exists());
    }

    public function test_group_mode_defaults_plan_gate_and_who_may_set_up(): void
    {
        [$company, $owner] = $this->sacco();
        // On Akiba Free a group may set up; office mode needs Starter or above.
        $free = \App\Models\Plan::where('slug', 'akiba-free')->first();
        \App\Models\Subscription::where('company_id', $company->id)->update(['plan_id' => $free->id]);
        $company = $company->fresh();
        $this->assertSame('feature_not_in_plan', $this->code(fn () => app(SaccoSetupService::class)->setup($company, ['mode' => 'office', 'legal_name' => 'Group'], $owner)));
        $group = app(SaccoSetupService::class)->setup($company, ['mode' => 'group', 'legal_name' => 'Twekembe Savings Group', 'single_officer_mode' => true], $owner);
        $this->assertSame(0, $group->withdrawal_approval_above_minor, 'every group withdrawal needs a second officer (AK-44)');
        $this->assertTrue($group->single_officer_mode);
        $this->assertNull(SaccoLedgerAccount::withoutGlobalScopes()->where('company_id', $company->id)->where('code', '1010')->first());

        $shop = Company::find($this->registerTenant(['business_type' => 'retail'])['company_id']);
        $this->assertSame('module_off', $this->code(fn () => app(SaccoSetupService::class)->setup($shop, ['mode' => 'group', 'legal_name' => 'X'], $owner)));
        $this->assertNotContains('sacco', app(OnboardingService::class)->stepsFor($shop), 'a shop never sees the SACCO step');
        $this->assertNotContains('sacco', config('onboarding.steps'), 'the family\'s step list (sent to the phones) is unchanged');
        $this->assertSame('invalid_step', $this->code(fn () => app(OnboardingService::class)->markStep($shop, 'sacco')));
    }
}
