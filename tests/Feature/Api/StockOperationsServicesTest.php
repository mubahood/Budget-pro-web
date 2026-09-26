<?php

namespace Tests\Feature\Api;

use App\Exceptions\BusinessRuleException;
use App\Models\Plan;
use App\Models\StockRecord;
use App\Models\StockTake;
use App\Models\Subscription;
use App\Services\Shop\LocationStock;
use App\Services\Shop\MovementDocuments;
use App\Services\Shop\StockTakeService;
use Illuminate\Support\Facades\DB;

/**
 * Shared stock rules used by the classic admin, the API and the new web interface:
 * MovementDocuments (which document a movement belongs to / can it be undone),
 * StockTakeService::cancel and TransferService::updateLocation.
 */
class StockOperationsServicesTest extends ApiTestCase
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

    private function product(string $name, float $qty): array
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $this->h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $this->h)->json('data.id');

        return $this->postJson('/api/v1/stock-items', ['name' => $name, 'stock_sub_category_id' => $sub, 'selling_price' => 1000, 'buying_price' => 600, 'original_quantity' => $qty], $this->h)
            ->assertStatus(201)->json('data');
    }

    public function test_movement_documents_give_keys_and_ids_and_the_api_refuses_document_reversals(): void
    {
        $p = $this->product('Sugar', 20);
        $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'cash', 'amount' => 2000]], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 2]]], $this->h)->assertStatus(201);
        $saleMove = StockRecord::withoutGlobalScopes()->where('company_id', $this->t['company_id'])->where('type', 'Sale')->firstOrFail();
        $doc = MovementDocuments::document($saleMove);
        $this->assertSame('sale', $doc['key']);
        $this->assertSame((int) $saleMove->sale_record_id, $doc['id']);
        $this->assertFalse(MovementDocuments::reversible($saleMove));

        $grn = $this->postJson('/api/v1/goods-receipts', ['items' => [['stock_item_id' => $p['id'], 'quantity' => 5, 'unit_cost' => 650]]], $this->h)->assertStatus(201)->json('data.id');
        $grnMove = StockRecord::withoutGlobalScopes()->where('reference_type', 'goods_receipt')->where('reference_id', $grn)->firstOrFail();
        $this->assertSame(['key' => 'goods_receipt', 'id' => (int) $grn], array_intersect_key(MovementDocuments::document($grnMove), ['key' => 1, 'id' => 1]));
        $this->postJson("/api/v1/stock-records/{$grnMove->id}/reverse", ['reason' => 'x'], $this->h)->assertStatus(422)
            ->assertJsonPath('errors.code', 'movement_belongs_to_document')->assertJsonPath('errors.document', 'delivery')
            ->assertJsonPath('errors.document_key', 'goods_receipt')->assertJsonPath('errors.document_id', (int) $grn);
        $this->assertFalse(StockRecord::withoutGlobalScopes()->where('reverses_id', $grnMove->id)->exists());

        $damage = $this->postJson('/api/v1/stock-records', ['stock_item_id' => $p['id'], 'type' => 'Damage', 'quantity' => 1, 'reason' => 'damage'], $this->h)->assertStatus(201)->json('data.id');
        $damage = StockRecord::withoutGlobalScopes()->findOrFail($damage);
        $this->assertNull(MovementDocuments::document($damage));
        $this->assertTrue(MovementDocuments::reversible($damage));
        $this->postJson("/api/v1/stock-records/{$damage->id}/reverse", ['reason' => 'typo'], $this->h)->assertStatus(201);
        $this->assertFalse(MovementDocuments::reversible($damage->fresh()));
        $contra = StockRecord::withoutGlobalScopes()->where('reverses_id', $damage->id)->firstOrFail();
        $this->assertFalse(MovementDocuments::reversible($contra), 'an undo cannot be undone');

        // The service refuses the same way for any caller.
        try {
            (new MovementDocuments())->reverse($saleMove, 'no');
            $this->fail('a sale movement must not be reversed on its own');
        } catch (BusinessRuleException $e) {
            $this->assertSame('movement_belongs_to_document', $e->errorCode());
            $this->assertSame('sale', $e->meta()['document_key']);
        }

        // The classic admin still gets its URLs from the same rule.
        $admin = \App\Admin\Controllers\StockRecordController::document($grnMove);
        $this->assertStringEndsWith('goods-receipts/'.$grn, $admin['url']);
        $this->assertSame('delivery', $admin['label']);
        $this->assertEquals(20 - 2 + 5, (float) DB::table('stock_items')->where('id', $p['id'])->value('current_quantity'));
    }

    public function test_a_draft_count_can_be_cancelled_and_then_never_posts(): void
    {
        $p = $this->product('Rice', 10);
        $svc = new StockTakeService();
        $take = $svc->create((int) $this->t['company_id'], (int) $this->t['user_id'], 'Shelf A');
        $svc->count($take, [['stock_item_id' => $p['id'], 'counted_quantity' => 4]]);
        $this->assertSame('cancelled', $svc->cancel($take->fresh())->status);
        $this->assertSame('cancelled', $svc->cancel($take->fresh())->status, 'cancelling twice is harmless');
        try {
            $svc->post($take->fresh(), (int) $this->t['user_id']);
            $this->fail('a cancelled count must not post');
        } catch (BusinessRuleException $e) {
            $this->assertSame('stock_take_cancelled', $e->errorCode());
        }
        $this->assertEquals(10, (float) DB::table('stock_items')->where('id', $p['id'])->value('current_quantity'));

        $posted = $svc->create((int) $this->t['company_id'], (int) $this->t['user_id'], 'Shelf B');
        $svc->count($posted, [['stock_item_id' => $p['id'], 'counted_quantity' => 9]]);
        $svc->post($posted, (int) $this->t['user_id']);
        $this->expectException(BusinessRuleException::class);
        $svc->cancel(StockTake::withoutGlobalScopes()->find($posted->id));
    }

    public function test_updating_a_location_goes_through_the_transfer_service_rules(): void
    {
        $p = $this->product('Soap', 12);
        $cid = (int) $this->t['company_id'];
        $main = LocationStock::defaultLocation($cid);
        $plan = Plan::create(['name' => 'Business', 'slug' => 'biz-'.uniqid(), 'price' => 49, 'price_ugx' => 185000, 'currency' => 'UGX', 'interval' => 'month', 'trial_days' => 0,
            'is_active' => true, 'is_public' => true, 'sort_order' => 3, 'features' => ['multi_location' => true], 'limits' => ['max_locations' => 3]]);
        Subscription::create(['company_id' => $cid, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        $branch = $this->postJson('/api/v1/locations', ['name' => 'Branch'], $this->h)->assertStatus(201)->json('data.id');
        $this->postJson('/api/v1/locations', ['name' => 'Store'], $this->h)->assertStatus(201);

        $this->putJson("/api/v1/locations/{$main}", ['is_active' => false], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'default_location');
        $this->putJson("/api/v1/locations/{$branch}", ['name' => 'Store'], $this->h)->assertStatus(422);
        $this->putJson("/api/v1/locations/{$branch}", ['name' => 'Ntinda branch', 'address' => 'Ntinda'], $this->h)->assertOk()->assertJsonPath('data.name', 'Ntinda branch');

        $this->postJson('/api/v1/stock-transfers', ['from_location_id' => $main, 'to_location_id' => $branch, 'items' => [['stock_item_id' => $p['id'], 'quantity' => 2]]], $this->h)->assertStatus(201);
        $this->putJson("/api/v1/locations/{$branch}", ['is_active' => false], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'location_has_stock');
        $this->postJson('/api/v1/stock-transfers', ['from_location_id' => $branch, 'to_location_id' => $main, 'items' => [['stock_item_id' => $p['id'], 'quantity' => 2]]], $this->h)->assertStatus(201);
        $this->putJson("/api/v1/locations/{$branch}", ['is_active' => false], $this->h)->assertOk();
        $this->assertFalse((bool) DB::table('locations')->where('id', $branch)->value('is_active'));
        $this->putJson('/api/v1/locations/999999', ['name' => 'x'], $this->h)->assertNotFound();
    }
}
