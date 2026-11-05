<?php

namespace Tests\Feature\Budget;

use App\Exceptions\BusinessRuleException;
use App\Models\BudgetItem;
use App\Models\BudgetItemCategory;
use App\Services\Budget\BudgetAccess;
use App\Services\Budget\ContributionPayments;
use App\Services\Budget\PledgeService;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PB-06, decision D5: closed means closed in the kernel. Pledges, payments, reversals and budget changes are
 * refused on the service and the API; from a phone, work made before the event closed is applied and later work is
 * refused with event_closed, so the phone tells its user. Reopening puts everything back.
 */
class ClosedEventTest extends PledgeTestCase
{
    private function assertClosedRefusal(callable $write): void
    {
        try {
            $write();
            $this->fail('a closed event took a write');
        } catch (BusinessRuleException $e) {
            $this->assertSame(['event_closed', 'This event is closed. Ask the chair to reopen it.'], [$e->errorCode(), $e->getMessage()]);
        }
    }

    public function test_closed_at_is_set_on_closing_and_cleared_on_reopening(): void
    {
        $event = $this->program();
        $this->assertNull(DB::table('budget_programs')->where('id', $event)->value('closed_at'));
        $this->close($event);
        $this->assertNotNull(DB::table('budget_programs')->where('id', $event)->value('closed_at'));
        $this->reopen($event);
        $this->assertNull(DB::table('budget_programs')->where('id', $event)->value('closed_at'));
    }

    public function test_the_services_refuse_every_money_write_when_closed(): void
    {
        $event = $this->program();
        $p = $this->pledge($event);
        $payId = $this->pay($p['id'], ['amount' => 1000])->assertStatus(201)->json('data.payment.id');
        $this->close($event);
        $by = $this->ownerUser();
        $pay = new ContributionPayments();
        $pledges = new PledgeService();

        $this->assertClosedRefusal(fn () => $pay->record($this->cid(), $p['id'], ['amount' => '2000'], $by->id));
        $this->assertClosedRefusal(fn () => $pay->reverse($this->cid(), $payId, 'A reason', $by->id));
        $this->assertClosedRefusal(fn () => $pledges->save($by, $this->programModel($event), ['name' => 'New', 'amount' => '1000']));
        $this->assertClosedRefusal(fn () => $pledges->save($by, $this->programModel($event), ['name' => 'Renamed', 'amount' => '500000'], $this->pledgeModel($p['id'])));
        $this->assertClosedRefusal(fn () => $pledges->withdraw($by, $this->pledgeModel($p['id']), 'Entered twice'));

        $this->assertSame('1000.00', (string) $this->pledgeModel($p['id'])->paid_amount, 'closing changes no figure');
    }

