<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Shop\LocationStock;
use App\Services\Shop\TransferService;
use App\Services\Team\Permissions;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse to stores (G2) for the phone: stock requests (ask → approve → send → receive, cancel) and transfers
 * in transit, through StockRequestService / TransferService, the same flow as the web Transfers screen.
 */
class StockRequestsApiTest extends ApiTestCase
{
    private array $t;

    private array $h;

    private int $main;

    private int $branch;

    protected function setUp(): void
    {
        parent::setUp();
        LocationStock::flush();
        $this->t = $this->registerTenant();
        $this->h = $this->auth($this->t['token']);
        $plan = Plan::create(['name' => 'Business', 'slug' => 'biz-'.uniqid(), 'price' => 49, 'price_ugx' => 185000, 'currency' => 'UGX', 'interval' => 'month', 'trial_days' => 0,
            'is_active' => true, 'is_public' => true, 'sort_order' => 3, 'features' => ['multi_location' => true], 'limits' => ['max_locations' => 3]]);
        Subscription::create(['company_id' => $this->t['company_id'], 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        $this->main = LocationStock::defaultLocation((int) $this->t['company_id']);
        $this->branch = (int) $this->postJson('/api/v1/locations', ['name' => 'Branch'], $this->h)->assertStatus(201)->json('data.id');
    }

    private function product(array $h, float $qty = 20): int
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $h)->json('data.id');

        return (int) $this->postJson('/api/v1/stock-items', ['name' => 'Soda '.uniqid(), 'stock_sub_category_id' => $sub, 'selling_price' => 1000, 'buying_price' => 600, 'original_quantity' => $qty], $h)
            ->assertStatus(201)->json('data.id');
    }

    private function member(string $role, ?int $location = null): array
    {
        $u = new User();
        $u->forceFill(['name' => ucfirst($role), 'email' => $role.uniqid('', true).'@example.test', 'password' => bcrypt('secret123'), 'status' => 'Active', 'company_id' => $this->t['company_id']])->save();
        CompanyMember::create(['company_id' => $this->t['company_id'], 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'joined_at' => now()]);
        if ($location !== null) {
            DB::table('company_members')->where('company_id', $this->t['company_id'])->where('user_id', $u->id)->update(['location_id' => $location]);
        }
        Permissions::flush();

        return $this->auth($u->createToken('t')->plainTextToken);
    }

    private function ask(int $p, float $qty = 5, ?array $h = null): array
    {
        return $this->postJson('/api/v1/stock-requests', ['from_location_id' => $this->main, 'to_location_id' => $this->branch, 'lines' => [['stock_item_id' => $p, 'quantity' => $qty]], 'note' => 'Weekend'], $h ?? $this->h)
            ->assertStatus(201)->json('data');
    }

    public function test_ask_approve_send_and_receive_part_of_it(): void
    {
        $p = $this->product($this->h);
        $r = $this->ask($p);
        $this->assertSame('requested', $r['request']['status']);
        $this->assertSame('Weekend', $r['request']['notes']);
        $id = (int) $r['request']['id'];
        $this->getJson('/api/v1/stock-requests?status=requested', $this->h)->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.lines', 1)->assertJsonPath('meta.total', 1);

        $this->postJson("/api/v1/stock-requests/{$id}/approve", ['lines' => [['stock_item_id' => $p, 'quantity' => 4]]], $this->h)->assertOk()->assertJsonPath('data.request.status', 'approved');
        $sent = $this->postJson("/api/v1/stock-requests/{$id}/send", [], $this->h)->assertOk()->assertJsonPath('data.request.status', 'sent')->json('data');
        $transfer = (int) $sent['request']['stock_transfer_id'];
        $this->assertEquals(4, $sent['items'][0]['sent_quantity'], 'sends the approved quantity');
        $this->assertEquals(16.0, LocationStock::level($this->main, $p));
        $this->assertEquals(0.0, LocationStock::level($this->branch, $p), 'in transit: on neither shelf');

        $transit = $this->getJson('/api/v1/stock-transfers?status=in_transit', $this->h)->assertOk()->json('data');
        $this->assertCount(1, $transit);
        $this->assertSame($transfer, $transit[0]['id']);
        $this->assertEquals(4, $transit[0]['items'][0]['quantity']);
        $this->assertEquals(4.0, (new TransferService())->inTransit((int) $this->t['company_id'], $this->branch)[$p]);
        $this->getJson('/api/v1/stock-transfers', $this->h)->assertOk()->assertJsonStructure(['data' => [['id', 'number', 'created_at', 'from', 'to', 'notes']]]);

        $this->postJson("/api/v1/stock-transfers/{$transfer}/receive", ['lines' => [['stock_item_id' => $p, 'received_quantity' => 3]]], $this->h)
            ->assertOk()->assertJsonPath('data.transfer.status', 'received')->assertJsonPath('message', 'Received. The stock is on the shelf at the store.');
        $this->assertEquals(3.0, LocationStock::level($this->branch, $p));
        $done = $this->getJson("/api/v1/stock-requests/{$id}", $this->h)->assertOk()->json('data');
        $this->assertSame('received', $done['request']['status']);
        $this->assertEquals(3, $done['items'][0]['received_quantity']);
        $this->getJson('/api/v1/stock-transfers?status=in_transit', $this->h)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/stock-transfers/{$transfer}/receive", [], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'request_status');
    }

