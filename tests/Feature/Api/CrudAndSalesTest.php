<?php

namespace Tests\Feature\Api;

class CrudAndSalesTest extends ApiTestCase
{
    public function test_full_stock_crud_lifecycle(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);

        // Create category -> sub-category -> item
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'Drinks'], $h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'Sodas', 'stock_category_id' => $cat, 'measurement_unit' => 'bottle'], $h)->json('data.id');

        $create = $this->postJson('/api/v1/stock-items', [
            'name' => 'Cola', 'stock_sub_category_id' => $sub,
            'selling_price' => 2000, 'buying_price' => 1500, 'original_quantity' => 100,
        ], $h);
        $create->assertStatus(201);
        $itemId = $create->json('data.id');

        // Read
        $this->getJson("/api/v1/stock-items/{$itemId}", $h)->assertOk()->assertJsonPath('data.name', 'Cola');

        // Update
        $this->putJson("/api/v1/stock-items/{$itemId}", ['selling_price' => 2500], $h)
            ->assertOk()->assertJsonPath('data.selling_price', '2500.00');

        // List / pagination envelope
        $this->getJson('/api/v1/stock-items', $h)
            ->assertOk()
            ->assertJsonStructure(['code', 'message', 'data', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);

        // Options + search
        $this->getJson('/api/v1/stock-items/options?q=cola', $h)->assertOk()
            ->assertJsonStructure(['data' => [['id', 'text']]]);
        $this->getJson('/api/v1/stock-items/search?q=cola', $h)->assertOk();

        // Delete
        $this->deleteJson("/api/v1/stock-items/{$itemId}", [], $h)->assertOk();
        $this->getJson("/api/v1/stock-items/{$itemId}", $h)->assertStatus(404);
    }

    public function test_sale_checkout_deducts_stock_and_computes_totals(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);

        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'Drinks'], $h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'Sodas', 'stock_category_id' => $cat, 'measurement_unit' => 'bottle'], $h)->json('data.id');
        $item = $this->postJson('/api/v1/stock-items', [
            'name' => 'Cola', 'stock_sub_category_id' => $sub,
            'selling_price' => 2000, 'buying_price' => 1500, 'original_quantity' => 100,
        ], $h)->json('data.id');

        $sale = $this->postJson('/api/v1/sales/checkout', [
            'customer_name' => 'John',
            'amount_paid' => 6000,
            'items' => [['stock_item_id' => $item, 'quantity' => 3, 'unit_price' => 2000]],
        ], $h);

        $sale->assertStatus(201)->assertJsonPath('code', 1);

        // Stock deducted 100 -> 97
        $this->getJson("/api/v1/stock-items/{$item}", $h)
            ->assertOk()->assertJsonPath('data.current_quantity', '97.00');
    }

    public function test_checkout_rejects_insufficient_stock(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);

        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'Drinks'], $h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'Sodas', 'stock_category_id' => $cat, 'measurement_unit' => 'bottle'], $h)->json('data.id');
        $item = $this->postJson('/api/v1/stock-items', [
            'name' => 'Cola', 'stock_sub_category_id' => $sub, 'selling_price' => 2000, 'original_quantity' => 2,
        ], $h)->json('data.id');

        $this->postJson('/api/v1/sales/checkout', [
            'items' => [['stock_item_id' => $item, 'quantity' => 5]],
        ], $h)->assertStatus(422);

        // Stock unchanged after a failed checkout.
        $this->getJson("/api/v1/stock-items/{$item}", $h)->assertJsonPath('data.current_quantity', '2.00');
    }

    public function test_budget_item_target_is_computed(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);

        $prog = $this->postJson('/api/v1/budget-programs', ['name' => 'Fundraiser'], $h)->json('data.id');
        $cat = $this->postJson('/api/v1/budget-item-categories', ['name' => 'Venue', 'budget_program_id' => $prog], $h)->json('data.id');

        $this->postJson('/api/v1/budget-items', [
            'name' => 'Hall', 'budget_item_category_id' => $cat, 'unit_price' => 100000, 'quantity' => 2,
        ], $h)->assertStatus(201)->assertJsonPath('data.target_amount', 200000);
    }

    public function test_dashboard_returns_summary(): void
    {
        $t = $this->registerTenant();
        $this->getJson('/api/v1/dashboard', $this->auth($t['token']))
            ->assertOk()
            ->assertJsonStructure(['data' => ['inventory', 'sales', 'finance', 'budget']]);
    }
}