    public function test_the_api_refuses_every_write_when_closed_and_reads_go_on(): void
    {
        $event = $this->program();
        $p = $this->pledge($event);
        $payId = $this->pay($p['id'], ['amount' => 1000])->assertStatus(201)->json('data.payment.id');
        $cat = (int) $this->postJson('/api/v1/budget-item-categories', ['budget_program_id' => $event, 'name' => 'Food'], $this->oh)->assertStatus(201)->json('data.id');
        $item = (int) $this->postJson('/api/v1/budget-items', ['budget_item_category_id' => $cat, 'name' => 'Cake', 'unit_price' => 1000, 'quantity' => 1], $this->oh)->assertStatus(201)->json('data.id');
        $this->close($event);

        $refused = fn ($r) => $r->assertStatus(422)->assertJsonPath('errors.code', 'event_closed');
        $refused($this->pay($p['id'], ['amount' => 500]));
        $refused($this->postJson("/api/v1/contribution-payments/{$payId}/reverse", ['reason' => 'Wrong person'], $this->oh));
        $refused($this->postJson('/api/v1/contribution-records', ['budget_program_id' => $event, 'name' => 'New', 'amount' => 1000], $this->oh));
        $refused($this->putJson("/api/v1/contribution-records/{$p['id']}", ['amount' => 600000], $this->oh));
        $refused($this->postJson("/api/v1/contribution-records/{$p['id']}/withdraw", ['reason' => 'Entered twice'], $this->oh));
        $refused($this->postJson('/api/v1/budget-item-categories', ['budget_program_id' => $event, 'name' => 'Drinks'], $this->oh));
        $refused($this->putJson("/api/v1/budget-item-categories/{$cat}", ['name' => 'Eats'], $this->oh));
        $refused($this->postJson('/api/v1/budget-items', ['budget_item_category_id' => $cat, 'name' => 'Soda', 'unit_price' => 1000, 'quantity' => 1], $this->oh));
        $refused($this->putJson("/api/v1/budget-items/{$item}", ['unit_price' => 2000], $this->oh));
        $refused($this->deleteJson("/api/v1/budget-items/{$item}", [], $this->oh));
        $refused($this->putJson("/api/v1/budget-programs/{$event}", ['name' => 'Renamed'], $this->oh));

        $this->getJson("/api/v1/contribution-records/{$p['id']}", $this->oh)->assertOk();
        $this->getJson("/api/v1/budget-programs/{$event}", $this->oh)->assertOk();

        // Reopened, all as before.
        $this->reopen($event);
        $this->pay($p['id'], ['amount' => 500])->assertStatus(201);
        $this->putJson("/api/v1/budget-items/{$item}", ['unit_price' => 2000], $this->oh)->assertOk();
    }

    public function test_the_model_itself_refuses_budget_changes_when_closed(): void
    {
        $event = $this->program();
        $cat = new BudgetItemCategory();
        $cat->forceFill(['company_id' => $this->cid(), 'budget_program_id' => $event, 'name' => 'Food'])->save();
        $item = new BudgetItem();
        $item->forceFill(['company_id' => $this->cid(), 'budget_item_category_id' => $cat->id, 'name' => 'Cake', 'unit_price' => '1000', 'quantity' => '1', 'created_by_id' => $this->o['user_id']])->save();
        $this->close($event);

        $item = BudgetItem::withoutGlobalScopes()->findOrFail($item->id);
        $item->invested_amount = '999';
        $this->assertClosedRefusal(fn () => $item->save());
        $this->assertClosedRefusal(fn () => BudgetItem::withoutGlobalScopes()->findOrFail($item->id)->delete());
        $this->assertSame('0.00', (string) DB::table('budget_items')->where('id', $item->id)->value('invested_amount'));
    }

