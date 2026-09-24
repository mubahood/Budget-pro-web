<?php

namespace Tests\Feature\Admin;

use App\Models\Subscription;

/**
 * P0-3: the web admin enforces subscription state like the API, with the
 * 7-day read-only grace window (DECISIONS.md H5).
 */
class WebAccessTest extends AdminTestCase
{
    private function lapse(array $tenant, int $daysAgo): void
    {
        Subscription::create([
            'company_id' => $tenant['company']->id,
            'status' => 'expired',
            'starts_at' => now()->subDays($daysAgo + 30),
            'ends_at' => now()->subDays($daysAgo),
            'provider' => 'manual',
        ]);
        $tenant['company']->license_expire = now()->subDays($daysAgo);
        $tenant['company']->saveQuietly();
    }

    public function test_active_tenant_is_unrestricted(): void
    {
        $t = $this->makeTenant('company');
        Subscription::create(['company_id' => $t['company']->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);

        $this->asAdmin($t['user'])->get('/stock-items')->assertOk();
        $this->assertSame('active', $t['company']->fresh()->accessState());
    }

    public function test_grace_period_is_read_only(): void
    {
        $t = $this->makeTenant('company');
        $this->lapse($t, 2);

        $this->assertSame('grace', $t['company']->fresh()->accessState());
        $this->asAdmin($t['user'])->get('/stock-items')->assertOk();
        $this->asAdmin($t['user'])->from('/stock-categories/create')->post('/stock-categories', ['name' => 'Blocked'])->assertRedirect('/stock-categories/create');
        $this->asAdmin($t['user'])->postJson('/stock-categories', ['name' => 'Blocked'])->assertStatus(402);
    }

    public function test_expired_tenant_is_sent_to_the_expired_page(): void
    {
        $t = $this->makeTenant('company');
        $this->lapse($t, 30);

        $this->assertSame('expired', $t['company']->fresh()->accessState());
        $this->asAdmin($t['user'])->get('/stock-items')->assertRedirect('/subscription-expired');
        $this->asAdmin($t['user'])->get('/subscription-expired')->assertOk()->assertSee('expired');
        $this->asAdmin($t['user'])->get('/auth/setting')->assertOk();
    }

    public function test_platform_admin_is_never_restricted(): void
    {
        $t = $this->makeTenant('admin');
        $this->lapse($t, 30);

        $this->asAdmin($t['user'])->get('/subscriptions')->assertOk();
    }

    public function test_legacy_tenant_without_subscription_uses_license_expire(): void
    {
        $t = $this->makeTenant('company');
        $t['company']->license_expire = now()->subDays(60);
        $t['company']->saveQuietly();

        $this->assertSame('expired', $t['company']->fresh()->accessState());
        $this->asAdmin($t['user'])->get('/stock-items')->assertRedirect('/subscription-expired');
    }
}
