<?php

namespace Tests\Feature\Budget;

use App\Models\Company;
use App\Services\Budget\ContributionPayments;
use App\Services\Budget\EventBook;
use App\Services\Budget\PledgeFollowUps;
use App\Services\Budget\PledgeService;
use App\Services\Onboarding\PublicDemo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PB-14 and decision D7: one set of words and one set of figures (In hand, Collected, Still owed, Paid out, Budget,
 * Still to find), on every event and on the demo. Also the kernel of PB-19 (chasing with memory), PB-39 (in-kind
 * pledges) and PB-40 (indexes).
 */
class EventFiguresTest extends PledgeTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function book(): EventBook
    {
        return app(EventBook::class);
    }

    private function company(): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($this->cid());
    }

    private function eventWithSpending(): int
    {
        $event = $this->program();
        $cat = $this->postJson('/api/v1/budget-item-categories', ['budget_program_id' => $event, 'name' => 'Food'], $this->oh)->assertStatus(201)->json('data.id');
        $item = $this->postJson('/api/v1/budget-items', ['budget_item_category_id' => $cat, 'name' => 'Cake', 'unit_price' => 900000, 'quantity' => 1], $this->oh)->assertStatus(201)->json('data.id');
        DB::table('budget_items')->where('id', $item)->update(['invested_amount' => '250000']);

        return $event;
    }

    public function test_the_six_words_and_what_they_mean(): void
    {
        $this->assertSame(['in_hand' => 'In hand', 'collected' => 'Collected', 'still_owed' => 'Still owed', 'paid_out' => 'Paid out', 'budget' => 'Budget', 'still_to_find' => 'Still to find'], EventBook::WORDS);

        $event = $this->eventWithSpending();
        $p = $this->pledge($event, ['amount' => 600000]);
        $this->pay($p['id'], ['amount' => 200000, 'method' => 'cash'])->assertStatus(201);
        $this->pay($p['id'], ['amount' => 100000, 'method' => 'mobile_money'])->assertStatus(201);

        $e = $this->book()->event($this->company(), $event);
        $this->assertSame(['50000.00', '300000.00', '300000.00', '250000.00', '900000.00', '300000.00', '0.00'],
            [$e['in_hand'], $e['collected'], $e['still_owed'], $e['paid_out'], $e['budget'], $e['still_to_find'], $e['extra']]);
        $this->assertIdentity($e);

        // Older keys stay for the screens that read them.
        $this->assertSame([$e['paid'], $e['owed'], $e['spent']], [$e['collected'], $e['still_owed'], $e['paid_out']]);
    }

    public function test_in_hand_may_be_negative(): void
    {
        $event = $this->eventWithSpending();
        $p = $this->pledge($event, ['amount' => 600000]);
        $this->pay($p['id'], ['amount' => 100000])->assertStatus(201);
        $this->assertSame('-150000.00', $this->book()->event($this->company(), $event)['in_hand']);
    }

    public function test_how_it_came_adds_up_to_collected_and_the_api_gives_the_same_figures(): void
    {
        $event = $this->program();
        $p = $this->pledge($event, ['amount' => 600000]);
        $this->pay($p['id'], ['amount' => 200000, 'method' => 'cash'])->assertStatus(201);
        $this->pay($p['id'], ['amount' => 100000, 'method' => 'mobile_money'])->assertStatus(201);
        $gone = $this->pay($p['id'], ['amount' => 5000, 'method' => 'bank'])->assertStatus(201)->json('data.payment.id');
        $this->postJson("/api/v1/contribution-payments/{$gone}/reverse", ['reason' => 'Wrong person'], $this->oh)->assertStatus(201);

        $position = $this->book()->position($this->cid(), $event);
        $methods = collect($position['by_method'])->pluck('amount', 'method')->all();
        $this->assertSame(['cash' => '200000.00', 'mobile_money' => '100000.00'], $methods);
        $this->assertSame('300000.00', array_reduce($position['by_method'], fn ($c, $m) => bcadd($c, $m['amount'], 2), '0'));

        $this->getJson("/api/v1/budget-programs/{$event}/figures", $this->oh)->assertOk()
            ->assertJsonPath('data.collected', '300000.00')->assertJsonPath('data.in_hand', '300000.00')
            ->assertJsonPath('data.words.in_hand', 'In hand');
    }

    public function test_only_one_pace_and_no_pace_a_day(): void
    {
        Carbon::setTestNow('2026-11-05 10:00:00');
        $event = $this->program();
        DB::table('budget_programs')->where('id', $event)->update(['deadline' => '2026-12-05']);
        $this->pledge($event, ['amount' => 600000]);
        $notes = $this->book()->insights($this->company(), $this->book()->event($this->company(), $event), fn ($v) => (string) $v);
        $pace = collect($notes)->firstWhere('key', 'pace');
        $this->assertStringNotContainsString('a day', $pace['text']);
    }

    public function test_every_demo_event_adds_up_and_the_wedding_has_its_figures(): void
    {
        config(['demo.days' => 2, 'demo.enabled' => true, 'demo.password' => 'demo2026', 'demo.email' => 'demo@schooldynamics.ug']);
        DB::table('admin_users')->where('username', PublicDemo::USERNAME)->update(['username' => 'public-demo-local-'.uniqid()]);
        $this->assertSame('built', app(PublicDemo::class)->run()['action']);
        $company = PublicDemo::company();

        $events = $this->book()->events($company, 'all');
        $this->assertGreaterThanOrEqual(3, count($events));
        foreach ($events as $e) {
            $this->assertIdentity($e);
            $this->assertSame(bcsub($e['collected'], $e['paid_out'], 2), $e['in_hand']);
            $position = $this->book()->position((int) $company->id, $e['id']);
            $this->assertSame($e['collected'], array_reduce($position['by_method'], fn ($c, $m) => bcadd($c, $m['amount'], 2), '0.00'));
            $this->assertSame($e['collected'], array_reduce($position['holders'], fn ($c, $h) => bcadd($c, $h['amount'], 2), '0.00'));
        }
        $wedding = collect($events)->first(fn ($e) => str_contains($e['name'], 'Wedding'));
        $this->assertSame('8640000.00', $wedding['in_hand']);
        $this->assertSame('10341000.00', $wedding['still_to_find']);
    }

    // ── Edge cases: nothing at all, zeros, a category removed with lines in it ──

    public function test_an_empty_event_reads_all_zeros_and_writes_no_note_about_money(): void
    {
        $event = $this->program();
        $e = $this->book()->event($this->company(), $event);
        $this->assertSame(['0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00'], [$e['in_hand'], $e['collected'], $e['still_owed'], $e['paid_out'], $e['budget'], $e['still_to_find'], $e['extra']]);
        $this->assertSame([0, 0], [$e['collected_pct'], $e['budget_pct']]);
        $this->assertIdentity($e);
        $this->assertSame([], $this->book()->position($this->cid(), $event)['holders']);
    }

    public function test_zero_amounts_never_become_money_and_never_divide_by_zero(): void
    {
        $event = $this->program();
        $pledges = new PledgeService();
        $by = $this->ownerUser();

        // A pledge of nothing and a payment of nothing are refused here.
        foreach (['0', '0.00', '-5'] as $none) {
            try {
                $pledges->save($by, $this->programModel($event), ['name' => 'Nothing', 'amount' => $none]);
                $this->fail('a pledge of '.$none);
            } catch (\App\Exceptions\BusinessRuleException $e) {
                $this->assertSame('invalid_amount', $e->errorCode());
            }
        }
        $p = $this->pledge($event, ['amount' => 1000]);
        foreach (['0', '0.00', '-5'] as $none) {
            try {
                (new ContributionPayments())->record($this->cid(), $p['id'], ['amount' => $none], $this->o['user_id']);
                $this->fail('a payment of '.$none);
            } catch (\App\Exceptions\BusinessRuleException $e) {
                $this->assertSame('invalid_amount', $e->errorCode());
            }
        }

        // A zero first payment records nothing; a zero in a pasted line is no amount.
        $first = $pledges->save($by, $this->programModel($event), ['name' => 'No First', 'amount' => '1000', 'first_payment' => '0']);
        $this->assertSame(0, DB::table('contribution_payments')->where('contribution_record_id', $first->id)->count());
        $this->assertSame('The amount must be above 0.', $pledges->parse($this->programModel($event), 'Peter, 0')[0]['error']);

        // An older client's pledge of 0, with money on it from a phone: every figure still adds up.
        $zero = $this->pledge($event, ['amount' => 0, 'name' => 'Zero Pledge']);
        $this->push([$this->op('contribution_payments', ['contribution_uuid' => $zero['uuid'], 'amount' => '500'])]);
        $e = $this->book()->event($this->company(), $event);
        $this->assertSame('500.00', $e['extra']);
        $this->assertIdentity($e);

        // A budget line priced at nothing is no over-budget and no division by zero; spending on it is plainly over.
        $cat = $this->postJson('/api/v1/budget-item-categories', ['budget_program_id' => $event, 'name' => 'Gifts'], $this->oh)->assertStatus(201)->json('data.id');
        $item = $this->postJson('/api/v1/budget-items', ['budget_item_category_id' => $cat, 'name' => 'Donated', 'unit_price' => 0, 'quantity' => 1], $this->oh)->assertStatus(201)->json('data.id');
        $e = $this->book()->event($this->company(), $event);
        $this->assertSame(['0.00', 0], [$e['budget'], $e['budget_pct']]);
        $this->assertNull(collect($this->book()->insights($this->company(), $e, fn ($v) => (string) $v))->firstWhere('key', 'over'));
        DB::table('budget_items')->where('id', $item)->update(['invested_amount' => '100']);
        $e = $this->book()->event($this->company(), $event);
        $this->assertNotNull(collect($this->book()->insights($this->company(), $e, fn ($v) => (string) $v))->firstWhere('key', 'over'));
    }

    public function test_a_category_with_lines_is_not_removed_on_the_api_and_one_removed_from_a_phone_loses_nothing(): void
    {
        $event = $this->program();
        $cat = (int) $this->postJson('/api/v1/budget-item-categories', ['budget_program_id' => $event, 'name' => 'Food'], $this->oh)->assertStatus(201)->json('data.id');
        $item = (int) $this->postJson('/api/v1/budget-items', ['budget_item_category_id' => $cat, 'name' => 'Cake', 'unit_price' => 900000, 'quantity' => 1], $this->oh)->assertStatus(201)->json('data.id');
        DB::table('budget_items')->where('id', $item)->update(['invested_amount' => '250000']);

        $this->deleteJson("/api/v1/budget-item-categories/{$cat}", [], $this->oh)->assertStatus(422)->assertJsonPath('errors.code', 'category_has_items');
        $empty = (int) $this->postJson('/api/v1/budget-item-categories', ['budget_program_id' => $event, 'name' => 'Empty'], $this->oh)->assertStatus(201)->json('data.id');
        $this->deleteJson("/api/v1/budget-item-categories/{$empty}", [], $this->oh)->assertOk();

        // A phone removes it anyway (its op is applied, never refused): the line stays, counted, spending and all.
        $uuid = (string) DB::table('budget_item_categories')->where('id', $cat)->value('uuid');
        $op = $this->op('budget_item_categories', [], $uuid, 'delete');
        $op['version'] = (int) DB::table('budget_item_categories')->where('id', $cat)->value('version');
        $this->assertContains($this->push([$op])['status'], ['applied', 'conflict']);
        $this->assertSame(1, (int) DB::table('budget_item_categories')->where('id', $cat)->value('is_deleted'));

        $e = $this->book()->event($this->company(), $event);
        $this->assertSame(['900000.00', '250000.00', '-250000.00'], [$e['budget'], $e['paid_out'], $e['in_hand']], 'money paid out does not vanish with a category');

        // The line can still be edited and moved, and the stored totals agree with the live ones.
        $other = (int) $this->postJson('/api/v1/budget-item-categories', ['budget_program_id' => $event, 'name' => 'Catering'], $this->oh)->assertStatus(201)->json('data.id');
        $this->putJson("/api/v1/budget-items/{$item}", ['unit_price' => 800000], $this->oh)->assertOk();
        $this->assertSame('800000.00', (string) DB::table('budget_programs')->where('id', $event)->value('budget_total'));
        $this->putJson("/api/v1/budget-items/{$item}", ['budget_item_category_id' => $other], $this->oh)->assertOk();
        $this->assertSame('800000.00', $this->book()->event($this->company(), $event)['budget']);
        $this->assertSame('800000.00', (string) DB::table('budget_programs')->where('id', $event)->value('budget_total'));
    }

    // ── PB-40 ──

    public function test_the_new_indexes_are_there(): void
    {
        foreach (['contribution_records' => 'contribution_records_program_deleted_idx', 'budget_items' => 'budget_items_program_deleted_idx', 'budget_item_categories' => 'budget_item_categories_program_deleted_idx',
            'contribution_payments' => 'contribution_payments_program_received_idx'] as $table => $index) {
            $this->assertNotEmpty(DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$index]), $index);
        }
        $this->assertNotEmpty(DB::select('SHOW INDEX FROM contribution_payments WHERE Key_name = ?', ['contribution_payments_program_reference_idx']));
    }

    // ── PB-19 ──

    public function test_following_up_leaves_a_record_and_the_walker_resumes(): void
    {
        Carbon::setTestNow('2026-11-05 10:00:00');
        $event = $this->program();
        $never = $this->pledge($event, ['name' => 'Never Reminded', 'amount' => 100000, 'phone' => '0772100001']);
        $long = $this->pledge($event, ['name' => 'Long Ago', 'amount' => 500000, 'phone' => '0772100002']);
        $recent = $this->pledge($event, ['name' => 'Yesterday', 'amount' => 900000, 'phone' => '0772100003']);
        $big = $this->pledge($event, ['name' => 'Big Never', 'amount' => 800000, 'phone' => '0772100004']);
        $paid = $this->pledge($event, ['name' => 'All Paid', 'amount' => 1000, 'phone' => '0772100005']);
        $this->pay($paid['id'], ['amount' => 1000])->assertStatus(201);
        $svc = new PledgeFollowUps();
        $by = $this->ownerUser();

        Carbon::setTestNow('2026-10-20 10:00:00');
        $svc->record($by, $this->pledgeModel($long['id']), ['kind' => 'reminder', 'channel' => 'whatsapp']);
        Carbon::setTestNow('2026-11-04 10:00:00');
        $svc->record($by, $this->pledgeModel($recent['id']), ['kind' => 'reminder', 'channel' => 'whatsapp']);
        Carbon::setTestNow('2026-11-05 10:00:00');

        $order = collect($svc->walker($by, $this->programModel($event)))->pluck('name')->all();
        $this->assertSame(['Big Never', 'Never Reminded', 'Long Ago', 'Yesterday'], $order, 'never reminded by biggest balance, then longest ago');

        // Opening the first one today moves the walker on, so a reload resumes.
        $svc->record($by, $this->pledgeModel($big['id']), ['kind' => 'reminder', 'channel' => 'whatsapp']);
        $order = collect($svc->walker($by, $this->programModel($event)))->pluck('name')->all();
        $this->assertSame('Never Reminded', $order[0]);
        $this->assertNotContains('Big Never', $order, 'not twice on one day by one person');

        // Everyone on the committee reads the same record.
        $last = $svc->latest($this->programModel($event));
        $this->assertSame((int) $big['id'], (int) $last[$big['id']]->contribution_record_id);
        $this->assertSame($by->name, $last[$big['id']]->by);

        // Not reminded in 7 days.
        $names = $this->book()->pledges($this->cid(), $event, ['not_reminded_days' => 7, 'status' => 'owing'])->pluck('r.name')->all();
        $this->assertEqualsCanonicalizing(['Never Reminded', 'Long Ago'], $names);
    }

    public function test_a_promise_that_has_passed_shows_under_worth_a_look_and_outcomes_are_checked(): void
    {
        Carbon::setTestNow('2026-11-05 10:00:00');
        $event = $this->program();
        $p = $this->pledge($event, ['amount' => 100000]);
        $svc = new PledgeFollowUps();
        $by = $this->ownerUser();
        $svc->record($by, $this->pledgeModel($p['id']), ['kind' => 'reminder', 'outcome' => 'will_pay', 'promised_on' => '2026-11-01']);
        $notes = $this->book()->insights($this->company(), $this->book()->event($this->company(), $event), fn ($v) => (string) $v);
        $this->assertSame('1 said they would pay by now and has not.', collect($notes)->firstWhere('key', 'promised')['text'] ?? null);

        $this->pay($p['id'], ['amount' => 100000])->assertStatus(201);
        $notes = $this->book()->insights($this->company(), $this->book()->event($this->company(), $event), fn ($v) => (string) $v);
        $this->assertNull(collect($notes)->firstWhere('key', 'promised'));

        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        $svc->record($by, $this->pledgeModel($p['id']), ['kind' => 'gossip']);
    }

    // ── PB-39 ──

    public function test_in_kind_never_enters_the_money_and_old_phones_never_see_it(): void
    {
        $event = $this->program();
        $money = $this->pledge($event, ['amount' => 100000]);
        $this->pay($money['id'], ['amount' => 40000])->assertStatus(201);
        $svc = new PledgeService();
        $goats = $svc->saveInKind($this->ownerUser(), $this->programModel($event), ['name' => 'Uncle Sam', 'what' => 'Two goats', 'value' => '600000', 'group' => 'Family']);
        $this->assertSame('in_kind', $goats->kind);
        $this->assertSame('0.00', (string) $goats->amount);

        $e = $this->book()->event($this->company(), $event);
        $this->assertSame(['100000.00', '40000.00', '60000.00', '0.00', 1], [$e['pledged'], $e['collected'], $e['still_owed'], $e['extra'], $e['pledges']]);
        $this->assertIdentity($e);

        $this->assertSame([$money['id']], $this->book()->pledges($this->cid(), $event)->pluck('r.id')->map(fn ($v) => (int) $v)->all());
        $inKind = $this->book()->inKind($this->cid(), $event);
        $this->assertSame(['Uncle Sam', 'Two goats', '600000.00'], [$inKind[0]->name, $inKind[0]->in_kind_what, (string) $inKind[0]->in_kind_value]);

        // Web only until PB-32: older phones and the older list do not see a row they cannot understand.
        $device = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $this->oh)->assertOk();
        $uuids = array_column($this->getJson('/api/v1/sync/pull?table=contribution_records&since_seq=0', $this->oh + ['X-Device-Id' => $device])->json('data.rows'), 'uuid');
        $this->assertNotContains($goats->uuid, $uuids);
        $this->assertNotContains($goats->id, array_column($this->getJson('/api/v1/contribution-records?filter[budget_program_id]='.$event, $this->oh)->json('data.items') ?? $this->getJson('/api/v1/contribution-records?filter[budget_program_id]='.$event, $this->oh)->json('data'), 'id'));
    }

    public function test_ticking_a_budget_line_is_the_only_way_in_kind_lowers_still_to_find(): void
    {
        $event = $this->eventWithSpending(); // a budget of 900,000 and no pledges
        $svc = new PledgeService();
        $goats = $svc->saveInKind($this->ownerUser(), $this->programModel($event), ['name' => 'Uncle Sam', 'what' => 'The cake', 'value' => '900000']);
        $this->assertSame('900000.00', $this->book()->event($this->company(), $event)['still_to_find']);
        $item = (int) DB::table('budget_items')->where('budget_program_id', $event)->value('id');
        $svc->coverLine($this->ownerUser(), $this->programModel($event), $item, (int) $goats->id);
        $this->assertSame('0.00', $this->book()->event($this->company(), $event)['still_to_find']);
        $svc->coverLine($this->ownerUser(), $this->programModel($event), $item, null);
        $this->assertSame('900000.00', $this->book()->event($this->company(), $event)['still_to_find']);
    }

    public function test_on_the_day_makes_a_pledge_and_its_payment_at_once(): void
    {
        $event = $this->program();
        $svc = new PledgeService();
        $svc->onTheDay($this->ownerUser(), $this->programModel($event), ['amount' => '50000', 'method' => 'cash']);
        $svc->onTheDay($this->ownerUser(), $this->programModel($event), ['amount' => '20000', 'method' => 'cash']);
        $svc->onTheDay($this->ownerUser(), $this->programModel($event), ['name' => 'Aunt Grace', 'amount' => '30000', 'method' => 'mobile_money', 'group' => 'Family']);
        $this->assertSame(2, DB::table('contribution_records')->where('budget_program_id', $event)->where('name', 'Well-wisher')->count());
        $e = $this->book()->event($this->company(), $event);
        $this->assertSame(['100000.00', '100000.00', '0.00'], [$e['pledged'], $e['collected'], $e['still_owed']]);
        $this->assertIdentity($e);
    }

    public function test_payments_stay_the_only_writer_of_paid_figures_after_all_of_this(): void
    {
        $event = $this->program();
        $p = $this->pledge($event, ['amount' => 1000]);
        (new ContributionPayments())->record($this->cid(), $p['id'], ['amount' => '1000'], $this->o['user_id']);
        $this->assertSame('Yes', $this->pledgeModel($p['id'])->fully_paid);
    }
}
