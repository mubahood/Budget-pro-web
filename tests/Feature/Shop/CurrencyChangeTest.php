<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\FinancialPeriod;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Services\Shop\CurrencyChangeService;
use App\Services\Shop\CustomerService;
use App\Services\Shop\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminTestCase;

/** A shop's currency can change after it has sales: corrected (label only) or converted (every amount × rate). */
class CurrencyChangeTest extends AdminTestCase
{
    private array $t;

    private StockItem $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->makeTenant('company', 'UGX');
        $cid = $this->t['company']->id;
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $cid, 'name' => 'Food']);
        $sub = StockSubCategory::create(['company_id' => $cid, 'stock_category_id' => $cat->id, 'name' => 'Dry', 'measurement_unit' => 'kg']);
        $this->p = StockItem::create(['company_id' => $cid, 'created_by_id' => $this->t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Rice', 'sku' => 'R-'.uniqid(), 'buying_price' => 3650, 'selling_price' => 7300, 'original_quantity' => 50]);
    }

    private function seedShop(): array
    {
        $cid = $this->t['company']->id;
        $c = new Customer();
        $c->forceFill(['company_id' => $cid, 'name' => 'Nakato', 'phone' => '0752300400', 'credit_limit' => 365000])->save();
        $svc = new SaleService();
        $cash = $svc->checkout($cid, $this->t['user']->id, ['payments' => [['method' => 'cash', 'amount' => 14600]], 'payments_explicit' => true,
            'items' => [['stock_item_id' => $this->p->id, 'quantity' => 2]]])['sale'];
        $credit = $svc->checkout($cid, $this->t['user']->id, ['customer_id' => $c->id, 'payments' => [['method' => 'cash', 'amount' => 3650]], 'payments_explicit' => true,
            'items' => [['stock_item_id' => $this->p->id, 'quantity' => 1]]])['sale'];
        DB::table('promotions')->insert(['company_id' => $cid, 'name' => 'Rice deal', 'type' => 'fixed_price', 'rules' => json_encode(['price' => 5475]),
            'stackable' => 0, 'priority' => 0, 'member_only' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$c, $cash, $credit];
    }

    public function test_convert_scales_every_amount_consistently_and_leaves_other_shops_alone(): void
    {
        [$c, $cash, $credit] = $this->seedShop();
        $cid = $this->t['company']->id;
        $other = $this->makeTenant('company', 'UGX');
        $seqBefore = (int) SaleRecord::withoutGlobalScopes()->whereKey($cash->id)->value('server_seq');
        $svc = new CurrencyChangeService();

        $preview = $svc->preview($this->t['company'], 'USD', 'convert', 1 / 3650);
        $this->assertSame('UGX', $preview['from']);
        $this->assertEquals(2, $preview['examples'][0]['after']);
        $this->assertGreaterThan(0, $preview['rows']);

        $svc->change($this->t['company'], $this->t['user'], 'USD', 'convert', 1 / 3650, 'secret123');

        $this->assertSame('USD', $this->t['company']->fresh()->currency);
        $this->assertEquals(2, (float) $this->p->fresh()->selling_price);
        $this->assertEquals(1, (float) $this->p->fresh()->buying_price);
        $cash = SaleRecord::withoutGlobalScopes()->find($cash->id);
        $this->assertEquals(4, (float) $cash->total_amount);
        $this->assertSame('USD', $cash->currency);
        $this->assertEquals(4, (float) DB::table('payments')->where('sale_record_id', $cash->id)->sum('amount'));
        $this->assertEquals(4, (float) DB::table('sale_record_items')->where('sale_record_id', $cash->id)->sum('line_total'));
        $this->assertEquals(100, (float) $c->fresh()->credit_limit);
        $this->assertEquals(1, (float) $c->fresh()->balance, 'balance converted');
        $this->assertEquals(1, (new CustomerService())->balance($c->fresh()), 'and still matches its sales and payments');
        $this->assertEquals(1.5, json_decode((string) DB::table('promotions')->where('company_id', $cid)->value('rules'), true)['price']);
        $this->assertGreaterThan($seqBefore, (int) $cash->server_seq, 'phones pull the converted sale again');
        $seqs = DB::table('sale_records')->where('company_id', $cid)->pluck('server_seq');
        $this->assertSame($seqs->count(), $seqs->unique()->count(), 'one new sequence value each');
        $this->assertSame('UGX', $other['company']->fresh()->currency);
        $log = DB::table('currency_changes')->where('company_id', $cid)->first();
        $this->assertSame(['UGX', 'USD', 'convert'], [$log->from_currency, $log->to_currency, $log->mode]);
    }

    public function test_relabel_changes_only_the_currency_and_the_owner_must_confirm(): void
    {
        [, $cash] = $this->seedShop();
        $svc = new CurrencyChangeService();
        foreach ([['USD', 'convert', null, 'secret123', 'invalid_rate'], ['UGX', 'relabel', null, 'secret123', 'same_currency'], ['XYZ', 'relabel', null, 'secret123', 'invalid_currency'],
            ['KES', 'relabel', null, 'wrong', 'wrong_password']] as [$to, $mode, $rate, $pw, $code]) {
            try {
                $svc->change($this->t['company'], $this->t['user'], $to, $mode, $rate, $pw);
                $this->fail("{$code} expected");
            } catch (BusinessRuleException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        }
        $staff = $this->makeTenant('company')['user'];
        try {
            $svc->change($this->t['company'], $staff, 'KES', 'relabel', null, 'secret123');
            $this->fail('owner only');
        } catch (BusinessRuleException $e) {
            $this->assertSame('owner_only', $e->errorCode());
        }

        $svc->change($this->t['company'], $this->t['user'], 'KES', 'relabel', null, 'secret123');
        $this->assertSame('KES', $this->t['company']->fresh()->currency);
        $this->assertEquals(7300, (float) $this->p->fresh()->selling_price, 'amounts unchanged');
        $this->assertEquals(14600, (float) SaleRecord::withoutGlobalScopes()->find($cash->id)->total_amount);
        $this->assertSame('KES', SaleRecord::withoutGlobalScopes()->find($cash->id)->currency);
    }
}
