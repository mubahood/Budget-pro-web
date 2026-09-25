<?php

namespace Tests\Feature\Api;

use App\Models\Plan;
use App\Models\StockItem;
use App\Models\Subscription;
use App\Services\Shop\LocationStock;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Plan P4-4 (decision H6): stock per location, transfers, batches with FEFO and expiry. */
class LocationsBatchesTest extends ApiTestCase
{
    private array $t;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        LocationStock::flush();
        $this->t = $this->registerTenant();
        $this->h = $this->auth($this->t['token']);
    }

    private function businessPlan(): void
    {
        $plan = Plan::create(['name' => 'Business', 'slug' => 'biz-'.uniqid(), 'price' => 49, 'price_ugx' => 185000, 'currency' => 'UGX', 'interval' => 'month', 'trial_days' => 0,
            'is_active' => true, 'is_public' => true, 'sort_order' => 3, 'features' => ['multi_location' => true], 'limits' => ['max_locations' => 3]]);
        Subscription::create(['company_id' => $this->t['company_id'], 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
    }

    private function product(string $name, float $qty, array $o = []): array
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $this->h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $this->h)->json('data.id');

        return $this->postJson('/api/v1/stock-items', array_merge(['name' => $name, 'stock_sub_category_id' => $sub, 'selling_price' => 1000, 'buying_price' => 600, 'original_quantity' => $qty], $o), $this->h)
            ->assertStatus(201)->json('data');
    }

    private function level(int $locationId, int $productId): float
    {
        return LocationStock::level($locationId, $productId);
    }

    private function assertLevelsAddUp(int $productId): void
    {
        $total = (float) StockItem::withoutGlobalScopes()->find($productId)->current_quantity;
        $sum = (float) DB::table('stock_levels')->where('stock_item_id', $productId)->sum('quantity');
        $this->assertEqualsWithDelta($total, $sum, 0.0005, 'Σ location levels = product total');
    }

    public function test_locations_are_a_plan_feature_and_transfers_move_stock_between_them(): void
    {
        $soda = $this->product('Soda', 40);
        $main = LocationStock::defaultLocation((int) $this->t['company_id']);
        $this->assertEquals(40, $this->level($main, $soda['id']));

        $this->postJson('/api/v1/locations', ['name' => 'Branch'], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'feature_not_in_plan');
        $this->businessPlan();
        $branch = $this->postJson('/api/v1/locations', ['name' => 'Branch', 'address' => 'Ntinda'], $this->h)->assertStatus(201)->json('data.id');
        $this->postJson('/api/v1/locations', ['name' => 'Branch'], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'duplicate_location');

        $r = $this->postJson('/api/v1/stock-transfers', ['from_location_id' => $main, 'to_location_id' => $branch, 'items' => [['stock_item_id' => $soda['id'], 'quantity' => 15]]], $this->h)
            ->assertStatus(201)->json('data');
        $this->assertStringStartsWith('TRF-', $r['number']);
        $this->assertEquals(25, $this->level($main, $soda['id']));
        $this->assertEquals(15, $this->level($branch, $soda['id']));
        $this->assertEquals(40, (float) StockItem::withoutGlobalScopes()->find($soda['id'])->current_quantity, 'a transfer never changes the total');
        $this->postJson('/api/v1/stock-transfers', ['from_location_id' => $branch, 'to_location_id' => $main, 'items' => [['stock_item_id' => $soda['id'], 'quantity' => 16]]], $this->h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'insufficient_stock');

        // Selling at the branch (API) and from a phone assigned to the branch (sync) both use the branch's stock.
        $this->postJson('/api/v1/sales/checkout', ['location_id' => $branch, 'payments' => [['method' => 'cash', 'amount' => 3000]], 'items' => [['stock_item_id' => $soda['id'], 'quantity' => 3]]], $this->h)->assertStatus(201);
        $this->assertEquals(12, $this->level($branch, $soda['id']));
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $this->h)->assertOk();
        $deviceRow = DB::table('devices')->where('device_id', $device)->value('id');
        $this->putJson("/api/v1/devices/{$deviceRow}/location", ['location_id' => $branch], $this->h)->assertOk();
        $sale = (string) Str::uuid();
        $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [
            ['op_uuid' => (string) Str::uuid(), 'table' => 'sales', 'uuid' => $sale, 'action' => 'insert', 'client_updated_at' => SyncSequence::nowMs(), 'data' => ['has_payment_ops' => 1, 'occurred_at' => SyncSequence::nowMs(), 'items' => [['product_uuid' => $soda['uuid'], 'quantity' => '2']]]],
            ['op_uuid' => (string) Str::uuid(), 'table' => 'payments', 'uuid' => (string) Str::uuid(), 'action' => 'insert', 'client_updated_at' => SyncSequence::nowMs(), 'data' => ['sale_uuid' => $sale, 'method' => 'cash', 'amount' => '2000', 'received_at' => SyncSequence::nowMs()]],
        ]]]], $this->h + ['X-Device-Id' => $device])->assertOk();
        $this->assertEquals(10, $this->level($branch, $soda['id']));
        $this->assertEquals(25, $this->level($main, $soda['id']));
        $this->assertLevelsAddUp($soda['id']);

        $locs = collect($this->getJson('/api/v1/locations', $this->h)->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(1, $locs['Branch']['phones']);
        $this->putJson("/api/v1/locations/{$main}", ['is_active' => false], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'default_location');
        $this->putJson("/api/v1/locations/{$branch}", ['is_active' => false], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'location_has_stock');
    }

    public function test_batches_are_picked_first_expiry_first_out_and_restored_on_void(): void
    {
        $amox = $this->product('Amoxicillin', 0, ['track_batches' => true]);
        $this->postJson('/api/v1/goods-receipts', ['items' => [
            ['stock_item_id' => $amox['id'], 'quantity' => 10, 'unit_cost' => 2000, 'batch_number' => 'A-LATE', 'expiry_date' => now()->addDays(300)->toDateString()],
            ['stock_item_id' => $amox['id'], 'quantity' => 5, 'unit_cost' => 2000, 'batch_number' => 'B-SOON', 'expiry_date' => now()->addDays(20)->toDateString()],
        ]], $this->h)->assertStatus(201);
        $batch = fn (string $n) => (float) DB::table('stock_batches')->where('stock_item_id', $amox['id'])->where('batch_number', $n)->value('quantity');

        $sale = $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'cash', 'amount' => 7000]], 'items' => [['stock_item_id' => $amox['id'], 'quantity' => 7]]], $this->h)->assertStatus(201)->json('data');
        $this->assertEquals(0, $batch('B-SOON'), 'the batch expiring first is sold first');
        $this->assertEquals(8, $batch('A-LATE'));

        $expiring = $this->getJson('/api/v1/reports/expiry?days=60', $this->h)->assertOk()->json('data');
        $this->assertSame([], $expiring['rows'], 'the soon-to-expire batch is sold out');

        $this->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong patient'], $this->h)->assertOk();
        $this->assertEquals(5, $batch('B-SOON'), 'a void puts back exactly what the sale took');
        $this->assertEquals(10, $batch('A-LATE'));
        $rows = $this->getJson('/api/v1/reports/expiry?days=60', $this->h)->json('data.rows');
        $this->assertSame('B-SOON', $rows[0]['batch']);
        $this->assertSame(20, $rows[0]['days_left']);

        // Batches travel with transfers.
        $this->businessPlan();
        $branch = $this->postJson('/api/v1/locations', ['name' => 'Pharmacy 2'], $this->h)->json('data.id');
        $this->postJson('/api/v1/stock-transfers', ['from_location_id' => LocationStock::defaultLocation((int) $this->t['company_id']), 'to_location_id' => $branch,
            'items' => [['stock_item_id' => $amox['id'], 'quantity' => 6]]], $this->h)->assertStatus(201);
        $moved = DB::table('stock_batches')->where('stock_item_id', $amox['id'])->where('location_id', $branch)->pluck('quantity', 'batch_number')->map(fn ($q) => (float) $q)->all();
        $this->assertEquals(['B-SOON' => 5.0, 'A-LATE' => 1.0], $moved);
        $this->assertEquals(15, (float) DB::table('stock_batches')->where('stock_item_id', $amox['id'])->sum('quantity'));
    }

    /** Property: any mix of receipts, sales, adjustments, voids and transfers keeps every location total and batch total in step. */
    public function test_random_movement_sequences_keep_locations_and_batches_consistent(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->businessPlan();
        $companyId = (int) $this->t['company_id'];
        $main = LocationStock::defaultLocation($companyId);
        $locs = [$main, $this->postJson('/api/v1/locations', ['name' => 'B'], $this->h)->json('data.id'), $this->postJson('/api/v1/locations', ['name' => 'C'], $this->h)->json('data.id')];
        $p = $this->product('Paracetamol', 0, ['track_batches' => true, 'allow_negative_stock' => true]);
        mt_srand(20260928);
        $sales = [];
        for ($i = 0; $i < 60; $i++) {
            $op = mt_rand(0, 4);
            $loc = $locs[mt_rand(0, 2)];
            if ($op === 0) {
                $this->postJson('/api/v1/goods-receipts', ['location_id' => $loc, 'items' => [['stock_item_id' => $p['id'], 'quantity' => mt_rand(1, 20), 'unit_cost' => 100,
                    'batch_number' => 'L'.mt_rand(1, 4), 'expiry_date' => now()->addDays(mt_rand(5, 400))->toDateString()]]], $this->h)->assertStatus(201);
            } elseif ($op === 1) {
                $q = mt_rand(1, 6);
                $sales[] = $this->postJson('/api/v1/sales/checkout', ['location_id' => $loc, 'allow_negative_stock' => true, 'payments' => [['method' => 'cash', 'amount' => 1000 * $q]],
                    'items' => [['stock_item_id' => $p['id'], 'quantity' => $q]]], $this->h)->assertStatus(201)->json('data.id');
            } elseif ($op === 2 && $sales) {
                $this->postJson('/api/v1/sales/'.array_pop($sales).'/void', ['reason' => 'test'], $this->h)->assertOk();
            } elseif ($op === 3) {
                $to = $locs[mt_rand(0, 2)];
                if ($to !== $loc) {
                    $this->postJson('/api/v1/stock-transfers', ['from_location_id' => $loc, 'to_location_id' => $to, 'items' => [['stock_item_id' => $p['id'], 'quantity' => mt_rand(1, 5)]]], $this->h)
                        ->assertStatus(201);
                }
            } else {
                $this->postJson('/api/v1/stock-records', ['stock_item_id' => $p['id'], 'type' => 'Damage', 'quantity' => 1, 'reason' => 'damage'], $this->h)->assertStatus(201);
            }
            $this->assertLevelsAddUp($p['id']);
        }
        // Batches never exceed what the location holds and are never negative.
        foreach ($locs as $loc) {
            $level = $this->level($loc, $p['id']);
            $batched = (float) DB::table('stock_batches')->where('stock_item_id', $p['id'])->where('location_id', $loc)->sum('quantity');
            $this->assertLessThanOrEqual(max(0, $level) + 0.0005, $batched);
            $this->assertSame(0, DB::table('stock_batches')->where('stock_item_id', $p['id'])->where('quantity', '<', 0)->count());
        }
    }
}
