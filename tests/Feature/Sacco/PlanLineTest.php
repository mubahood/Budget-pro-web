<?php

namespace Tests\Feature\Sacco;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use App\Services\Billing\Entitlements;
use App\Services\Billing\Lifecycle;
use App\Services\Billing\PlanLine;
use App\Services\Billing\Quotas;
use Database\Seeders\AkibaPlanSeeder;
use Database\Seeders\PlanSeeder;
use Tests\Feature\Api\ApiTestCase;

/**
 * Akiba Ledger P1.2 (docs/akiba/03-proposal.md §5.1, AK-15): Akiba's plans are their own line. A shop, farm or
 * event sees exactly the plans it saw before they existed; a SACCO sees only `akiba-*`; trials and the free
 * fallback go by line; the member and SMS allowances count for SACCOs only.
 */
class PlanLineTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_a_shops_plan_list_is_unchanged_byte_for_byte(): void
    {
        $shop = $this->registerTenant(['business_type' => 'retail']);
        $before = [
            'public' => $this->getJson('/api/v1/plans')->assertOk()->getContent(),
            'signed_in' => $this->getJson('/api/v1/plans', $this->auth($shop['token']))->assertOk()->getContent(),
            'next_plan' => app(Quotas::class)->planThatFits(Company::find($shop['company_id']), 'users', 50)?->slug,
        ];
        $this->seed(AkibaPlanSeeder::class);
        $this->seed(AkibaPlanSeeder::class); // idempotent

        $this->assertSame($before['public'], $this->getJson('/api/v1/plans')->assertOk()->getContent());
        $this->assertSame($before['signed_in'], $this->getJson('/api/v1/plans', $this->auth($shop['token']))->assertOk()->getContent());
        $this->assertSame($before['next_plan'], app(Quotas::class)->planThatFits(Company::find($shop['company_id']), 'users', 50)?->slug);
        $this->assertSame(6, Plan::where('slug', 'like', 'akiba-%')->count());
        $this->assertNotContains('akiba-free', collect($this->getJson('/api/v1/plans')->json('data'))->pluck('slug'));

        // The family's usage block (auth/me) gains nothing either.
        $usage = app(Quotas::class)->usage(Company::find($shop['company_id']));
        $this->assertSame(['users', 'products', 'sales', 'storage', 'devices', 'locations'], array_keys($usage));
    }

    public function test_a_sacco_sees_only_akiba_plans_and_starts_on_the_akiba_trial(): void
    {
        $this->seed(AkibaPlanSeeder::class);
        $t = $this->registerTenant(['modules' => ['sacco']]);
        $company = Company::find($t['company_id']);
        $this->assertSame(PlanLine::AKIBA, PlanLine::for($company));

        $sub = Subscription::where('company_id', $company->id)->first();
        $this->assertSame('akiba-trial', $sub->plan->slug);
        $this->assertSame('trialing', $sub->status);
        $this->assertSame(30, (int) round(now()->diffInHours($sub->trial_ends_at) / 24), 'the Akiba trial runs 30 days (AK-16)');

        $slugs = collect($this->getJson('/api/v1/plans', $this->auth($t['token']))->assertOk()->json('data'))->pluck('slug')->all();
        $this->assertSame(['akiba-free', 'akiba-starter', 'akiba-standard', 'akiba-compliance', 'akiba-enterprise'], $slugs);
        $this->assertSame($slugs, collect($this->getJson('/api/v1/plans?line=akiba')->json('data'))->pluck('slug')->all(), 'the Akiba site asks by line');

        // The next plan that fits is on the line, and a family plan cannot be bought.
        $this->assertSame('akiba-starter', app(Quotas::class)->planThatFits($company, 'members', 100)?->slug);
        $this->postJson('/api/v1/subscription/checkout', ['plan_id' => Plan::where('slug', 'business')->value('id')], $this->auth($t['token']))
            ->assertStatus(422)->assertJsonPath('errors.code', 'plan_line_mismatch');
        try {
            app(BillingService::class)->quote(Company::find($this->registerTenant()['company_id']), Plan::where('slug', 'akiba-standard')->firstOrFail());
            $this->fail('a shop may not buy an Akiba plan');
        } catch (BusinessRuleException $e) {
            $this->assertSame('plan_line_mismatch', $e->errorCode());
        }

        // Members and SMS are limits of the Akiba line (counted in MemberTest once members exist); the sacco_* flags
        // are off unless a plan sells them.
        $this->assertSame(['members' => PlanLine::AKIBA, 'sms' => PlanLine::AKIBA], Quotas::LINE_KEYS);
        $this->assertSame(300, app(Quotas::class)->limit($company, 'members'));
        $this->assertSame(100, app(Quotas::class)->limit($company, 'sms'));
        foreach (['sacco_office_mode', 'sacco_returns', 'sacco_momo', 'sacco_payouts', 'sacco_checkoff', 'sacco_member_app', 'sacco_sms_receipts'] as $flag) {
            $this->assertFalse(Entitlements::DEFAULT_FEATURES[$flag]);
        }
        $this->assertTrue(app(Quotas::class)->featureOn($company, 'sacco_momo'), 'the trial is Standard');
    }

    public function test_the_lapse_fallback_goes_per_line(): void
    {
        $this->seed(AkibaPlanSeeder::class);
        $sacco = $this->registerTenant(['modules' => ['sacco']]);
        $shop = $this->registerTenant(['business_type' => 'retail']);
        $this->travelTo(now()->addDays(31));
        app(Lifecycle::class)->run();

        $this->assertSame('akiba-free', Subscription::where('company_id', $sacco['company_id'])->first()->plan->slug);
        $this->assertSame('free', Subscription::where('company_id', $shop['company_id'])->first()->plan->slug);
        $this->assertSame('akiba-free', app(Lifecycle::class)->freePlan(Company::find($sacco['company_id']))->slug);
        $this->assertSame('free', app(Lifecycle::class)->freePlan()->slug);
        $company = Company::find($sacco['company_id']);
        $this->assertFalse(app(Quotas::class)->featureOn($company, 'sacco_office_mode'), 'Akiba Free is group mode only');
        $this->assertSame(30, app(Quotas::class)->limit($company, 'members'));
        $this->assertSame(0, app(Quotas::class)->limit($company, 'sms'));
    }
}
