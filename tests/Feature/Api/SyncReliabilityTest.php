<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\FinancialRecord;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Shop\GoodsReceiptService;
use App\Support\Sync\SyncSequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Mobile improvement plan: S2 (a bad batch never fails the push), O2 (supplier_payments op),
 * S6 (client_uuid on online money calls).
 */
class SyncReliabilityTest extends ApiTestCase
{
    private function tenant(): array
    {
        $t = $this->registerTenant();
        $t['device'] = (string) Str::uuid();
        $t['h'] = $this->auth($t['token']) + ['X-Device-Id' => $t['device']];
        $this->postJson('/api/v1/devices/register', ['device_id' => $t['device'], 'name' => 'Till 1', 'platform' => 'android'], $this->auth($t['token']))->assertOk();

        return $t;
    }

    private function batch(string $table, string $uuid, array $data, ?string $batchUuid = null, array $op = []): array
    {
        return ['batch_uuid' => $batchUuid ?? (string) Str::uuid(), 'ops' => [
            ['op_uuid' => (string) Str::uuid(), 'table' => $table, 'uuid' => $uuid, 'action' => 'insert', 'client_updated_at' => SyncSequence::nowMs(), 'data' => $data] + $op,
        ]];
    }

    private function supplierOwing(array $t, float $owed): Supplier
    {
        $id = $this->postJson('/api/v1/suppliers', ['name' => 'Sup '.uniqid()], $t['h'])->assertStatus(201)->json('data.id');
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $t['h'])->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $t['h'])->json('data.id');
        $p = $this->postJson('/api/v1/stock-items', ['name' => 'P'.uniqid(), 'stock_sub_category_id' => $sub, 'selling_price' => 1000, 'buying_price' => 600, 'original_quantity' => 1], $t['h'])->json('data.id');
        (new GoodsReceiptService())->receive($t['company_id'], $t['user_id'], [['stock_item_id' => $p, 'quantity' => 10, 'unit_cost' => $owed / 10]], $id, 'INV-'.uniqid(), 0);

        return Supplier::withoutGlobalScopes()->find($id);
    }

    public function test_a_batch_that_throws_does_not_fail_the_push(): void
    {
        $t = $this->tenant();
        $existing = $this->postJson('/api/v1/customers', ['name' => 'Already here'], $t['h'])->assertStatus(201)->json('data');
        $clash = fn () => new UniqueConstraintViolationException('mysql', 'insert ...', [], new \PDOException('Duplicate entry'));
        Customer::creating(function ($c) use ($clash) {
            if ($c->name === 'Clash') {
                throw $clash();
            }
            if ($c->name === 'Boom') {
                throw new \RuntimeException('disk full');
            }
        });
        Customer::updating(function ($c) use ($clash) {
            if ($c->name === 'Replay') {
                throw $clash();
            }
        });

        $clashUuid = (string) Str::uuid();
        $okUuid = (string) Str::uuid();
        $res = $this->postJson('/api/v1/sync/push', ['batches' => [
            $this->batch('customers', $clashUuid, ['name' => 'Clash']),
            $this->batch('customers', $existing['uuid'], ['name' => 'Replay'], null, ['action' => 'upsert', 'version' => (int) $existing['version']]),
            $this->batch('customers', (string) Str::uuid(), ['name' => 'Boom']),
            $this->batch('customers', $okUuid, ['name' => 'Fine']),
        ]], $t['h'])->assertOk();

        $r = $res->json('data.results');
        $this->assertSame(['rejected', 'retry_later'], [$r[0]['status'], $r[0]['code']], 'a clash whose row is missing is retried later');
        $this->assertSame('replayed', $r[1]['status'], 'a clash whose row exists is replayed');
        $this->assertSame('replayed', $r[1]['ops'][0]['status']);
        $this->assertSame(['rejected', 'retry_later'], [$r[2]['status'], $r[2]['code']]);
        $this->assertSame('applied', $r[3]['status'], 'the other batches still apply');
        $this->assertTrue(Customer::withoutGlobalScopes()->where('uuid', $okUuid)->exists());
        $this->assertFalse(Customer::withoutGlobalScopes()->where('uuid', $clashUuid)->exists(), 'the failed batch was rolled back');
    }

    public function test_supplier_payment_op_lowers_what_we_owe_and_replays_once(): void
    {
        $t = $this->tenant();
        $s = $this->supplierOwing($t, 5000);
        $this->assertEquals(5000, (float) $s->balance);
        $seq = SyncSequence::current();
        $payUuid = (string) Str::uuid();
        $data = ['supplier_uuid' => $s->uuid, 'amount' => '2000', 'method' => 'mobile_money', 'reference' => 'MM 1', 'occurred_at' => SyncSequence::nowMs()];

        $first = $this->postJson('/api/v1/sync/push', ['batches' => [$this->batch('supplier_payments', $payUuid, $data)]], $t['h'])->assertOk();
        $this->assertSame('applied', $first->json('data.results.0.status'), json_encode($first->json('data.results')));
        $again = $this->postJson('/api/v1/sync/push', ['batches' => [$this->batch('supplier_payments', $payUuid, $data)]], $t['h'])->assertOk();
        $this->assertSame('replayed', $again->json('data.results.0.ops.0.status'));

        $this->assertEquals(3000, (float) $s->fresh()->balance);
        $rows = FinancialRecord::withoutGlobalScopes()->where('company_id', $t['company_id'])->where('source_type', 'supplier_payment')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($payUuid, $rows[0]->uuid);
        $this->assertSame('MM 1', $rows[0]->receipt);

        $pulled = collect($this->getJson('/api/v1/sync/pull?table=suppliers&since_seq='.$seq, $t['h'])->assertOk()->json('data.rows'))->firstWhere('uuid', $s->uuid);
        $this->assertEquals(3000, (float) $pulled['balance'], 'phones see the new balance on the next pull');
        $this->getJson('/api/v1/sync/pull?table=supplier_payments&since_seq=0', $t['h'])->assertOk()->assertJsonCount(0, 'data.rows');

        $missing = $this->postJson('/api/v1/sync/push', ['batches' => [$this->batch('supplier_payments', (string) Str::uuid(), ['supplier_uuid' => (string) Str::uuid(), 'amount' => 10])]], $t['h']);
        $this->assertSame('missing_parent', $missing->json('data.results.0.ops.0.code'));
    }

    public function test_client_uuid_makes_online_supplier_payment_and_purchase_order_idempotent(): void
    {
        $t = $this->tenant();
        $s = $this->supplierOwing($t, 5000);
        $uuid = (string) Str::uuid();
        $body = ['amount' => 1500, 'method' => 'cash', 'client_uuid' => $uuid];
        $this->postJson("/api/v1/suppliers/{$s->id}/payments", $body, $t['h'])->assertStatus(201);
        $this->postJson("/api/v1/suppliers/{$s->id}/payments", $body, $t['h'])->assertStatus(200)->assertJsonPath('data.replayed', true)->assertJsonPath('data.ledger.uuid', $uuid);
        $this->assertSame(1, FinancialRecord::withoutGlobalScopes()->where('company_id', $t['company_id'])->where('source_type', 'supplier_payment')->count());
        $this->assertEquals(3500, (float) $s->fresh()->balance);

        $product = \App\Models\StockItem::withoutGlobalScopes()->where('company_id', $t['company_id'])->value('id');
        $po = ['supplier_id' => $s->id, 'client_uuid' => (string) Str::uuid(), 'items' => [['stock_item_id' => $product, 'quantity' => 5, 'unit_cost' => 400]]];
        $a = $this->postJson('/api/v1/purchase-orders', $po, $t['h'])->assertStatus(201)->json('data.id');
        $b = $this->postJson('/api/v1/purchase-orders', $po, $t['h'])->assertStatus(200)->json('data.id');
        $this->assertSame($a, $b);
        $this->assertSame(1, PurchaseOrder::withoutGlobalScopes()->where('company_id', $t['company_id'])->count());
    }
}
