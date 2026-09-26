<?php

namespace Tests\Feature\Admin;

use App\Models\StockItem;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

/** Plan C2/C4/C12 (P3-2, P3-8): web /setup, dashboard checklist, module enablement on the web. */
class SetupWizardTest extends AdminTestCase
{
    public function test_new_owner_is_guided_through_setup_then_sees_the_checklist(): void
    {
        $t = $this->makeTenant('company');
        Subscription::create(['company_id' => $t['company']->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        $u = $t['user'];

        $this->asAdmin($u)->get('/')->assertRedirect(admin_url('setup'));
        $this->asAdmin($u)->get('/setup')->assertOk()->assertSee('Tell us about your business');
        $this->asAdmin($u)->post('/setup/business', ['name' => 'Kato Hardware', 'business_type' => 'hardware', 'country' => 'UG'])->assertRedirect(admin_url('setup?step=products'));
        $page = $this->asAdmin($u)->get('/setup?step=products')->assertOk()->assertSee('Cement 50kg');
        $cement = DB::table('product_templates')->where('business_type', 'hardware')->where('name', 'Cement 50kg')->value('id');
        $resp = $this->asAdmin($u)->post('/setup/products', ['items' => [$cement => ['pick' => 1, 'selling_price' => 36500, 'buying_price' => 33500, 'opening_stock' => 40]]]);
        $resp->assertRedirect(admin_url('setup?step=money'));
        $this->assertEquals(40, (float) StockItem::withoutGlobalScopes()->where('company_id', $t['company']->id)->where('name', 'Cement 50kg')->value('current_quantity'));
        $this->asAdmin($u)->post('/setup/money', ['payment_methods' => ['cash', 'mobile_money'], 'momo_providers' => ['mtn_momo'], 'receipt_channels' => ['whatsapp']])->assertRedirect(admin_url('setup?step=team'));
        $this->asAdmin($u)->post('/setup/skip/team')->assertRedirect(admin_url('setup?step=done'));
        $this->asAdmin($u)->get('/setup?step=done')->assertOk()->assertSee("You're set", false);

        // Setup finished: the dashboard opens, with the getting-started card. Only "add products" is
        // really done: ticking MoMo/WhatsApp no longer counts until a MoMo number is registered and a
        // receipt is actually sent (honest checklist, POWER_PLAN §3.1).
        $this->asAdmin($u)->get('/')->assertOk()->assertSee('Getting started — 1 of 5 done', false);
        $this->asAdmin($u)->post('/setup/dismiss')->assertRedirect();
        $this->asAdmin($u)->get('/')->assertOk()->assertDontSee('Getting started —', false);
        $this->assertSame($page->getStatusCode(), 200);
    }

    public function test_switched_off_modules_leave_the_menu_and_their_pages(): void
    {
        $t = $this->makeTenant('company');
        Subscription::create(['company_id' => $t['company']->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        $t['company']->forceFill(['enabled_modules' => ['shop', 'finance'], 'onboarding_state' => ['step' => 'done', 'completed_at' => now()->toIso8601String()]])->saveQuietly();

        $this->asAdmin($t['user'])->get('/budget-programs')->assertRedirect(admin_url('/'));
        $html = $this->asAdmin($t['user'])->get('/stock-items')->assertOk()->getContent();
        $this->assertStringNotContainsString('/budget-programs"', $html);
        $this->assertStringContainsString('/stock-items"', $html);

        $t['company']->forceFill(['enabled_modules' => ['shop', 'finance', 'budget']])->saveQuietly();
        $this->asAdmin($t['user'])->get('/budget-programs')->assertOk();
    }
}
