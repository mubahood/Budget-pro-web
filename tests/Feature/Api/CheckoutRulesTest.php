<?php

namespace Tests\Feature\Api;

use App\Support\Rules\CheckoutRules;
use Illuminate\Support\Facades\Validator;

/** One checkout rule set for the API and the new web till (budget-pro-new's POS validates with it too). */
class CheckoutRulesTest extends ApiTestCase
{
    private function product(array $h, array $o): array
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $h)->json('data.id');

        return $this->postJson('/api/v1/stock-items', $o + ['stock_sub_category_id' => $sub, 'buying_price' => 5], $h)->assertStatus(201)->json('data');
    }

    public function test_rules_and_price_changes(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $p = $this->product($h, ['name' => 'Rules soap', 'selling_price' => 1000, 'buying_price' => 600, 'original_quantity' => 20]);
        $pack = $this->postJson('/api/v1/units', ['name' => 'Box of 12', 'abbreviation' => 'bx', 'factor' => 12], $h)->assertStatus(201)->json('data');
        $other = $this->registerTenant();
        $theirs = $this->product($this->auth($other['token']), ['name' => 'Not mine', 'selling_price' => 10, 'original_quantity' => 1]);
        $cid = (int) $t['company_id'];

        $ok = ['client_uuid' => '0b3a8d44-6b8e-4f58-9a55-1b8e2b0f7c11', 'items' => [['stock_item_id' => $p['id'], 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 2000]]];
        $this->assertFalse(Validator::make($ok, CheckoutRules::rules($cid))->fails());
        $this->assertTrue(Validator::make(['items' => []], CheckoutRules::rules($cid))->fails());
        $this->assertTrue(Validator::make(['items' => [['stock_item_id' => $theirs['id'], 'quantity' => 1]]], CheckoutRules::rules($cid))->fails(), 'another shop\'s product');
        $this->assertTrue(Validator::make(['items' => [['stock_item_id' => $p['id'], 'quantity' => 0]]], CheckoutRules::rules($cid))->fails());
        $this->assertTrue(Validator::make(['client_uuid' => 'not-a-uuid'] + $ok, ['client_uuid' => CheckoutRules::rules($cid)['client_uuid']])->fails());

        $this->assertFalse(CheckoutRules::changesPrices($cid, $ok));
        $this->assertFalse(CheckoutRules::changesPrices($cid, ['items' => [['stock_item_id' => $p['id'], 'quantity' => 1, 'unit_price' => 1000]]]));
        $this->assertFalse(CheckoutRules::changesPrices($cid, ['items' => [['stock_item_id' => $p['id'], 'quantity' => 1, 'unit_id' => $pack['id'], 'unit_price' => 12000]]]), 'a pack at 12 × the price is the list price');
        $this->assertTrue(CheckoutRules::changesPrices($cid, ['items' => [['stock_item_id' => $p['id'], 'quantity' => 1, 'unit_price' => 900]]]));
        $this->assertTrue(CheckoutRules::changesPrices($cid, ['items' => [['stock_item_id' => $p['id'], 'quantity' => 1, 'discount_amount' => 50]]]));
        $this->assertTrue(CheckoutRules::changesPrices($cid, ['discount_amount' => 10, 'items' => [['stock_item_id' => $p['id'], 'quantity' => 1]]]));

        // The API uses the same rules: a missing item list is a 422 with the field named.
        $this->postJson('/api/v1/sales/checkout', ['items' => []], $h)->assertStatus(422)->assertJsonValidationErrors('items');
    }
}
