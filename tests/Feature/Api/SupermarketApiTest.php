<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Shop\ApprovalService;
use App\Services\Team\Permissions;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Supermarket endpoints for the phone: every one refuses with 403 `feature_off` while its switch is off,
 * then works through the same services as the web (approvals, X/Z, gift cards, held carts, short-dated
 * stock, labels, tax classes, supplier prices / scorecard, cash movements), scoped to the caller's shop.
 */
class SupermarketApiTest extends ApiTestCase
{
    private array $t;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->registerTenant();
        $this->h = $this->auth($this->t['token']);
    }

    private function on(array $settings = []): void
    {
        StoreFeatures::update(Company::find($this->t['company_id']), ['mode' => true, 'settings' => $settings]);
    }

    private function member(string $role): array
    {
        $u = new User();
        $u->forceFill(['name' => ucfirst($role), 'email' => $role.uniqid('', true).'@example.test', 'password' => bcrypt('secret123'), 'status' => 'Active', 'company_id' => $this->t['company_id']])->save();
        CompanyMember::create(['company_id' => $this->t['company_id'], 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'joined_at' => now()]);
        Permissions::flush();

        return ['user' => $u, 'h' => $this->auth($u->createToken('t')->plainTextToken)];
    }

    private function product(float $qty = 20): array
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $this->h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $this->h)->json('data.id');

        return $this->postJson('/api/v1/stock-items', ['name' => 'Milk '.uniqid(), 'stock_sub_category_id' => $sub, 'selling_price' => 2000, 'buying_price' => 1500, 'original_quantity' => $qty], $this->h)
            ->assertStatus(201)->json('data');
    }

    public function test_every_endpoint_answers_feature_off_while_its_switch_is_off(): void
    {
        $calls = [
            ['POST', 'approvals', ['action' => 'no_sale', 'pin' => '1234'], 'approvals'],
            ['GET', 'x-report', [], 'cash_control'], ['POST', 'z-reports', ['date' => now()->toDateString()], 'cash_control'], ['GET', 'z-reports', [], 'cash_control'],
            ['GET', 'gift-cards/1234567812345678', [], 'gift_cards'], ['POST', 'gift-cards', ['amount' => 10, 'method' => 'cash'], 'gift_cards'],
            ['GET', 'held-carts', [], 'held_carts'], ['POST', 'held-carts', ['state' => ['lines' => [['a' => 1]]]], 'held_carts'],
            ['GET', 'expiring', [], 'fefo'], ['POST', 'batches/1/markdown', ['pct' => 10], 'markdowns'], ['POST', 'batches/1/write-off', ['qty' => 1], 'fefo'],
            ['GET', 'label-queue', [], 'shelf_labels'], ['POST', 'label-queue/printed', [], 'shelf_labels'],
            ['GET', 'tax-classes', [], 'tax_classes'], ['PUT', 'tax-classes', ['classes' => []], 'tax_classes'],
            ['GET', 'suppliers/1/prices', [], 'supplier_prices'], ['GET', 'suppliers/1/scorecard', [], 'smart_reorder'],
            ['POST', 'cash-movements', ['type' => 'no_sale', 'reason' => 'x', 'shift_id' => 1], 'cash_control'],
        ];
        foreach ($calls as [$method, $uri, $body, $feature]) {
            $this->json($method, '/api/v1/'.$uri, $body, $this->h)->assertStatus(403)->assertJsonPath('errors.code', 'feature_off')->assertJsonPath('errors.feature', $feature);
        }
        $this->getJson('/api/v1/held-carts')->assertStatus(401);
    }

    public function test_approvals_held_carts_and_gift_cards(): void
    {
        $this->on();
        $owner = User::find($this->t['user_id']);
        (new ApprovalService())->setPin($owner, '4321');
        $cashier = $this->member('cashier');

        $this->postJson('/api/v1/approvals', ['action' => 'no_sale', 'pin' => '0000'], $cashier['h'])->assertStatus(422)->assertJsonPath('errors.code', 'wrong_pin');
        $ok = $this->postJson('/api/v1/approvals', ['action' => 'void_sale', 'pin' => '4321', 'context' => ['reason' => 'Wrong item']], $cashier['h'])->assertStatus(201);
        $this->assertGreaterThan(0, $ok->json('data.approval_id'));
        $this->assertSame($owner->id, $ok->json('data.approver.id'));

        // Held carts: any till of the shop takes a cart once.
        $held = $this->postJson('/api/v1/held-carts', ['label' => 'Lady in red', 'state' => ['lines' => [['product_uuid' => 'x', 'quantity' => 2, 'unit_price' => 1500]]]], $cashier['h'])->assertStatus(201);
        $this->assertEquals(3000, $held->json('data.total'));
        $id = $held->json('data.id');
        $this->assertCount(1, $this->getJson('/api/v1/held-carts', $this->h)->json('data'));
        $other = $this->registerTenant();
        StoreFeatures::update(Company::find($other['company_id']), ['mode' => true]);
        $this->postJson("/api/v1/held-carts/{$id}/take", [], $this->auth($other['token']))->assertStatus(404);
        $this->postJson("/api/v1/held-carts/{$id}/take", [], $this->h)->assertOk()->assertJsonPath('data.state.lines.0.quantity', 2);
        $this->postJson("/api/v1/held-carts/{$id}/take", [], $this->h)->assertStatus(404)->assertJsonPath('errors.code', 'held_cart_gone');
        $second = $this->postJson('/api/v1/held-carts', ['state' => ['lines' => [['q' => 1]]]], $this->h)->json('data.id');
        $this->deleteJson("/api/v1/held-carts/{$second}", [], $this->h)->assertOk();
        $this->assertSame([], $this->getJson('/api/v1/held-carts', $this->h)->json('data'));

        // Gift cards: sold once (the code shown once), looked up by code, only in this shop.
        $sold = $this->postJson('/api/v1/gift-cards', ['amount' => 50000, 'method' => 'cash'], $cashier['h'])->assertStatus(201);
        $code = $sold->json('data.code');
        $this->assertNotEmpty($code);
        $look = $this->getJson('/api/v1/gift-cards/'.rawurlencode($code), $this->h)->assertOk();
        $look->assertJsonPath('data.status', 'active')->assertJsonPath('data.last4', substr(str_replace(' ', '', $code), -4));
        $this->assertEquals(50000, $look->json('data.balance'));
        $this->getJson('/api/v1/gift-cards?code='.urlencode($code), $this->h)->assertOk();
        $this->getJson('/api/v1/gift-cards/'.rawurlencode($code), $this->auth($other['token']))->assertStatus(404);
        $this->postJson('/api/v1/gift-cards', ['amount' => 100, 'method' => 'credit'], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'gift_card_credit');
        $viewer = $this->member('viewer');
        $this->postJson('/api/v1/gift-cards', ['amount' => 100, 'method' => 'cash'], $viewer['h'])->assertStatus(403);
    }

    public function test_cash_movements_x_and_z_reports(): void
    {
        $this->on();
        $cashier = $this->member('cashier');
        $shift = $this->postJson('/api/v1/shifts/open', ['opening_float' => 10000], $cashier['h'])->assertStatus(201)->json('data');

        $m = $this->postJson('/api/v1/cash-movements', ['type' => 'paid_in', 'amount' => 2000, 'reason' => 'Float top-up', 'shift_id' => $shift['id'], 'client_uuid' => (string) Str::uuid()], $cashier['h'])
            ->assertStatus(201);
        $this->assertEquals(12000, $m->json('data.expected_cash'));
        $this->assertGreaterThan(0, $m->json('data.server_seq'));
        $this->postJson('/api/v1/cash-movements', ['type' => 'drop', 'amount' => 999999, 'reason' => 'To the safe', 'shift_id' => $shift['id']], $cashier['h'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'not_enough_cash');
        $this->postJson('/api/v1/cash-movements', ['type' => 'no_sale', 'reason' => 'Open drawer', 'shift_id' => $shift['id']], $cashier['h'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'approval_required');

        $x = $this->getJson('/api/v1/x-report?scope=shift', $cashier['h'])->assertOk();
        $this->assertSame('shift', $x->json('data.scope'));
        $this->assertArrayHasKey('shift_totals', $x->json('data'));
        $this->getJson('/api/v1/x-report?scope=day', $cashier['h'])->assertStatus(403);
        $this->getJson('/api/v1/x-report?scope=day', $this->h)->assertOk()->assertJsonPath('data.scope', 'day');

        $this->postJson('/api/v1/z-reports', ['date' => now()->toDateString()], $cashier['h'])->assertStatus(403);
        $today = \App\Support\LocalDate::today($this->t['company_id'])->toDateString();
        $z = $this->postJson('/api/v1/z-reports', ['date' => $today], $this->h)->assertStatus(201);
        $this->assertStringStartsWith('Z-', $z->json('data.number'));
        $this->postJson('/api/v1/z-reports', ['date' => $today], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'day_closed');
        $this->assertCount(1, $this->getJson('/api/v1/z-reports', $this->h)->json('data'));
        $id = $z->json('data.id');
        $this->getJson("/api/v1/z-reports/{$id}", $this->h)->assertOk()->assertJsonStructure(['data' => ['totals' => ['sales', 'by_method']]]);
        $pdf = $this->get("/api/v1/z-reports/{$id}?format=pdf", $this->h)->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $other = $this->registerTenant();
        StoreFeatures::update(Company::find($other['company_id']), ['mode' => true]);
        $this->getJson("/api/v1/z-reports/{$id}", $this->auth($other['token']))->assertStatus(404);
    }

    public function test_short_dated_stock_labels_tax_classes_and_supplier_pages(): void
    {
        $this->on(['waste_limit' => 0]);
        $p = $this->product(20);
        $batch = DB::table('stock_batches')->insertGetId(['company_id' => $this->t['company_id'], 'stock_item_id' => $p['id'], 'batch_number' => 'L7', 'expiry_date' => now()->addDays(3)->toDateString(),
            'quantity' => 5, 'unit_cost' => 1500, 'created_at' => now(), 'updated_at' => now()]);

        $rows = $this->getJson('/api/v1/expiring?days=7', $this->h)->assertOk()->json('data');
        $this->assertSame($batch, $rows[0]['id']);
        $this->assertSame($p['uuid'], $rows[0]['product_uuid']);
        $cashier = $this->member('cashier');
        $this->getJson('/api/v1/expiring', $cashier['h'])->assertStatus(403);

        $md = $this->postJson("/api/v1/batches/{$batch}/markdown", ['pct' => 30], $this->h)->assertStatus(201);
        $this->assertEquals(1400, $md->json('data.price'));
        $this->assertStringStartsWith('MD', $md->json('data.barcode'));

        // waste_limit 0: every write-off needs a supervisor.
        $this->postJson("/api/v1/batches/{$batch}/write-off", ['qty' => 2, 'reason' => 'expired'], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'approval_required');
        (new ApprovalService())->setPin(User::find($this->t['user_id']), '4321');
        $approval = $this->postJson('/api/v1/approvals', ['action' => 'waste', 'pin' => '4321', 'context' => ['amount' => 3000]], $this->h)->json('data.approval_id');
        $this->postJson("/api/v1/batches/{$batch}/write-off", ['qty' => 2, 'reason' => 'expired', 'approval_id' => $approval], $this->h)->assertStatus(201)->assertJsonPath('data.type', 'Expired');
        $this->postJson("/api/v1/batches/{$batch}/write-off", ['qty' => 1, 'reason' => 'eaten'], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'invalid_movement_type');

        DB::table('label_queue')->insert(['company_id' => $this->t['company_id'], 'stock_item_id' => $p['id'], 'reason' => 'price_change', 'created_at' => now()]);
        $labels = $this->getJson('/api/v1/label-queue', $this->h)->assertOk()->json('data');
        $this->assertContains('price_change', array_column($labels, 'reason'));
        $this->assertSame($p['uuid'], $labels[0]['product_uuid']);
        $this->postJson('/api/v1/label-queue/printed', ['ids' => array_column($labels, 'id')], $this->h)->assertOk()->assertJsonPath('data.printed', count($labels));
        $this->assertSame([], $this->getJson('/api/v1/label-queue', $this->h)->json('data'));

        $classes = $this->getJson('/api/v1/tax-classes', $this->h)->assertOk()->json('data');
        $this->assertCount(3, $classes, 'the usual three the first time');
        $saved = $this->putJson('/api/v1/tax-classes', ['classes' => [['name' => 'Reduced', 'code' => 'reduced', 'rate' => 10, 'is_default' => true]]], $this->h)->assertOk()->json('data');
        $this->assertSame('Reduced', collect($saved)->firstWhere('is_default', true)['name']);
        $this->putJson('/api/v1/tax-classes', ['classes' => [['name' => 'X', 'code' => 'standard', 'rate' => 5]]], $cashier['h'])->assertStatus(403);
        $this->putJson('/api/v1/tax-classes', ['delete' => [collect($saved)->firstWhere('is_default', true)['id']]], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'tax_class_default');

        $supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Fresh Dairies'], $this->h)->assertStatus(201)->json('data.id');
        DB::table('supplier_prices')->insert(['company_id' => $this->t['company_id'], 'supplier_id' => $supplier, 'stock_item_id' => $p['id'], 'cost' => 1450, 'valid_from' => now()->subDay()->toDateString(),
            'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        $prices = $this->getJson("/api/v1/suppliers/{$supplier}/prices", $this->h)->assertOk()->json('data');
        $this->assertEquals(1450, $prices[0]['cost']);
        $this->getJson("/api/v1/suppliers/{$supplier}/scorecard", $this->h)->assertOk()->assertJsonPath('data.orders', 0);
        $other = $this->registerTenant();
        StoreFeatures::update(Company::find($other['company_id']), ['mode' => true]);
        $this->getJson("/api/v1/suppliers/{$supplier}/prices", $this->auth($other['token']))->assertStatus(404);
    }
}
