<?php

namespace Tests\Feature\PingPin;

use App\Models\Company;
use App\Models\PingPinPlan;
use App\Models\PingPinSubscription;
use App\PingPin\Services\EntitlementService;
use Tests\Feature\Api\ApiTestCase;

class EntitlementServiceTest extends ApiTestCase
{
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = new EntitlementService();
    }

    private function planWith(array $features, array $limits): PingPinPlan
    {
        return PingPinPlan::create([
            'name' => 'Test Plan', 'slug' => 'test-'.uniqid('', true),
            'price' => 1, 'price_ugx' => 1000, 'features' => $features, 'limits' => $limits,
        ]);
    }

    private function activeSubscription(Company $company, PingPinPlan $plan): PingPinSubscription
    {
        return PingPinSubscription::create([
            'company_id' => $company->id, 'plan_id' => $plan->id,
            'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(),
        ]);
    }

    public function test_company_with_no_pingpin_subscription_is_denied_everything(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);

        $this->assertNull($this->entitlements->activePlan($company));
        $this->assertFalse($this->entitlements->allows($company, 'sms_fallback'));
        $this->assertTrue($this->entitlements->hasReachedLimit($company, 'max_devices', 0));
        $this->assertSame(0, $this->entitlements->remainingQuota($company, 'max_devices', 0));
    }

    public function test_active_plan_grants_its_declared_features(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $plan = $this->planWith(['sms_fallback' => true, 'intruder_photo' => false], ['max_devices' => 3]);
        $this->activeSubscription($company, $plan);

        $this->assertTrue($this->entitlements->allows($company, 'sms_fallback'));
        $this->assertFalse($this->entitlements->allows($company, 'intruder_photo'));
        $this->assertFalse($this->entitlements->allows($company, 'a_feature_never_declared_at_all'));
    }

    public function test_finite_limit_is_enforced(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $plan = $this->planWith([], ['max_devices' => 2]);
        $this->activeSubscription($company, $plan);

        $this->assertFalse($this->entitlements->hasReachedLimit($company, 'max_devices', 1));
        $this->assertTrue($this->entitlements->hasReachedLimit($company, 'max_devices', 2));
        $this->assertTrue($this->entitlements->hasReachedLimit($company, 'max_devices', 3));
        $this->assertSame(1, $this->entitlements->remainingQuota($company, 'max_devices', 1));
        $this->assertSame(0, $this->entitlements->remainingQuota($company, 'max_devices', 5));
    }

    public function test_null_limit_on_the_plan_means_genuinely_unlimited(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $plan = $this->planWith([], ['max_devices' => null]);
        $this->activeSubscription($company, $plan);

        $this->assertFalse($this->entitlements->hasReachedLimit($company, 'max_devices', 999999));
        $this->assertNull($this->entitlements->remainingQuota($company, 'max_devices', 999999));
    }

    public function test_expired_subscription_denies_access_even_though_a_row_exists(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $plan = $this->planWith(['sms_fallback' => true], ['max_devices' => 10]);
        PingPinSubscription::create([
            'company_id' => $company->id, 'plan_id' => $plan->id,
            'status' => 'active', 'starts_at' => now()->subMonths(2), 'ends_at' => now()->subMonth(),
        ]);

        $this->assertNull($this->entitlements->activePlan($company));
        $this->assertFalse($this->entitlements->allows($company, 'sms_fallback'));
        $this->assertTrue($this->entitlements->hasReachedLimit($company, 'max_devices', 0));
    }

    public function test_active_trial_grants_access(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $plan = $this->planWith(['sms_fallback' => false], ['max_devices' => 1]);
        PingPinSubscription::create([
            'company_id' => $company->id, 'plan_id' => $plan->id,
            'status' => 'trialing', 'trial_ends_at' => now()->addDays(10),
        ]);

        $this->assertNotNull($this->entitlements->activePlan($company));
        $this->assertFalse($this->entitlements->hasReachedLimit($company, 'max_devices', 0));
        $this->assertTrue($this->entitlements->hasReachedLimit($company, 'max_devices', 1));
    }

    public function test_lapsed_trial_denies_access(): void
    {
        $t = $this->registerTenant();
        $company = Company::find($t['company_id']);
        $plan = $this->planWith([], ['max_devices' => 1]);
        PingPinSubscription::create([
            'company_id' => $company->id, 'plan_id' => $plan->id,
            'status' => 'trialing', 'trial_ends_at' => now()->subDay(),
        ]);

        $this->assertNull($this->entitlements->activePlan($company));
        $this->assertTrue($this->entitlements->hasReachedLimit($company, 'max_devices', 0));
    }
}
