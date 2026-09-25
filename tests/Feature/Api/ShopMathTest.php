<?php

namespace Tests\Feature\Api;

use App\Models\FinancialRecord;
use App\Models\Payment;
use App\Models\SaleRecord;
use App\Models\StockRecord;
use Illuminate\Support\Str;

/**
 * Plan §A2 acceptance tests for the shop maths (P0-4..P0-11).
 */
class ShopMathTest extends ApiTestCase
{
    private function product(array $h, array $overrides = []): int
    {
        static $seq = 0;
        $seq++;
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'Cat '.$seq.uniqid()], $h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'Sub '.$seq.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $h)->json('data.id');
        $res = $this->postJson('/api/v1/stock-items', array_merge([
            'name' => 'Item '.$seq, 'stock_sub_category_id' => $sub,
            'selling_price' => 1000, 'buying_price' => 600, 'original_quantity' => 10,
        ], $overrides), $h);
        $res->assertStatus(201);

        return (int) $res->json('data.id');
    }

    private function qty(array $h, int $item): float
    {
        return (float) $this->getJson("/api/v1/stock-items/{$item}", $h)->json('data.current_quantity');
    }

    public function test_checkout_is_atomic_and_stock_movement_carries_signed_delta(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $item = $this->product($h);

        $res = $this->postJson('/api/v1/sales/checkout', [
            'amount_paid' => 3000,
            'items' => [['stock_item_id' => $item, 'quantity' => 3]],
        ], $h);
        $res->assertStatus(201)
            ->assertJsonPath('data.total_amount', '3000.00')
            ->assertJsonPath('data.amount_paid', '3000.00')
            ->assertJsonPath('data.balance', '0.00')
            ->assertJsonPath('data.payment_status', 'Paid');

        $this->assertSame(7.0, $this->qty($h, $item));

        $movement = StockRecord::withoutGlobalScopes()->where('sale_record_id', $res->json('data.id'))->first();
        $this->assertSame('-3.000', $movement->quantity_delta);
        $this->assertSame('3.000', $movement->quantity);
        $this->assertSame('1200.00', $movement->profit); // (1000-600)*3

        // One ledger row per payment, linked back to its source.
        $ledger = FinancialRecord::withoutGlobalScopes()->where('company_id', $t['company_id'])->where('source_type', 'payment')->get();
        $this->assertCount(1, $ledger);
        $this->assertSame('3000.00', $ledger->first()->amount);
        $this->assertSame('Income', $ledger->first()->type);
    }

    public function test_checkout_with_client_uuid_is_idempotent(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $item = $this->product($h);
        $uuid = (string) Str::uuid();

        $payload = ['client_uuid' => $uuid, 'amount_paid' => 2000, 'items' => [['stock_item_id' => $item, 'quantity' => 2]]];
        $first = $this->postJson('/api/v1/sales/checkout', $payload, $h)->assertStatus(201);
        $second = $this->postJson('/api/v1/sales/checkout', $payload, $h)->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(8.0, $this->qty($h, $item), 'a replay must not deduct stock twice');
        $this->assertSame(1, SaleRecord::withoutGlobalScopes()->where('company_id', $t['company_id'])->count());
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('company_id', $t['company_id'])->count());
    }

    public function test_credit_sale_posts_nothing_until_payment_then_ledger_matches(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $item = $this->product($h);

        $sale = $this->postJson('/api/v1/sales/checkout', [
            'customer_name' => 'Credit Customer', 'payment_method' => 'credit', 'amount_paid' => 0,
            'items' => [['stock_item_id' => $item, 'quantity' => 4]],
        ], $h)->assertStatus(201)
            ->assertJsonPath('data.payment_status', 'Unpaid')
            ->assertJsonPath('data.balance', '4000.00');
        $id = $sale->json('data.id');

        $this->assertSame(0, FinancialRecord::withoutGlobalScopes()->where('company_id', $t['company_id'])->where('type', 'Income')->count(), 'credit sale must not post income');
        $this->assertSame(6.0, $this->qty($h, $item), 'stock leaves at sale time even on credit');

        // Partial payment.
        $this->postJson("/api/v1/sales/{$id}/payments", ['amount' => 1500, 'method' => 'mobile_money', 'reference' => 'MM123'], $h)
            ->assertStatus(201)
            ->assertJsonPath('data.sale.amount_paid', '1500.00')
            ->assertJsonPath('data.sale.balance', '2500.00')
            ->assertJsonPath('data.sale.payment_status', 'Partial');

        // Second payment settles it.
        $this->postJson("/api/v1/sales/{$id}/payments", ['amount' => 2500, 'method' => 'cash'], $h)
            ->assertStatus(201)
            ->assertJsonPath('data.sale.balance', '0.00')
            ->assertJsonPath('data.sale.payment_status', 'Paid');

        $income = FinancialRecord::withoutGlobalScopes()->where('company_id', $t['company_id'])->where('type', 'Income')->get();
        $this->assertCount(2, $income);
        $this->assertSame(4000.0, (float) $income->sum('amount'));
        $this->assertSame(['mobile_money', 'cash'], $income->sortBy('id')->pluck('payment_method')->values()->all());

        // Overpaying is refused: payments never exceed the balance silently.
        $this->postJson("/api/v1/sales/{$id}/payments", ['amount' => 1], $h)->assertStatus(201);
        $this->assertSame('4000.00', SaleRecord::withoutGlobalScopes()->find($id)->amount_paid);
        $this->assertSame('1.00', SaleRecord::withoutGlobalScopes()->find($id)->change_given);
    }

    public function test_marking_paid_via_update_records_a_real_payment(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $item = $this->product($h);
        $id = $this->postJson('/api/v1/sales/checkout', ['amount_paid' => 0, 'items' => [['stock_item_id' => $item, 'quantity' => 1]]], $h)->json('data.id');

        $this->patchJson("/api/v1/sales/{$id}", ['payment_status' => 'Paid'], $h)->assertOk()
            ->assertJsonPath('data.payment_status', 'Paid')->assertJsonPath('data.amount_paid', '1000.00');
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('sale_record_id', $id)->count());
        $this->assertSame(1, FinancialRecord::withoutGlobalScopes()->where('source_type', 'payment')->where('company_id', $t['company_id'])->count());

        // Lowering amount_paid directly is refused (reverse the payment instead).
        $this->patchJson("/api/v1/sales/{$id}", ['amount_paid' => 0], $h)->assertStatus(422)->assertJsonPath('errors.code', 'use_payment_reversal');
    }

    public function test_void_reverses_stock_and_ledger_without_deleting(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $item = $this->product($h);
        $id = $this->postJson('/api/v1/sales/checkout', ['amount_paid' => 2000, 'items' => [['stock_item_id' => $item, 'quantity' => 2]]], $h)->json('data.id');
        $this->assertSame(8.0, $this->qty($h, $item));

        $this->deleteJson("/api/v1/sales/{$id}", [], $h)->assertStatus(422)->assertJsonPath('errors.code', 'delete_not_allowed');

        $this->postJson("/api/v1/sales/{$id}/void", ['reason' => 'customer changed mind'], $h)->assertOk()
            ->assertJsonPath('data.is_voided', true)->assertJsonPath('data.status', 'Voided')->assertJsonPath('data.amount_paid', '0.00');

        $this->assertSame(10.0, $this->qty($h, $item));
        $this->assertSame(2, StockRecord::withoutGlobalScopes()->where('sale_record_id', $id)->count());
        $this->assertSame(0.0, (float) FinancialRecord::withoutGlobalScopes()->where('company_id', $t['company_id'])->sum('amount'), 'contra row nets the ledger to zero');
        $this->assertSame(2, FinancialRecord::withoutGlobalScopes()->where('company_id', $t['company_id'])->count(), 'nothing deleted');

        // Idempotent.
        $this->postJson("/api/v1/sales/{$id}/void", [], $h)->assertOk();
        $this->assertSame(10.0, $this->qty($h, $item));
        $this->postJson("/api/v1/sales/{$id}/payments", ['amount' => 5], $h)->assertStatus(422)->assertJsonPath('errors.code', 'sale_voided');
    }

    public function test_discounts_flow_into_totals_movements_and_ledger(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $a = $this->product($h, ['selling_price' => 1000, 'buying_price' => 600]);
        $b = $this->product($h, ['selling_price' => 500, 'buying_price' => 300]);

        $res = $this->postJson('/api/v1/sales/checkout', [
            'discount_amount' => 300, 'discount_reason' => 'loyal',
            'amount_paid' => 10000,
            'items' => [
                ['stock_item_id' => $a, 'quantity' => 2, 'discount_amount' => 200], // 2000 - 200 = 1800
                ['stock_item_id' => $b, 'quantity' => 2],                            // 1000
            ],
        ], $h)->assertStatus(201);
        // net before header discount 2800; header 300 → total 2500; change 7500
        $res->assertJsonPath('data.subtotal', '3000.00')
            ->assertJsonPath('data.discount_amount', '500.00')
            ->assertJsonPath('data.total_amount', '2500.00')
            ->assertJsonPath('data.amount_paid', '2500.00')
            ->assertJsonPath('data.change_given', '7500.00');

        $lines = collect($res->json('data.sale_record_items'));
        $this->assertSame(2500.0, (float) $lines->sum('line_total'));
        $movements = StockRecord::withoutGlobalScopes()->where('sale_record_id', $res->json('data.id'))->get();
        $this->assertSame(2500.0, (float) $movements->sum('total_sales'), 'movement revenue reflects the discounted price');
        $this->assertSame(2500.0 - (2 * 600 + 2 * 300), (float) $movements->sum('profit'));
        $this->assertSame(2500.0, (float) FinancialRecord::withoutGlobalScopes()->where('company_id', $t['company_id'])->sum('amount'), 'change is not income');
    }

    public function test_receipt_numbers_are_per_company_sequences(): void
    {
        $a = $this->registerTenant();
        $b = $this->registerTenant();
        $ha = $this->auth($a['token']);
        $hb = $this->auth($b['token']);
        $ia = $this->product($ha);
        $ib = $this->product($hb);

        $r1 = $this->postJson('/api/v1/sales/checkout', ['items' => [['stock_item_id' => $ia, 'quantity' => 1]]], $ha)->json('data.receipt_number');
        $r2 = $this->postJson('/api/v1/sales/checkout', ['items' => [['stock_item_id' => $ia, 'quantity' => 1]]], $ha)->json('data.receipt_number');
        $rb = $this->postJson('/api/v1/sales/checkout', ['items' => [['stock_item_id' => $ib, 'quantity' => 1]]], $hb)->json('data.receipt_number');

        $year = now()->format('Y');
        $this->assertSame("RCP-{$year}-000001", $r1);
        $this->assertSame("RCP-{$year}-000002", $r2);
        $this->assertSame("RCP-{$year}-000001", $rb, 'each company has its own sequence');
    }

    public function test_stock_record_reverse_and_immutability(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $item = $this->product($h);

        $this->getJson('/api/v1/stock-records/types', $h)->assertOk()->assertJsonPath('data.inbound.0', 'Stock In');

        $in = $this->postJson('/api/v1/stock-records', ['stock_item_id' => $item, 'type' => 'Stock In', 'quantity' => 5, 'client_uuid' => (string) Str::uuid()], $h)->assertStatus(201);
        $this->assertSame(15.0, $this->qty($h, $item));
        $this->assertSame('5.000', $in->json('data.quantity_delta'));

        $out = $this->postJson('/api/v1/stock-records', ['stock_item_id' => $item, 'type' => 'Damage', 'quantity' => 4], $h)->assertStatus(201);
        $this->assertSame(11.0, $this->qty($h, $item));

        $this->postJson('/api/v1/stock-records', ['stock_item_id' => $item, 'type' => 'Lost', 'quantity' => 50], $h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'insufficient_stock');
        $this->assertSame(11.0, $this->qty($h, $item));

        $this->putJson('/api/v1/stock-records/'.$out->json('data.id'), ['quantity' => 1], $h)->assertStatus(422);
        $this->deleteJson('/api/v1/stock-records/'.$out->json('data.id'), [], $h)->assertStatus(422);

        $contra = $this->postJson('/api/v1/stock-records/'.$out->json('data.id').'/reverse', ['reason' => 'counted wrong'], $h)->assertStatus(201);
        $this->assertSame(15.0, $this->qty($h, $item));
        $this->assertTrue($contra->json('data.is_reversal'));
        $this->assertSame('4.000', $contra->json('data.quantity_delta'));

        // Reversing twice returns the same contra row.
        $again = $this->postJson('/api/v1/stock-records/'.$out->json('data.id').'/reverse', [], $h)->assertStatus(201);
        $this->assertSame($contra->json('data.id'), $again->json('data.id'));
        $this->assertSame(15.0, $this->qty($h, $item));

        // A sale's movement is undone by voiding or returning on the sale, never on its own.
        $sale = $this->postJson('/api/v1/sales/checkout', ['items' => [['stock_item_id' => $item, 'quantity' => 1]], 'amount_paid' => 100000], $h)->assertStatus(201);
        $saleMove = \App\Models\StockRecord::withoutGlobalScopes()->where('sale_record_id', $sale->json('data.id'))->value('id');
        $this->postJson('/api/v1/stock-records/'.$saleMove.'/reverse', [], $h)->assertStatus(422)->assertJsonPath('errors.code', 'movement_belongs_to_document');
        $this->assertSame(14.0, $this->qty($h, $item));
    }

    public function test_sale_dated_in_closed_period_is_refused_and_duplicate_category_is_422(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $item = $this->product($h);

        $active = \App\Models\FinancialPeriod::withoutGlobalScopes()->where('company_id', $t['company_id'])->where('status', 'Active')->first();
        $closed = new \App\Models\FinancialPeriod(['company_id' => $t['company_id'], 'name' => 'Old year', 'start_date' => '2020-01-01', 'end_date' => '2020-12-31', 'status' => 'Closed']);
        $closed->save();
        $this->assertNotNull($closed->closed_at);
        $this->assertSame('Active', $active->fresh()->status);

        $this->postJson('/api/v1/sales/checkout', ['sale_date' => '2020-06-15', 'items' => [['stock_item_id' => $item, 'quantity' => 1]]], $h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'period_closed');
        $this->assertSame(10.0, $this->qty($h, $item));

        $this->postJson('/api/v1/financial-categories', ['name' => 'Rent'], $h)->assertStatus(201);
        $this->postJson('/api/v1/financial-categories', ['name' => 'rent'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'duplicate_category');
    }

    public function test_low_stock_uses_per_product_threshold(): void
    {
        $t = $this->registerTenant();
        $h = $this->auth($t['token']);
        $this->product($h, ['original_quantity' => 50, 'min_stock' => 60]); // low by its own threshold
        $this->product($h, ['original_quantity' => 50]);                    // fine by the default (10)
        $this->product($h, ['original_quantity' => 3]);                     // low by the default

        $this->getJson('/api/v1/dashboard', $h)->assertOk()->assertJsonPath('data.inventory.low_stock_count', 2);
    }
}