    public function test_a_phones_work_made_before_the_close_is_applied_and_later_work_is_refused(): void
    {
        $event = $this->program();
        $programUuid = $this->programUuid($event);
        $p = $this->pledge($event, ['amount' => 100000]);
        $cat = (string) DB::table('budget_item_categories')->where('id', $this->postJson('/api/v1/budget-item-categories', ['budget_program_id' => $event, 'name' => 'Food'], $this->oh)->json('data.id'))->value('uuid');
        $before = SyncSequence::nowMs() - 60000;
        $this->close($event);
        $after = SyncSequence::nowMs() + 60000;

        // Money received offline before the close: kept.
        $r = $this->push([$this->op('contribution_payments', ['contribution_uuid' => $p['uuid'], 'amount' => '40000'], null, 'insert', $before)]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertSame('40000.00', (string) $this->pledgeModel($p['id'])->paid_amount);
        $pledge = (string) Str::uuid();
        $r = $this->push([$this->op('contribution_records', ['name' => 'Offline Pledge', 'program_uuid' => $programUuid, 'amount' => '5000'], $pledge, 'insert', $before)]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $r = $this->push([$this->op('budget_items', ['name' => 'Cake', 'unit_price' => '1000', 'quantity' => '1', 'program_uuid' => $programUuid, 'category_uuid' => $cat], null, 'insert', $before)]);
        $this->assertSame('applied', $r['status'], json_encode($r));

        // Made after the close: refused with its own code and the words to show.
        foreach ([
            $this->op('contribution_payments', ['contribution_uuid' => $p['uuid'], 'amount' => '1000'], null, 'insert', $after),
            $this->op('contribution_records', ['name' => 'Late Pledge', 'program_uuid' => $programUuid, 'amount' => '5000'], null, 'insert', $after),
            $this->op('budget_items', ['name' => 'Soda', 'unit_price' => '1000', 'quantity' => '1', 'program_uuid' => $programUuid, 'category_uuid' => $cat], null, 'insert', $after),
            $this->op('budget_item_categories', ['name' => 'Drinks', 'program_uuid' => $programUuid], null, 'insert', $after),
        ] as $op) {
            $r = $this->push([$op]);
            $this->assertSame('rejected', $r['status'], json_encode($r));
            $this->assertSame('event_closed', $r['ops'][0]['code']);
            $this->assertSame('This event is closed. Ask the chair to reopen it.', $r['ops'][0]['message']);
        }
    }

    public function test_a_reversal_from_a_phone_follows_the_same_rule(): void
    {
        $event = $this->program();
        $p = $this->pledge($event);
        $pay = (string) Str::uuid();
        $this->push([$this->op('contribution_payments', ['contribution_uuid' => $p['uuid'], 'amount' => '40000'], $pay)]);
        $before = SyncSequence::nowMs() - 60000;
        $this->close($event);
        $r = $this->push([$this->op('contribution_payments', ['reverses_uuid' => $pay, 'reason' => 'Counted twice'], null, 'insert', SyncSequence::nowMs() + 60000)]);
        $this->assertSame('event_closed', $r['ops'][0]['code'] ?? null, json_encode($r));
        $r = $this->push([$this->op('contribution_payments', ['reverses_uuid' => $pay, 'reason' => 'Counted twice'], null, 'insert', $before)]);
        $this->assertSame('applied', $r['status'], json_encode($r));
    }

    public function test_an_event_closed_without_a_stamp_is_still_closed(): void
    {
        // The demo's builder and older rows close by status alone.
        $event = $this->program();
        $p = $this->pledge($event);
        DB::table('budget_programs')->where('id', $event)->update(['status' => 'Inactive', 'closed_at' => null, 'updated_at' => now()]);
        $this->pay($p['id'], ['amount' => 500])->assertStatus(422)->assertJsonPath('errors.code', 'event_closed');
        $r = $this->push([$this->op('contribution_payments', ['contribution_uuid' => $p['uuid'], 'amount' => '1000'], null, 'insert', SyncSequence::nowMs() + 60000)]);
        $this->assertSame('event_closed', $r['ops'][0]['code'] ?? null, json_encode($r));
    }

    public function test_a_reopen_from_a_phone_goes_through(): void
    {
        $event = $this->program();
        $this->close($event);
        $op = $this->op('budget_programs', ['status' => 'Active'], $this->programUuid($event), 'update', SyncSequence::nowMs() + 60000);
        $op['version'] = (int) DB::table('budget_programs')->where('id', $event)->value('version');
        $op['changed_fields'] = ['status'];
        $r = $this->push([$op]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertNull(DB::table('budget_programs')->where('id', $event)->value('closed_at'));
    }

    public function test_only_who_manages_the_event_may_reopen_it(): void
    {
        $event = $this->program();
        $chair = $this->teammate();
        $treasurer = $this->teammate();
        $this->seat($event, $chair, 'chair');
        $this->seat($event, $treasurer, 'treasurer');
        $this->close($event);
        $this->assertTrue(BudgetAccess::canManageEvent($chair['user'], $event));
        $this->assertFalse(BudgetAccess::canManageEvent($treasurer['user'], $event));
    }
}
