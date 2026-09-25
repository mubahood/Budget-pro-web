<?php

namespace Tests\Feature\Api;

use App\Support\OpenApi\Generator;

/** Plan A8 / P4-7: the published API reference is generated from the live routes and their rules. */
class OpenApiTest extends ApiTestCase
{
    public function test_spec_covers_every_v1_route_with_real_request_schemas(): void
    {
        $spec = app(Generator::class)->spec();
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/'))
            ->sum(fn ($r) => count(array_diff($r->methods(), ['HEAD'])));
        $this->assertSame($routes, array_sum(array_map('count', $spec['paths'])), 'every operation is documented');

        $checkout = $spec['paths']['/sales/checkout']['post'];
        $this->assertSame([['bearer' => []]], $checkout['security']);
        $items = $checkout['requestBody']['content']['application/json']['schema']['properties']['items'];
        $this->assertSame('array', $items['type']);
        $this->assertContains('stock_item_id', $items['items']['required']);
        $this->assertSame('integer', $items['items']['properties']['stock_item_id']['type']);

        $this->assertEqualsCanonicalizing(['stock_sub_category_id', 'name', 'selling_price'], $spec['paths']['/stock-items']['post']['requestBody']['content']['application/json']['schema']['required']);
        $otp = $spec['paths']['/auth/otp/request']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertSame(['login', 'register', 'reset'], $otp['properties']['purpose']['enum']);
        $this->assertArrayNotHasKey('security', $spec['paths']['/auth/login']['post']);
        $this->assertContains('format', array_column(array_filter($spec['paths']['/reports/{name}']['get']['parameters'], fn ($p) => isset($p['name'])), 'name'));

        $postman = app(Generator::class)->postman($spec);
        $this->assertSame(array_sum(array_map('count', $spec['paths'])), array_sum(array_map(fn ($f) => count($f['item']), $postman['item'])));
    }

    public function test_reference_pages_are_served(): void
    {
        $this->getJson('/api/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0');
        $this->get('/api/docs')->assertOk()->assertSee('swagger-ui', false);
    }
}
