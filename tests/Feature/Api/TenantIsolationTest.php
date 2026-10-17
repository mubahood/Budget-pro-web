<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\V1\BaseCrudController;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\DeviceCommand;
use App\Models\Plan;
use App\Models\StockCategory;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SyncConflict;
use App\Models\TrackedDevice;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Services\Shop\LocationStock;
use App\Services\Shop\TransferService;
use App\Services\Team\Permissions;
use App\Support\LocalDate;
use App\Support\StoreFeatures;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The crown-jewel tests: one tenant must never read or write another tenant's data.
 *
 * Company A has one row of everything; company B (every module on, supermarket mode on) tries to reach each
 * of them through every apiCrud resource and the custom {id} routes of each route group. Every attempt is a
 * 404 (MOBILE_API_CONTRACT: "an id from another shop answers 404, never its data"), lists never show A's
 * rows, and A's rows are untouched afterwards. The v1 poultry sync has its own cross-tenant test
 * (PoultrySyncTest::test_cross_tenant_push_and_pull_isolation), as do debts (DebtsApiTest) and Ping Pin's
 * organisations (tests/Feature/PingPin/TenantIsolationTest).
 */
class TenantIsolationTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // One company makes a few hundred calls here; the per-user rate limit is not what these tests are about.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_tenant_cannot_read_update_or_delete_another_tenants_record(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();

        // Tenant A creates a stock category.
        $created = $this->postJson('/api/v1/stock-categories', ['name' => 'A-only'], $this->auth($a['token']));
        $created->assertStatus(201);
        $id = $created->json('data.id');

        // Tenant B must not see it.
        $this->getJson("/api/v1/stock-categories/{$id}", $this->auth($b['token']))->assertStatus(404);
        $this->putJson("/api/v1/stock-categories/{$id}", ['name' => 'hacked'], $this->auth($b['token']))->assertStatus(404);
        $this->deleteJson("/api/v1/stock-categories/{$id}", [], $this->auth($b['token']))->assertStatus(404);

        // The record is untouched for tenant A.
        $this->getJson("/api/v1/stock-categories/{$id}", $this->auth($a['token']))
            ->assertOk()->assertJsonPath('data.name', 'A-only');
    }

    public function test_list_is_scoped_to_own_company(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();

        $this->postJson('/api/v1/stock-categories', ['name' => 'Cat A1'], $this->auth($a['token']))->assertStatus(201);
        $this->postJson('/api/v1/stock-categories', ['name' => 'Cat A2'], $this->auth($a['token']))->assertStatus(201);

        $listA = $this->getJson('/api/v1/stock-categories', $this->auth($a['token']));
        $listA->assertOk();
        $this->assertSame(2, $listA->json('meta.total'));

        $listB = $this->getJson('/api/v1/stock-categories', $this->auth($b['token']));
        $listB->assertOk();
        $this->assertSame(0, $listB->json('meta.total'));
    }

    public function test_mass_assignment_of_company_and_creator_is_ignored(): void
    {
        $a = $this->registerTenant();

        $res = $this->postJson('/api/v1/stock-categories', [
            'name' => 'Guarded',
            'company_id' => 999999,       // attempt to plant in another tenant
            'created_by_id' => 888888,    // attempt to forge creator
            'earned_profit' => 500000,    // attempt to set a computed rollup
        ], $this->auth($a['token']));

        $res->assertStatus(201);
        $this->assertSame($a['company_id'], $res->json('data.company_id'));
    }

    public function test_cannot_reference_another_tenants_foreign_key(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();

        // A creates a category; B tries to attach a sub-category to A's category.
        $catA = $this->postJson('/api/v1/stock-categories', ['name' => 'A cat'], $this->auth($a['token']))->json('data.id');

        $this->postJson('/api/v1/stock-sub-categories', [
            'name' => 'Sneaky', 'stock_category_id' => $catA,
        ], $this->auth($b['token']))->assertStatus(422); // exists rule is company-scoped
    }

    public function test_a_controller_that_forgets_the_company_filter_is_still_narrowed_by_the_scope(): void
    {
        // Probes behind the real tenant stack, written the careless way: no company filter at all.
        $stack = ['api', 'auth:sanctum', 'api.tenant', 'api.module:core'];
        Route::middleware($stack)->get('api/v1/_probe/forgot', fn () => response()->json(StockCategory::query()->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()));
        Route::middleware($stack)->get('api/v1/_probe/stream', fn () => response()->streamDownload(function () {
            echo json_encode(StockCategory::query()->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values());
        }, 'probe.json'));
        Route::middleware($stack)->get('api/v1/_probe/explicit', fn () => response()->json(StockCategory::withoutGlobalScopes()->pluck('company_id')->map(fn ($id) => (int) $id)->unique()->values()));
        Route::getRoutes()->refreshNameLookups();

        $a = $this->registerTenant();
        $b = $this->registerTenant();
        $this->postJson('/api/v1/stock-categories', ['name' => 'A only'], $this->auth($a['token']))->assertStatus(201);
        $this->postJson('/api/v1/stock-categories', ['name' => 'B only'], $this->auth($b['token']))->assertStatus(201);

        $this->assertSame([$a['company_id']], $this->getJson('/api/v1/_probe/forgot', $this->auth($a['token']))->assertOk()->json());
        $this->assertSame([$b['company_id']], $this->getJson('/api/v1/_probe/forgot', $this->auth($b['token']))->assertOk()->json());
        $this->assertSame([$a['company_id']], json_decode($this->download('/api/v1/_probe/stream', $this->auth($a['token'])), true), 'a streamed body is narrowed too');
        $this->assertNull(\App\Scopes\CompanyScope::requestCompanyId(), 'and forgotten once written');
        // Crossing companies takes an explicit withoutGlobalScopes(), visible in review.
        $this->assertContains($b['company_id'], $this->getJson('/api/v1/_probe/explicit', $this->auth($a['token']))->assertOk()->json());
    }

    // ── Every route group: company A's rows, company B's attempts ──────────────────────────────

    /** @return array{t: array, h: array} a registered tenant with every module on and supermarket mode on */
    private function shop(): array
    {
        $t = $this->registerTenant();
        StoreFeatures::update(Company::withoutGlobalScopes()->findOrFail($t['company_id']), ['mode' => true]);

        return ['t' => $t, 'h' => $this->auth($t['token'])];
    }

    /** @return array{0: int, 1: string} [id, uuid] of a new product of the tenant */
    private function product(array $h, string $name, float $qty = 50): array
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'Cat '.$name], $h)->assertStatus(201)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'Sub '.$name, 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $h)->assertStatus(201)->json('data.id');
        $item = $this->postJson('/api/v1/stock-items', ['name' => $name, 'stock_sub_category_id' => $sub, 'selling_price' => 1000, 'buying_price' => 600, 'original_quantity' => $qty], $h)
            ->assertStatus(201)->json('data');

        return [(int) $item['id'], (string) $item['uuid']];
    }

    /** A 404, never the row: the contract for another shop's id. */
    private function assertNotFound(TestResponse $res, string $what): void
    {
        $this->assertSame(404, $res->status(), "{$what} must be a 404 for another company, got {$res->status()}: ".mb_substr((string) $res->getContent(), 0, 300));
    }

    /**
     * A refusal that does not depend on whose row it is (an immutable resource, an idempotent discard) is
     * allowed instead of the 404, when it is exactly what a row that does not exist gets: no oracle, no write.
     */
    private function assertRefusedLikeAMissingRow(string $method, string $uri, string $missingUri, array $body, array $h, string $what): void
    {
        $res = $this->json($method, '/api/v1/'.$uri, $body, $h);
        if ($res->status() === 404) {
            return;
        }
        $missing = $this->json($method, '/api/v1/'.$missingUri, $body, $h);
        $this->assertSame([$missing->status(), $missing->json('errors.code'), $missing->json('message')], [$res->status(), $res->json('errors.code'), $res->json('message')],
            "{$what}: another company's row must answer like a row that does not exist (got {$res->status()}: ".mb_substr((string) $res->getContent(), 0, 200).')');
        $this->assertNull($res->json('data'), "{$what} returns no data");
    }

    /** A file response (CSV or PDF) for the token, read whole; guards are forgotten first as json() does. */
    private function download(string $uri, array $h): string
    {
        $this->app['auth']->forgetGuards();
        $res = $this->get($uri, $h)->assertOk();

        return $res->baseResponse instanceof StreamedResponse ? (string) $res->streamedContent() : (string) $res->getContent();
    }

    /** @return array<int, int> the ids in a list response (data, data.data or data.items) */
    private function ids(TestResponse $res): array
    {
        $data = $res->json('data');
        $rows = is_array($data) && array_is_list($data) ? $data : ($data['data'] ?? $data['items'] ?? $data['rows'] ?? []);

        return array_values(array_map('intval', array_column(is_array($rows) ? $rows : [], 'id')));
    }

    /** @return array<string, class-string> every apiCrud resource, from the routes: uri => controller */
    private function crudResources(): array
    {
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $controller = $route->getControllerClass();
            if (preg_match('#^api/v1/([a-z-]+)/options$#', $route->uri(), $m) && $controller && is_subclass_of($controller, BaseCrudController::class)) {
                $out[$m[1]] = $controller;
            }
        }
        ksort($out);

        return $out;
    }

    public function test_no_crud_resource_shows_lists_changes_or_deletes_another_companys_row(): void
    {
        ['t' => $a, 'h' => $ha] = $this->shop();
        [$item] = $this->product($ha, 'A secret soap');
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'A secret category'], $ha)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'A secret shelf', 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $ha)->json('data.id');
        $customer = $this->postJson('/api/v1/customers', ['name' => 'A secret customer', 'phone' => '0772 600 701'], $ha)->assertStatus(201)->json('data.id');
        $supplier = $this->postJson('/api/v1/suppliers', ['name' => 'A secret supplier'], $ha)->assertStatus(201)->json('data.id');
        $shift = $this->postJson('/api/v1/shifts/open', ['opening_float' => 10000], $ha)->assertStatus(201)->json('data.id');
        $sale = $this->postJson('/api/v1/sales/checkout', ['shift_id' => $shift, 'customer_id' => $customer, 'payments' => [['method' => 'cash', 'amount' => 1000]],
            'items' => [['stock_item_id' => $item, 'quantity' => 1]]], $ha)->assertStatus(201)->json('data.id');
        $fcat = $this->postJson('/api/v1/financial-categories', ['name' => 'A secret rent'], $ha)->assertStatus(201)->json('data.id');
        $program = $this->postJson('/api/v1/budget-programs', ['name' => 'A secret wedding'], $ha)->assertStatus(201)->json('data.id');
        $bcat = $this->postJson('/api/v1/budget-item-categories', ['name' => 'A secret venue', 'budget_program_id' => $program], $ha)->assertStatus(201)->json('data.id');

        $rows = [
            'stock-categories' => $cat,
            'stock-sub-categories' => $sub,
            'stock-items' => $item,
            'stock-records' => $this->postJson('/api/v1/stock-records', ['stock_item_id' => $item, 'type' => 'Damage', 'quantity' => 1, 'reason' => 'damage'], $ha)->assertStatus(201)->json('data.id'),
            'units' => $this->postJson('/api/v1/units', ['name' => 'A secret box', 'abbreviation' => 'asb'], $ha)->assertStatus(201)->json('data.id'),
            'product-barcodes' => $this->postJson('/api/v1/product-barcodes', ['stock_item_id' => $item, 'barcode' => 'ASECRET'.random_int(1000, 9999)], $ha)->assertStatus(201)->json('data.id'),
            'customers' => $customer,
            'suppliers' => $supplier,
            'shifts' => $shift,
            'sales' => $sale,
            'stock-takes' => $this->postJson('/api/v1/stock-takes', ['name' => 'A secret count'], $ha)->assertStatus(201)->json('data.id'),
            'goods-receipts' => $this->postJson('/api/v1/goods-receipts', ['items' => [['stock_item_id' => $item, 'quantity' => 2, 'unit_cost' => 500]]], $ha)->assertStatus(201)->json('data.id'),
            'purchase-orders' => $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $supplier, 'items' => [['stock_item_id' => $item, 'quantity' => 3]]], $ha)->assertStatus(201)->json('data.id'),
            'purchase-returns' => $this->postJson('/api/v1/purchase-returns', ['supplier_id' => $supplier, 'reason' => 'A secret return', 'items' => [['stock_item_id' => $item, 'quantity' => 1, 'unit_cost' => 600]]], $ha)
                ->assertStatus(201)->json('data.id'),
            'financial-categories' => $fcat,
            'financial-periods' => $this->postJson('/api/v1/financial-periods', ['name' => 'A secret year', 'start_date' => '2031-01-01', 'end_date' => '2031-12-31', 'status' => 'Inactive'], $ha)->assertStatus(201)->json('data.id'),
            'financial-records' => $this->postJson('/api/v1/financial-records', ['financial_category_id' => $fcat, 'type' => 'Income', 'amount' => 5000], $ha)->assertStatus(201)->json('data.id'),
            'financial-reports' => $this->postJson('/api/v1/financial-reports', ['type' => 'Financial', 'period_type' => 'Month', 'do_generate' => 'No'], $ha)->assertStatus(201)->json('data.id'),
            'budget-programs' => $program,
            'budget-item-categories' => $bcat,
            'budget-items' => $this->postJson('/api/v1/budget-items', ['name' => 'A secret hall', 'budget_item_category_id' => $bcat, 'unit_price' => 1000, 'quantity' => 1], $ha)->assertStatus(201)->json('data.id'),
            'contribution-records' => $this->postJson('/api/v1/contribution-records', ['budget_program_id' => $program, 'name' => 'A secret guest', 'amount' => 1000], $ha)->assertStatus(201)->json('data.id'),
        ];
        $covered = $rows;
        ksort($covered);
        $this->assertSame(array_keys($this->crudResources()), array_keys($covered), 'every apiCrud resource has a row of company A');

        ['h' => $hb] = $this->shop();
        foreach ($rows as $uri => $id) {
            $before = $this->getJson("/api/v1/{$uri}/{$id}", $ha)->assertOk()->json('data');
            $label = collect($this->getJson("/api/v1/{$uri}/options?limit=100", $ha)->json('data'))->firstWhere('id', $id)['text'] ?? '';

            $this->assertNotFound($this->getJson("/api/v1/{$uri}/{$id}", $hb), "GET {$uri}/{id}");
            $this->assertRefusedLikeAMissingRow('PUT', "{$uri}/{$id}", "{$uri}/2147483647", ['name' => 'B was here', 'amount' => 1, 'notes' => 'B'], $hb, "PUT {$uri}/{id}");
            $this->assertRefusedLikeAMissingRow('PATCH', "{$uri}/{$id}", "{$uri}/2147483647", ['name' => 'B was here'], $hb, "PATCH {$uri}/{id}");
            $this->assertRefusedLikeAMissingRow('DELETE', "{$uri}/{$id}", "{$uri}/2147483647", [], $hb, "DELETE {$uri}/{id}");

            $this->assertNotContains($id, $this->ids($this->getJson("/api/v1/{$uri}?per_page=100", $hb)->assertOk()), "{$uri} index");
            $this->assertNotContains($id, $this->ids($this->getJson("/api/v1/{$uri}/search?limit=50&q=".urlencode((string) $label), $hb)->assertOk()), "{$uri}/search");
            $this->assertNotContains($id, $this->ids($this->getJson("/api/v1/{$uri}/options?limit=100&q=".urlencode((string) $label), $hb)->assertOk()), "{$uri}/options");

            $this->assertEquals($before, $this->getJson("/api/v1/{$uri}/{$id}", $ha)->assertOk()->json('data'), "A's {$uri} row is untouched");
        }
    }

    public function test_shop_record_routes_by_id_never_reach_another_companys_rows(): void
    {
        Http::fake();
        ['t' => $a, 'h' => $ha] = $this->shop();
        [$item, $itemUuid] = $this->product($ha, 'A secret milk');
        $barcode = 'ASEC'.random_int(100000, 999999);
        $this->postJson('/api/v1/product-barcodes', ['stock_item_id' => $item, 'barcode' => $barcode], $ha)->assertStatus(201);
        $customer = $this->postJson('/api/v1/customers', ['name' => 'A secret customer', 'phone' => '0772 600 702'], $ha)->assertStatus(201)->json('data');
        $supplier = $this->postJson('/api/v1/suppliers', ['name' => 'A secret supplier'], $ha)->json('data.id');
        $shift = $this->postJson('/api/v1/shifts/open', ['opening_float' => 10000], $ha)->assertStatus(201)->json('data.id');
        $sale = $this->postJson('/api/v1/sales/checkout', ['shift_id' => $shift, 'customer_id' => $customer['id'], 'payments' => [['method' => 'cash', 'amount' => 2000]],
            'items' => [['stock_item_id' => $item, 'quantity' => 2]]], $ha)->assertStatus(201)->json('data');
        $record = $this->postJson('/api/v1/stock-records', ['stock_item_id' => $item, 'type' => 'Damage', 'quantity' => 1, 'reason' => 'damage'], $ha)->json('data.id');
        $take = $this->postJson('/api/v1/stock-takes', ['name' => 'A secret count'], $ha)->json('data.id');
        $po = $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $supplier, 'items' => [['stock_item_id' => $item, 'quantity' => 3]]], $ha)->json('data.id');
        $momo = DB::table('momo_requests')->insertGetId(['company_id' => $a['company_id'], 'phone' => '256772600702', 'amount' => 1000, 'currency' => 'UGX', 'tx_ref' => 'MOMO-'.Str::random(10),
            'created_at' => now(), 'updated_at' => now()]);

        ['t' => $b, 'h' => $hb] = $this->shop();
        $bShift = $this->postJson('/api/v1/shifts/open', ['opening_float' => 0], $hb)->assertStatus(201)->json('data.id');
        $calls = [
            ['GET', "customers/{$customer['id']}/summary", []], ['GET', "customers/{$customer['uuid']}/summary", []],
            ['GET', "customers/{$customer['id']}/statement.pdf", []], ['GET', "customers/{$customer['id']}/statement", []],
            ['POST', "customers/{$customer['id']}/payments", ['amount' => 100, 'method' => 'cash', 'shift_id' => $bShift]],
            ['POST', "customers/{$customer['id']}/remind", []], ['POST', "customers/{$customer['uuid']}/remind", []],
            ['GET', "stock-items/{$item}/summary", []], ['GET', "stock-items/{$itemUuid}/summary", []],
            ['GET', "stock-items/{$item}/supplier-prices", []], ['GET', 'stock-items/by-barcode/'.$barcode, []],
            ['POST', "stock-records/{$record}/reverse", ['reason' => 'B']],
            ['POST', "sales/{$sale['id']}/payments", ['amount' => 100, 'method' => 'cash', 'shift_id' => $bShift]],
            ['POST', "sales/{$sale['id']}/void", ['reason' => 'B']],
            ['POST', "sales/{$sale['id']}/returns", ['items' => [['stock_item_id' => $item, 'quantity' => 1]], 'reason' => 'B', 'refund_method' => 'cash', 'shift_id' => $bShift]],
            ['POST', "sales/{$sale['id']}/send-receipt", ['phone' => '0772000001']], ['POST', "sales/{$sale['uuid']}/send-receipt", ['phone' => '0772000001']],
            ['POST', "sales/{$sale['id']}/momo-request", ['phone' => '0772000001']],
            ['GET', "sales/{$sale['id']}/receipt.txt", []], ['GET', "sales/{$sale['id']}/receipt.pdf", []],
            ['GET', "momo-requests/{$momo}", []],
            ['GET', "suppliers/{$supplier}/statement", []], ['POST', "suppliers/{$supplier}/payments", ['amount' => 100, 'method' => 'cash']],
            ['GET', "suppliers/{$supplier}/prices", []], ['GET', "suppliers/{$supplier}/scorecard", []],
            ['POST', "shifts/{$shift}/close", ['closing_cash' => 0, 'counted_cash' => 0]],
            ['POST', "stock-takes/{$take}/counts", ['counts' => [['stock_item_id' => $item, 'counted_quantity' => 1]]]],
            ['POST', "stock-takes/{$take}/recount", []], ['POST', "stock-takes/{$take}/post", []], ['POST', "stock-takes/{$take}/cancel", []],
            ['POST', "purchase-orders/{$po}/send", []], ['POST', "purchase-orders/{$po}/receive", ['items' => [['stock_item_id' => $item, 'quantity' => 1]]]],
            ['POST', "purchase-orders/{$po}/cancel", []],
        ];
        foreach ($calls as [$method, $uri, $body]) {
            $this->assertNotFound($this->json($method, '/api/v1/'.$uri, $body, $hb), "{$method} {$uri}");
        }

        // A's rows are as they were.
        $this->getJson("/api/v1/sales/{$sale['id']}", $ha)->assertOk()->assertJsonPath('data.status', $sale['status']);
        $this->assertNull(DB::table('sale_records')->where('id', $sale['id'])->value('voided_at'));
        $this->getJson("/api/v1/shifts/{$shift}", $ha)->assertOk()->assertJsonPath('data.status', 'open');
        $this->getJson("/api/v1/stock-takes/{$take}", $ha)->assertOk()->assertJsonPath('data.status', $this->getJson("/api/v1/stock-takes/{$take}", $ha)->json('data.status'));
        $this->assertSame('draft', $this->getJson("/api/v1/purchase-orders/{$po}", $ha)->json('data.status'));
        $this->assertSame(0, DB::table('stock_records')->where('company_id', $b['company_id'])->count(), 'B moved no stock of its own either');

        // Exports and reports (a streamed file runs after the middleware: the hand-written filters are the wall there).
        foreach (['customers', 'sales', 'products'] as $kind) {
            $this->assertStringContainsString('A secret', $this->download("/api/v1/exports/{$kind}?format=csv", $ha), "A's own {$kind} export has its rows");
            $this->assertStringNotContainsString('A secret', $this->download("/api/v1/exports/{$kind}?format=csv", $hb), "B's {$kind} export");
        }
        $this->assertStringNotContainsString('A secret', (string) $this->getJson('/api/v1/reports/customer_aging', $hb)->assertOk()->getContent());
        $this->assertStringNotContainsString('A secret', (string) $this->getJson('/api/v1/reports/fast_movers', $hb)->assertOk()->getContent());
        $this->assertStringNotContainsString('A secret', (string) $this->getJson('/api/v1/dashboard', $hb)->assertOk()->getContent());
    }

    public function test_stores_transfers_and_stock_requests_by_id_never_reach_another_companys_rows(): void
    {
        LocationStock::flush();
        $plan = Plan::create(['name' => 'Business', 'slug' => 'biz-'.uniqid(), 'price' => 49, 'price_ugx' => 185000, 'currency' => 'UGX', 'interval' => 'month', 'trial_days' => 0,
            'is_active' => true, 'is_public' => true, 'sort_order' => 3, 'features' => ['multi_location' => true], 'limits' => ['max_locations' => 3]]);
        $stores = function () use ($plan): array {
            $s = $this->shop();
            Subscription::create(['company_id' => $s['t']['company_id'], 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
            $s['main'] = LocationStock::defaultLocation((int) $s['t']['company_id']);
            $s['branch'] = (int) $this->postJson('/api/v1/locations', ['name' => 'Branch '.uniqid()], $s['h'])->assertStatus(201)->json('data.id');

            return $s;
        };
        $a = $stores();
        [$item] = $this->product($a['h'], 'A secret flour');
        $request = $this->postJson('/api/v1/stock-requests', ['from_location_id' => $a['main'], 'to_location_id' => $a['branch'], 'lines' => [['stock_item_id' => $item, 'quantity' => 2]]], $a['h'])
            ->assertStatus(201)->json('data.request.id');
        $transfer = (new TransferService())->send((int) $a['t']['company_id'], (int) $a['t']['user_id'], $a['main'], $a['branch'], [['stock_item_id' => $item, 'quantity' => 1]]);
        $deviceUuid = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $deviceUuid, 'name' => 'A till'], $a['h'])->assertOk();
        $device = (int) DB::table('devices')->where('company_id', $a['t']['company_id'])->where('device_id', $deviceUuid)->value('id');

        $b = $stores();
        $calls = [
            ['PUT', "locations/{$a['branch']}", ['name' => 'B was here']],
            ['GET', "stock-transfers/{$transfer}", []], ['POST', "stock-transfers/{$transfer}/receive", ['lines' => [['stock_item_id' => $item, 'received_quantity' => 1]]]],
            ['GET', "stock-requests/{$request}", []],
            ['POST', "stock-requests/{$request}/approve", ['lines' => [['stock_item_id' => $item, 'quantity' => 1]]]],
            ['POST', "stock-requests/{$request}/send", []], ['POST', "stock-requests/{$request}/cancel", []],
            ['PUT', "devices/{$device}/location", ['location_id' => $b['main']]],
            ['POST', "devices/{$device}/revoke", []],
        ];
        foreach ($calls as [$method, $uri, $body]) {
            $this->assertNotFound($this->json($method, '/api/v1/'.$uri, $body, $b['h']), "{$method} {$uri}");
        }
        $this->assertNotContains($a['branch'], $this->ids($this->getJson('/api/v1/locations', $b['h'])->assertOk()));
        $this->assertNotContains($request, $this->ids($this->getJson('/api/v1/stock-requests', $b['h'])->assertOk()));
        $this->assertNotContains((int) $transfer, $this->ids($this->getJson('/api/v1/stock-transfers', $b['h'])->assertOk()));
        $this->assertStringNotContainsString('A secret', (string) $this->getJson('/api/v1/stock-levels', $b['h'])->assertOk()->getContent());
        $this->assertNotContains($device, $this->ids($this->getJson('/api/v1/devices', $b['h'])->assertOk()));

        $this->assertSame('requested', $this->getJson("/api/v1/stock-requests/{$request}", $a['h'])->assertOk()->json('data.request.status'));
        $this->assertNull(DB::table('devices')->where('id', $device)->value('revoked_at'));
    }

    public function test_supermarket_and_debts_by_id_never_reach_another_companys_rows(): void
    {
        ['t' => $a, 'h' => $ha] = $this->shop();
        [$item] = $this->product($ha, 'A secret yoghurt');
        $code = $this->postJson('/api/v1/gift-cards', ['amount' => 50000, 'method' => 'cash'], $ha)->assertStatus(201)->json('data.code');
        $held = $this->postJson('/api/v1/held-carts', ['label' => 'A secret cart', 'state' => ['lines' => [['q' => 1]]]], $ha)->assertStatus(201)->json('data.id');
        $z = $this->postJson('/api/v1/z-reports', ['date' => LocalDate::today($a['company_id'])->toDateString()], $ha)->assertStatus(201)->json('data.id');
        $batch = DB::table('stock_batches')->insertGetId(['company_id' => $a['company_id'], 'stock_item_id' => $item, 'batch_number' => 'A1', 'expiry_date' => now()->addDays(3)->toDateString(),
            'quantity' => 5, 'unit_cost' => 600, 'created_at' => now(), 'updated_at' => now()]);
        $label = DB::table('label_queue')->insertGetId(['company_id' => $a['company_id'], 'stock_item_id' => $item, 'reason' => 'price_change', 'created_at' => now()]);
        $twins = [
            $this->postJson('/api/v1/customers', ['name' => 'A secret twin', 'phone' => '0772 600 703'], $ha)->json('data.id'),
            $this->postJson('/api/v1/customers', ['name' => 'A secret twin 2', 'phone' => '0772 600 704'], $ha)->json('data.id'),
        ];
        $debtor = 'c:'.$twins[0];

        ['h' => $hb] = $this->shop();
        $calls = [
            ['GET', 'gift-cards/'.rawurlencode($code), []], ['GET', 'gift-cards?code='.urlencode($code), []],
            ['POST', "held-carts/{$held}/take", []],
            ['GET', "z-reports/{$z}", []],
            ['POST', "batches/{$batch}/markdown", ['pct' => 10]], ['POST', "batches/{$batch}/write-off", ['qty' => 1, 'reason' => 'expired']],
            ['POST', 'debts/'.rawurlencode($debtor).'/receive', ['amount' => 100, 'method' => 'cash']],
        ];
        foreach ($calls as [$method, $uri, $body]) {
            $this->assertNotFound($this->json($method, '/api/v1/'.$uri, $body, $hb), "{$method} {$uri}");
        }
        // Discarding is idempotent (a cart another till already took is "discarded" too): A's cart answers like one that does not exist.
        $this->assertRefusedLikeAMissingRow('DELETE', "held-carts/{$held}", 'held-carts/2147483647', [], $hb, 'DELETE held-carts/{id}');
        $this->assertNotContains($held, $this->ids($this->getJson('/api/v1/held-carts', $hb)->assertOk()));
        $this->assertNotContains($z, $this->ids($this->getJson('/api/v1/z-reports', $hb)->assertOk()));
        $this->assertNotContains($batch, $this->ids($this->getJson('/api/v1/expiring?days=30', $hb)->assertOk()));
        $this->assertNotContains($label, $this->ids($this->getJson('/api/v1/label-queue', $hb)->assertOk()));
        $this->postJson('/api/v1/label-queue/printed', ['ids' => [$label]], $hb)->assertOk()->assertJsonPath('data.printed', 0);
        $this->assertSame([], $this->getJson('/api/v1/debts/'.rawurlencode($debtor).'/sales', $hb)->assertOk()->json('data'));
        $this->assertStringNotContainsString('A secret', (string) $this->getJson('/api/v1/debts', $hb)->assertOk()->getContent());
        $merge = $this->postJson('/api/v1/duplicates/merge', ['kind' => 'customers', 'keep_id' => $twins[0], 'merge_ids' => [$twins[1]]], $hb);
        $this->assertContains($merge->status(), [404, 422], 'merging another company\'s customers is refused');

        // A's rows are as they were.
        $this->assertCount(1, $this->getJson('/api/v1/held-carts', $ha)->json('data'));
        $this->getJson('/api/v1/gift-cards/'.rawurlencode($code), $ha)->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertNull(DB::table('label_queue')->where('id', $label)->value('printed_at'));
        $this->assertEquals(5, (float) DB::table('stock_batches')->where('id', $batch)->value('quantity'));
        foreach ($twins as $id) {
            $this->getJson("/api/v1/customers/{$id}", $ha)->assertOk();
        }
    }

    public function test_budget_print_links_never_reach_another_companys_event(): void
    {
        ['h' => $ha] = $this->shop();
        $program = $this->postJson('/api/v1/budget-programs', ['name' => 'A secret wedding'], $ha)->assertStatus(201)->json('data.id');
        $this->postJson('/api/v1/contribution-records', ['budget_program_id' => $program, 'name' => 'A secret guest', 'amount' => 1000], $ha)->assertStatus(201);
        $this->postJson("/api/v1/budget-programs/{$program}/print-link", [], $ha)->assertOk();

        ['h' => $hb] = $this->shop();
        $this->assertNotFound($this->postJson("/api/v1/budget-programs/{$program}/print-link", [], $hb), 'POST budget-programs/{id}/print-link');
        $pledges = $this->postJson('/api/v1/contribution-records/print-link', ['budget_program_id' => $program], $hb);
        $this->assertContains($pledges->status(), [404, 422], 'a pledge list of another company\'s event is refused');
        $this->assertStringNotContainsString('http', (string) $pledges->json('data.url'));
    }

    public function test_core_routes_by_id_never_reach_another_companys_rows(): void
    {
        Storage::fake('public');
        $this->app->instance(ChatService::class, new ChatService(Storage::fake('chat')));
        ['t' => $a, 'h' => $ha] = $this->shop();
        $ann = new User;
        $ann->forceFill(['name' => 'A secret Ann', 'email' => 'ann'.uniqid('', true).'@example.test', 'password' => bcrypt('secret123'), 'status' => 'Active', 'company_id' => $a['company_id']])->save();
        CompanyMember::create(['company_id' => $a['company_id'], 'user_id' => $ann->id, 'role' => 'cashier', 'status' => 'active', 'joined_at' => now()]);
        Permissions::flush();
        $conversation = $this->postJson('/api/v1/chat/direct', ['user_id' => $ann->id], $ha)->assertOk()->json('data.conversation.id');
        $message = $this->postJson("/api/v1/chat/conversations/{$conversation}/messages", ['body' => 'A secret message'], $ha)->assertStatus(201)->json('data.id');
        $conflict = SyncConflict::create(['company_id' => $a['company_id'], 'table_name' => 'customers', 'code' => 'version_conflict', 'state' => 'open']);
        $invite = $this->postJson('/api/v1/team/invites', ['role' => 'cashier', 'phone' => '0772818009'], $ha)->assertStatus(201)->json('data.invite.id');
        $export = $this->postJson('/api/v1/company/export', [], $ha)->assertStatus(201)->json('data.id');
        $invoice = SubscriptionInvoice::create(['company_id' => $a['company_id'], 'number' => 'BP-'.uniqid(), 'status' => 'paid', 'amount' => 1000, 'currency' => 'UGX']);
        $session = collect($this->getJson('/api/v1/auth/sessions', $ha)->assertOk()->json('data'))->pluck('id')->first();
        $fileUuid = (string) Str::uuid();
        $this->app['auth']->forgetGuards();
        $aFile = $this->post('/api/v1/files', ['uuid' => $fileUuid, 'purpose' => 'product_image', 'file' => UploadedFile::fake()->image('a.jpg')], $ha + ['Accept' => 'application/json'])
            ->assertStatus(201)->json('data');

        ['t' => $b, 'h' => $hb] = $this->shop();
        $calls = [
            ['GET', "chat/conversations/{$conversation}/messages", []], ['POST', "chat/conversations/{$conversation}/messages", ['body' => 'B was here']],
            ['POST', "chat/conversations/{$conversation}/read", []], ['POST', "chat/conversations/{$conversation}/typing", []],
            ['DELETE', "chat/messages/{$message}", []],
            ['POST', "sync/conflicts/{$conflict->id}/resolve", ['choice' => 'server']],
            ['POST', "team/invites/{$invite}/resend", []], ['DELETE', "team/invites/{$invite}", []],
            ['PATCH', "team/members/{$ann->id}", ['role' => 'viewer']], ['GET', "team/members/{$ann->id}/activity", []],
            ['PUT', "team/members/{$ann->id}/pin", ['pin' => '4321']],
            ['GET', "company/exports/{$export}/download", []],
            ['GET', "subscription/invoices/{$invoice->id}.pdf", []], ['GET', "subscription/payments/{$invoice->id}", []],
            ['DELETE', "auth/sessions/{$session}", []],
        ];
        foreach ($calls as [$method, $uri, $body]) {
            $this->assertNotFound($this->json($method, '/api/v1/'.$uri, $body, $hb), "{$method} {$uri}");
        }
        $this->assertContains($this->postJson('/api/v1/chat/direct', ['user_id' => $ann->id], $hb)->status(), [404, 422], 'no chat with another company\'s member');
        $this->assertStringNotContainsString('A secret', (string) $this->getJson('/api/v1/chat/conversations', $hb)->assertOk()->getContent());
        $this->assertStringNotContainsString('A secret', (string) $this->getJson('/api/v1/members', $hb)->assertOk()->getContent());
        $this->assertNotContains($conflict->id, $this->ids($this->getJson('/api/v1/sync/conflicts', $hb)->assertOk()));
        $this->assertStringNotContainsString('A secret', (string) $this->getJson('/api/v1/team', $hb)->assertOk()->getContent());

        // The same file uuid in another company is that company's own file, never A's.
        $this->app['auth']->forgetGuards();
        $bFile = $this->post('/api/v1/files', ['uuid' => $fileUuid, 'purpose' => 'product_image', 'file' => UploadedFile::fake()->image('b.jpg')], $hb + ['Accept' => 'application/json'])
            ->assertStatus(201)->json('data');
        $this->assertNotSame($aFile['path'] ?? $aFile['url'] ?? null, $bFile['path'] ?? $bFile['url'] ?? null);
        $this->assertSame(1, DB::table('files')->where('company_id', $b['company_id'])->where('uuid', $fileUuid)->count());

        // A's rows are as they were, and A's session still works.
        $this->getJson('/api/v1/auth/me', $ha)->assertOk();
        $this->assertSame('open', $conflict->fresh()->state);
        $this->assertNull(DB::table('chat_messages')->where('id', $message)->value('deleted_at'));
        $this->assertSame('cashier', CompanyMember::where('company_id', $a['company_id'])->where('user_id', $ann->id)->value('role'));
        $this->assertNotNull(DB::table('invites')->where('id', $invite)->first());
    }

    public function test_find_my_phone_devices_by_uuid_never_reach_another_company(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['display_name' => 'x'], 200)]);
        ['h' => $ha] = $this->shop();
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'A secret phone'], $ha)->assertOk();
        $command = DeviceCommand::create(['device_id' => TrackedDevice::withoutGlobalScopes()->where('uuid', $uuid)->value('id'), 'command' => 'locate_now', 'status' => 'pending']);

        ['h' => $hb] = $this->shop();
        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => (string) Str::uuid(), 'name' => 'B phone'], $hb)->assertOk(); // Ping Pin on for B
        $calls = [
            ['GET', "tracking/devices/{$uuid}/config", []], ['POST', "tracking/devices/{$uuid}/config", ['tracking_interval_seconds' => 999]],
            ['POST', "tracking/devices/{$uuid}/locations/batch", ['points' => [['recorded_at' => 1787900001000, 'lat' => 1.0, 'lng' => 32.0]]]],
            ['POST', "tracking/devices/{$uuid}/commands/{$command->id}/ack", []],
        ];
        foreach ($calls as [$method, $uri, $body]) {
            $this->assertNotFound($this->json($method, '/api/v1/'.$uri, $body, $hb), "{$method} {$uri}");
        }
        $this->assertSame('pending', $command->fresh()->status);
    }
}
