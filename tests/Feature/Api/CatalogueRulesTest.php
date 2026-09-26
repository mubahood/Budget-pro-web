<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Plan;
use App\Models\StockItem;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Shop\ProductImportService;
use App\Support\Rules\StockItemRules;

/**
 * The catalogue's shared rules (App\Support\Rules\{StockItem,StockCategory,StockSubCategory,Unit,ProductBarcode}Rules),
 * used by the API and by the new web interface, and the product CSV import built on the setup import.
 */
class CatalogueRulesTest extends ApiTestCase
{
    private function shop(): array
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'Drinks'], $h)->assertStatus(201)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'Sodas', 'stock_category_id' => $cat, 'measurement_unit' => 'bottle'], $h)->assertStatus(201)->json('data.id');

        return $t + ['h' => $h, 'cat' => $cat, 'sub' => $sub];
    }

    public function test_the_api_validates_products_with_the_shared_rules(): void
    {
        $s = $this->shop();
        $this->postJson('/api/v1/stock-items', ['name' => 'Cola', 'stock_sub_category_id' => $s['sub'], 'selling_price' => 2000, 'barcode' => '111'], $s['h'])->assertStatus(201);
        $this->postJson('/api/v1/stock-items', ['name' => 'Cola 2', 'stock_sub_category_id' => $s['sub'], 'selling_price' => 2000, 'barcode' => '111'], $s['h'])
            ->assertStatus(422)->assertJsonValidationErrors('barcode');
        $this->postJson('/api/v1/stock-items', ['name' => 'No price', 'stock_sub_category_id' => $s['sub']], $s['h'])->assertStatus(422)->assertJsonValidationErrors('selling_price');
        $this->assertSame(StockItemRules::WRITABLE, (new \ReflectionProperty(\App\Http\Controllers\Api\V1\StockItemController::class, 'writable'))->getDefaultValue());
    }

    public function test_a_category_or_sub_category_with_products_cannot_be_deleted(): void
    {
        $s = $this->shop();
        $item = $this->postJson('/api/v1/stock-items', ['name' => 'Cola', 'stock_sub_category_id' => $s['sub'], 'selling_price' => 2000], $s['h'])->json('data.id');

        $this->deleteJson("/api/v1/stock-categories/{$s['cat']}", [], $s['h'])->assertStatus(422)->assertJsonPath('errors.code', 'category_in_use');
        $this->deleteJson("/api/v1/stock-sub-categories/{$s['sub']}", [], $s['h'])->assertStatus(422)->assertJsonPath('errors.code', 'sub_category_in_use');

        $this->deleteJson("/api/v1/stock-items/{$item}", [], $s['h'])->assertOk();
        // Still a sub-category inside the category.
        $this->deleteJson("/api/v1/stock-categories/{$s['cat']}", [], $s['h'])->assertStatus(422)->assertJsonPath('errors.sub_categories', 1);
        $this->deleteJson("/api/v1/stock-sub-categories/{$s['sub']}", [], $s['h'])->assertOk();
        $this->deleteJson("/api/v1/stock-categories/{$s['cat']}", [], $s['h'])->assertOk();
    }

    public function test_units_cannot_be_their_own_base_and_in_use_units_stay(): void
    {
        $s = $this->shop();
        $pcs = $this->postJson('/api/v1/units', ['name' => 'Piece', 'abbreviation' => 'pc'], $s['h'])->assertStatus(201)->json('data.id');
        $this->putJson("/api/v1/units/{$pcs}", ['base_unit_id' => $pcs], $s['h'])->assertStatus(422)->assertJsonValidationErrors('base_unit_id');
        $box = $this->postJson('/api/v1/units', ['name' => 'Box', 'abbreviation' => 'bx', 'base_unit_id' => $pcs, 'factor' => 12], $s['h'])->assertStatus(201)->json('data.id');
        $this->postJson('/api/v1/stock-items', ['name' => 'Pens', 'stock_sub_category_id' => $s['sub'], 'selling_price' => 500, 'unit_id' => $box], $s['h'])->assertStatus(201);
        $this->deleteJson("/api/v1/units/{$box}", [], $s['h'])->assertStatus(422)->assertJsonPath('errors.code', 'unit_in_use');
        $this->postJson('/api/v1/product-barcodes', ['stock_item_id' => StockItem::withoutGlobalScopes()->where('name', 'Pens')->value('id'), 'barcode' => 'X1', 'unit_id' => $box], $s['h'])->assertStatus(201);
        $this->postJson('/api/v1/product-barcodes', ['stock_item_id' => StockItem::withoutGlobalScopes()->where('name', 'Pens')->value('id'), 'barcode' => 'X1'], $s['h'])->assertStatus(422)->assertJsonValidationErrors('barcode');
    }

    public function test_delete_blocker_names_stock_on_the_shelf(): void
    {
        $s = $this->shop();
        $id = $this->postJson('/api/v1/stock-items', ['name' => 'Cola', 'stock_sub_category_id' => $s['sub'], 'selling_price' => 2000, 'original_quantity' => 4], $s['h'])->json('data.id');
        $item = StockItem::withoutGlobalScopes()->findOrFail($id);
        $this->assertStringContainsString('still has 4 in stock', (string) StockItemRules::deleteBlocker($item));
        $empty = $this->postJson('/api/v1/stock-items', ['name' => 'Empty', 'stock_sub_category_id' => $s['sub'], 'selling_price' => 2000], $s['h'])->json('data.id');
        $this->assertNull(StockItemRules::deleteBlocker(StockItem::withoutGlobalScopes()->findOrFail($empty)));
    }

    public function test_import_previews_new_update_and_invalid_rows_then_imports_through_the_model(): void
    {
        $s = $this->shop();
        $this->postJson('/api/v1/stock-items', ['name' => 'Cola', 'stock_sub_category_id' => $s['sub'], 'selling_price' => 2000, 'buying_price' => 1500, 'original_quantity' => 10, 'barcode' => '999'], $s['h'])->assertStatus(201);
        $company = Company::withoutGlobalScopes()->findOrFail($s['company_id']);
        $user = User::findOrFail($s['user_id']);
        $csv = "name,category,selling_price,cost,opening_stock,barcode\n"
            ."Cola,Drinks,2500,1500,5,\n"         // update: price only (stock untouched)
            ."Fanta,Drinks,2000,1400,12,\n"       // new
            ."Water,Drinks,abc,,,\n"              // invalid numbers
            ."Juice,Drinks,3000,2000,1,999\n"     // barcode taken by Cola
            ."fanta,Drinks,2000,1400,1,\n"        // same name twice in the file
            ."Cola,Drinks,2500,1500,,\n";         // duplicate again

        $svc = new ProductImportService();
        $p = $svc->preview($company, $csv);
        $this->assertSame(['new' => 1, 'update' => 1, 'same' => 0, 'invalid' => 4], $p['counts']);
        $byRow = collect($p['rows'])->keyBy('row');
        $this->assertSame('update', $byRow[2]['status']);
        $this->assertSame([2000.0, 2500.0], $byRow[2]['changes']['selling_price']);
        $this->assertArrayNotHasKey('buying_price', $byRow[2]['changes'], 'cost unchanged');
        $this->assertStringContainsString('Stock is not changed', $byRow[2]['reasons'][0]);
        $this->assertSame('new', $byRow[3]['status']);
        $this->assertSame('invalid', $byRow[4]['status']);
        $this->assertSame('invalid', $byRow[5]['status']);
        $this->assertStringContainsString('barcode', strtolower($byRow[5]['reasons'][0]));
        $this->assertSame('invalid', $byRow[6]['status']);

        $r = $svc->import($company, $user, $csv);
        $this->assertSame(['created' => 1, 'updated' => 1, 'skipped' => 4], $r);
        $cola = StockItem::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Cola')->first();
        $this->assertEquals(2500, (float) $cola->selling_price);
        $this->assertEquals(10, (float) $cola->current_quantity, 'an update never moves stock');
        $fanta = StockItem::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Fanta')->first();
        $this->assertEquals(12, (float) $fanta->current_quantity);
        $this->assertNotEmpty($fanta->uuid);
        $this->assertGreaterThan(0, (int) $fanta->server_seq);

        // Importing the same file again changes nothing.
        $again = $svc->preview($company, "name,selling_price\nCola,2500\n");
        $this->assertSame('same', $again['rows'][0]['status']);
        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        $svc->import($company, $user, "name,selling_price\nCola,2500\n");
    }

    public function test_import_respects_the_plan_limit(): void
    {
        $s = $this->shop();
        $plan = Plan::create(['name' => 'Tiny', 'slug' => 'tiny-'.uniqid(), 'price' => 10, 'price_ugx' => 30000, 'currency' => 'UGX', 'interval' => 'month',
            'trial_days' => 0, 'is_active' => true, 'is_public' => true, 'sort_order' => 1, 'features' => [], 'limits' => ['max_products' => 1]]);
        Subscription::create(['company_id' => $s['company_id'], 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'provider' => 'manual']);
        $company = Company::withoutGlobalScopes()->findOrFail($s['company_id']);
        try {
            (new ProductImportService())->import($company, User::findOrFail($s['user_id']), "name,selling_price\nA,1\nB,2\n");
            $this->fail('the plan limit should refuse');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('plan_limit_reached', $e->toErrors()['code']);
        }
        $this->assertSame(0, StockItem::withoutGlobalScopes()->where('company_id', $s['company_id'])->count());
    }
}
