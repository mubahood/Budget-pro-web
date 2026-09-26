<?php

namespace Tests\Feature\Admin;

use App\Models\CompanyMember;
use App\Models\FinancialPeriod;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\Shop\ReturnService;
use App\Services\Shop\SaleService;
use App\Services\Shop\StockService;
use App\Services\Team\Permissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** The owner's dashboard: one date range drives every figure; staff see what their role allows. */
class DashboardTest extends AdminTestCase
{
    private array $t;

    private StockItem $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->makeTenant('company');
        $cid = $this->t['company']->id;
        $this->t['company']->forceFill(['onboarding_state' => ['step' => 'done', 'completed_at' => now()->toIso8601String()], 'timezone' => 'Africa/Kampala'])->saveQuietly();
        Subscription::create(['company_id' => $cid, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        Auth::guard('admin')->login($this->t['user']);
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $cid, 'name' => 'Elec']);
        $sub = StockSubCategory::create(['company_id' => $cid, 'stock_category_id' => $cat->id, 'name' => 'Bulbs', 'measurement_unit' => 'pcs']);
        $this->p = StockItem::create(['company_id' => $cid, 'created_by_id' => $this->t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Panda bulb', 'sku' => 'B-'.uniqid(), 'buying_price' => 600, 'selling_price' => 1000, 'original_quantity' => 50]);
    }

    public function test_figures_for_a_day_count_every_kind_of_sale_once(): void
    {
        $cid = $this->t['company']->id;
        $uid = $this->t['user']->id;
        $sales = new SaleService();
        // Old app: bare movement (3 × 1000, profit 1200). Web: 2 × 1000 cash + 1 × 1000 on credit. Voided: 5 × 1000.
        (new StockService())->record(['stock_item_id' => $this->p->id, 'type' => 'Sale', 'quantity' => 3, 'selling_price' => 1000, 'created_by_id' => $uid]);
        $cash = $sales->checkout($cid, $uid, ['payments' => [['method' => 'mobile_money', 'amount' => 2000]], 'payments_explicit' => true, 'items' => [['stock_item_id' => $this->p->id, 'quantity' => 2]]])['sale'];
        $sales->checkout($cid, $uid, ['customer_name' => 'Amina', 'customer_phone' => '0772000001', 'payments' => [], 'payments_explicit' => true, 'items' => [['stock_item_id' => $this->p->id, 'quantity' => 1]]]);
        $void = $sales->checkout($cid, $uid, ['payments' => [['method' => 'cash', 'amount' => 5000]], 'payments_explicit' => true, 'items' => [['stock_item_id' => $this->p->id, 'quantity' => 5]]])['sale'];
        $sales->void($void, 'mistake', $uid);
        // One of the two mobile-money bulbs comes back faulty.
        $line = DB::table('sale_record_items')->where('sale_record_id', $cash->id)->first();
        app(ReturnService::class)->create($cash, [['sale_item_id' => $line->id, 'quantity' => 1, 'restock' => false]], $uid, 'Faulty', 'mobile_money');
        // Expenses: rent counts; a deleted expense and a stock purchase do not.
        $catId = DB::table('financial_categories')->insertGetId(['company_id' => $cid, 'name' => 'Rent', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[500, null, 0], [9999, null, 1], [7000, 'goods_receipt', 0]] as [$amount, $source, $deleted]) {
            DB::table('financial_records')->insert(['company_id' => $cid, 'financial_category_id' => $catId, 'user_id' => $uid, 'created_by_id' => $uid, 'amount' => $amount, 'type' => 'Expense',
                'date' => now('Africa/Kampala')->toDateString(), 'source_type' => $source, 'is_deleted' => $deleted, 'created_at' => now(), 'updated_at' => now()]);
        }

        $dash = new DashboardService();
        $r = $dash->range($this->t['company'], 'today');
        $k = $dash->kpis($cid, $r['from'], $r['to']);
        $this->assertSame(3, $k['count'], 'old-app sale, cash sale, credit sale; the void is out');
        $this->assertEquals(3000 + 1000 + 1000, $k['sales'], 'the returned bulb is netted');
        $this->assertEquals(1200 + 400 + 400, $k['profit']);
        $this->assertEquals(500, $k['expenses']);
        $this->assertEquals(2000 - 500, $k['net']);
        $this->assertEquals(1000, $k['on_credit']);
        $this->assertSame(1, $k['returns_count']);
        $byMethod = array_column($dash->collectedByMethod($cid, $r['from'], $r['to']), 'amount', 'method');
        $this->assertEquals(3000, $byMethod['cash'] ?? 0, 'old-app sale is cash; the voided cash sale nets to zero');
        $this->assertEquals(1000, $byMethod['mobile_money'] ?? 0, '2000 received, 1000 refunded');
        $this->assertEquals(4000, $k['collected']);
        $this->assertSame('Panda bulb', $dash->topProducts($cid, $r['from'], $r['to'])[0]->name);
        $this->assertEquals(5, (float) $dash->topProducts($cid, $r['from'], $r['to'])[0]->quantity);

        $page = $this->asAdmin($this->t['user'])->get('/?range=today')->assertOk();
        $page->assertSee('New sale')->assertSee('Profit on sales')->assertSee('Customers owe you')->assertSee('Amina');
        foreach (array_keys(DashboardService::RANGES) as $key) {
            $this->asAdmin($this->t['user'])->get('/?range='.$key)->assertOk();
        }
        $this->asAdmin($this->t['user'])->get('/?range=custom&from=2026-01-01&to=2025-12-01')->assertOk();
        $this->asAdmin($this->t['user'])->get('/?range=custom&from=junk&to=')->assertOk();
    }

    public function test_ranges_are_local_days_and_compare_with_the_period_before(): void
    {
        $dash = new DashboardService();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-03-01 22:30:00', 'UTC')); // 01:30 on 2 March in Kampala
        $r = $dash->range($this->t['company'], 'today');
        $this->assertSame('2026-03-02', $r['from']);
        $this->assertSame('2026-03-01', $r['prev_to']);
        $m = $dash->range($this->t['company'], 'last_month');
        $this->assertSame(['2026-02-01', '2026-02-28'], [$m['from'], $m['to']]);
        $w = $dash->range($this->t['company'], '7d');
        $this->assertSame(['2026-02-24', '2026-03-02', 7], [$w['from'], $w['to'], $w['days']]);
        $this->assertSame(['2026-02-17', '2026-02-23'], [$w['prev_from'], $w['prev_to']]);
        $this->assertNull(DashboardService::change(10, 0));
        $this->assertEquals(50, DashboardService::change(150, 100));
    }

    public function test_a_cashier_sees_selling_tools_but_no_profit_or_money_totals(): void
    {
        $cashier = new User();
        $cashier->forceFill(['name' => 'Cashier', 'first_name' => 'C', 'last_name' => 'Ashier', 'email' => 'c_'.uniqid().'@example.com', 'password' => bcrypt('secret123'), 'status' => 'Active', 'company_id' => $this->t['company']->id])->save();
        CompanyMember::create(['company_id' => $this->t['company']->id, 'user_id' => $cashier->id, 'role' => 'cashier', 'status' => 'active', 'joined_at' => now()]);
        DB::table('admin_role_users')->insert(['role_id' => DB::table('admin_roles')->where('slug', 'company')->value('id'), 'user_id' => $cashier->id]);
        Permissions::flush();

        $page = $this->asAdmin($cashier->fresh())->get('/')->assertOk();
        $page->assertSee('New sale')->assertDontSee('Profit on sales')->assertDontSee('Record expense')->assertDontSee('You owe suppliers');
    }

    public function test_the_classic_admin_offers_the_new_interface_when_configured(): void
    {
        config(['saas.new_ui_url' => 'https://shop.example.test']);
        $this->asAdmin($this->t['user'])->get('/')->assertOk()->assertSee('Try the new Budget Pro')->assertSee('https://shop.example.test/login', false);
        config(['saas.new_ui_url' => '']);
        $this->asAdmin($this->t['user'])->get('/')->assertOk()->assertDontSee('Try the new Budget Pro');
    }
}
