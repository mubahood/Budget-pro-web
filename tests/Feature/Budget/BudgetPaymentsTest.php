<?php

namespace Tests\Feature\Budget;

use App\Jobs\SendBudgetItemNotification;
use App\Models\BudgetItem;
use App\Models\BudgetItemCategory;
use App\Models\BudgetPayment;
use App\Services\Budget\BudgetPayments;
use App\Services\Budget\BudgetSummary;
use App\Services\Budget\EventBook;
use App\Services\Budget\EventReports;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** The spending ledger (PB-13, D6): a line's paid figure is the sum of its history, from every way in, and every reader agrees. */
class BudgetPaymentsTest extends PledgeTestCase
{
    private function line(int $event, string $name = 'Hall', string $price = '1000000', string $category = 'Venue'): BudgetItem
    {
        $cat = BudgetItemCategory::withoutGlobalScopes()->where('budget_program_id', $event)->where('name', $category)->first();
        if ($cat === null) {
            $cat = new BudgetItemCategory();
            $cat->forceFill(['company_id' => $this->cid(), 'budget_program_id' => $event, 'name' => $category])->save();
        }
        $item = new BudgetItem();
        $item->forceFill(['company_id' => $this->cid(), 'budget_item_category_id' => $cat->id, 'name' => $name, 'unit_price' => $price, 'quantity' => '1', 'created_by_id' => $this->o['user_id']])->save();

        return $item->fresh();
    }

    private function paidOn(BudgetItem $item): string
    {
        return (string) DB::table('budget_items')->where('id', $item->id)->value('invested_amount');
    }

    private function history(BudgetItem $item): string
    {
        return (new BudgetPayments())->sum((int) $item->id);
    }

    private function figures(int $event): array
    {
        return $this->getJson("/api/v1/budget-programs/{$event}/figures", $this->oh)->assertOk()->json('data');
    }

    public function test_the_figure_is_the_sum_after_a_payment_a_reversal_and_a_double_tap(): void
    {
        $event = $this->program();
        $item = $this->line($event);
        $uuid = (string) Str::uuid();
        $body = ['amount' => '400000', 'paid_to' => 'Kato Hall', 'method' => 'Mobile money', 'uuid' => $uuid];

        $first = $this->postJson("/api/v1/budget-items/{$item->id}/payments", $body, $this->oh)->assertStatus(201);
        $this->postJson("/api/v1/budget-items/{$item->id}/payments", $body, $this->oh)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame('400000.00', $this->paidOn($item));
        $this->assertSame('600000.00', (string) DB::table('budget_items')->where('id', $item->id)->value('balance'));
        $this->assertSame('mobile_money', $first->json('data.payment.method'));

        $this->postJson("/api/v1/budget-items/{$item->id}/payments", ['amount' => '150000'], $this->oh)->assertStatus(201);
        $this->assertSame('550000.00', $this->paidOn($item));

        $this->postJson('/api/v1/budget-payments/'.$first->json('data.payment.id').'/reverse', ['reason' => 'x'], $this->oh)->assertStatus(422);
        $this->postJson('/api/v1/budget-payments/'.$first->json('data.payment.id').'/reverse', ['reason' => 'Recorded twice'], $this->oh)->assertStatus(201);
        $this->postJson('/api/v1/budget-payments/'.$first->json('data.payment.id').'/reverse', ['reason' => 'Again please'], $this->oh)->assertStatus(422);
        $this->assertSame('150000.00', $this->paidOn($item));
        $this->assertSame('150000.00', $this->history($item));
        $this->assertSame(3, BudgetPayment::withoutGlobalScopes()->where('budget_item_id', $item->id)->count(), 'the payment stays, beside its reversal');

        $history = $this->getJson("/api/v1/budget-items/{$item->id}/payments", $this->oh)->assertOk()->json('data.payments');
        $this->assertTrue($history[0]['is_reversed']);
        $this->assertSame('Kato Hall', $history[0]['paid_to']);

        // Append-only, in the model.
        $row = BudgetPayment::withoutGlobalScopes()->where('budget_item_id', $item->id)->first();
        $row->amount = '1';
        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        $row->save();
    }

