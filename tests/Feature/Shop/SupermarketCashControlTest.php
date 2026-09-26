<?php

namespace Tests\Feature\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Approval;
use App\Models\CompanyMember;
use App\Models\FinancialPeriod;
use App\Models\SaleRecord;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockSubCategory;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Shop\ApprovalService;
use App\Services\Shop\SaleService;
use App\Services\Shop\ShiftService;
use App\Services\Shop\ZReportService;
use App\Services\Team\Permissions;
use App\Support\LocalDate;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Admin\AdminTestCase;

/** Supermarket phase 1: supervisor PIN approvals (A5), cash movements (E1/E4), X/Z reports (E2), blind count (E3) — off unless switched on. */
class SupermarketCashControlTest extends AdminTestCase
{
    private array $t;

    private StockItem $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->makeTenant('company');
        $cid = $this->t['company']->id;
        $this->t['company']->forceFill(['timezone' => 'Africa/Kampala', 'tax_rate' => 18])->save();
        FinancialPeriod::withoutGlobalScopes()->firstOrCreate(['company_id' => $cid, 'status' => 'Active'], ['name' => 'FY', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cat = StockCategory::create(['company_id' => $cid, 'name' => 'Food']);
        $sub = StockSubCategory::create(['company_id' => $cid, 'stock_category_id' => $cat->id, 'name' => 'Dry', 'measurement_unit' => 'kg']);
        $this->p = StockItem::create(['company_id' => $cid, 'created_by_id' => $this->t['user']->id, 'stock_category_id' => $cat->id, 'stock_sub_category_id' => $sub->id,
            'name' => 'Rice', 'sku' => 'R-'.uniqid(), 'buying_price' => 4000, 'selling_price' => 5000, 'original_quantity' => 100]);
    }

    private function member(string $role): User
    {
        $u = new User();
        $u->forceFill(['name' => ucfirst($role).' '.uniqid(), 'email' => $role.uniqid('', true).'@example.test', 'password' => bcrypt('secret123'), 'status' => 'Active', 'company_id' => $this->t['company']->id])->save();
        CompanyMember::create(['company_id' => $this->t['company']->id, 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'joined_at' => now()]);
        Permissions::flush();

        return $u->fresh();
    }

    private function on(array $features): void
    {
        StoreFeatures::update($this->t['company'], ['features' => $features]);
        $this->t['company'] = $this->t['company']->fresh();
    }

    private function sell(int $shiftId, int $qty, string $method = 'cash', array $extra = []): SaleRecord
    {
        return (new SaleService())->checkout($this->t['company']->id, $this->t['user']->id, $extra + [
            'shift_id' => $shiftId, 'payments' => [['method' => $method, 'amount' => $qty * 5000]], 'payments_explicit' => true,
            'items' => [['stock_item_id' => $this->p->id, 'quantity' => $qty]],
        ])['sale'];
    }

    public function test_approvals_are_off_by_default_and_need_a_supervisor_pin_when_on(): void
    {
        $cid = $this->t['company']->id;
        $svc = new ApprovalService();
        $cashier = $this->member('cashier');
        $manager = $this->member('manager');
        $this->assertFalse($svc->required($this->t['company'], 'void_sale'), 'off: nothing asks for a PIN');

        $this->on(['approvals' => true]);
        $this->assertTrue($svc->required($this->t['company'], 'void_sale'));
        $this->assertTrue($svc->required($this->t['company'], 'no_sale'));
        $this->assertFalse($svc->required($this->t['company'], 'price_override', ['pct' => 10]), 'a cut at the 10% limit is fine');
        $this->assertTrue($svc->required($this->t['company'], 'price_override', ['original' => 1000, 'price' => 850]));
        $this->assertTrue($svc->required($this->t['company'], 'waste', ['amount' => 1]), 'waste_limit 0 = any write-off');
        StoreFeatures::update($this->t['company'], ['settings' => ['waste_limit' => 5000]]);
        $this->assertFalse($svc->required($this->t['company']->fresh(), 'waste', ['amount' => 5000]));
        $this->assertFalse($svc->required($this->t['company'], 'nonsense'));

        try {
            $svc->grant($cid, 'void_sale', '1234', $cashier->id);
            $this->fail('no PINs yet');
        } catch (BusinessRuleException $e) {
            $this->assertSame('no_supervisor_pin', $e->errorCode());
        }
        foreach (['12', '1234567', 'abcd'] as $bad) {
            try {
                $svc->setPin($manager, $bad);
                $this->fail("{$bad} is not a PIN");
            } catch (BusinessRuleException $e) {
                $this->assertSame('invalid_pin', $e->errorCode());
            }
        }
        $svc->setPin($manager, '4321');
        $svc->setPin($cashier, '1111');
        $this->assertNotSame('4321', DB::table('admin_users')->where('id', $manager->id)->value('pos_pin_hash'), 'hashed');
        $this->assertArrayNotHasKey('pos_pin_hash', $manager->fresh()->toArray(), 'never serialised');
        try {
            $svc->setPin($this->t['user'], '9999', $cashier);
            $this->fail('a cashier sets no one else’s PIN');
        } catch (BusinessRuleException $e) {
            $this->assertSame('forbidden', $e->errorCode());
        }

        // The cashier's own PIN does not approve: they lack the `approve` permission.
        try {
            $svc->grant($cid, 'void_sale', '1111', $cashier->id);
            $this->fail('cashier PIN');
        } catch (BusinessRuleException $e) {
            $this->assertSame('wrong_pin', $e->errorCode());
        }

        $row = $svc->grant($cid, 'void_sale', '4321', $cashier->id, ['sale_record_id' => 77, 'amount' => 15000, 'reason' => 'Wrong item']);
        $this->assertSame($manager->id, $row->approver->id);
        $this->assertDatabaseHas('approvals', ['id' => $row->id, 'company_id' => $cid, 'action' => 'void_sale', 'requested_by' => $cashier->id, 'approved_by' => $manager->id, 'sale_record_id' => 77, 'reason' => 'Wrong item']);
        $this->assertSame($manager->id, $svc->approve($cid, 'refund', '4321', $cashier->id)->id);

        // Used once, for its own action, sale and requester.
        foreach ([['refund', $cashier->id, 77], ['void_sale', $manager->id, 77], ['void_sale', $cashier->id, 78]] as [$action, $by, $sale]) {
            try {
                $svc->consume($cid, $row->id, $action, $by, $sale);
                $this->fail('mismatch');
            } catch (BusinessRuleException $e) {
                $this->assertSame('approval_invalid', $e->errorCode());
            }
        }
        $this->assertNotNull($svc->consume($cid, $row->id, 'void_sale', $cashier->id, 77)->consumed_at);
        try {
            $svc->consume($cid, $row->id, 'void_sale', $cashier->id, 77);
            $this->fail('used twice');
        } catch (BusinessRuleException $e) {
            $this->assertSame('approval_invalid', $e->errorCode());
        }

        // Wrong PINs: 5 a minute per shop and person.
        RateLimiter::clear('approval-pin:'.$cid.':'.$cashier->id);
        for ($i = 0; $i < 5; $i++) {
            try {
                $svc->grant($cid, 'void_sale', '0000', $cashier->id);
            } catch (BusinessRuleException $e) {
                $this->assertSame('wrong_pin', $e->errorCode());
            }
        }
        try {
            $svc->grant($cid, 'void_sale', '4321', $cashier->id);
            $this->fail('rate limited');
        } catch (BusinessRuleException $e) {
            $this->assertSame('too_many_attempts', $e->errorCode());
        }
        $this->assertSame($manager->id, $svc->approve($cid, 'void_sale', '4321', $manager->id)->id, 'the limit is per person');
        RateLimiter::clear('approval-pin:'.$cid.':'.$cashier->id);
    }

    public function test_cash_movements_move_expected_cash_and_post_the_ledger(): void
    {
        $cid = $this->t['company']->id;
        $uid = $this->t['user']->id;
        $svc = new ShiftService();
        $shift = $svc->open($cid, $uid, 50000);
        $this->sell($shift->id, 4); // 20,000 cash
        $before = $svc->totals($shift->fresh());
        $this->assertArrayNotHasKey('cash_movements', $before, 'no movements: the old shape');
        $this->assertSame(70000.0, $before['expected_cash']);

        try {
            $svc->cashMovement($shift, 'drop', 10000, 'To the safe', $uid);
            $this->fail('feature off');
        } catch (BusinessRuleException $e) {
            $this->assertSame('feature_off', $e->errorCode());
        }
        $this->on(['cash_control' => true]);

        $drop = $svc->cashMovement($shift, 'drop', 30000, 'To the safe', $uid, null, 'drop-uuid-1');
        $this->assertSame($drop->id, $svc->cashMovement($shift, 'drop', 30000, 'To the safe', $uid, null, 'drop-uuid-1')->id, 'idempotent');
        $svc->cashMovement($shift, 'pickup', 5000, 'Change from the safe', $uid);
        $in = $svc->cashMovement($shift, 'paid_in', 2000, 'Owner topped up coins', $uid);
        $out = $svc->cashMovement($shift, 'paid_out', 3000, 'Boda to the market', $uid);
        $svc->cashMovement($shift, 'no_sale', 999, 'Customer wanted change', $uid);
        $this->assertNull($drop->financial_record_id, 'a drop is not income or expense');

        $t = $svc->totals($shift->fresh());
        $this->assertSame(70000.0 - 30000 + 5000 + 2000 - 3000, $t['expected_cash']);
        $this->assertSame(['count' => 1, 'total' => 0.0], $t['cash_movements']['no_sale'], 'no-sale moves no money');
        $this->assertDatabaseHas('financial_records', ['id' => $out->financial_record_id, 'type' => 'Expense', 'amount' => 3000, 'source_type' => 'cash_movement', 'source_id' => $out->id, 'payment_method' => 'cash']);
        $this->assertDatabaseHas('financial_records', ['id' => $in->financial_record_id, 'type' => 'Income', 'amount' => 2000, 'source_type' => 'cash_movement']);
        $this->assertSame('Cash paid in', DB::table('financial_categories')->where('id', DB::table('financial_records')->where('id', $in->financial_record_id)->value('financial_category_id'))->value('name'));

        foreach ([['drop', 1000000, 'not_enough_cash'], ['paid_in', 0, 'invalid_amount'], ['drop', 10, 'reason_required'], ['bribe', 10, 'invalid_type']] as [$type, $amt, $code]) {
            try {
                $svc->cashMovement($shift, $type, $amt, $code === 'reason_required' ? 'x' : 'Some reason', $uid);
                $this->fail($code);
            } catch (BusinessRuleException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        }
        try {
            $drop->forceFill(['amount' => 1])->save();
            $this->fail('a movement is never edited');
        } catch (BusinessRuleException $e) {
            $this->assertSame('cash_movement_locked', $e->errorCode());
        }
    }

    public function test_paid_out_and_no_sale_need_approval_when_approvals_are_on(): void
    {
        $cid = $this->t['company']->id;
        $cashier = $this->member('cashier');
        $manager = $this->member('manager');
        $this->on(['cash_control' => true, 'approvals' => true]);
        (new ApprovalService())->setPin($manager, '2468');
        $svc = new ShiftService();
        $shift = $svc->open($cid, $cashier->id, 10000);

        $svc->cashMovement($shift, 'drop', 5000, 'To the safe', $cashier->id); // a drop needs no supervisor
        try {
            $svc->cashMovement($shift, 'no_sale', 0, 'Change for a customer', $cashier->id);
            $this->fail('approval required');
        } catch (BusinessRuleException $e) {
            $this->assertSame('approval_required', $e->errorCode());
        }
        $a = (new ApprovalService())->grant($cid, 'no_sale', '2468', $cashier->id, ['reason' => 'Change for a customer']);
        $m = $svc->cashMovement($shift, 'no_sale', 0, 'Change for a customer', $cashier->id, $a->id);
        $this->assertSame($manager->id, (int) $m->approved_by);
        $this->assertNotNull(Approval::withoutGlobalScopes()->find($a->id)->consumed_at);

        $b = (new ApprovalService())->grant($cid, 'cash_out', '2468', $cashier->id);
        $this->assertSame($manager->id, (int) $svc->cashMovement($shift, 'paid_out', 1000, 'Airtime', $cashier->id, $b->id)->approved_by);
    }

    public function test_blind_count_is_kept_and_the_variance_is_the_shifts(): void
    {
        $svc = new ShiftService();
        $shift = $svc->open($this->t['company']->id, $this->t['user']->id, 10000);
        $closed = $svc->close($shift, 9000, $this->t['user']->id, null, ['5000' => 1, '2000' => 2, '1000' => 0]);
        $this->assertSame(-1000.0, (float) $closed->variance);
        $this->assertEquals(['5000' => 1, '2000' => 2], json_decode((string) DB::table('shifts')->where('id', $shift->id)->value('cash_count'), true));
    }

    public function test_x_and_z_reports_close_the_day_once_with_a_number_and_list_late_sales(): void
    {
        $cid = $this->t['company']->id;
        $uid = $this->t['user']->id;
        $today = LocalDate::today($cid)->toDateString();
        $yesterday = LocalDate::today($cid)->subDay()->toDateString();
        $z = new ZReportService();
        $svc = new ShiftService();

        $this->on(['cash_control' => true]);
        $shift = $svc->open($cid, $uid, 10000);
        $a = $this->sell($shift->id, 2);                        // 10,000 cash
        $this->sell($shift->id, 1, 'mobile_money');              // 5,000
        $this->sell($shift->id, 2, 'cash', ['discount_amount' => 1000]); // 9,000 net
        $v = $this->sell($shift->id, 1);
        (new SaleService())->void($v, 'Mistake', $uid);
        $svc->cashMovement($shift, 'drop', 5000, 'To the safe', $uid);
        $svc->cashMovement($shift, 'no_sale', 0, 'Checked a note', $uid);
        $svc->close($shift->fresh(), 24000, $uid);

        $x = $z->figures($cid, $today);
        $this->assertSame(3, $x['sales']['count']);
        $this->assertSame(24000.0, $x['sales']['net']);
        $this->assertSame(['count' => 1, 'total' => 1000.0], $x['discounts']);
        $this->assertSame(1, $x['voids']['count']);
        $this->assertSame(5000.0, $x['by_method']['mobile_money']['total']);
        $this->assertSame(round(24000 * 18 / 118, 2), $x['tax']['vat']);
        $this->assertSame(5000.0, $x['cash_movements']['drop']['total']);
        $this->assertSame(1, $x['no_sales']);
        $this->assertSame(10000.0 + 19000 - 5000, $x['cashiers'][0]['expected']);
        $this->assertSame(0.0, $x['cashiers'][0]['over_short']);
        $this->assertSame(1, $x['cashiers'][0]['no_sales']);
        $this->assertSame([], $x['late_sales']);
        $this->assertSame($x['sales'], $z->figures($cid, $today, null, $shift->fresh())['sales'], 'the shift X matches the day here');
        $this->assertSame(0, ZReport::withoutGlobalScopes()->where('company_id', $cid)->count(), 'X changes nothing');

        DB::table('sale_records')->where('company_id', $cid)->update(['created_at' => now()->subMinutes(10)]); // rung up before the close
        $zr = $z->close($cid, $today, $uid);
        $this->assertMatchesRegularExpression('/^Z-\d{4}-000001$/', $zr->number);
        $this->assertSame(24000.0, (float) $zr->totals['sales']['net']);
        try {
            $z->close($cid, $today, $uid);
            $this->fail('closed twice');
        } catch (BusinessRuleException $e) {
            $this->assertSame('day_closed', $e->errorCode());
        }
        try {
            $z->close($cid, LocalDate::today($cid)->addDay()->toDateString(), $uid);
            $this->fail('future');
        } catch (BusinessRuleException $e) {
            $this->assertSame('invalid_date', $e->errorCode());
        }
        try {
            $zr->forceFill(['number' => 'Z-EDIT'])->save();
            $this->fail('immutable');
        } catch (BusinessRuleException $e) {
            $this->assertSame('z_report_locked', $e->errorCode());
        }

        // A phone syncs a sale for the closed day later: it is not refused, and the next X/Z lists it.
        DB::table('z_reports')->where('id', $zr->id)->update(['created_at' => now()->subMinute()]);
        $late = $this->sell($shift->id, 1, 'cash', ['shift_id' => null]);
        $this->assertSame($today, $late->sale_date->toDateString());
        $next = $z->figures($cid, $today);
        $this->assertSame([['date' => $today, 'count' => 1, 'total' => 5000.0]], $next['late_sales']);

        $z2 = $z->close($cid, $yesterday, $uid);
        $this->assertMatchesRegularExpression('/-000002$/', $z2->number, 'gap-free');
        $this->assertEquals([['date' => $today, 'count' => 1, 'total' => 5000]], $z2->totals['late_sales'], 'stored as JSON');
        $this->assertSame([], $z->figures($cid, $today)['late_sales'], 'listed once');
        $this->assertSame(10000.0, (float) $a->total_amount);
    }

    public function test_z_is_refused_while_cash_control_is_off_and_settings_are_validated(): void
    {
        try {
            (new ZReportService())->close($this->t['company']->id, LocalDate::today($this->t['company']->id)->toDateString(), $this->t['user']->id);
            $this->fail('off');
        } catch (BusinessRuleException $e) {
            $this->assertSame('feature_off', $e->errorCode());
        }

        $ok = StoreFeatures::validated(['mode' => true, 'features' => ['approvals' => false], 'settings' => ['cash_rounding' => '0.05', 'override_limit_pct' => '15', 'note_buttons' => ['1000', '5000'], 'tax_inclusive' => '1']]);
        $this->assertSame(0.05, $ok['settings']['cash_rounding']);
        $this->assertSame(15, $ok['settings']['override_limit_pct']);
        $this->assertSame([1000, 5000], $ok['settings']['note_buttons']);
        $this->assertTrue($ok['settings']['tax_inclusive']);
        foreach ([['override_limit_pct' => 150], ['scale_format' => 'volume'], ['note_buttons' => [-5]], ['short_dated_days' => 0]] as $bad) {
            try {
                StoreFeatures::validated(['settings' => $bad]);
                $this->fail(json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }
}
