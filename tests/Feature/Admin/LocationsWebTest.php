<?php

namespace Tests\Feature\Admin;

use App\Models\FinancialPeriod;
use App\Models\Plan;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\Subscription;
use App\Services\Shop\LocationStock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** P4-4 on the web: locations (plan feature), phone → location, transfers, batches on receipt and on the product page. */
class LocationsWebTest extends AdminTestCase
{
    public function test_locations_transfers_and_batches_from_the_web(): void
    {
        LocationStock::flush();
        $t = $this->makeTenant('company');
        $t['company']->forceFill(['onboarding_state' => ['step' => 'done', 'completed_at' => now()->toIso8601String()]])->saveQuietly();
        $plan = Plan::create(['name' => 'Business', 'slug' => 'biz-'.uniqid(), 'price' => 49, 'price_ugx' => 185000, 'currency' => 'UGX', 'interval' => 'month', 'trial_days' => 0,
            'is_active' => true, 'is_public' => true, 'sort_order' => 3, 'features' => ['multi_location' => true], 'limits' => []]);
        Subscription::create(['company_id' => $t['company']->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        Auth::guard('admin')->login($t['user']);
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $t['company']->id, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $t['company']->id, 'name' => 'Medicine']);
        $sub = StockSubCategory::create(['company_id' => $t['company']->id, 'stock_category_id' => $cat->id, 'name' => 'Tabs', 'measurement_unit' => 'strip']);
        $p = StockItem::create(['company_id' => $t['company']->id, 'created_by_id' => $t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Panadol', 'sku' => 'P-'.uniqid(), 'buying_price' => 600, 'selling_price' => 1000, 'original_quantity' => 0, 'track_batches' => true]);
        $u = $t['user'];

        $this->asAdmin($u)->get('/locations')->assertOk()->assertSee('Main shop')->assertSee('Add location');
        $this->asAdmin($u)->post('/locations', ['name' => 'Kireka branch'])->assertRedirect();
        $branch = DB::table('locations')->where('company_id', $t['company']->id)->where('name', 'Kireka branch')->value('id');
        $this->assertNotNull($branch);

        $this->asAdmin($u)->get('/goods-receipts/create')->assertOk()->assertSee('Received at')->assertSee('Expires (optional)');
        $this->asAdmin($u)->post('/goods-receipts', ['location_id' => $branch, 'items' => [['stock_item_id' => $p->id, 'quantity' => 20, 'unit_cost' => 600, 'batch_number' => 'PX1', 'expiry_date' => now()->addDays(15)->toDateString()]]])->assertRedirect();
        $this->assertEquals(20, LocationStock::level($branch, $p->id));

        $this->asAdmin($u)->get('/stock-transfers')->assertOk()->assertSee('No transfers yet');
        $this->asAdmin($u)->post('/stock-transfers', ['from_location_id' => $branch, 'to_location_id' => LocationStock::defaultLocation((int) $t['company']->id), 'items' => [['stock_item_id' => $p->id, 'quantity' => 8]]])->assertRedirect();
        $this->asAdmin($u)->get('/stock-transfers')->assertSee('TRF-');
        $page = $this->asAdmin($u)->get("/stock-items/{$p->id}")->assertOk();
        $page->assertSee('Where the stock is')->assertSee('Kireka branch')->assertSee('PX1');

        $this->asAdmin($u)->get('/reports?report=expiry&days=30')->assertOk()->assertSee('PX1');
    }
}