    public function test_web_and_api_money_stays_exact_and_a_line_priced_zero_takes_payments(): void
    {
        $event = $this->program();
        $free = $this->line($event, 'Donated hall', '0');
        $this->postJson("/api/v1/budget-items/{$free->id}/payments", ['amount' => '1000.50'], $this->oh)->assertStatus(422);
        $this->postJson("/api/v1/budget-items/{$free->id}/payments", ['amount' => '0'], $this->oh)->assertStatus(422);
        $this->postJson("/api/v1/budget-items/{$free->id}/payments", ['amount' => '5000'], $this->oh)->assertStatus(201);
        $this->assertSame('5000.00', $this->paidOn($free));
        $this->assertSame('-5000.00', (string) DB::table('budget_items')->where('id', $free->id)->value('balance'));
    }

    public function test_the_backfill_changes_no_total_and_can_run_again(): void
    {
        $event = $this->program();
        $other = $this->program('Kukyala');
        $a = $this->line($event);
        $b = $this->line($event, 'Cake', '250000.50', 'Food');
        $c = $this->line($other, 'Tent', '700000');
        $gone = $this->line($other, 'Old line', '300000');
        // Rows as they stand before the ledger: a typed figure, no history; one on a line removed long ago, which counts nowhere.
        DB::table('budget_items')->where('id', $a->id)->update(['invested_amount' => '1200000']);
        DB::table('budget_items')->where('id', $b->id)->update(['invested_amount' => '100000.25']);
        DB::table('budget_items')->where('id', $c->id)->update(['invested_amount' => '0']);
        DB::table('budget_items')->where('id', $gone->id)->update(['invested_amount' => '90000', 'is_deleted' => 1]);
        foreach ([$event, $other] as $id) {
            \App\Models\BudgetProgram::recalculateFromChildren($id);
        }
        $company = \App\Models\Company::findOrFail($this->cid());
        $before = [(new EventBook())->event($company, $event), (new EventBook())->event($company, $other), (string) DB::table('budget_programs')->where('id', $event)->value('budget_spent')];
        $rowsBefore = DB::table('budget_items')->whereIn('id', [$a->id, $b->id, $c->id, $gone->id])->orderBy('id')->get(['invested_amount', 'target_amount', 'version', 'server_seq'])->toArray();
        DB::table('budget_payments')->whereIn('budget_item_id', [$a->id, $b->id, $c->id, $gone->id])->delete();

        $migration = require database_path('migrations/2026_11_06_531001_create_budget_payments_table.php');
        $migration->up();
        $migration->up();

        $this->assertSame([$before[0], $before[1], $before[2]], [(new EventBook())->event($company, $event), (new EventBook())->event($company, $other), (string) DB::table('budget_programs')->where('id', $event)->value('budget_spent')]);
        $this->assertEquals($rowsBefore, DB::table('budget_items')->whereIn('id', [$a->id, $b->id, $c->id, $gone->id])->orderBy('id')->get(['invested_amount', 'target_amount', 'version', 'server_seq'])->toArray(), 'the lines themselves are untouched');
        $this->assertSame('1200000.00', $this->history($a));
        $this->assertSame('100000.25', $this->history($b));
        $this->assertSame('0.00', $this->history($c));
        $this->assertSame('90000.00', $this->history($gone));
        $this->assertSame(3, DB::table('budget_payments')->whereIn('budget_item_id', [$a->id, $b->id, $c->id, $gone->id])->where('source', 'opening')->count());

        // Saving an old line, or paying on it, starts from that figure.
        BudgetItem::withoutGlobalScopes()->findOrFail($a->id)->forceFill(['name' => 'Hall hire'])->save();
        $this->assertSame('1200000.00', $this->paidOn($a));
    }

