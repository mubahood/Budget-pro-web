<?php

namespace Tests\Feature\Api;

use App\Models\FinancialRecord;
use App\Models\SaleRecord;
use App\Models\StockItem;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Str;

/**
 * Phase 2 acceptance (P2-1..P2-8, P2-11): units, credit & debt book, returns with
 * ledger symmetry, cash-up, stock takes, goods receipts & suppliers, receipts,
 * and the same flows arriving through offline sync.
 */
class PosInventoryTest extends ApiTestCase
{
    private array $t;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->registerTenant();
        $this->t['device'] = (string) Str::uuid();
        $this->h = $this->auth($this->t['token']) + ['X-Device-Id' => $this->t['device']];
        $this->postJson('/api/v1/devices/register', ['device_id' => $this->t['device']], $this->auth($this->t['token']))->assertOk();
    }

    private function product(array $o = []): array
    {
        $cat = $this->postJson('/api/v1/stock-categories', ['name' => 'C'.uniqid()], $this->h)->json('data.id');
        $sub = $this->postJson('/api/v1/stock-sub-categories', ['name' => 'S'.uniqid(), 'stock_category_id' => $cat, 'measurement_unit' => 'pcs'], $this->h)->json('data.id');
        $r = $this->postJson('/api/v1/stock-items', array_merge(['name' => 'Soda '.uniqid(), 'stock_sub_category_id' => $sub, 'selling_price' => 1000, 'buying_price' => 600, 'original_quantity' => 48], $o), $this->h);
        $r->assertStatus(201);

        return $r->json('data');
    }

    private function qty(int $id): float
    {
        return (float) StockItem::withoutGlobalScopes()->find($id)->current_quantity;
    }

    public function test_selling_by_the_crate_moves_base_units_and_prices_per_crate(): void
    {
        $p = $this->product();
        $crate = $this->postJson('/api/v1/units', ['name' => 'Crate', 'abbreviation' => 'crt', 'factor' => 24], $this->h)->assertStatus(201)->json('data.id');
        $this->postJson('/api/v1/product-barcodes', ['stock_item_id' => $p['id'], 'barcode' => '6001234500017', 'unit_id' => $crate], $this->h)->assertStatus(201);
        $this->postJson('/api/v1/product-barcodes', ['stock_item_id' => $p['id'], 'barcode' => '6001234500017'], $this->h)->assertStatus(422);

        $scan = $this->getJson('/api/v1/stock-items/by-barcode/6001234500017', $this->h)->assertOk();
        $this->assertSame($p['id'], $scan->json('data.id'));
        $this->assertSame($crate, $scan->json('data.scanned_unit_id'));

        $sale = $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'cash', 'amount' => 24000]], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 1, 'unit_id' => $crate]]], $this->h)->assertStatus(201);
        $sale->assertJsonPath('data.total_amount', '24000.00')->assertJsonPath('data.sale_record_items.0.unit_price', '24000.00');
        $this->assertSame(24.0, $this->qty($p['id']));
        $this->assertSame('9600.00', $sale->json('data.sale_record_items.0.profit')); // (1000-600) x 24
    }

    public function test_service_items_do_not_touch_stock(): void
    {
        $p = $this->product(['name' => 'Delivery fee', 'track_stock' => false, 'original_quantity' => 0, 'selling_price' => 3000]);
        $this->postJson('/api/v1/sales/checkout', ['items' => [['stock_item_id' => $p['id'], 'quantity' => 2]], 'amount_paid' => 6000], $this->h)->assertStatus(201);
        $this->assertSame(0.0, $this->qty($p['id']));
    }

    public function test_credit_needs_a_customer_respects_limits_and_the_debt_book_settles_oldest_first(): void
    {
        $p = $this->product();
        $this->postJson('/api/v1/sales/checkout', ['payments' => [], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 2]]], $this->h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'customer_required');

        $c = $this->postJson('/api/v1/customers', ['name' => 'Mama Grace', 'phone' => '0772000111', 'credit_limit' => 5000], $this->h)->assertStatus(201)->json('data');
        $s1 = $this->postJson('/api/v1/sales/checkout', ['customer_id' => $c['id'], 'payments' => [], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 3]]], $this->h)->assertStatus(201)->json('data');
        $this->assertSame('Mama Grace', $s1['customer_name']);
        $s2 = $this->postJson('/api/v1/sales/checkout', ['customer_id' => $c['id'], 'payments' => [['method' => 'cash', 'amount' => 500]], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 2]]], $this->h)->assertStatus(201)->json('data');
        $this->getJson('/api/v1/customers/'.$c['id'], $this->h)->assertJsonPath('data.balance', '4500.00');

        $this->postJson('/api/v1/sales/checkout', ['customer_id' => $c['id'], 'payments' => [], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 1]]], $this->h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'credit_limit_exceeded');

        // 4,000 received: 3,000 closes the oldest sale, 1,000 goes to the next.
        $this->postJson('/api/v1/customers/'.$c['id'].'/payments', ['amount' => 4000, 'method' => 'mobile_money', 'reference' => 'MP123', 'client_uuid' => (string) Str::uuid()], $this->h)->assertStatus(201);
        $this->assertSame('Paid', SaleRecord::withoutGlobalScopes()->find($s1['id'])->payment_status);
        $this->assertSame('500.00', SaleRecord::withoutGlobalScopes()->find($s2['id'])->balance);
        $this->getJson('/api/v1/customers/'.$c['id'], $this->h)->assertJsonPath('data.balance', '500.00');

        // Overpayment stays on account as credit.
        $this->postJson('/api/v1/customers/'.$c['id'].'/payments', ['amount' => 1500], $this->h)->assertStatus(201);
        $this->getJson('/api/v1/customers/'.$c['id'], $this->h)->assertJsonPath('data.balance', '-1000.00');

        $st = $this->getJson('/api/v1/customers/'.$c['id'].'/statement', $this->h)->assertOk()->json('data');
        $this->assertSame(-1000.0, (float) $st['closing_balance']);
        $this->assertSame(['sale', 'payment'], array_values(array_unique(array_column($st['entries'], 'type'))));

        // Phone-identified buyers join the debt book automatically on the legacy path.
        $this->postJson('/api/v1/sales/checkout', ['customer_name' => 'Okello', 'customer_phone' => '0700999888', 'amount_paid' => 0, 'items' => [['stock_item_id' => $p['id'], 'quantity' => 1]]], $this->h)->assertStatus(201);
        $this->getJson('/api/v1/customers?q=Okello', $this->h)->assertJsonPath('data.0.balance', '1000.00');
    }

    public function test_partial_return_refunds_cash_restocks_and_reverses_income(): void
    {
        $p = $this->product();
        $sale = $this->postJson('/api/v1/sales/checkout', ['payments' => [['method' => 'cash', 'amount' => 5000]], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 5]]], $this->h)->json('data');
        $this->assertSame(43.0, $this->qty($p['id']));
        $line = $sale['sale_record_items'][0]['id'];

        $this->postJson("/api/v1/sales/{$sale['id']}/returns", ['items' => [['sale_item_id' => $line, 'quantity' => 6]]], $this->h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'invalid_return_quantity');

        $r = $this->postJson("/api/v1/sales/{$sale['id']}/returns", ['reason' => 'Expired stock', 'items' => [['sale_item_id' => $line, 'quantity' => 2]]], $this->h)->assertStatus(201);
        $r->assertJsonPath('data.return.value', '2000.00')->assertJsonPath('data.return.refund_amount', '2000.00')
            ->assertJsonPath('data.sale.status', 'Partially Refunded')->assertJsonPath('data.sale.balance', '0.00');
        $this->assertSame(45.0, $this->qty($p['id']));
        $this->assertSame(3000.0, (float) FinancialRecord::withoutGlobalScopes()->where('company_id', $this->t['company_id'])->where('type', 'Income')->sum('amount'));

        // Damaged goods: refund without restocking; everything returned => Refunded.
        $this->postJson("/api/v1/sales/{$sale['id']}/returns", ['items' => [['sale_item_id' => $line, 'quantity' => 3, 'restock' => false]]], $this->h)
            ->assertStatus(201)->assertJsonPath('data.sale.status', 'Refunded');
        $this->assertSame(45.0, $this->qty($p['id']));
        $this->assertSame(0.0, (float) FinancialRecord::withoutGlobalScopes()->where('company_id', $this->t['company_id'])->where('type', 'Income')->sum('amount'));
        $this->assertSame(0.0, (float) \App\Models\SaleRecordItem::withoutGlobalScopes()->find($line)->profit);
    }

    public function test_return_on_a_credit_sale_reduces_what_is_owed_instead_of_paying_cash(): void
    {
        $p = $this->product();
        $c = $this->postJson('/api/v1/customers', ['name' => 'Wasswa'], $this->h)->json('data');
        $sale = $this->postJson('/api/v1/sales/checkout', ['customer_id' => $c['id'], 'payments' => [['method' => 'cash', 'amount' => 1000]], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 4]]], $this->h)->json('data');
        $r = $this->postJson("/api/v1/sales/{$sale['id']}/returns", ['items' => [['sale_item_id' => $sale['sale_record_items'][0]['id'], 'quantity' => 2]]], $this->h)->assertStatus(201);
        $r->assertJsonPath('data.return.refund_amount', '0.00')->assertJsonPath('data.sale.balance', '1000.00');
        $this->getJson('/api/v1/customers/'.$c['id'], $this->h)->assertJsonPath('data.balance', '1000.00');
    }

    public function test_shift_cash_up_counts_cash_in_and_refunds_out(): void
    {
        $p = $this->product();
        $shift = $this->postJson('/api/v1/shifts/open', ['opening_float' => 10000], $this->h)->assertStatus(201)->json('data');
        $this->postJson('/api/v1/shifts/open', ['opening_float' => 0], $this->h)->assertStatus(422)->assertJsonPath('errors.code', 'shift_already_open');
        $this->getJson('/api/v1/shifts/current', $this->h)->assertJsonPath('data.id', $shift['id']);

        $s = $this->postJson('/api/v1/sales/checkout', ['shift_id' => $shift['id'], 'payments' => [['method' => 'cash', 'amount' => 3000]], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 3]]], $this->h)->json('data');
        $this->postJson('/api/v1/sales/checkout', ['shift_id' => $shift['id'], 'payments' => [['method' => 'mobile_money', 'amount' => 2000, 'reference' => 'X1']], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 2]]], $this->h);
        $this->postJson("/api/v1/sales/{$s['id']}/returns", ['shift_id' => $shift['id'], 'items' => [['sale_item_id' => $s['sale_record_items'][0]['id'], 'quantity' => 1]]], $this->h)->assertStatus(201);

        $closed = $this->postJson("/api/v1/shifts/{$shift['id']}/close", ['counted_cash' => 11500], $this->h)->assertOk()->json('data');
        $this->assertSame('12000.00', $closed['expected_cash']); // 10,000 float + 3,000 cash − 1,000 refunded
        $this->assertSame('-500.00', $closed['variance']);
        $this->assertSame(2, $closed['sales_count']);
        $this->assertSame('4000.00', $closed['sales_total']);
        $this->postJson('/api/v1/sales/checkout', ['shift_id' => $shift['id'], 'items' => [['stock_item_id' => $p['id'], 'quantity' => 1]], 'amount_paid' => 1000], $this->h)
            ->assertStatus(422)->assertJsonPath('errors.code', 'shift_closed');
    }

    public function test_stock_take_sets_on_hand_to_the_count(): void
    {
        $a = $this->product();
        $b = $this->product();
        $take = $this->postJson('/api/v1/stock-takes', ['name' => 'Month end'], $this->h)->assertStatus(201)->json('data');
        $this->postJson("/api/v1/stock-takes/{$take['id']}/counts", ['counts' => [['stock_item_id' => $a['id'], 'counted_quantity' => 45], ['stock_item_id' => $b['id'], 'counted_quantity' => 50]]], $this->h)->assertOk();
        // A sale happens between counting and posting: posting uses the latest on-hand.
        $this->postJson('/api/v1/sales/checkout', ['items' => [['stock_item_id' => $a['id'], 'quantity' => 1]], 'amount_paid' => 1000], $this->h);
        $posted = $this->postJson("/api/v1/stock-takes/{$take['id']}/post", [], $this->h)->assertOk()->json('data');
        $this->assertSame('posted', $posted['status']);
        $this->assertSame(45.0, $this->qty($a['id']));
        $this->assertSame(50.0, $this->qty($b['id']));
        $this->postJson("/api/v1/stock-takes/{$take['id']}/counts", ['counts' => [['stock_item_id' => $a['id'], 'counted_quantity' => 1]]], $this->h)->assertStatus(422);
    }

    public function test_goods_receipt_updates_cost_stock_supplier_balance_and_ledger(): void
    {
        $p = $this->product();
        $sup = $this->postJson('/api/v1/suppliers', ['name' => 'Crown Beverages', 'phone' => '0414000000'], $this->h)->assertStatus(201)->json('data');
        $grn = $this->postJson('/api/v1/goods-receipts', ['supplier_id' => $sup['id'], 'invoice_ref' => 'INV-77', 'amount_paid' => 20000, 'items' => [['stock_item_id' => $p['id'], 'quantity' => 48, 'unit_cost' => 650]]], $this->h)->assertStatus(201)->json('data');
        $this->assertStringStartsWith('GRN-', $grn['number']);
        $this->assertSame('31200.00', $grn['total_cost']);
        $this->assertSame(96.0, $this->qty($p['id']));
        $this->assertSame('650.00', StockItem::withoutGlobalScopes()->find($p['id'])->buying_price);
        $this->getJson('/api/v1/suppliers/'.$sup['id'], $this->h)->assertJsonPath('data.balance', '11200.00');
        $this->assertSame(20000.0, (float) FinancialRecord::withoutGlobalScopes()->where('company_id', $this->t['company_id'])->where('type', 'Expense')->sum('amount'));

        $this->postJson('/api/v1/suppliers/'.$sup['id'].'/payments', ['amount' => 11200, 'method' => 'bank'], $this->h)->assertStatus(201);
        $this->getJson('/api/v1/suppliers/'.$sup['id'], $this->h)->assertJsonPath('data.balance', '0.00');
        $this->assertSame(0.0, (float) $this->getJson('/api/v1/suppliers/'.$sup['id'].'/statement', $this->h)->json('data.closing_balance'));
    }

    public function test_adjustment_with_reason_and_photo(): void
    {
        $p = $this->product();
        $this->postJson('/api/v1/stock-records', ['stock_item_id' => $p['id'], 'type' => 'Damage', 'quantity' => 2, 'reason' => 'damage', 'image' => 'files/1/abc.jpg'], $this->h)
            ->assertStatus(201)->assertJsonPath('data.reason', 'damage')->assertJsonPath('data.image', 'files/1/abc.jpg');
        $this->postJson('/api/v1/stock-records', ['stock_item_id' => $p['id'], 'type' => 'Damage', 'quantity' => 1, 'reason' => 'because'], $this->h)->assertStatus(422);
        $this->assertContains('expired', $this->getJson('/api/v1/stock-records/types', $this->h)->json('data.reasons'));
    }

    public function test_receipt_text_matches_the_golden_file_and_pdf_renders(): void
    {
        \App\Models\Company::where('id', $this->t['company_id'])->update(['name' => 'Kampala Corner Shop', 'phone_number' => '0772123456', 'receipt_footer' => 'Webale nnyo!']);
        \App\Support\Money::flush();
        $p = $this->product(['name' => 'Rwenzori Water 500ml', 'selling_price' => 1500]);
        $sale = $this->postJson('/api/v1/sales/checkout', ['customer_name' => 'Nakato', 'discount_amount' => 500, 'payments' => [['method' => 'cash', 'amount' => 5000]],
            'items' => [['stock_item_id' => $p['id'], 'quantity' => 3]]], $this->h)->json('data');
        SaleRecord::withoutGlobalScopes()->where('id', $sale['id'])->update(['sale_date' => '2026-09-26', 'created_at' => '2026-09-26 14:05:00']);

        $text = $this->get("/api/v1/sales/{$sale['id']}/receipt.txt", $this->h)->assertOk()->getContent();
        $golden = base_path('tests/golden/receipt_cash_discount.txt');
        if (! file_exists($golden) || getenv('UPDATE_GOLDEN')) {
            file_put_contents($golden, $text);
        }
        $this->assertSame(file_get_contents($golden), $text);

        $pdf = $this->get("/api/v1/sales/{$sale['id']}/receipt.pdf", $this->h)->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_offline_pos_flows_arrive_through_sync(): void
    {
        $p = $this->product();
        $push = fn (array $ops) => $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => array_map(fn ($o) => $o + ['op_uuid' => (string) Str::uuid(), 'client_updated_at' => SyncSequence::nowMs()], $ops)]]], $this->h)
            ->assertOk()->json('data.results.0');

        $cust = (string) Str::uuid();
        $shift = (string) Str::uuid();
        $sale = (string) Str::uuid();
        $unit = (string) Str::uuid();
        $r = $push([
            ['table' => 'units', 'uuid' => $unit, 'action' => 'insert', 'data' => ['name' => 'Half dozen', 'abbreviation' => '6pk', 'factor' => '6']],
            ['table' => 'customers', 'uuid' => $cust, 'action' => 'insert', 'data' => ['name' => 'Aunt Sarah', 'phone' => '0701111222']],
            ['table' => 'shifts', 'uuid' => $shift, 'action' => 'insert', 'data' => ['opening_float' => '5000', 'opened_at' => SyncSequence::nowMs()]],
            ['table' => 'sales', 'uuid' => $sale, 'action' => 'insert', 'data' => ['customer_uuid' => $cust, 'shift_uuid' => $shift, 'has_payment_ops' => 1, 'occurred_at' => SyncSequence::nowMs(),
                'items' => [['product_uuid' => $p['uuid'], 'quantity' => '2', 'unit_uuid' => $unit]]]],
            ['table' => 'payments', 'uuid' => (string) Str::uuid(), 'action' => 'insert', 'data' => ['sale_uuid' => $sale, 'method' => 'cash', 'amount' => '4000', 'received_at' => SyncSequence::nowMs()]],
        ]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertSame(36.0, $this->qty($p['id'])); // 2 x half-dozen
        $s = SaleRecord::withoutGlobalScopes()->where('uuid', $sale)->first();
        $this->assertSame('12000.00', $s->total_amount);
        $this->assertSame('8000.00', $s->balance);

        // Customer pays on account, a return comes in, goods are received, a count is posted, the shift closes.
        $sup = (string) Str::uuid();
        $take = (string) Str::uuid();
        $r = $push([
            ['table' => 'payments', 'uuid' => (string) Str::uuid(), 'action' => 'insert', 'data' => ['customer_uuid' => $cust, 'method' => 'mobile_money', 'amount' => '3000']],
            ['table' => 'sale_returns', 'uuid' => (string) Str::uuid(), 'action' => 'insert', 'data' => ['sale_uuid' => $sale, 'shift_uuid' => $shift, 'reason' => 'Broken', 'items' => [['product_uuid' => $p['uuid'], 'quantity' => '1', 'restock' => false]]]],
            ['table' => 'suppliers', 'uuid' => $sup, 'action' => 'insert', 'data' => ['name' => 'Wholesaler']],
            ['table' => 'goods_receipts', 'uuid' => (string) Str::uuid(), 'action' => 'insert', 'data' => ['supplier_uuid' => $sup, 'amount_paid' => '0', 'items' => [['product_uuid' => $p['uuid'], 'quantity' => '12', 'unit_cost' => '550']]]],
            ['table' => 'stock_takes', 'uuid' => $take, 'action' => 'insert', 'data' => ['name' => 'Shelf check', 'counts' => [['product_uuid' => $p['uuid'], 'counted_quantity' => '47']], 'status' => 'posted']],
            ['table' => 'shifts', 'uuid' => $shift, 'action' => 'update', 'data' => ['status' => 'closed', 'counted_cash' => '9000']],
        ]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertSame(47.0, $this->qty($p['id']));
        $s->refresh();
        $this->assertSame('6000.00', $s->refunded_amount);
        $this->assertSame('0.00', $s->balance, 'the account payment settled what was left');
        $shiftRow = \App\Models\Shift::withoutGlobalScopes()->where('uuid', $shift)->first();
        $this->assertSame('closed', $shiftRow->status);
        // 5,000 float + 4,000 cash − 1,000 refunded: the 3,000 account payment landed before the
        // 6,000 return, so the customer had over-paid by 1,000 and got it back in cash.
        $this->assertSame('8000.00', $shiftRow->expected_cash);
        $this->assertSame('6600.00', \App\Models\Supplier::withoutGlobalScopes()->where('uuid', $sup)->first()->balance);

        foreach (['customers', 'suppliers', 'shifts', 'units', 'sale_returns', 'goods_receipts', 'stock_takes'] as $table) {
            $this->assertNotEmpty($this->getJson("/api/v1/sync/pull?table={$table}&since_seq=0", $this->h)->assertOk()->json('data.rows'), $table);
        }
    }

    public function test_category_scoped_stock_take_through_sync(): void
    {
        $p = $this->product();
        $catUuid = \App\Models\StockCategory::withoutGlobalScopes()->find($p['stock_category_id'])->uuid;
        $r = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'ops' => [[
            'op_uuid' => (string) Str::uuid(), 'table' => 'stock_takes', 'uuid' => (string) Str::uuid(), 'action' => 'insert', 'client_updated_at' => SyncSequence::nowMs(),
            'data' => ['name' => 'Drinks shelf', 'category_uuid' => $catUuid, 'counts' => [['product_uuid' => $p['uuid'], 'counted_quantity' => '40']], 'status' => 'posted'],
        ]]]]], $this->h)->assertOk()->json('data.results.0');
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertSame(40.0, $this->qty($p['id']));
        $this->assertSame($p['stock_category_id'], \App\Models\StockTake::withoutGlobalScopes()->latest('id')->first()->stock_category_id);
    }
}
