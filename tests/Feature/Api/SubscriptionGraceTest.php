<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Subscription;

/** P0-3: API keeps serving during the grace window and returns 402 after it. */
class SubscriptionGraceTest extends ApiTestCase
{
    private function lapse(int $companyId, int $daysAgo): void
    {
        Subscription::where('company_id', $companyId)->delete();
        Subscription::create(['company_id' => $companyId, 'status' => 'expired', 'starts_at' => now()->subDays($daysAgo + 30), 'ends_at' => now()->subDays($daysAgo), 'provider' => 'manual']);
        Company::where('id', $companyId)->update(['license_expire' => now()->subDays($daysAgo)]);
    }

    public function test_grace_window_still_serves_with_a_state_header(): void
    {
        $t = $this->registerTenant();
        $this->lapse($t['company_id'], 2);

        $this->getJson('/api/v1/stock-categories', $this->auth($t['token']))
            ->assertOk()
            ->assertHeader('X-Subscription-State', 'grace');
    }

    public function test_after_grace_the_api_returns_402(): void
    {
        $t = $this->registerTenant();
        $this->lapse($t['company_id'], 30);

        $this->getJson('/api/v1/stock-categories', $this->auth($t['token']))
            ->assertStatus(402)
            ->assertJsonPath('data.reason', 'subscription_expired');
    }
}