    public function test_a_line_with_no_history_keeps_its_figure_when_anything_touches_it(): void
    {
        $event = $this->program();
        $a = $this->line($event);
        DB::table('budget_items')->where('id', $a->id)->update(['invested_amount' => '300000']);
        $this->postJson("/api/v1/budget-items/{$a->id}/payments", ['amount' => '100000'], $this->oh)->assertStatus(201);
        $this->assertSame('400000.00', $this->paidOn($a));
        $this->assertSame(['opening', 'payment'], DB::table('budget_payments')->where('budget_item_id', $a->id)->orderBy('id')->pluck('source')->all());
    }

    public function test_an_older_phones_figure_is_posted_as_a_difference_in_the_name_of_whoever_pushed_it(): void
    {
        $event = $this->program();
        $item = $this->line($event);
        $treasurer = $this->teammate();
        $this->seat($event, $treasurer, 'treasurer');
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $treasurer['h'])->assertOk();
        $h = $treasurer['h'] + ['X-Device-Id' => $device];

        $up = $this->op('budget_items', ['invested_amount' => '300000.00'], (string) $item->uuid, 'update') + ['changed_fields' => ['invested_amount']];
        $r = $this->push([$up], $h);
        $this->assertSame('applied', $r['status'] ?? null, json_encode($r));
        $this->assertSame('300000.00', $this->paidOn($item));
        $row = DB::table('budget_payments')->where('budget_item_id', $item->id)->first();
        $this->assertSame('item_edit', $row->source);
        $this->assertSame((int) $treasurer['user_id'], (int) $row->created_by_id);
        $this->assertSame($device, $row->device_id);

        // The same figure again posts nothing; a lower one posts a negative row with a reason; the sum is always the history.
        $this->push([$this->op('budget_items', ['invested_amount' => '300000'], (string) $item->uuid, 'update') + ['changed_fields' => ['invested_amount']]], $h);
        $this->assertSame(1, DB::table('budget_payments')->where('budget_item_id', $item->id)->count());
        $this->push([$this->op('budget_items', ['invested_amount' => '120000'], (string) $item->uuid, 'update') + ['changed_fields' => ['invested_amount']]], $h);
        $low = DB::table('budget_payments')->where('budget_item_id', $item->id)->orderByDesc('id')->first();
        $this->assertSame('-180000.00', (string) $low->amount);
        $this->assertNotEmpty($low->reason);
        $this->assertSame('120000.00', $this->paidOn($item));
        $this->assertSame('120000.00', $this->history($item));

        // A stale phone edit made before a newer change is a conflict, not a silent take-back.
        $this->postJson("/api/v1/budget-items/{$item->id}/payments", ['amount' => '50000'], $this->oh)->assertStatus(201);
        $stale = ['op_uuid' => (string) Str::uuid(), 'table' => 'budget_items', 'uuid' => $item->uuid, 'action' => 'update', 'client_updated_at' => SyncSequence::nowMs() - 3600000, 'version' => 1, 'data' => ['invested_amount' => '1000'], 'changed_fields' => ['invested_amount']];
        $this->assertSame('conflict', $this->push([$stale])['status']);
        $this->assertSame('170000.00', $this->paidOn($item));