    public function test_cancel_and_the_status_rules(): void
    {
        $p = $this->product($this->h);
        $id = (int) $this->ask($p)['request']['id'];
        $this->postJson("/api/v1/stock-requests/{$id}/cancel", [], $this->h)->assertOk()->assertJsonPath('data.request.status', 'cancelled');
        $this->postJson("/api/v1/stock-requests/{$id}/approve", [], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'request_status');

        $id2 = (int) $this->ask($p)['request']['id'];
        $this->postJson("/api/v1/stock-requests/{$id2}/send", [], $this->h)->assertOk();
        $this->postJson("/api/v1/stock-requests/{$id2}/cancel", [], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'request_sent');

        $this->postJson('/api/v1/stock-requests', ['from_location_id' => $this->main, 'to_location_id' => $this->main, 'lines' => [['stock_item_id' => $p, 'quantity' => 1]]], $this->h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'same_location');
        $this->postJson('/api/v1/stock-requests', ['from_location_id' => $this->main, 'to_location_id' => $this->branch, 'lines' => []], $this->h)->assertStatus(422);
    }

    public function test_a_transfer_sent_without_a_request_is_received_whole(): void
    {
        $p = $this->product($this->h, 10);
        $id = (new TransferService())->send((int) $this->t['company_id'], (int) $this->t['user_id'], $this->main, $this->branch, [['stock_item_id' => $p, 'quantity' => 6]]);
        $this->getJson("/api/v1/stock-transfers/{$id}", $this->h)->assertOk()->assertJsonPath('data.transfer.status', 'in_transit')->assertJsonPath('data.items.0.stock_item_id', $p);
        $this->postJson("/api/v1/stock-transfers/{$id}/receive", [], $this->h)->assertOk()->assertJsonPath('data.transfer.status', 'received');
        $this->assertEquals(6.0, LocationStock::level($this->branch, $p));
        $this->assertEquals(4.0, LocationStock::level($this->main, $p));
    }

    public function test_auth_permissions_and_tenant_isolation(): void
    {
        $p = $this->product($this->h);
        $id = (int) $this->ask($p)['request']['id'];
        $this->getJson('/api/v1/stock-requests')->assertStatus(401);
        $this->postJson('/api/v1/stock-transfers/1/receive', [])->assertStatus(401);

        $cashier = $this->member('cashier');
        $this->getJson('/api/v1/stock-requests', $cashier)->assertOk(); // reads are open, as for transfers
        $this->postJson('/api/v1/stock-requests', ['from_location_id' => $this->main, 'to_location_id' => $this->branch, 'lines' => [['stock_item_id' => $p, 'quantity' => 1]]], $cashier)
            ->assertStatus(403)->assertJsonPath('errors.permission', 'adjust');
        foreach (['approve', 'send', 'cancel'] as $step) {
            $this->postJson("/api/v1/stock-requests/{$id}/{$step}", [], $cashier)->assertStatus(403);
        }
        $keeper = $this->member('stock_keeper');
        $sent = $this->postJson("/api/v1/stock-requests/{$id}/send", [], $keeper)->assertOk()->json('data.request.stock_transfer_id');
        $this->postJson("/api/v1/stock-transfers/{$sent}/receive", [], $cashier)->assertStatus(403);

        $other = $this->registerTenant();
        $oh = $this->auth($other['token']);
        $this->getJson("/api/v1/stock-requests/{$id}", $oh)->assertStatus(404);
        $this->getJson('/api/v1/stock-requests', $oh)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/stock-requests/{$id}/cancel", [], $oh)->assertStatus(404);
        $this->getJson("/api/v1/stock-transfers/{$sent}", $oh)->assertStatus(404);
        $this->postJson("/api/v1/stock-transfers/{$sent}/receive", [], $oh)->assertStatus(404);
        $this->postJson('/api/v1/stock-requests', ['from_location_id' => $this->main, 'to_location_id' => $this->branch, 'lines' => [['stock_item_id' => $p, 'quantity' => 1]]], $oh)
            ->assertStatus(422); // another shop's locations
        $this->assertSame('sent', DB::table('stock_requests')->where('id', $id)->value('status'));
    }

    public function test_a_store_member_works_for_their_own_store_only(): void
    {
        StoreFeatures::update(Company::find($this->t['company_id']), ['features' => ['store_scoping' => true]]);
        $p = $this->product($this->h);
        $third = (int) $this->postJson('/api/v1/locations', ['name' => 'Third'], $this->h)->assertStatus(201)->json('data.id');
        $branchKeeper = $this->member('stock_keeper', $this->branch);

        $mine = (int) $this->ask($p, 2, $branchKeeper)['request']['id'];
        $this->postJson('/api/v1/stock-requests', ['from_location_id' => $this->main, 'to_location_id' => $third, 'lines' => [['stock_item_id' => $p, 'quantity' => 1]]], $branchKeeper)
            ->assertStatus(422)->assertJsonPath('errors.code', 'other_store');
        $this->postJson("/api/v1/stock-requests/{$mine}/approve", [], $branchKeeper)->assertStatus(422)->assertJsonPath('errors.code', 'other_store');

        $theirs = (int) $this->postJson('/api/v1/stock-requests', ['from_location_id' => $this->main, 'to_location_id' => $third, 'lines' => [['stock_item_id' => $p, 'quantity' => 1]]], $this->h)
            ->assertStatus(201)->json('data.request.id');
        $this->getJson("/api/v1/stock-requests/{$theirs}", $branchKeeper)->assertStatus(404);
        $this->assertSame([$mine], array_column($this->getJson('/api/v1/stock-requests', $branchKeeper)->assertOk()->json('data'), 'id'));

        $sent = (int) $this->postJson("/api/v1/stock-requests/{$mine}/send", [], $this->h)->assertOk()->json('data.request.stock_transfer_id');
        $this->postJson("/api/v1/stock-transfers/{$sent}/receive", [], $branchKeeper)->assertOk();
        $this->assertEquals(2.0, LocationStock::level($this->branch, $p));
    }
}
