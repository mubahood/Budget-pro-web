<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Shop\SupplierPriceService;
use App\Services\Team\Permissions;
use App\Support\StoreFeatures;
use App\Support\Sync\SyncSequence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Web parity for the phone app: landed costs on receipts, batches on the sync goods_receipt op, aisle counts and
 * recounts, a product's supplier prices, till PINs, the company logo and the dashboard date range.
 */
class PhoneParityAdditionsTest extends ApiTestCase
{
    private array $t;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->registerTenant();
        $this->h = $this->auth($this->t['token']);
    }

    private function features(array $features): void
    {
        StoreFeatures::update(Company::find($this->t['company_id']), ['features' => $features]);
    }

    private function product(float $qty = 20, float $cost = 600): array
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $this->h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $this->h)->json('data.id');

        return $this->postJson('/api/v1/stock-items', ['name' => 'Item '.uniqid(), 'stock_sub_category_id' => $sub, 'selling_price' => 1000, 'buying_price' => $cost, 'original_quantity' => $qty], $this->h)
            ->assertStatus(201)->json('data');
    }

    private function member(string $role): array
    {
        $u = new User();
        $u->forceFill(['name' => ucfirst($role), 'email' => $role.uniqid('', true).'@example.test', 'password' => bcrypt('secret123'), 'status' => 'Active', 'company_id' => $this->t['company_id']])->save();
        CompanyMember::create(['company_id' => $this->t['company_id'], 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'joined_at' => now()]);
        Permissions::flush();

        return ['user' => $u, 'h' => $this->auth($u->createToken('t')->plainTextToken)];
    }

    // ── 1. Landed costs on goods receipts ──────────────────────

    public function test_goods_receipt_spreads_landed_costs_when_the_shop_uses_them(): void
    {
        $a = $this->product(0, 100);
        $b = $this->product(0, 100);
        $body = ['items' => [['stock_item_id' => $a['id'], 'quantity' => 10, 'unit_cost' => 100], ['stock_item_id' => $b['id'], 'quantity' => 10, 'unit_cost' => 300]],
            'landed_costs' => [['label' => 'Transport', 'amount' => 400], ['label' => 'Empty', 'amount' => 0]], 'landed_split' => 'quantity'];

        // Feature off: accepted, nothing applied, same as before.
        $off = $this->postJson('/api/v1/goods-receipts', $body, $this->h)->assertStatus(201);
        $this->assertEquals(0, $off->json('data.landed_cost_total'));
        $this->assertSame('4000.00', $off->json('data.total_cost'));

        $this->features(['landed_cost' => true]);
        $grn = $this->postJson('/api/v1/goods-receipts', $body, $this->h)->assertStatus(201)->json('data');
        $this->assertEquals(400, $grn['landed_cost_total']);
        $this->assertSame('quantity', $grn['landed_split']);
        $this->assertSame([['label' => 'Transport', 'amount' => 400]], $grn['landed_costs']);
        $this->assertSame('4000.00', $grn['total_cost'], 'the supplier invoice is unchanged');
        $this->assertEquals(120.0, (float) DB::table('stock_items')->where('id', $a['id'])->value('buying_price'), '100 + 400 / 20 units');
        $this->assertEquals(320.0, (float) DB::table('stock_items')->where('id', $b['id'])->value('buying_price'));

        $this->postJson('/api/v1/goods-receipts', ['landed_split' => 'weight'] + $body, $this->h)->assertStatus(422)->assertJsonValidationErrors('landed_split');
        $this->postJson('/api/v1/goods-receipts', ['landed_costs' => [['amount' => -5]]] + $body, $this->h)->assertStatus(422);
        $this->postJson('/api/v1/goods-receipts', $body, $this->member('cashier')['h'])->assertStatus(403);
        $this->postJson('/api/v1/goods-receipts', $body)->assertStatus(401);
    }

    // ── 2. Batches on the sync goods_receipt op ───────────────

    public function test_sync_goods_receipt_op_carries_batch_and_expiry(): void
    {
        $p = $this->product(0, 500);
        DB::table('stock_items')->where('id', $p['id'])->update(['track_batches' => 1]);
        $plain = $this->product(0, 500);
        $grn = (string) Str::uuid();
        $old = (string) Str::uuid();
        $expiry = now()->addMonths(6)->toDateString();
        $ops = [
            ['table' => 'goods_receipts', 'uuid' => $grn, 'data' => ['amount_paid' => '0', 'items' => [['product_uuid' => $p['uuid'], 'quantity' => '12', 'unit_cost' => '550', 'batch_number' => 'LOT-7', 'expiry_date' => $expiry]]]],
            ['table' => 'goods_receipts', 'uuid' => $old, 'data' => ['amount_paid' => '0', 'items' => [['product_uuid' => $plain['uuid'], 'quantity' => '3', 'unit_cost' => '500']]]],
        ];
        $ops = array_map(fn ($o) => $o + ['op_uuid' => (string) Str::uuid(), 'action' => 'insert', 'client_updated_at' => SyncSequence::nowMs()], $ops);
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device, 'name' => 'Till', 'platform' => 'android'], $this->h)->assertOk();
        $r = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => $ops]]], $this->h + ['X-Device-Id' => $device])->assertOk()->json('data.results.0');
        $this->assertSame('applied', $r['status'], json_encode($r));

        $id = DB::table('goods_receipts')->where('uuid', $grn)->value('id');
        $line = DB::table('goods_receipt_items')->where('goods_receipt_id', $id)->first();
        $this->assertSame('LOT-7', $line->batch_number);
        $this->assertSame($expiry, substr((string) $line->expiry_date, 0, 10));
        $batch = DB::table('stock_batches')->where('stock_item_id', $p['id'])->where('batch_number', 'LOT-7')->first();
        $this->assertNotNull($batch);
        $this->assertEquals(12, (float) $batch->quantity);
        $this->assertSame($expiry, substr((string) $batch->expiry_date, 0, 10));
        // The old op shape still works.
        $this->assertEquals(3, (float) DB::table('stock_items')->where('id', $plain['id'])->value('current_quantity'));
    }

    // ── 4. Aisle counts and recounts ──────────────────────────

    public function test_stock_take_shelf_scope_recount_flags_and_recount(): void
    {
        $p = $this->product(20);
        $q = $this->product(10);
        // Without aisle_counts: the shelf is ignored and nothing is flagged (as before).
        $plain = $this->postJson('/api/v1/stock-takes', ['name' => 'Plain', 'shelf_location' => 'a3'], $this->h)->assertStatus(201)->json('data');
        $this->assertNull($plain['shelf_location'] ?? null);
        $this->postJson("/api/v1/stock-takes/{$plain['id']}/counts", ['counts' => [['stock_item_id' => $p['id'], 'counted_quantity' => 5]]], $this->h)
            ->assertOk()->assertJsonPath('data.recount_needed', [])->assertJsonPath('data.items.0.needs_recount', false);

        $this->features(['aisle_counts' => true]);
        DB::table('stock_items')->where('id', $p['id'])->update(['shelf_location' => 'A3-B1']);
        $this->getJson('/api/v1/stock-takes/shelf-locations', $this->h)->assertOk()->assertJsonPath('data', ['A3-B1'])->assertJsonPath('meta.aisle_counts', true);
        $take = $this->postJson('/api/v1/stock-takes', ['name' => 'Aisle A3', 'shelf_location' => ' a3 '], $this->h)->assertStatus(201)->json('data');
        $this->assertSame('A3', $take['shelf_location']);

        $res = $this->postJson("/api/v1/stock-takes/{$take['id']}/counts", ['counts' => [['stock_item_id' => $p['id'], 'counted_quantity' => 5], ['stock_item_id' => $q['id'], 'counted_quantity' => 10]]], $this->h)
            ->assertOk()->json('data');
        $this->assertSame([(int) $p['id']], $res['recount_needed']);
        $flags = array_column($res['items'], 'needs_recount', 'stock_item_id');
        $this->assertTrue($flags[$p['id']]);
        $this->assertFalse($flags[$q['id']]);
        $this->getJson("/api/v1/stock-takes/{$take['id']}", $this->h)->assertOk()->assertJsonPath('data.items.0.needs_recount', true);

        $this->postJson("/api/v1/stock-takes/{$take['id']}/post", [], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'recount_needed');
        $this->postJson("/api/v1/stock-takes/{$take['id']}/recount", ['counts' => [['stock_item_id' => $q['id'], 'counted_quantity' => 9]]], $this->h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'not_flagged');
        $this->postJson("/api/v1/stock-takes/{$take['id']}/recount", ['counts' => [['stock_item_id' => $p['id'], 'counted_quantity' => 6]]], $this->member('cashier')['h'])->assertStatus(403);
        $this->postJson("/api/v1/stock-takes/{$take['id']}/recount", ['counts' => [['stock_item_id' => $p['id'], 'counted_quantity' => 6]]], $this->h)
            ->assertOk()->assertJsonPath('data.recount_needed', []);
        $this->postJson("/api/v1/stock-takes/{$take['id']}/post", [], $this->h)->assertOk();
        $this->assertEquals(6, (float) DB::table('stock_items')->where('id', $p['id'])->value('current_quantity'), 'the recount stands');

        $other = $this->auth($this->registerTenant()['token']);
        $this->postJson("/api/v1/stock-takes/{$take['id']}/recount", ['counts' => [['stock_item_id' => $p['id'], 'counted_quantity' => 1]]], $other)->assertStatus(404);
    }

    // ── 5. A product's supplier prices ────────────────────────

    public function test_product_supplier_prices_with_history(): void
    {
        $p = $this->product();
        $this->getJson("/api/v1/stock-items/{$p['id']}/supplier-prices", $this->h)->assertStatus(403)->assertJsonPath('errors.feature', 'supplier_prices');
        $this->features(['supplier_prices' => true]);
        $cheap = $this->postJson('/api/v1/suppliers', ['name' => 'Cheap Ltd'], $this->h)->assertStatus(201)->json('data.id');
        $dear = $this->postJson('/api/v1/suppliers', ['name' => 'Dear Ltd'], $this->h)->assertStatus(201)->json('data.id');
        $svc = new SupplierPriceService();
        $cid = (int) $this->t['company_id'];
        $svc->record($cid, (int) $cheap, (int) $p['id'], 500, now()->subDays(20)->toDateString());
        $svc->record($cid, (int) $cheap, (int) $p['id'], 550, now()->subDays(2)->toDateString());
        $svc->record($cid, (int) $dear, (int) $p['id'], 700, now()->subDays(5)->toDateString());

        $data = $this->getJson("/api/v1/stock-items/{$p['id']}/supplier-prices", $this->h)->assertOk()->json('data');
        $this->assertSame((int) $p['id'], $data['stock_item_id']);
        $this->assertSame(['Cheap Ltd', 'Dear Ltd'], array_column($data['suppliers'], 'supplier'));
        $this->assertEquals(550, $data['suppliers'][0]['cost']);
        $this->assertEquals(500, $data['suppliers'][0]['previous']);
        $this->assertEquals([550, 500], array_column($data['suppliers'][0]['history'], 'cost'));
        $this->assertCount(1, $data['suppliers'][1]['history']);

        $this->getJson("/api/v1/stock-items/{$p['id']}/supplier-prices", $this->member('cashier')['h'])->assertStatus(403)->assertJsonPath('errors.code', 'forbidden');
        $this->getJson("/api/v1/stock-items/{$p['id']}/supplier-prices", $this->member('viewer')['h'])->assertOk();
        $other = $this->registerTenant();
        StoreFeatures::update(Company::find($other['company_id']), ['features' => ['supplier_prices' => true]]);
        $this->getJson("/api/v1/stock-items/{$p['id']}/supplier-prices", $this->auth($other['token']))->assertStatus(404);
        $this->getJson("/api/v1/stock-items/{$p['id']}/supplier-prices")->assertStatus(401);
    }

    // ── 6. Till PINs ──────────────────────────────────────────

    public function test_till_pins_follow_the_web_rules(): void
    {
        $cashier = $this->member('cashier');
        $other = $this->member('cashier');
        $cid = $cashier['user']->id;
        $this->putJson("/api/v1/team/members/{$cid}/pin", ['pin' => '1234'], $cashier['h'])->assertStatus(403)->assertJsonPath('errors.code', 'feature_off');
        $this->putJson('/api/v1/me/pin', ['pin' => '1234', 'password' => 'secret123'], $cashier['h'])->assertStatus(403)->assertJsonPath('errors.code', 'feature_off');

        $this->features(['approvals' => true]);
        $this->putJson("/api/v1/team/members/{$cid}/pin", ['pin' => '1234'], $cashier['h'])->assertOk()->assertJsonPath('data.has_pin', true);
        $this->assertTrue(Hash::check('1234', User::find($cid)->pos_pin_hash));
        $this->putJson("/api/v1/team/members/{$other['user']->id}/pin", ['pin' => '1234'], $cashier['h'])->assertStatus(403)->assertJsonPath('errors.permission', 'manage_team');
        $this->putJson("/api/v1/team/members/{$this->t['user_id']}/pin", ['pin' => '1234'], $cashier['h'])->assertStatus(403);
        $this->putJson("/api/v1/team/members/{$other['user']->id}/pin", ['pin' => '98765'], $this->h)->assertOk();
        $this->assertTrue(Hash::check('98765', User::find($other['user']->id)->pos_pin_hash));
        $this->putJson("/api/v1/team/members/{$cid}/pin", ['pin' => '12'], $this->h)->assertStatus(422)->assertJsonValidationErrors('pin');

        $this->putJson('/api/v1/me/pin', ['pin' => '4321', 'password' => 'wrong-one'], $cashier['h'])->assertStatus(422)->assertJsonPath('errors.code', 'wrong_password');
        $this->putJson('/api/v1/me/pin', ['pin' => '4321'], $cashier['h'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/me/pin', ['pin' => '4321', 'password' => 'secret123'], $cashier['h'])->assertOk();
        $this->assertTrue(Hash::check('4321', User::find($cid)->pos_pin_hash));
        $this->putJson('/api/v1/me/pin', ['pin' => '4321', 'password' => 'secret123'])->assertStatus(401);

        $shop2 = $this->registerTenant();
        StoreFeatures::update(Company::find($shop2['company_id']), ['features' => ['approvals' => true]]);
        $this->putJson("/api/v1/team/members/{$cid}/pin", ['pin' => '5555'], $this->auth($shop2['token']))->assertStatus(404);
        $this->assertTrue(Hash::check('4321', User::find($cid)->pos_pin_hash));
    }

    // ── 7. Company logo ───────────────────────────────────────

    public function test_company_logo_upload(): void
    {
        $this->postJson('/api/v1/company/logo', ['file' => UploadedFile::fake()->image('logo.png', 200, 200)], $this->member('cashier')['h'])
            ->assertStatus(403)->assertJsonPath('errors.permission', 'manage_settings');
        $this->postJson('/api/v1/company/logo', ['file' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')], $this->h)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson('/api/v1/company/logo', ['file' => UploadedFile::fake()->image('big.png')->size(3000)], $this->h)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson('/api/v1/company/logo', [], $this->h)->assertStatus(422);

        $res = $this->postJson('/api/v1/company/logo', ['file' => UploadedFile::fake()->image('logo.png', 200, 200)], $this->h)->assertOk()->json('data');
        try {
            $this->assertMatchesRegularExpression('#^images/logo-'.$this->t['company_id'].'-[A-Za-z0-9]{12}\.png$#', $res['logo']);
            $this->assertStringEndsWith('/storage/'.$res['logo'], $res['logo_url']);
            $this->assertFileExists(public_path('storage/'.$res['logo']));
            $this->assertSame($res['logo'], Company::find($this->t['company_id'])->logo);
            $this->getJson('/api/v1/company', $this->h)->assertJsonPath('data.logo', $res['logo']);
        } finally {
            File::delete(public_path('storage/'.$res['logo']));
        }
        $this->postJson('/api/v1/company/logo', [])->assertStatus(401);
    }

    // ── 8. Dashboard date range ───────────────────────────────

    public function test_dashboard_range_is_additive(): void
    {
        $p = $this->product(10);
        $this->postJson('/api/v1/sales/checkout', ['amount_paid' => 3000, 'items' => [['stock_item_id' => $p['id'], 'quantity' => 3, 'unit_price' => 1000]]], $this->h)->assertStatus(201);

        $plain = $this->getJson('/api/v1/dashboard', $this->h)->assertOk()->json('data');
        $this->assertSame(['inventory', 'sales', 'finance', 'budget', 'recent_sales'], array_keys($plain), 'no params: unchanged');

        $today = now()->setTimezone(\App\Support\LocalTime::timezone(Company::find($this->t['company_id'])))->toDateString();
        $d = $this->getJson("/api/v1/dashboard?from={$today}&to={$today}", $this->h)->assertOk()->json('data');
        foreach (['inventory', 'sales', 'finance', 'budget', 'recent_sales'] as $k) {
            $this->assertSame($plain[$k], $d[$k]);
        }
        $this->assertSame('custom', $d['range']['key']);
        $this->assertSame($today, $d['range']['from']);
        $this->assertEquals(3000, $d['kpis']['sales']);
        $this->assertSame(1, $d['kpis']['count']);
        $this->assertEquals(0, $d['previous']['sales']);
        $this->assertNull($d['change']['sales']);

        $old = $this->getJson('/api/v1/dashboard?from=2020-01-01&to=2020-01-31', $this->h)->assertOk()->json('data');
        $this->assertEquals(0, $old['kpis']['sales']);
        $this->assertSame(31, $old['range']['days']);
        $this->getJson('/api/v1/dashboard?range=7d', $this->h)->assertOk()->assertJsonPath('data.range.key', '7d');
        $this->getJson('/api/v1/dashboard?from=01-01-2020', $this->h)->assertStatus(422);
        $this->getJson("/api/v1/dashboard?from={$today}", $this->member('cashier')['h'])->assertOk()->assertJsonPath('data.kpis', null);

        $other = $this->getJson("/api/v1/dashboard?from={$today}&to={$today}", $this->auth($this->registerTenant()['token']))->assertOk()->json('data');
        $this->assertEquals(0, $other['kpis']['sales'], 'tenant isolation');
        $this->getJson('/api/v1/dashboard?from=2020-01-01')->assertStatus(401);
    }
}