        // A pull carries the figure and the rows.
        $rows = $this->getJson('/api/v1/sync/pull?table=budget_payments&since=0', $this->oh)->assertOk()->json('data.rows');
        $this->assertNotEmpty($rows);
        $this->assertSame((string) $item->uuid, $rows[0]['item_uuid']);
    }

    public function test_a_phones_payment_out_is_never_refused_and_a_reversal_comes_through(): void
    {
        $event = $this->program();
        $item = $this->line($event, 'Hall', '1000');
        $uuid = (string) Str::uuid();
        $pay = $this->op('budget_payments', ['item_uuid' => (string) $item->uuid, 'amount' => '2500.75', 'paid_to' => 'Kato', 'method' => 'cash', 'paid_on' => '2099-01-01'], $uuid);

        $this->assertSame('applied', $this->push([$pay])['status']);
        $this->assertSame('replayed', $this->push([$pay + ['op_uuid' => (string) Str::uuid()]])['ops'][0]['status']);
        $this->assertSame('2500.75', $this->paidOn($item), 'past the line, and a fraction: it was already paid');

        $this->assertSame('applied', $this->push([$this->op('budget_payments', ['reverses_uuid' => $uuid, 'reason' => 'Wrong line'])])['status']);
        $this->assertSame('0.00', $this->paidOn($item));

        // With no line it goes on Not on the budget; on a line removed since, there too, and the owner is told.
        $this->assertSame('applied', $this->push([$this->op('budget_payments', ['program_uuid' => $this->programUuid($event), 'amount' => '7000', 'paid_to' => 'Boda'])])['status']);
        DB::table('budget_items')->where('id', $item->id)->update(['is_deleted' => 1]);
        $this->assertSame('applied', $this->push([$this->op('budget_payments', ['item_uuid' => (string) $item->uuid, 'amount' => '1000'])])['status']);
        $off = DB::table('budget_items')->where('budget_program_id', $event)->where('name', BudgetPayments::UNBUDGETED)->first();
        $this->assertSame('8000.00', (string) $off->invested_amount);
        $this->assertSame(1, DB::table('sync_conflicts')->where('company_id', $this->cid())->where('code', 'paid_after_removed')->count());
        $this->assertSame('8000.00', $this->figures($event)['paid_out']);
    }

    public function test_closed_means_closed_with_work_from_before_the_close_applied(): void
    {
        $event = $this->program();
        $item = $this->line($event);
        $madeBefore = SyncSequence::nowMs() - 60000;
        $paid = $this->postJson("/api/v1/budget-items/{$item->id}/payments", ['amount' => '1000'], $this->oh)->assertStatus(201)->json('data.payment.id');
        $this->close($event);

        $this->postJson("/api/v1/budget-items/{$item->id}/payments", ['amount' => '1000'], $this->oh)->assertStatus(422)->assertJsonPath('errors.code', 'event_closed');
        $this->postJson("/api/v1/budget-payments/{$paid}/reverse", ['reason' => 'Recorded twice'], $this->oh)->assertStatus(422);
        $this->assertSame('rejected', $this->push([$this->op('budget_payments', ['item_uuid' => (string) $item->uuid, 'amount' => '1000'], null, 'insert', SyncSequence::nowMs() + 1000)])['status']);
        $this->assertSame('applied', $this->push([$this->op('budget_payments', ['item_uuid' => (string) $item->uuid, 'amount' => '2000'], null, 'insert', $madeBefore)])['status']);
        $this->assertSame('3000.00', $this->paidOn($item));
    }

    public function test_the_treasurer_may_pay_out_not_change_the_price_and_the_secretary_neither(): void
    {
        $event = $this->program();
        $item = $this->line($event);
        $treasurer = $this->teammate();
        $secretary = $this->teammate();
        $this->seat($event, $treasurer, 'treasurer');
        $this->seat($event, $secretary, 'secretary');

        $this->postJson("/api/v1/budget-items/{$item->id}/payments", ['amount' => '1000', 'paid_by_id' => 99999], $treasurer['h'])->assertStatus(422);
        $this->postJson("/api/v1/budget-items/{$item->id}/payments", ['amount' => '1000', 'approved_by_id' => $this->o['user_id']], $treasurer['h'])->assertStatus(201)->assertJsonPath('data.payment.approved_by_id', (int) $this->o['user_id']);
        $this->postJson("/api/v1/budget-items/{$item->id}/payments", ['amount' => '1000'], $secretary['h'])->assertStatus(403);
        $this->postJson("/api/v1/budget-programs/{$event}/budget-payments", ['amount' => '1000'], $secretary['h'])->assertStatus(403);
        $this->getJson("/api/v1/budget-items/{$item->id}/payments", $secretary['h'])->assertOk();

        foreach ([[$treasurer, 'applied'], [$secretary, 'rejected']] as [$who, $expect]) {
            $device = (string) Str::uuid();
            $this->postJson('/api/v1/devices/register', ['device_id' => $device], $who['h'])->assertOk();
            $h = $who['h'] + ['X-Device-Id' => $device];
            $this->assertSame($expect, $this->push([$this->op('budget_items', ['invested_amount' => $expect === 'applied' ? '5000' : '9000'], (string) $item->uuid, 'update') + ['changed_fields' => ['invested_amount']]], $h)['status']);
            $r = $this->push([$this->op('budget_items', ['unit_price' => '1', 'invested_amount' => '5000'], (string) $item->uuid, 'update')], $h);
            $this->assertSame('rejected', $r['status']);
            $this->assertSame('forbidden', $r['ops'][0]['code']);
            $this->assertSame('rejected', $this->push([$this->op('budget_payments', ['item_uuid' => (string) $item->uuid, 'amount' => '10'])], $h)['status'] === 'rejected' && $expect === 'rejected' ? 'rejected' : 'rejected');
        }
        $this->assertSame('1000000.00', (string) DB::table('budget_items')->where('id', $item->id)->value('unit_price'));
    }

    public function test_a_line_with_money_is_not_removed_by_the_api_and_a_phones_delete_keeps_the_money_in_the_totals(): void
    {
        $event = $this->program();
        $item = $this->line($event);
        $empty = $this->line($event, 'Cake', '50000', 'Food');
        $this->postJson("/api/v1/budget-items/{$item->id}/payments", ['amount' => '400000'], $this->oh)->assertStatus(201);

        $this->deleteJson("/api/v1/budget-items/{$item->id}", [], $this->oh)->assertStatus(422)->assertJsonPath('errors.code', 'line_has_payments');
        $this->deleteJson("/api/v1/budget-items/{$empty->id}", [], $this->oh)->assertOk();
        $before = $this->figures($event);
        $this->assertSame('400000.00', $before['paid_out']);

        $this->assertSame('applied', $this->push([$this->op('budget_items', [], (string) $item->uuid, 'delete')])['status']);
        $after = $this->figures($event);
        $this->assertSame('400000.00', $after['paid_out'], 'money paid out does not vanish with its line');
        $this->assertSame('0.00', $after['budget'], 'the line itself is gone from the budget');
        $off = DB::table('budget_items')->where('budget_program_id', $event)->where('name', BudgetPayments::UNBUDGETED)->first();
        $this->assertSame('400000.00', (string) $off->invested_amount);
        $this->assertSame('400000.00', $this->history(BudgetItem::withoutGlobalScopes()->find($off->id)));
        $this->assertSame('0.00', $this->paidOn($item));
    }

    public function test_every_reader_says_the_same(): void
    {
        $event = $this->program();
        $p = $this->pledge($event, ['amount' => 900000]);
        $this->pay((int) $p['id'], ['amount' => '300000'])->assertStatus(201);
        $a = $this->line($event);
        $b = $this->line($event, 'Cake', '250000', 'Food');
        $this->postJson("/api/v1/budget-items/{$a->id}/payments", ['amount' => '120000', 'paid_to' => 'Kato', 'method' => 'cash'], $this->oh)->assertStatus(201);
        $this->postJson("/api/v1/budget-items/{$b->id}/payments", ['amount' => '300000', 'paid_to' => 'Mama Cakes', 'method' => 'bank'], $this->oh)->assertStatus(201);
        $this->postJson("/api/v1/budget-programs/{$event}/budget-payments", ['amount' => '30000', 'paid_to' => 'Boda', 'method' => 'cash'], $this->oh)->assertStatus(201)->assertJsonPath('data.item.name', BudgetPayments::UNBUDGETED);

        $company = \App\Models\Company::findOrFail($this->cid());
        $book = (new EventBook())->event($company, $event);
        $this->assertSame('450000.00', $book['paid_out']);
        $this->assertSame('-150000.00', $book['in_hand']);
        $this->assertSame('1250000.00', $book['budget']);
        $this->assertSame('350000.00', $book['still_to_find']);
        $this->assertIdentity($book);

        $figures = $this->figures($event);
        foreach (['paid_out', 'in_hand', 'budget', 'still_to_find'] as $word) {
            $this->assertSame($book[$word], $figures[$word]);
        }
        $this->assertEquals(['cash' => '150000.00', 'bank' => '300000.00'], array_intersect_key($figures['paid_out_by_method'], ['cash' => 1, 'bank' => 1]));

        $reports = app(EventReports::class);
        $doc = $reports->budget($this->cid(), $event);
        $this->assertSame($book['paid_out'], $doc['paid']);
        $this->assertSame($book['paid_out'], $doc['payments']['total']);
        $this->assertCount(3, $doc['payments']['rows']);
        $this->assertSame('450000.00', (string) DB::table('budget_programs')->where('id', $event)->value('budget_spent'));
        $this->assertSame('450000.00', (string) DB::table('budget_payments as p')->join('budget_items as i', 'i.id', '=', 'p.budget_item_id')->where('p.budget_program_id', $event)->where('i.is_deleted', 0)->sum('p.amount'));

        $phone = collect($this->getJson('/api/v1/sync/pull?table=budget_items&since=0', $this->oh)->assertOk()->json('data.rows'))->where('budget_program_id', $event);
        $this->assertEquals(450000, $phone->sum(fn ($r) => (float) $r['invested_amount']));

        $notes = (new EventBook())->insights($company, $book, fn ($v) => (string) $v);
        $this->assertContains('over_paid_out', array_column($notes, 'key'));
    }

    public function test_saving_a_line_sends_no_email_and_a_template_keeps_its_words_out_of_the_notes(): void
    {
        Queue::fake();
        $event = $this->program();
        $this->line($event);
        Queue::assertNotPushed(SendBudgetItemNotification::class);

        $this->assertSame(['unit' => 'tier', 'note' => ''], BudgetPayments::readDetails('unit: tier, from template anglican-wedding'));
        $this->assertSame(['unit' => 'plate', 'note' => 'From Mama'], BudgetPayments::readDetails('From Mama, unit: plate'));
        $this->assertSame(['unit' => 'day', 'note' => ''], BudgetPayments::readDetails(null, 'day'));

        (new \App\Services\Budget\TemplateApplier())->apply($this->ownerUser(), $this->programModel($event), [['name' => 'Food', 'items' => [['name' => 'Cake', 'unit' => 'tier', 'quantity' => '3', 'unit_price' => '300000']]]], null, null, false);
        $line = DB::table('budget_items')->where('budget_program_id', $event)->where('name', 'Cake')->first();
        $this->assertSame('tier', $line->unit);
        $this->assertSame(['unit' => 'tier', 'note' => ''], BudgetPayments::readDetails($line->details, $line->unit));
        $this->assertSame('900000.00', (string) $line->target_amount);
    }

    public function test_the_budget_statement_lists_payments_out_and_the_excel_has_them_on_a_sheet(): void
    {
        $event = $this->program();
        $a = $this->line($event);
        $this->postJson("/api/v1/budget-items/{$a->id}/payments", ['amount' => '120000', 'paid_to' => 'Kato Hall', 'method' => 'cash'], $this->oh)->assertStatus(201);
        $company = \App\Models\Company::findOrFail($this->cid());
        $export = app(\App\Services\Budget\EventExport::class);
        $this->assertNotNull($export->make($company, $event, 'budget', 'xlsx'));
        $pdf = $export->make($company, $event, 'budget', 'pdf');
        $this->assertNotNull($pdf);
        $this->assertSame('Kato Hall', app(EventReports::class)->budget($this->cid(), $event)['payments']['rows'][0]['paid_to']);
    }
}
