<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\EnsureApiModule;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Module gating in the API (roadmap 02 §3, phase-0 plan P0-3): every authenticated /api/v1 route says which
 * module it belongs to (`api.module:shop|finance|budget|poultry|pingpin`, or `api.module:core` for every
 * account), and an account without the module gets 403 module_off with the way to add it back.
 */
class ModuleGatingTest extends ApiTestCase
{
    /** @return array<int, RoutingRoute> */
    private function authenticatedV1Routes(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/v1/') && in_array('auth:sanctum', $r->gatherMiddleware(), true)));
    }

    /** @return array<int, string> the route's api.module:* entries */
    private function moduleMarks(RoutingRoute $route): array
    {
        return array_values(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'api.module')));
    }

    private function label(RoutingRoute $route): string
    {
        return implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
    }

    public function test_every_authenticated_v1_route_carries_exactly_one_module_mark(): void
    {
        $routes = $this->authenticatedV1Routes();
        $this->assertGreaterThan(300, count($routes), 'the walk sees the whole API');

        $missing = [];
        $several = [];
        $unknown = [];
        foreach ($routes as $route) {
            $marks = $this->moduleMarks($route);
            if ($marks === []) {
                $missing[] = $this->label($route);

                continue;
            }
            if (count($marks) > 1) {
                $several[] = $this->label($route).' ('.implode(', ', $marks).')';
            }
            $modules = explode(',', Str::after($marks[0], 'api.module:'));
            if (! str_contains($marks[0], ':') || array_diff($modules, [EnsureApiModule::CORE, ...EnsureApiModule::MODULES]) !== []) {
                $unknown[] = $this->label($route).' ('.$marks[0].')';
            }
        }

        $this->assertSame([], $missing, "Authenticated v1 routes without an api.module mark (add api.module:<module>, or api.module:core for every account):\n".implode("\n", $missing));
        $this->assertSame([], $several, "Routes with more than one api.module mark:\n".implode("\n", $several));
        $this->assertSame([], $unknown, "Routes with an unknown module:\n".implode("\n", $unknown));
    }

    public function test_a_module_mark_runs_straight_after_the_tenant_guard_and_never_without_it(): void
    {
        $wrong = [];
        foreach ($this->authenticatedV1Routes() as $route) {
            $middleware = array_values($route->gatherMiddleware());
            $mark = $this->moduleMarks($route)[0] ?? null;
            if ($mark === null || $mark === 'api.module:core') {
                continue; // core needs no company
            }
            $tenant = array_search('api.tenant', $middleware, true);
            if ($tenant === false || array_search($mark, $middleware, true) !== $tenant + 1) {
                $wrong[] = $this->label($route).': '.implode(', ', $middleware);
            }
        }
        $this->assertSame([], $wrong, "A module mark reads the company api.tenant resolved, so it comes right after it:\n".implode("\n", $wrong));
    }

    public function test_the_module_map_is_the_one_in_the_plan(): void
    {
        $expect = [
            'GET api/v1/auth/me' => 'core', 'GET api/v1/meta' => 'core', 'PUT api/v1/company/modules' => 'core',
            'GET api/v1/subscription' => 'core', 'GET api/v1/team' => 'core', 'GET api/v1/onboarding' => 'core',
            'POST api/v1/sync/push' => 'core', 'GET api/v1/sync/pull' => 'core', 'POST api/v1/files' => 'core', 'POST api/v1/uploads' => 'core',
            'GET api/v1/dashboard' => 'core', 'GET api/v1/chat/conversations' => 'core', 'POST api/v1/presence' => 'core', 'GET api/v1/members' => 'core',
            'POST api/v1/tracking/devices/register' => 'core', 'GET api/v1/store-features' => 'core', 'GET api/v1/notifications' => 'core',
            'GET api/v1/pingpin/organisations' => 'core', 'POST api/v1/pingpin/auth/verify-password' => 'core',
            'GET api/v1/stock-items' => 'shop', 'POST api/v1/sales/checkout' => 'shop', 'GET api/v1/customers/{id}/summary' => 'shop',
            'GET api/v1/exports/{kind}' => 'shop', 'GET api/v1/reports' => 'shop', 'GET api/v1/debts' => 'shop', 'GET api/v1/held-carts' => 'shop',
            'GET api/v1/z-reports' => 'shop', 'GET api/v1/reorder-suggestions' => 'shop', 'PUT api/v1/company/engagement' => 'shop',
            'GET api/v1/financial-records' => 'finance', 'GET api/v1/financial-reports' => 'finance',
            'GET api/v1/budget-programs' => 'budget', 'POST api/v1/budget-programs/{id}/print-link' => 'budget', 'GET api/v1/contribution-records' => 'budget',
            'GET api/v1/poultry/sync/pull' => 'poultry', 'POST api/v1/poultry/sync/push' => 'poultry',
            'POST api/v1/tracking/devices/{uuid}/locations/batch' => 'pingpin', 'GET api/v1/tracking/devices/{uuid}/config' => 'pingpin',
        ];
        $seen = [];
        foreach ($this->authenticatedV1Routes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $seen[$method.' '.$route->uri()] = Str::after($this->moduleMarks($route)[0] ?? '', 'api.module:');
            }
        }
        foreach ($expect as $key => $module) {
            $this->assertArrayHasKey($key, $seen, "{$key} exists");
            $this->assertSame($module, $seen[$key], "{$key} belongs to {$module}");
        }
    }

    private function companyWith(array $t, array $modules): Company
    {
        $company = Company::withoutGlobalScopes()->findOrFail($t['company_id']);
        $company->enabled_modules = $modules;
        $company->save();

        return $company;
    }

    public function test_a_poultry_only_farm_is_refused_shop_and_finance_routes_in_the_portals_words(): void
    {
        $farm = $this->registerTenant();
        $this->companyWith($farm, ['poultry']);
        $h = $this->auth($farm['token']);

        $this->getJson('/api/v1/stock-items', $h)->assertStatus(403)->assertExactJson([
            'code' => 0, 'message' => 'Shop is not on your account.', 'data' => null,
            'errors' => ['code' => 'module_off', 'module' => 'shop', 'add' => 'PUT /api/v1/company/modules'],
        ]);
        // A write is refused before the permission and idempotency checks, and nothing is created.
        $this->postJson('/api/v1/stock-categories', ['name' => 'Feeds'], $h + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(403)->assertJsonPath('errors.code', 'module_off');
        $this->assertDatabaseMissing('stock_categories', ['company_id' => $farm['company_id'], 'name' => 'Feeds']);
        $this->getJson('/api/v1/customers/1/summary', $h)->assertStatus(403)->assertJsonPath('errors.module', 'shop');
        $this->getJson('/api/v1/exports/sales', $h)->assertStatus(403)->assertJsonPath('errors.module', 'shop');

        $this->getJson('/api/v1/financial-records', $h)->assertStatus(403)
            ->assertJsonPath('message', 'Income & expenses is not on your account.')
            ->assertJsonPath('errors.module', 'finance')
            ->assertJsonPath('errors.add', 'PUT /api/v1/company/modules');

        // Its own module answers.
        $this->getJson('/api/v1/poultry/sync/pull?table=batches&since=0', $h)->assertOk();
    }

    public function test_a_shop_is_refused_budget_routes_and_the_v1_poultry_sync(): void
    {
        $shop = $this->registerTenant();
        $this->companyWith($shop, ['shop', 'finance']);
        $h = $this->auth($shop['token']);

        $this->getJson('/api/v1/budget-programs', $h)->assertStatus(403)
            ->assertJsonPath('message', 'Budget & pledges is not on your account.')
            ->assertJsonPath('errors.code', 'module_off')->assertJsonPath('errors.module', 'budget');
        $this->postJson('/api/v1/budget-programs/1/print-link', [], $h)->assertStatus(403)->assertJsonPath('errors.module', 'budget');
        $this->postJson('/api/v1/contribution-records/print-link', [], $h)->assertStatus(403)->assertJsonPath('errors.module', 'budget');
        $this->getJson('/api/v1/poultry/sync/pull', $h)->assertStatus(403)
            ->assertJsonPath('message', 'Poultry farm is not on your account.')->assertJsonPath('errors.module', 'poultry');
        $this->postJson('/api/v1/poultry/sync/push', ['records' => []], $h)->assertStatus(403)->assertJsonPath('errors.module', 'poultry');

        $this->getJson('/api/v1/stock-items', $h)->assertOk();
        $this->getJson('/api/v1/financial-records', $h)->assertOk();
    }

    public function test_the_add_endpoint_stays_reachable_and_adding_the_module_opens_its_routes(): void
    {
        $farm = $this->registerTenant();
        $this->companyWith($farm, ['poultry']);
        $h = $this->auth($farm['token']);
        $this->getJson('/api/v1/stock-items', $h)->assertStatus(403);

        $this->putJson('/api/v1/company/modules', ['modules' => ['poultry', 'shop']], $h)->assertOk();

        $this->getJson('/api/v1/stock-items', $h)->assertOk();
        $this->getJson('/api/v1/financial-records', $h)->assertStatus(403)->assertJsonPath('errors.module', 'finance');
    }

    public function test_core_routes_answer_whatever_modules_the_account_has(): void
    {
        $event = $this->registerTenant();
        $this->companyWith($event, ['budget']);
        $h = $this->auth($event['token']);

        foreach (['/api/v1/auth/me', '/api/v1/meta', '/api/v1/company', '/api/v1/onboarding', '/api/v1/team', '/api/v1/notifications',
            '/api/v1/store-features', '/api/v1/devices', '/api/v1/sync/pull?table=categories&since_seq=0', '/api/v1/sync/conflicts', '/api/v1/dashboard',
            '/api/v1/dashboard/suggestions', '/api/v1/chat/conversations', '/api/v1/chat/unread', '/api/v1/members', '/api/v1/subscription'] as $uri) {
            $res = $this->getJson($uri, $h);
            $this->assertNotSame('module_off', $res->json('errors.code'), "{$uri} is core");
            $this->assertTrue($res->status() < 400, "{$uri} answers ({$res->status()})");
        }
        $this->postJson('/api/v1/presence', [], $h)->assertOk();
        $this->getJson('/api/v1/budget-programs', $h)->assertOk();
    }

    public function test_registering_the_first_tracked_device_needs_no_ping_pin_and_turns_it_on(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['display_name' => 'Test Road'], 200)]);
        $shop = $this->registerTenant();
        $this->companyWith($shop, ['shop', 'finance']);
        $h = $this->auth($shop['token']);
        $uuid = (string) Str::uuid();

        $this->getJson("/api/v1/tracking/devices/{$uuid}/config", $h)->assertStatus(403)->assertExactJson([
            'code' => 0, 'message' => 'Ping Pin is not on your account.', 'data' => null,
            'errors' => ['code' => 'module_off', 'module' => 'pingpin', 'add' => 'POST /api/v1/tracking/devices/register'],
        ]);

        $this->postJson('/api/v1/tracking/devices/register', ['uuid' => $uuid, 'name' => 'Till phone'], $h)->assertOk();

        $this->getJson("/api/v1/tracking/devices/{$uuid}/config", $h)->assertOk();
    }

    public function test_an_account_with_no_modules_set_keeps_its_business_types_modules(): void
    {
        $farm = $this->registerTenant(['business_type' => 'poultry']);
        $company = Company::withoutGlobalScopes()->findOrFail($farm['company_id']);
        $this->assertNull($company->enabled_modules);
        $h = $this->auth($farm['token']);

        $this->getJson('/api/v1/poultry/sync/pull?table=batches&since=0', $h)->assertOk();
        $this->getJson('/api/v1/financial-records', $h)->assertOk();
        $this->getJson('/api/v1/stock-items', $h)->assertStatus(403)->assertJsonPath('errors.module', 'shop');
    }

    public function test_several_modules_pass_on_any_and_name_the_first_when_refused(): void
    {
        $farm = $this->registerTenant();
        $company = $this->companyWith($farm, ['poultry']);
        $mw = new EnsureApiModule;
        $request = Request::create('/api/v1/anything');
        $request->attributes->set('company', $company);

        $this->assertSame(204, $mw->handle($request, fn () => response()->noContent(), 'shop', 'poultry')->getStatusCode());
        $refused = $mw->handle($request, fn () => response()->noContent(), 'budget', 'shop');
        $this->assertSame(403, $refused->getStatusCode());
        $this->assertSame(['code' => 'module_off', 'module' => 'budget', 'add' => 'PUT /api/v1/company/modules', 'any_of' => ['budget', 'shop']],
            json_decode((string) $refused->getContent(), true)['errors']);
        $this->assertSame(204, $mw->handle(Request::create('/x'), fn () => response()->noContent(), 'core')->getStatusCode(), 'core needs no company');
    }

    public function test_a_wiring_mistake_fails_loudly_instead_of_opening_or_closing_quietly(): void
    {
        $mw = new EnsureApiModule;
        $next = fn () => response()->noContent();

        foreach ([[], ['shopp'], ['shop']] as $modules) {
            try {
                $mw->handle(Request::create('/x'), $next, ...$modules);
                $this->fail('api.module:'.implode(',', $modules).' without a company must throw');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('api.module', $e->getMessage());
            }
        }
    }
}
