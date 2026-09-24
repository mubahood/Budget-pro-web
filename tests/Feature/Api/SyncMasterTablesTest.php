<?php

namespace Tests\Feature\Api;

use App\Support\Sync\SyncSequence;
use Illuminate\Support\Str;

/**
 * Every master table the mobile app writes offline must be accepted through
 * /sync/push by its model hooks (P1-9/P1-12 contract), parents first.
 */
class SyncMasterTablesTest extends ApiTestCase
{
    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->registerTenant();
        $this->t['device'] = (string) Str::uuid();
        $this->t['h'] = $this->auth($this->t['token']) + ['X-Device-Id' => $this->t['device']];
        $this->postJson('/api/v1/devices/register', ['device_id' => $this->t['device']], $this->auth($this->t['token']))->assertOk();
    }

    private function op(string $table, array $data, ?string $uuid = null, string $action = 'insert'): array
    {
        return ['op_uuid' => (string) Str::uuid(), 'table' => $table, 'uuid' => $uuid ?? (string) Str::uuid(), 'action' => $action,
            'client_updated_at' => SyncSequence::nowMs(), 'version' => 0, 'data' => $data];
    }

    private function push(array $ops): array
    {
        return $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'master', 'ops' => $ops]]], $this->t['h'])
            ->assertOk()->json('data.results.0');
    }

    public function test_offline_created_catalogue_product_and_restock(): void
    {
        $cat = (string) Str::uuid();
        $sub = (string) Str::uuid();
        $prod = (string) Str::uuid();
        $r = $this->push([
            $this->op('categories', ['name' => 'Drinks'], $cat),
            $this->op('sub_categories', ['name' => 'Sodas', 'category_uuid' => $cat, 'measurement_unit' => 'bottle'], $sub),
            $this->op('products', ['name' => 'Cola 500ml', 'sub_category_uuid' => $sub, 'category_uuid' => $cat, 'buying_price' => '1500', 'selling_price' => '2000', 'original_quantity' => '24', 'sku' => 'COLA-500'], $prod),
            $this->op('stock_movements', ['product_uuid' => $prod, 'type' => 'purchase_receipt', 'quantity' => '12']),
        ]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $rows = $this->getJson('/api/v1/sync/pull?table=products&since_seq=0', $this->t['h'])->json('data.rows');
        $this->assertSame($prod, $rows[0]['uuid']);
        $this->assertSame('36.000', $rows[0]['current_quantity']);
        $this->assertSame($sub, $rows[0]['sub_category_uuid']);

        // Edit only the price from the device.
        $v = (int) $rows[0]['version'];
        $r = $this->push([['op_uuid' => (string) Str::uuid(), 'table' => 'products', 'uuid' => $prod, 'action' => 'update', 'client_updated_at' => SyncSequence::nowMs(), 'version' => $v,
            'changed_fields' => ['selling_price'], 'data' => ['selling_price' => '2500', 'name' => 'ignored because not changed']]]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $row = $this->getJson('/api/v1/stock-items/'.$rows[0]['id'], $this->t['h'])->json('data');
        $this->assertSame('2500.00', $row['selling_price']);
        $this->assertSame('Cola 500ml', $row['name']);
    }

    public function test_offline_budget_program_categories_items_and_pledges(): void
    {
        $prog = (string) Str::uuid();
        $cat = (string) Str::uuid();
        $r = $this->push([
            $this->op('budget_programs', ['name' => 'Wedding', 'status' => 'Active', 'deadline' => '2026-12-12'], $prog),
            $this->op('budget_item_categories', ['name' => 'Venue', 'program_uuid' => $prog], $cat),
            $this->op('budget_items', ['name' => 'Hall hire', 'program_uuid' => $prog, 'category_uuid' => $cat, 'unit_price' => '500000', 'quantity' => '2', 'approved' => 'No']),
            $this->op('contribution_records', ['name' => 'Uncle Joe', 'program_uuid' => $prog, 'amount' => '300000', 'paid_amount' => '100000']),
        ]);
        $this->assertSame('applied', $r['status'], json_encode($r));

        $items = $this->getJson('/api/v1/sync/pull?table=budget_items&since_seq=0', $this->t['h'])->json('data.rows');
        $this->assertSame('1000000', (string) (int) $items[0]['target_amount']);
        $this->assertSame($cat, $items[0]['category_uuid']);
        $pledges = $this->getJson('/api/v1/sync/pull?table=contribution_records&since_seq=0', $this->t['h'])->json('data.rows');
        $this->assertSame(200000, (int) $pledges[0]['not_paid_amount']);
        $cats = $this->getJson('/api/v1/sync/pull?table=budget_item_categories&since_seq=0', $this->t['h'])->json('data.rows');
        $this->assertSame(1000000, (int) $cats[0]['target_amount'], 'category rolled up from its items');
    }

    public function test_offline_finance_category_and_expense(): void
    {
        $cat = (string) Str::uuid();
        $r = $this->push([
            $this->op('financial_categories', ['name' => 'Transport', 'type' => 'Expense'], $cat),
            $this->op('financial_records', ['category_uuid' => $cat, 'amount' => '45000', 'type' => 'Expense', 'payment_method' => 'cash', 'description' => 'Boda to market', 'date' => '2026-09-20']),
        ]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $rows = $this->getJson('/api/v1/sync/pull?table=financial_records&since_seq=0', $this->t['h'])->json('data.rows');
        $this->assertSame('45000.00', $rows[0]['amount']);
        $this->assertSame($cat, $rows[0]['category_uuid']);
        $this->assertNotNull($rows[0]['period_uuid'], 'period derived from the date');

        // Duplicate category name from another device → rejected with a code, not a 500.
        $r = $this->push([$this->op('financial_categories', ['name' => 'transport', 'type' => 'Expense'])]);
        $this->assertSame('rejected', $r['status']);
        $this->assertSame('duplicate_category', $r['ops'][0]['code']);
    }

    public function test_tombstone_from_device(): void
    {
        $cat = (string) Str::uuid();
        $this->push([$this->op('categories', ['name' => 'Temp'], $cat)]);
        $r = $this->push([['op_uuid' => (string) Str::uuid(), 'table' => 'categories', 'uuid' => $cat, 'action' => 'delete', 'client_updated_at' => SyncSequence::nowMs() + 1000, 'version' => 1, 'data' => []]]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertSame(0, count($this->getJson('/api/v1/stock-categories', $this->t['h'])->json('data')));
    }
}
