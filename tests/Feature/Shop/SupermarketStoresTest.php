<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\CompanyMember;
use App\Models\FinancialPeriod;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\Reports\ReportService;
use App\Services\Shop\LocationStock;
use App\Services\Shop\PriceBookService;
use App\Services\Shop\PriceLevelService;
use App\Services\Shop\PromotionService;
use App\Services\Shop\SaleService;
use App\Services\Shop\StockRequestService;
use App\Services\Shop\StorePriceService;
use App\Services\Shop\TransferService;
use App\Services\Team\Permissions;
use App\Services\Team\TeamService;
use App\Support\StoreFeatures;
use App\Support\StoreScope;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminTestCase;

/** Supermarket phase 4, several stores: local prices and availability (G1), warehouse to stores (G2), store-level figures (G3). */
class SupermarketStoresTest extends AdminTestCase
{
    private array $t;

    private int $cid;

    private int $main;

    private int $branch;

    protected function setUp(): void
    {
        parent::setUp();
        LocationStock::flush();
        $this->t = $this->makeTenant('company');
        $this->cid = (int) $this->t['company']->id;
        $this->t['company']->forceFill(['timezone' => 'Africa/Kampala'])->save();
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $this->cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $this->main = LocationStock::defaultLocation($this->cid);
        $this->branch = (int) DB::table('locations')->insertGetId(['company_id' => $this->cid, 'name' => 'Ntinda', 'is_default' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        PromotionService::forget($this->cid);
    }

    private function product(string $name, float $price, float $qty = 100): StockItem
    {
        $cat = StockCategory::create(['company_id' => $this->cid, 'name' => 'Cat '.uniqid()]);
        $sub = StockSubCategory::create(['company_id' => $this->cid, 'stock_category_id' => $cat->id, 'name' => 'Sub '.uniqid(), 'measurement_unit' => 'pcs']);

        return StockItem::create(['company_id' => $this->cid, 'created_by_id' => $this->t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => $name, 'sku' => 'S-'.uniqid(), 'buying_price' => 500, 'selling_price' => $price, 'original_quantity' => $qty])->fresh();
    }

    private function features(array $features): void
    {
        StoreFeatures::update($this->t['company'], ['features' => $features]);
        $this->t['company'] = $this->t['company']->fresh();
    }

    private function sell(array $items, array $extra = [], ?int $userId = null): SaleRecord
    {
        return (new SaleService())->checkout($this->cid, $userId ?? $this->t['user']->id, $extra + ['items' => $items, 'amount_paid' => 0])['sale'];
    }

    private function member(string $role): User
    {
        $u = new User();
        $u->forceFill(['name' => ucfirst($role).' '.uniqid(), 'email' => $role.uniqid('', true).'@example.test', 'password' => bcrypt('secret123'), 'status' => 'Active', 'company_id' => $this->cid])->save();
        CompanyMember::create(['company_id' => $this->cid, 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'joined_at' => now()]);
        Permissions::flush();

        return $u->fresh();
    }

    private function paidSale(StockItem $p, float $qty, ?int $location): SaleRecord
    {
        $total = $qty * (float) $p->selling_price;

        return (new SaleService())->checkout($this->cid, $this->t['user']->id, ['items' => [['stock_item_id' => $p->id, 'quantity' => $qty]],
            'payments' => [['method' => 'cash', 'amount' => $total]], 'payments_explicit' => true, 'location_id' => $location])['sale'];
    }

    // ── G1 ───────────────────────────────────────────────────

    public function test_with_store_prices_off_a_store_row_changes_nothing(): void
    {
        $milk = $this->product('Milk', 1000);
        DB::table('location_prices')->insert(['company_id' => $this->cid, 'location_id' => $this->branch, 'stock_item_id' => $milk->id, 'price' => 1200, 'is_available' => false]);
        $sale = $this->sell([['stock_item_id' => $milk->id, 'quantity' => 2]], ['location_id' => $this->branch]);
        $this->assertEquals(2000, (float) $sale->total_amount);
        $this->assertSame([], StorePriceService::unavailable($this->cid, $this->branch, [$milk->id]));
        $this->assertSame([], PromotionService::quote($this->cid, ['a' => ['stock_item_id' => $milk->id, 'quantity' => 1]], ['location_id' => $this->branch])['lines']);
        $this->expectException(BusinessRuleException::class);
        (new StorePriceService())->save($this->cid, $milk->id, [['location_id' => $this->branch, 'price' => 900]]);
    }

    public function test_a_store_sells_at_its_own_price_and_not_what_it_does_not_stock(): void
    {
        $this->features(['store_prices' => true]);
        $milk = $this->product('Milk', 1000);
        $bread = $this->product('Bread', 3000);
        $svc = new StorePriceService();
        $svc->save($this->cid, $milk->id, [['location_id' => $this->branch, 'price' => 1200], ['location_id' => $this->main, 'price' => '']]);
        $svc->save($this->cid, $bread->id, [['location_id' => $this->branch, 'price' => null, 'is_available' => false]]);
        $rows = collect($svc->forProduct($this->cid, $milk->id))->keyBy('location_id');
        $this->assertEquals(1200, $rows[$this->branch]['price']);
        $this->assertNull($rows[$this->main]['price']);
        $this->assertSame(1, DB::table('location_prices')->where('stock_item_id', $milk->id)->count(), 'a row that says nothing is not kept');

        // The till's quote and checkout agree: the store's price at the branch, the usual one at the main shop.
        $q = PromotionService::quote($this->cid, ['a' => ['stock_item_id' => $milk->id, 'quantity' => 3]], ['location_id' => $this->branch]);
        $this->assertEquals(1200, $q['lines']['a']['unit_price']);
        $atBranch = $this->sell([['stock_item_id' => $milk->id, 'quantity' => 3]], ['location_id' => $this->branch]);
        $this->assertEquals(3600, (float) $atBranch->total_amount);
        $this->assertEquals(1200, (float) $atBranch->saleRecordItems->first()->unit_price);
        $this->assertEquals(97, LocationStock::level($this->main, $milk->id) + LocationStock::level($this->branch, $milk->id));
        $this->assertEquals(2000, (float) $this->sell([['stock_item_id' => $milk->id, 'quantity' => 2]])->total_amount, 'no location = the main shop');
        // An explicit price (a re-price) still wins.
        $this->assertEquals(1100, (float) $this->sell([['stock_item_id' => $milk->id, 'quantity' => 1, 'unit_price' => 1100]], ['location_id' => $this->branch])->total_amount);

        // Bread is not sold at Ntinda: refused, by name; fine at the main shop.
        try {
            $this->sell([['stock_item_id' => $bread->id, 'quantity' => 1]], ['location_id' => $this->branch]);
            $this->fail('An unavailable product must be refused.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('not_sold_here', $e->errorCode());
            $this->assertStringContainsString('Bread is not sold at Ntinda', $e->getMessage());
        }
        $this->assertEquals(3000, (float) $this->sell([['stock_item_id' => $bread->id, 'quantity' => 1]], ['location_id' => $this->main])->total_amount);
        // A sale synced from an offline till was made already: never refused, priced as sent.
        $synced = $this->sell([['stock_item_id' => $bread->id, 'quantity' => 1, 'unit_price' => 3000]], ['location_id' => $this->branch, 'from_sync' => true]);
        $this->assertEquals(3000, (float) $synced->total_amount);

        // Levels come after the store price; promotions work on it.
        $this->features(['store_prices' => true, 'price_levels' => true, 'promotions' => true]);
        DB::table('product_prices')->insert(['company_id' => $this->cid, 'stock_item_id' => $milk->id, 'level' => 'retail', 'price' => 1100, 'min_qty' => 10]);
        $this->assertEquals(1200, PriceLevelService::price($this->cid, $milk->id, null, 1, null, $this->branch));
        $this->assertEquals(1100, PriceLevelService::price($this->cid, $milk->id, null, 10, null, $this->branch));
        (new PromotionService())->save($this->cid, $this->t['user']->id, ['name' => '10% off milk', 'type' => 'percent_off', 'percent' => 10, 'targets' => [['type' => 'product', 'id' => $milk->id]]]);
        $q = PromotionService::quote($this->cid, ['a' => ['stock_item_id' => $milk->id, 'quantity' => 2]], ['location_id' => $this->branch]);
        $this->assertEquals(1200, $q['lines']['a']['unit_price']);
        $this->assertEquals(240, $q['lines']['a']['discount']);
        $this->assertEquals(2160, (float) $this->sell([['stock_item_id' => $milk->id, 'quantity' => 2]], ['location_id' => $this->branch])->total_amount);
    }

    public function test_hq_pushes_a_price_now_or_through_the_price_book(): void
    {
        $this->features(['store_prices' => true, 'price_book' => false]);
        $milk = $this->product('Milk', 1000);
        $svc = new StorePriceService();
        // Without the price book a date is ignored: the price starts now, at every store.
        $r = $svc->push($this->cid, $this->t['user']->id, $milk->id, 1300, null, now()->addDay()->toIso8601String());
        $this->assertSame(['applied' => 2, 'scheduled' => 0], $r);
        $this->assertEquals(1300, DB::table('location_prices')->where('location_id', $this->branch)->where('stock_item_id', $milk->id)->value('price'));
        $this->assertEquals(1000, (float) $milk->fresh()->selling_price, 'the product itself keeps its price');

        // With the price book: scheduled for the chosen store, applied when due, kept in history for that store only.
        $this->features(['store_prices' => true, 'price_book' => true]);
        $r = $svc->push($this->cid, $this->t['user']->id, $milk->id, 1400, [$this->branch], now()->addHour()->toIso8601String(), 'New supplier');
        $this->assertSame(['applied' => 0, 'scheduled' => 1], $r);
        $this->assertCount(1, collect($svc->forProduct($this->cid, $milk->id))->firstWhere('location_id', $this->branch)['scheduled']);
        $this->assertSame([], (new PriceBookService())->scheduled($this->cid, $milk->id), 'a store price is not the product\'s price');
        $this->assertSame(1, (new PriceBookService())->applyDue($this->cid, now()->addHours(2)));
        $this->assertEquals(1400, DB::table('location_prices')->where('location_id', $this->branch)->where('stock_item_id', $milk->id)->value('price'));
        $this->assertEquals(1300, DB::table('location_prices')->where('location_id', $this->main)->where('stock_item_id', $milk->id)->value('price'));
        $this->assertEquals(1300, (float) DB::table('price_changes')->where('location_id', $this->branch)->whereNotNull('applied_at')->value('old'));
        $svc->push($this->cid, $this->t['user']->id, $milk->id, 1250, [$this->main]);
        $this->assertSame(1, DB::table('price_changes')->where('location_id', $this->main)->whereNotNull('applied_at')->count(), 'a price pushed now is in the book');

        $this->expectException(BusinessRuleException::class);
        $svc->push($this->cid, $this->t['user']->id, $milk->id, 1250, [999999]);
    }

    // ── G2 ───────────────────────────────────────────────────

    public function test_a_store_requests_stock_the_warehouse_sends_it_and_the_store_receives_what_arrived(): void
    {
        $soda = $this->product('Soda', 1000, 40);
        $svc = new StockRequestService();
        $id = $svc->request($this->cid, $this->t['user']->id, $this->main, $this->branch, [['stock_item_id' => $soda->id, 'quantity' => 10], ['stock_item_id' => $soda->id, 'quantity' => 2]], 'Weekend');
        $req = DB::table('stock_requests')->find($id);
        $this->assertStringStartsWith('REQ-', $req->number);
        $this->assertSame('requested', $req->status);
        $this->assertEquals(12, DB::table('stock_request_items')->where('stock_request_id', $id)->value('quantity'), 'the same product twice is one line');

        $svc->approve($this->cid, $this->t['user']->id, $id, [$soda->id => 10]);
        $transfer = $svc->send($this->cid, $this->t['user']->id, $id);
        $this->assertSame('sent', DB::table('stock_requests')->where('id', $id)->value('status'));
        $this->assertSame('in_transit', DB::table('stock_transfers')->where('id', $transfer)->value('status'));
        // In transit: off the warehouse shelf, not yet on the store's, visible on both sides.
        $this->assertEquals(30, LocationStock::level($this->main, $soda->id));
        $this->assertEquals(0, LocationStock::level($this->branch, $soda->id));
        $this->assertEquals([$soda->id => 10.0], (new TransferService())->inTransit($this->cid, $this->branch));
        $this->assertEquals([$soda->id => 10.0], (new TransferService())->inTransit($this->cid, null, $this->main));
        $this->assertEquals(30, (float) $soda->fresh()->current_quantity);

        try {
            $svc->receive($this->cid, $this->t['user']->id, $id, [$soda->id => 11]);
            $this->fail('More than was sent cannot arrive.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('between 0 and 10', $e->getMessage());
        }
        $svc->receive($this->cid, $this->t['user']->id, $id, [$soda->id => 9]);
        $this->assertEquals(9, LocationStock::level($this->branch, $soda->id));
        $this->assertEquals(39, (float) $soda->fresh()->current_quantity, 'one went missing on the way');
        $this->assertEquals(39, (float) DB::table('stock_levels')->where('stock_item_id', $soda->id)->sum('quantity'));
        $this->assertSame('received', DB::table('stock_requests')->where('id', $id)->value('status'));
        $this->assertEquals(9, DB::table('stock_request_items')->where('stock_request_id', $id)->value('received_quantity'));
        $this->assertSame([], (new TransferService())->inTransit($this->cid));
        $this->expectException(BusinessRuleException::class);
        $svc->cancel($this->cid, $this->t['user']->id, $id);
    }

    public function test_store_members_act_only_for_their_own_store(): void
    {
        $soda = $this->product('Soda', 1000, 40);
        $svc = new StockRequestService();
        $this->assertThrows(fn () => $svc->request($this->cid, 1, $this->main, $this->branch, [['stock_item_id' => $soda->id, 'quantity' => 1]], null, $this->main), BusinessRuleException::class);
        $id = $svc->request($this->cid, 1, $this->main, $this->branch, [['stock_item_id' => $soda->id, 'quantity' => 5]], null, $this->branch);
        $this->assertThrows(fn () => $svc->send($this->cid, 1, $id, null, $this->branch), BusinessRuleException::class, 'Only the store that sends');
        $svc->send($this->cid, 1, $id, null, $this->main);
        $this->assertThrows(fn () => $svc->receive($this->cid, 1, $id, null, $this->main), BusinessRuleException::class, 'Only the store that asked');
        $this->assertThrows(fn () => $svc->cancel($this->cid, 1, $id), BusinessRuleException::class, 'already on its way');
        $svc->receive($this->cid, 1, $id, null, $this->branch);
        $this->assertEquals(5, LocationStock::level($this->branch, $soda->id));
        $id2 = $svc->request($this->cid, 1, $this->main, $this->branch, [['stock_item_id' => $soda->id, 'quantity' => 5]]);
        $svc->cancel($this->cid, 1, $id2);
        $this->assertSame('cancelled', DB::table('stock_requests')->where('id', $id2)->value('status'));
    }

    // ── G3 ───────────────────────────────────────────────────

    public function test_a_store_manager_is_scoped_and_figures_split_by_store(): void
    {
        $soda = $this->product('Soda', 1000, 40);
        (new TransferService())->transfer($this->cid, $this->t['user']->id, $this->main, $this->branch, [['stock_item_id' => $soda->id, 'quantity' => 10]]);
        $this->paidSale($soda, 3, $this->main);
        $this->paidSale($soda, 2, $this->branch);
        $today = \App\Support\LocalDate::today($this->cid)->toDateString();

        $manager = $this->member('manager');
        (new TeamService())->setStore($this->t['company'], $manager, $this->branch);
        $this->assertNull(StoreScope::forUser($manager->fresh()), 'off: nobody is scoped');
        $this->features(['store_scoping' => true]);
        $this->assertSame($this->branch, StoreScope::forUser($manager->fresh()));
        $this->assertNull(StoreScope::forUser($this->t['user']->fresh()), 'the owner sees every store');
        $this->assertThrows(fn () => (new TeamService())->setStore($this->t['company'], $this->t['user'], $this->branch), BusinessRuleException::class);
        DB::table('locations')->where('id', $this->branch)->update(['is_active' => false]);
        $this->assertNull(StoreScope::forUser($manager->fresh()), 'one open store: nothing to hide');
        DB::table('locations')->where('id', $this->branch)->update(['is_active' => true]);

        $dash = new DashboardService();
        $this->assertEquals(5000, $dash->kpis($this->cid, $today, $today)['sales']);
        $this->assertEquals(2000, $dash->kpis($this->cid, $today, $today, $this->branch)['sales']);
        $this->assertEquals(3000, $dash->kpis($this->cid, $today, $today, $this->main)['sales']);
        $this->assertEquals(2000, array_sum(array_column($dash->collectedByMethod($this->cid, $today, $today, $this->branch), 'amount')));
        $this->assertCount(1, $dash->recentSales($this->cid, 8, $this->branch));
        $this->assertEquals(8000, $dash->stock($this->t['company'], $this->branch)['sale_value']);
        $this->assertEquals(35000, $dash->stock($this->t['company'])['sale_value']);

        $reports = new ReportService();
        $all = $reports->run($this->cid, 'sales_summary', $today, $today);
        $this->assertEquals($all, $reports->run($this->cid, 'sales_summary', $today, $today, ['location_id' => null]), 'no location: unchanged');
        $this->assertEquals(5000, $all['totals']['amount']);
        $this->assertEquals(2000, $reports->run($this->cid, 'sales_summary', $today, $today, ['location_id' => $this->branch])['totals']['amount']);
        $this->assertEquals(8, $reports->run($this->cid, 'stock_valuation', null, null, ['location_id' => $this->branch])['rows'][0]['quantity']);
        $this->assertEquals(35, $reports->run($this->cid, 'stock_valuation')['rows'][0]['quantity']);
        $this->assertEquals(2, $reports->run($this->cid, 'fast_movers', $today, $today, ['location_id' => $this->branch])['rows'][0]['quantity']);
        $this->assertArrayNotHasKey('location_id', $reports->run($this->cid, 'customer_aging', null, null, ['location_id' => $this->branch])['meta'] ?? [], 'a whole-business report ignores it');
    }
}
