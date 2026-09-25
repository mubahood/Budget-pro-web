<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Plan C5 / A10 (P3-4, P3-7): roles, invites, permission checks on API, sync and web; plan limits. */
class TeamPermissionsTest extends ApiTestCase
{
    private array $owner;

    private array $oh;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::flush();
        $this->owner = $this->registerTenant();
        $this->oh = $this->auth($this->owner['token']);
    }

    private function inviteLink(array $body): string
    {
        $r = $this->postJson('/api/v1/team/invites', $body, $this->oh)->assertStatus(201);

        return $r->json('data.link');
    }

    /** @return array{token: string, user_id: int, h: array} */
    private function member(string $role, string $phone): array
    {
        $link = $this->inviteLink(['role' => $role, 'phone' => $phone, 'name' => 'Staff']);
        $token = basename($link);
        $r = $this->postJson("/api/v1/invites/{$token}/accept", ['first_name' => 'Staff', 'last_name' => ucfirst($role), 'password' => 'secret123'])->assertStatus(201);
        Permissions::flush();

        return ['token' => $r->json('data.token'), 'user_id' => $r->json('data.user.id'), 'h' => $this->auth($r->json('data.token'))];
    }

    private function product(): array
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $this->oh)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $this->oh)->json('data.id');

        return $this->postJson('/api/v1/stock-items', ['name' => 'Soap '.uniqid(), 'stock_sub_category_id' => $sub, 'selling_price' => 2000, 'buying_price' => 1200, 'original_quantity' => 30], $this->oh)
            ->assertStatus(201)->json('data');
    }

    private function limitPlan(array $limits): void
    {
        $plan = Plan::create(['name' => 'Starter', 'slug' => 'limit-'.uniqid(), 'price' => 10, 'price_ugx' => 30000, 'currency' => 'UGX', 'interval' => 'month',
            'trial_days' => 0, 'is_active' => true, 'is_public' => true, 'sort_order' => 1, 'features' => [], 'limits' => $limits]);
        Subscription::create(['company_id' => $this->owner['company_id'], 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
    }

    public function test_invite_by_whatsapp_accept_and_cashier_limits(): void
    {
        $p = $this->product();
        $cashier = $this->member('cashier', '0772 404 001');
        $this->assertSame('whatsapp', DB::table('message_log')->where('purpose', 'invite')->where('to', '+256772404001')->value('channel'));

        $me = $this->getJson('/api/v1/auth/me', $cashier['h'])->assertOk();
        $me->assertJsonPath('data.role', 'cashier');
        $this->assertEqualsCanonicalizing(['sell', 'refund'], $me->json('data.permissions'));

        // Sells at list price; discounts and price changes need `discount`.
        $sale = $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'cash', 'amount' => 4000]], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 2]]], $cashier['h'])->assertStatus(201)->json('data');
        $this->postJson('/api/v1/sales/checkout', ['items' => [['stock_item_id' => $p['id'], 'quantity' => 1, 'unit_price' => 1500]]], $cashier['h'])
            ->assertStatus(403)->assertJsonPath('errors.permission', 'discount');
        $this->postJson('/api/v1/sales/checkout', ['discount_amount' => 100, 'items' => [['stock_item_id' => $p['id'], 'quantity' => 1]]], $cashier['h'])->assertStatus(403);
        $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'cash', 'amount' => 1500]], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 1, 'unit_price' => 1500]]], $this->oh)->assertStatus(201);

        // Cannot void, add products, adjust stock, see cost, manage the team or settings.
        $this->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'x'], $cashier['h'])->assertStatus(403)->assertJsonPath('errors.code', 'forbidden');
        $this->postJson('/api/v1/stock-items', ['name' => 'Nope'], $cashier['h'])->assertStatus(403)->assertJsonPath('errors.permission', 'manage_products');
        $this->postJson('/api/v1/stock-records', ['stock_item_id' => $p['id'], 'type' => 'Damage', 'quantity' => 1, 'reason' => 'Broken'], $cashier['h'])->assertStatus(403);
        $this->assertArrayNotHasKey('buying_price', $this->getJson("/api/v1/stock-items/{$p['id']}", $cashier['h'])->assertOk()->json('data'));
        $this->assertArrayHasKey('buying_price', $this->getJson("/api/v1/stock-items/{$p['id']}", $this->oh)->json('data'));
        $this->getJson('/api/v1/team', $cashier['h'])->assertStatus(403);
        $this->putJson('/api/v1/company', ['name' => 'Hijack'], $cashier['h'])->assertStatus(403);
        $this->postJson('/api/v1/subscription/checkout', ['plan_id' => 1], $cashier['h'])->assertStatus(403);

        // The link is single-use.
        $this->assertSame(1, DB::table('invites')->where('company_id', $this->owner['company_id'])->where('status', 'accepted')->count());
        $team = $this->getJson('/api/v1/team', $this->oh)->assertOk()->assertJsonCount(2, 'data.members')->assertJsonCount(0, 'data.invites')->json('data.members');
        $this->assertEqualsCanonicalizing(['owner', 'cashier'], array_column($team, 'role'), 'each member shows their real role');
    }

    public function test_role_change_override_deactivate_and_activity(): void
    {
        $p = $this->product();
        $m = $this->member('cashier', '0772404002');

        // Owner removes `refund` from cashiers in this shop only.
        $this->putJson('/api/v1/team/roles/cashier', ['permissions' => ['sell']], $this->oh)->assertOk();
        Permissions::flush();
        $sale = $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'cash', 'amount' => 2000]], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 1]]], $m['h'])->json('data');
        $this->postJson("/api/v1/sales/{$sale['id']}/returns", ['items' => [['sale_item_id' => $sale['sale_record_items'][0]['id'], 'quantity' => 1]]], $m['h'])->assertStatus(403);
        // Billing can never be granted to a non-owner role.
        $this->putJson('/api/v1/team/roles/cashier', ['permissions' => ['sell', 'billing']], $this->oh)->assertOk();
        Permissions::flush();
        $this->assertNotContains('billing', Permissions::of(User::withoutGlobalScopes()->find($m['user_id'])));

        // Promote to stock keeper: may add products now, still no sales.
        $this->patchJson("/api/v1/team/members/{$m['user_id']}", ['role' => 'stock_keeper'], $this->oh)->assertOk();
        Permissions::flush();
        $this->assertTrue(DB::table('admin_role_users')->join('admin_roles', 'admin_roles.id', '=', 'admin_role_users.role_id')->where('user_id', $m['user_id'])->where('slug', 'shop_stock_keeper')->exists());
        $this->postJson('/api/v1/stock-records', ['stock_item_id' => $p['id'], 'type' => 'Stock In', 'quantity' => 5], $m['h'])->assertStatus(201);
        $this->postJson('/api/v1/sales/checkout', ['items' => [['stock_item_id' => $p['id'], 'quantity' => 1]]], $m['h'])->assertStatus(403);

        $activity = $this->getJson("/api/v1/team/members/{$m['user_id']}/activity", $this->oh)->assertOk()->json('data');
        $this->assertContains('sale', array_column($activity, 'type'));
        $this->assertContains('stock', array_column($activity, 'type'));

        // Owner cannot be demoted; deactivation signs the member out everywhere.
        $this->patchJson("/api/v1/team/members/{$this->owner['user_id']}", ['role' => 'viewer'], $this->oh)->assertStatus(422)->assertJsonPath('errors.code', 'owner_role');
        $this->patchJson("/api/v1/team/members/{$m['user_id']}", ['active' => false], $this->oh)->assertOk();
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $m['user_id'])->count());
        Permissions::flush();
        $this->assertSame([], Permissions::of(User::withoutGlobalScopes()->find($m['user_id'])));
    }

    public function test_sync_ops_are_checked_against_the_pushers_role(): void
    {
        $p = $this->product();
        $m = $this->member('cashier', '0772404003');
        $device = (string) Str::uuid();
        $h = $m['h'] + ['X-Device-Id' => $device];
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $m['h'])->assertOk();
        $push = fn (array $ops) => $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => array_map(fn ($o) => $o + ['op_uuid' => (string) Str::uuid(), 'client_updated_at' => SyncSequence::nowMs()], $ops)]]], $h)
            ->assertOk()->json('data.results.0');

        $r = $push([['table' => 'products', 'uuid' => (string) Str::uuid(), 'action' => 'insert', 'data' => ['name' => 'Offline product', 'selling_price' => '10']]]);
        $this->assertSame('rejected', $r['status']);
        $this->assertSame('forbidden', $r['ops'][0]['code']);
        $this->assertSame('manage_products', $r['ops'][0]['permission']);

        $r = $push([['table' => 'stock_movements', 'uuid' => (string) Str::uuid(), 'action' => 'insert', 'data' => ['product_uuid' => $p['uuid'], 'type' => 'damage', 'quantity' => '1', 'occurred_at' => SyncSequence::nowMs()]]]);
        $this->assertSame('adjust', $r['ops'][0]['permission']);

        $sale = (string) Str::uuid();
        $r = $push([
            ['table' => 'sales', 'uuid' => $sale, 'action' => 'insert', 'data' => ['has_payment_ops' => 1, 'occurred_at' => SyncSequence::nowMs(), 'items' => [['product_uuid' => $p['uuid'], 'quantity' => '1']]]],
            ['table' => 'payments', 'uuid' => (string) Str::uuid(), 'action' => 'insert', 'data' => ['sale_uuid' => $sale, 'method' => 'cash', 'amount' => '2000', 'received_at' => SyncSequence::nowMs()]],
        ]);
        $this->assertSame('applied', $r['status'], json_encode($r));
    }

    public function test_plan_limits_on_users_and_products_with_usage_in_me(): void
    {
        $this->limitPlan(['max_users' => 2, 'max_products' => 1]);
        $this->product();
        $this->member('cashier', '0772404004');

        $this->postJson('/api/v1/team/invites', ['role' => 'viewer', 'email' => 'third@example.com'], $this->oh)
            ->assertStatus(422)->assertJsonPath('errors.code', 'plan_limit_reached')->assertJsonPath('errors.max', 2);
        $sub = DB::table('stock_sub_categories')->where('company_id', $this->owner['company_id'])->value('id');
        $this->postJson('/api/v1/stock-items', ['name' => 'Second', 'stock_sub_category_id' => $sub, 'selling_price' => 5], $this->oh)
            ->assertStatus(422)->assertJsonPath('errors.limit', 'max_products');

        $usage = $this->getJson('/api/v1/auth/me', $this->oh)->json('data.entitlements.usage');
        $this->assertSame(['used' => 2, 'max' => 2, 'over' => false], $usage['users']);
        $this->assertSame(1, $usage['products']['used']);
    }

    public function test_transfer_ownership_and_invite_edge_cases(): void
    {
        $m = $this->member('manager', '0772404005');
        $this->postJson('/api/v1/team/transfer-ownership', ['user_id' => $m['user_id'], 'password' => 'wrong'], $this->oh)->assertStatus(422)->assertJsonPath('errors.code', 'wrong_password');
        $this->postJson('/api/v1/team/transfer-ownership', ['user_id' => $m['user_id'], 'password' => 'secret123'], $this->oh)->assertOk();
        Permissions::flush();
        $this->assertSame('owner', Permissions::roleOf(User::withoutGlobalScopes()->find($m['user_id'])));
        $this->assertSame('manager', Permissions::roleOf(User::withoutGlobalScopes()->find($this->owner['user_id'])));
        $this->getJson('/api/v1/team', $this->oh)->assertStatus(403); // old owner is now a manager

        // New owner: an existing account elsewhere cannot be invited; bad roles/phones are refused; expired links die.
        $other = $this->registerTenant(['phone_number' => '0772404099']);
        $this->postJson('/api/v1/team/invites', ['role' => 'cashier', 'phone' => '0772404099'], $m['h'])->assertStatus(422)->assertJsonPath('errors.code', 'already_member');
        $this->postJson('/api/v1/team/invites', ['role' => 'owner', 'phone' => '0772404098'], $m['h'])->assertStatus(422)->assertJsonPath('errors.code', 'invalid_role');
        $this->postJson('/api/v1/team/invites', ['role' => 'cashier', 'phone' => '123'], $m['h'])->assertStatus(422)->assertJsonPath('errors.code', 'invalid_phone');
        $link = $this->postJson('/api/v1/team/invites', ['role' => 'cashier', 'email' => 'late@example.com'], $m['h'])->assertStatus(201)->json('data.link');
        $this->get('/invite/'.basename($link))->assertOk()->assertSee('Join the team');
        $this->travel(8)->days();
        $this->getJson('/api/v1/invites/'.basename($link))->assertStatus(404)->assertJsonPath('errors.code', 'invite_invalid');
        $this->get('/invite/'.basename($link))->assertOk()->assertSee('This invite has expired');
        $this->assertNotNull($other['token']);
    }

    public function test_web_invite_page_accepts_and_revoked_invites_stop_working(): void
    {
        $link = $this->inviteLink(['role' => 'accountant', 'email' => 'acc@example.com']);
        $token = basename($link);
        $this->post('/invite/'.$token, ['first_name' => 'Ann', 'last_name' => 'Acc', 'password' => 'secret123', 'password_confirmation' => 'secret123'])
            ->assertOk()->assertSee('You joined as');
        $user = User::withoutGlobalScopes()->where('email', 'acc@example.com')->first();
        $this->assertSame('accountant', Permissions::roleOf($user));
        $this->assertNotNull($user->email_verified_at);

        $id = $this->postJson('/api/v1/team/invites', ['role' => 'viewer', 'email' => 'v@example.com'], $this->oh)->json('data.invite.id');
        $this->postJson("/api/v1/team/invites/{$id}/resend", [], $this->oh)->assertOk();
        $this->assertSame(2, (int) DB::table('invites')->where('id', $id)->value('sent_count'));
        $this->deleteJson("/api/v1/team/invites/{$id}", [], $this->oh)->assertOk();
        $this->assertSame('revoked', DB::table('invites')->where('id', $id)->value('status'));
    }

    public function test_staff_from_before_roles_keep_manager_access(): void
    {
        $company = Company::withoutGlobalScopes()->find($this->owner['company_id']);
        $legacy = new User();
        $legacy->forceFill(['name' => 'Old Staff', 'first_name' => 'Old', 'last_name' => 'Staff', 'username' => 'old'.uniqid().'@x.com', 'email' => 'old'.uniqid().'@x.com', 'password' => bcrypt('x'), 'company_id' => $company->id])->save();
        DB::table('company_members')->where('user_id', $legacy->id)->delete();
        $this->assertSame('manager', Permissions::roleOf($legacy));
        $this->assertFalse(Permissions::can($legacy, 'billing'));
        $this->assertTrue(Permissions::can($legacy, 'void'));
    }
}
