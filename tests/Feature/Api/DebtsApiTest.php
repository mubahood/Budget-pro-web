<?php

namespace Tests\Feature\Api;

use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Debtors & creditors for the phone (DebtService): list, open sales, receive, adopt; permissions and isolation. */
class DebtsApiTest extends ApiTestCase
{
    private function tenant(): array
    {
        $t = $this->registerTenant();
        $t['device'] = (string) Str::uuid();
        $t['h'] = $this->auth($t['token']) + ['X-Device-Id' => $t['device']];
        $this->postJson('/api/v1/devices/register', ['device_id' => $t['device'], 'name' => 'Till'], $this->auth($t['token']))->assertOk();

        return $t;
    }

    private function member(array $t, string $role): array
    {
        $u = new User();
        $u->forceFill(['name' => ucfirst($role), 'email' => $role.uniqid('', true).'@example.test', 'password' => bcrypt('secret123'), 'status' => 'Active', 'company_id' => $t['company_id']])->save();
        CompanyMember::create(['company_id' => $t['company_id'], 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'joined_at' => now()]);
        Permissions::flush();

        return ['h' => $this->auth($u->createToken('t')->plainTextToken)];
    }

    /** A credit sale under a typed name only (no customer account), as an offline till records it. */
    private function creditSale(array $t, string $name, float $price): void
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $t['h'])->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $t['h'])->json('data.id');
        $uuid = $this->postJson('/api/v1/stock-items', ['name' => 'P'.uniqid(), 'stock_sub_category_id' => $sub, 'selling_price' => $price, 'buying_price' => 1, 'original_quantity' => 10], $t['h'])->json('data.uuid');
        $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [['op_uuid' => (string) Str::uuid(), 'table' => 'sales', 'uuid' => (string) Str::uuid(), 'action' => 'insert',
            'data' => ['occurred_at' => SyncSequence::nowMs(), 'has_payment_ops' => 1, 'customer_name' => $name, 'items' => [['product_uuid' => $uuid, 'quantity' => 1]]]]]]]], $t['h'])
            ->assertOk()->assertJsonPath('data.results.0.status', 'applied');
    }

    public function test_debtors_receive_and_adopt(): void
    {
        $this->getJson('/api/v1/debts')->assertStatus(401);
        $t = $this->tenant();
        $other = $this->tenant();
        $this->creditSale($t, 'Thembo', 3000);
        $this->creditSale($t, 'Thembo', 2000);

        $list = $this->getJson('/api/v1/debts?tab=debtors', $t['h'])->assertOk();
        $row = $list->json('data.0');
        $this->assertSame('n:thembo', $row['key']);
        $this->assertSame('name', $row['type']);
        $this->assertEquals(5000, $row['owed']);
        $this->assertSame(2, $row['sales']);
        $this->assertNotNull($row['oldest']);
        $this->assertEquals(5000, $list->json('meta.total'));
        $this->assertSame([], $this->getJson('/api/v1/debts?q=zzz', $t['h'])->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/debts', $other['h'])->json('data'), 'another shop sees none of them');
        $this->assertSame([], $this->getJson('/api/v1/debts/n%3Athembo/sales', $other['h'])->json('data'));

        $sales = $this->getJson('/api/v1/debts/n%3Athembo/sales', $t['h'])->assertOk();
        $this->assertCount(2, $sales->json('data'));
        $this->assertNotNull($sales->json('data.0.uuid'));
        $this->getJson('/api/v1/debts/sales?key=n:thembo', $t['h'])->assertOk()->assertJsonCount(2, 'data');

        $this->postJson('/api/v1/debts/n%3Athembo/receive', ['amount' => 9000, 'method' => 'cash'], $t['h'])->assertStatus(422)->assertJsonPath('errors.code', 'overpayment');
        $paid = $this->postJson('/api/v1/debts/n%3Athembo/receive', ['amount' => 3500, 'method' => 'cash', 'reference' => 'r1'], $t['h'])->assertOk();
        $this->assertEquals(3500, $paid->json('data.received'));
        $this->assertEquals(1500, $paid->json('data.owed'));

        $viewer = $this->member($t, 'viewer');
        $this->getJson('/api/v1/debts', $viewer['h'])->assertOk();
        $this->postJson('/api/v1/debts/receive', ['key' => 'n:thembo', 'amount' => 100, 'method' => 'cash'], $viewer['h'])->assertStatus(403)->assertJsonPath('errors.permission', 'sell');

        $adopted = $this->postJson('/api/v1/debts/n%3Athembo/adopt', ['name' => 'Thembo Moses', 'phone' => '0772111222'], $t['h'])->assertOk();
        $key = $adopted->json('data.key');
        $this->assertStringStartsWith('c:', $key);
        $this->assertNotNull($adopted->json('data.customer.uuid'));
        $this->assertEquals(1500, $adopted->json('data.customer.balance'));
        $this->postJson('/api/v1/debts/'.rawurlencode($key).'/adopt', ['name' => 'X'], $t['h'])->assertStatus(422)->assertJsonPath('errors.code', 'not_a_name');
        $this->postJson('/api/v1/debts/'.rawurlencode($key).'/receive', ['amount' => 1500, 'method' => 'cash'], $t['h'])->assertOk()->assertJsonPath('data.owed', 0);
        $this->postJson('/api/v1/debts/'.rawurlencode($key).'/receive', ['amount' => 100, 'method' => 'cash'], $other['h'])->assertStatus(404);
    }

    public function test_creditors_tab_lists_suppliers_owed(): void
    {
        $t = $this->tenant();
        DB::table('suppliers')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $t['company_id'], 'name' => 'Mukwano', 'phone' => '0700', 'balance' => 70000, 'is_deleted' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $r = $this->getJson('/api/v1/debts?tab=creditors', $t['h'])->assertOk();
        $this->assertSame('supplier', $r->json('data.0.type'));
        $this->assertEquals(70000, $r->json('data.0.owed'));
        $cashier = $this->member($t, 'cashier');
        $this->getJson('/api/v1/debts?tab=creditors', $cashier['h'])->assertStatus(403);
        $this->getJson('/api/v1/debts?tab=debtors', $cashier['h'])->assertOk();
    }
}
