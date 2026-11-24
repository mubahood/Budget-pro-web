<?php

namespace Tests\Feature\Admin;

use App\Services\Platform\PlatformOverview;

/** Platform overview: every app and account on one page, platform admins only; their home lands there. */
class PlatformOverviewTest extends AdminTestCase
{
    public function test_platform_admins_see_every_app_and_account_and_tenants_do_not(): void
    {
        $admin = $this->makeTenant('admin');
        $shop = $this->makeTenant('company');
        $shop['company']->forceFill(['name' => 'Kisenyi Hardware', 'enabled_modules' => ['shop', 'finance'], 'onboarding_state' => ['step' => 'done', 'completed_at' => now()->toIso8601String()]])->saveQuietly();

        $this->asAdmin($admin['user'])->get('/')->assertRedirect(admin_url('platform-overview'));
        $this->asAdmin($admin['user'])->get('/platform-overview')->assertOk()
            ->assertSeeInOrder(['Apps', 'Budget Pro', 'Shelfie', 'Kuku Tally', 'Pledge &amp; Budget', 'Akiba Ledger', 'Estate Ledger'], false)
            ->assertSee('Rentals')->assertSee('SACCOs')->assertSee('Kisenyi Hardware')->assertSee('Sign in as');
        $this->asAdmin($admin['user'])->get('/platform-overview?app=shelfie')->assertOk()->assertSee('Shelfie accounts')->assertSee('Kisenyi Hardware');

        $this->asAdmin($shop['user'])->get('/platform-overview')->assertForbidden();
    }

    public function test_an_account_s_app_follows_its_modules(): void
    {
        $this->assertSame('akiba', PlatformOverview::appOf(['sacco']));
        $this->assertSame('estate-ledger', PlatformOverview::appOf('["rentals"]'));
        $this->assertSame('shelfie', PlatformOverview::appOf(['shop', 'finance']));
        $this->assertSame('kuku-tally', PlatformOverview::appOf(['poultry', 'finance']));
        $this->assertSame('pledge-and-budget', PlatformOverview::appOf(['budget', 'finance']));
        $this->assertSame('budget-pro', PlatformOverview::appOf(['shop', 'finance', 'budget', 'poultry']));
        $this->assertSame('budget-pro', PlatformOverview::appOf(null));
    }
}
