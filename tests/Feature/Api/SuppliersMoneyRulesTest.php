<?php

namespace Tests\Feature\Api;

use App\Models\FinancialRecord;
use App\Models\Supplier;
use App\Services\Shop\GoodsReceiptService;
use App\Services\Shop\PurchaseReturnService;
use App\Services\Shop\SupplierService;

/**
 * The shared rules (App\Support\Rules\{Supplier,FinancialRecord,FinancialCategory,FinancialPeriod,Shift}Rules)
 * the API and the new web interface both validate with, and SupplierService::statement.
 */
class SuppliersMoneyRulesTest extends ApiTestCase
{
    private array $t;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->registerTenant();
        $this->h = $this->auth($this->t['token']);
    }

    private function product(): int
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $this->h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $this->h)->json('data.id');

        return (int) $this->postJson('/api/v1/stock-items', ['name' => 'P'.uniqid(), 'stock_sub_category_id' => $sub, 'selling_price' => 1000, 'buying_price' => 600, 'original_quantity' => 10], $this->h)
            ->assertStatus(201)->json('data.id');
    }

    public function test_supplier_rules_and_statement_match_the_balance(): void
    {
        $id = $this->postJson('/api/v1/suppliers', ['name' => 'Mukwano', 'phone' => '0772', 'lead_time_days' => 5, 'payment_terms_days' => 30], $this->h)
            ->assertStatus(201)->assertJsonPath('data.lead_time_days', 5)->json('data.id');
        $this->postJson('/api/v1/suppliers', ['name' => 'Mukwano'], $this->h)->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/suppliers', ['name' => 'Other', 'lead_time_days' => 999], $this->h)->assertStatus(422)->assertJsonValidationErrors('lead_time_days');
        $this->putJson("/api/v1/suppliers/{$id}", ['notes' => 'Delivers Tuesdays'], $this->h)->assertOk();

        $p = $this->product();
        (new GoodsReceiptService())->receive($this->t['company_id'], $this->t['user_id'], [['stock_item_id' => $p, 'quantity' => 10, 'unit_cost' => 500]], $id, 'INV-1', 1000);
        $this->postJson("/api/v1/suppliers/{$id}/payments", ['amount' => 0], $this->h)->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/suppliers/{$id}/payments", ['amount' => 1500, 'method' => 'mobile_money', 'reference' => 'MM1'], $this->h)->assertStatus(201);
        (new PurchaseReturnService())->create($this->t['company_id'], $this->t['user_id'], [['stock_item_id' => $p, 'quantity' => 2, 'unit_cost' => 500]], $id, 'Broken', 300);

        $s = Supplier::withoutGlobalScopes()->find($id);
        $balance = (new SupplierService())->balance($s);
        $this->assertEquals(5000 - 1000 - 1500 - (1000 - 300), $balance);
        $st = (new SupplierService())->statement($s);
        $this->assertEquals($balance, $st['closing_balance']);
        $this->assertCount(5, $st['entries']);
        $api = $this->getJson("/api/v1/suppliers/{$id}/statement", $this->h)->assertOk()->assertJsonCount(5, 'data.entries');
        $this->assertEquals($balance, $api->json('data.closing_balance'));
    }

    public function test_finance_category_type_status_and_in_use_delete(): void
    {
        $inc = $this->postJson('/api/v1/financial-categories', ['name' => 'Consulting', 'type' => 'Income'], $this->h)->assertStatus(201)->assertJsonPath('data.type', 'Income')->json('data.id');
        $this->postJson('/api/v1/financial-categories', ['name' => 'Bad', 'type' => 'Loan'], $this->h)->assertStatus(422)->assertJsonValidationErrors('type');
        $this->putJson("/api/v1/financial-categories/{$inc}", ['status' => 'Inactive'], $this->h)->assertOk()->assertJsonPath('data.status', 'Inactive');

        $this->postJson('/api/v1/financial-records', ['financial_category_id' => $inc, 'type' => 'Income', 'amount' => 5000], $this->h)->assertStatus(201);
        $this->deleteJson("/api/v1/financial-categories/{$inc}", [], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'category_in_use');
        $unused = $this->postJson('/api/v1/financial-categories', ['name' => 'Unused'], $this->h)->json('data.id');
        $this->deleteJson("/api/v1/financial-categories/{$unused}", [], $this->h)->assertOk();
    }

    public function test_record_period_and_shift_rules(): void
    {
        $this->postJson('/api/v1/financial-records', ['type' => 'Expense', 'amount' => 10], $this->h)->assertStatus(422)->assertJsonValidationErrors('financial_category_id');
        $this->postJson('/api/v1/financial-periods', ['name' => 'Bad', 'start_date' => '2026-02-01', 'end_date' => '2026-01-01'], $this->h)->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->postJson('/api/v1/shifts/open', ['opening_float' => -1], $this->h)->assertStatus(422)->assertJsonValidationErrors('opening_float');
        $shift = $this->postJson('/api/v1/shifts/open', ['opening_float' => 20000], $this->h)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/shifts/{$shift}/close", [], $this->h)->assertStatus(422)->assertJsonValidationErrors('counted_cash');
        $this->postJson("/api/v1/shifts/{$shift}/close", ['counted_cash' => 19000], $this->h)->assertOk()->assertJsonPath('data.variance', '-1000.00');
        $this->assertSame(0, FinancialRecord::withoutGlobalScopes()->where('company_id', $this->t['company_id'])->where('source_type', 'shift')->count());
    }

    public function test_a_period_can_be_closed_only_when_finished_and_not_active(): void
    {
        $cid = $this->t['company_id'];
        $active = \App\Models\FinancialPeriod::withoutGlobalScopes()->where('company_id', $cid)->where('status', 'Active')->firstOrFail();
        $this->assertStringContainsString('active period', (string) \App\Support\Rules\FinancialPeriodRules::closingBlocker($active));

        $running = new \App\Models\FinancialPeriod(['company_id' => $cid, 'name' => 'Running', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(), 'status' => 'Inactive']);
        $running->save();
        $this->assertStringContainsString('runs until', (string) \App\Support\Rules\FinancialPeriodRules::closingBlocker($running));

        $old = new \App\Models\FinancialPeriod(['company_id' => $cid, 'name' => 'Old', 'start_date' => '2020-01-01', 'end_date' => '2020-12-31', 'status' => 'Inactive']);
        $old->save();
        $this->assertNull(\App\Support\Rules\FinancialPeriodRules::closingBlocker($old));
        $old->status = 'Closed';
        $old->save();
        $this->assertStringContainsString('already closed', (string) \App\Support\Rules\FinancialPeriodRules::closingBlocker($old->fresh()));
    }
}
