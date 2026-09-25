<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\FinancialPeriod;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\Subscription;
use App\Services\FlutterwaveService;
use App\Services\Shop\SaleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\FakeFlutterwaveService;

/** Phase 5 on the web: send a receipt, request mobile money, remind a customer, settings. */
class EngageWebTest extends AdminTestCase
{
    public function test_owner_uses_receipts_momo_and_reminders_from_the_web(): void
    {
        $flw = new FakeFlutterwaveService();
        $this->app->instance(FlutterwaveService::class, $flw);
        $t = $this->makeTenant('company');
        $t['company']->forceFill(['onboarding_state' => ['step' => 'done', 'completed_at' => now()->toIso8601String()], 'country' => 'UG'])->saveQuietly();
        Subscription::create(['company_id' => $t['company']->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        Auth::guard('admin')->login($t['user']);
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $t['company']->id, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $t['company']->id, 'name' => 'Food']);
        $sub = StockSubCategory::create(['company_id' => $t['company']->id, 'stock_category_id' => $cat->id, 'name' => 'Dry', 'measurement_unit' => 'kg']);
        $p = StockItem::create(['company_id' => $t['company']->id, 'created_by_id' => $t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Rice', 'sku' => 'R-'.uniqid(), 'buying_price' => 4000, 'selling_price' => 5000, 'original_quantity' => 20]);
        $c = new Customer();
        $c->forceFill(['company_id' => $t['company']->id, 'name' => 'Nakato', 'phone' => '0752 300 400', 'credit_limit' => 50000])->save();
        $sale = (new SaleService())->checkout($t['company']->id, $t['user']->id, ['customer_id' => $c->id, 'payments' => [], 'payments_explicit' => true, 'items' => [['stock_item_id' => $p->id, 'quantity' => 2]]])['sale'];
        $u = $t['user'];

        $this->asAdmin($u)->get("/sale-records/{$sale->id}")->assertOk()->assertSee('Send receipt')->assertSee('Set up mobile money');
        $this->asAdmin($u)->post("/sale-records/{$sale->id}/send-receipt", ['phone' => '0752300400'])->assertRedirect();
        $this->assertTrue(DB::table('message_log')->where('purpose', 'receipt')->where('to', '+256752300400')->exists());

        $this->asAdmin($u)->get('/engagement')->assertOk()->assertSee('Receive mobile money')->assertSee('Debt reminders');
        $this->asAdmin($u)->post('/engagement/momo', ['phone' => '0772 111 222', 'network' => 'AIRTEL'])->assertRedirect(admin_url('engagement'));
        $this->asAdmin($u)->post('/engagement', ['debt_reminders_enabled' => 1, 'credit_terms_days' => 7])->assertRedirect();
        $this->assertTrue((bool) $t['company']->fresh()->debt_reminders_enabled);

        $this->asAdmin($u)->post("/sale-records/{$sale->id}/momo-request", ['phone' => '0752300400', 'network' => 'AIRTEL'])->assertRedirect();
        $this->assertSame(1, DB::table('momo_requests')->where('sale_record_id', $sale->id)->count());
        $this->assertSame('AIRTEL', $flw->charges[0]['payload']['network']);

        $this->asAdmin($u)->post("/customers/{$c->id}/remind")->assertRedirect();
        $this->assertTrue(DB::table('message_log')->where('purpose', 'debt_reminder')->where('to', '+256752300400')->exists());
    }
}
