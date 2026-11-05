<?php

namespace Tests\Feature\Budget;

use App\Exceptions\BusinessRuleException;
use App\Services\Budget\ContributionPayments;
use App\Services\Budget\EventBook;
use App\Services\Budget\PledgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OB-06: Received by. The team member who took the money, the signed-in person unless another is chosen; who recorded
 * it stays separate. Old payments and older phones keep working, and reports can total money by who holds it.
 */
class ReceivedByTest extends PledgeTestCase
{
    public function test_received_by_defaults_to_the_person_recording_and_can_be_another_teammate(): void
    {
        $event = $this->program();
        $p = $this->pledge($event);
        $treasurer = $this->teammate();
        $this->seat($event, $treasurer, 'treasurer');
        $svc = new ContributionPayments();

        $mine = $svc->record($this->cid(), $p['id'], ['amount' => '1000'], $this->o['user_id']);
        $this->assertSame([$this->o['user_id'], $this->o['user_id']], [(int) $mine['payment']->received_by_id, (int) $mine['payment']->created_by_id]);

        $theirs = $svc->record($this->cid(), $p['id'], ['amount' => '2000', 'received_by_id' => $treasurer['user_id']], $this->o['user_id']);
        $this->assertSame([$treasurer['user_id'], $this->o['user_id']], [(int) $theirs['payment']->received_by_id, (int) $theirs['payment']->created_by_id]);
    }

    public function test_only_the_events_team_can_be_chosen(): void
    {
        $event = $this->program();
        $p = $this->pledge($event);
        $stranger = $this->registerTenant();
        $outsider = $this->teammate();
        foreach ([$stranger['user_id'], $outsider['user_id']] as $id) {
            try {
                (new ContributionPayments())->record($this->cid(), $p['id'], ['amount' => '1000', 'received_by_id' => $id], $this->o['user_id']);
                $this->fail('someone off the team was named');
            } catch (BusinessRuleException $e) {
                $this->assertSame('invalid_receiver', $e->errorCode());
            }
        }
    }

    public function test_the_api_takes_it_and_answers_both_people_and_an_old_client_sends_nothing(): void
    {
        $event = $this->program();
        $p = $this->pledge($event);
        $mate = $this->teammate();
        $stranger = $this->registerTenant();
        $this->seat($event, $mate, 'member');

        $r = $this->pay($p['id'], ['amount' => 1000, 'received_by_id' => $mate['user_id']])->assertStatus(201);
        $r->assertJsonPath('data.payment.received_by_id', $mate['user_id'])->assertJsonPath('data.payment.created_by_id', $this->o['user_id']);
        $this->assertNotEmpty($r->json('data.payment.received_by_text'));
        $this->assertNotEmpty($r->json('data.payment.created_by_text'));

        $this->pay($p['id'], ['amount' => 1000, 'received_by_id' => $stranger['user_id']])->assertStatus(422);

        $this->pay($p['id'], ['amount' => 1500])->assertStatus(201)->assertJsonPath('data.payment.received_by_id', $this->o['user_id']);
    }

    public function test_a_phone_sends_it_or_not_and_is_never_refused_over_it(): void
    {
        $event = $this->program();
        $p = $this->pledge($event);
        $mate = $this->teammate();
        $stranger = $this->registerTenant();

        $old = (string) Str::uuid();
        $new = (string) Str::uuid();
        $bad = (string) Str::uuid();
        $this->assertSame('applied', $this->push([$this->op('contribution_payments', ['contribution_uuid' => $p['uuid'], 'amount' => '1000'], $old)])['status']);
        $this->assertSame('applied', $this->push([$this->op('contribution_payments', ['contribution_uuid' => $p['uuid'], 'amount' => '2000', 'received_by_id' => $mate['user_id']], $new)])['status']);
        $this->assertSame('applied', $this->push([$this->op('contribution_payments', ['contribution_uuid' => $p['uuid'], 'amount' => '3000', 'received_by_id' => $stranger['user_id']], $bad)])['status']);

        $by = fn (string $uuid) => (int) DB::table('contribution_payments')->where('uuid', $uuid)->value('received_by_id');
        $this->assertSame($this->o['user_id'], $by($old));
        $this->assertSame($mate['user_id'], $by($new));
        $this->assertSame($this->o['user_id'], $by($bad), 'a person the server does not know falls back to the one pushing');
    }

    public function test_adding_a_pledge_with_money_takes_it_too(): void
    {
        $event = $this->program();
        $mate = $this->teammate();
        $this->seat($event, $mate, 'member');
        $r = (new PledgeService())->save($this->ownerUser(), $this->programModel($event), ['name' => 'Aunt Grace', 'amount' => '100000', 'first_payment' => '40000', 'received_by_id' => $mate['user_id']]);
        $row = DB::table('contribution_payments')->where('contribution_record_id', $r->id)->first();
        $this->assertSame([$mate['user_id'], $this->o['user_id']], [(int) $row->received_by_id, (int) $row->created_by_id]);
    }

    // ── Who took the pledge, not only the money (the owner's fuller brief) ──

    public function test_a_pledge_has_who_took_it_defaulting_to_the_person_saving_and_on_the_events_team(): void
    {
        $event = $this->program();
        $mate = $this->teammate();
        $outsider = $this->teammate();
        $stranger = $this->registerTenant();
        $this->seat($event, $mate, 'secretary');
        $svc = new PledgeService();
        $program = $this->programModel($event);

        $mine = $svc->save($this->ownerUser(), $program, ['name' => 'Mine', 'amount' => '1000']);
        $this->assertSame($this->o['user_id'], (int) $mine->received_by_id);

        $theirs = $svc->save($this->ownerUser(), $program, ['name' => 'Theirs', 'amount' => '1000', 'received_by_id' => $mate['user_id']]);
        $this->assertSame([$mate['user_id'], $this->o['user_id']], [(int) $theirs->received_by_id, (int) $theirs->treasurer_id], 'who took it stays separate from who entered it');

        foreach ([$outsider['user_id'], $stranger['user_id']] as $notOnTheTeam) {
            try {
                $svc->save($this->ownerUser(), $program, ['name' => 'Nope', 'amount' => '1000', 'received_by_id' => $notOnTheTeam]);
                $this->fail('someone off the team was named');
            } catch (BusinessRuleException $e) {
                $this->assertSame('invalid_receiver', $e->errorCode());
                $this->assertSame("Choose someone on this event's team.", $e->getMessage());
            }
        }
        $this->assertSame(0, DB::table('contribution_records')->where('budget_program_id', $event)->where('name', 'Nope')->count());
    }

    public function test_the_api_takes_who_took_the_pledge_and_an_old_client_sends_nothing(): void
    {
        $event = $this->program();
        $mate = $this->teammate();
        $outsider = $this->teammate();
        $this->seat($event, $mate, 'member');

        $this->pledge($event, ['received_by_id' => $mate['user_id']]);
        $this->postJson('/api/v1/contribution-records', ['budget_program_id' => $event, 'name' => 'Off Team', 'amount' => 1000, 'received_by_id' => $outsider['user_id']], $this->oh)
            ->assertStatus(422)->assertJsonValidationErrors('received_by_id');
        $old = $this->pledge($event);
        $this->assertSame($this->o['user_id'], (int) $this->pledgeModel($old['id'])->received_by_id);

        // An old row with nobody on it answers who entered it.
        DB::table('contribution_records')->where('id', $old['id'])->update(['received_by_id' => null]);
        $this->getJson("/api/v1/contribution-records/{$old['id']}", $this->oh)->assertJsonPath('data.received_by_id', $this->o['user_id']);
    }

    public function test_a_phone_sends_who_took_the_pledge_or_not_and_is_never_refused_over_it(): void
    {
        $event = $this->program();
        $uuid = $this->programUuid($event);
        $mate = $this->teammate();
        $stranger = $this->registerTenant();
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();
        $c = (string) Str::uuid();
        $this->assertSame('applied', $this->push([$this->op('contribution_records', ['name' => 'Older Phone', 'program_uuid' => $uuid, 'amount' => '1000'], $a)])['status']);
        $this->assertSame('applied', $this->push([$this->op('contribution_records', ['name' => 'Newer Phone', 'program_uuid' => $uuid, 'amount' => '1000', 'received_by_id' => $mate['user_id']], $b)])['status']);
        $this->assertSame('applied', $this->push([$this->op('contribution_records', ['name' => 'Bad List', 'program_uuid' => $uuid, 'amount' => '1000', 'received_by_id' => $stranger['user_id']], $c)])['status']);

        $by = fn (string $u) => (int) DB::table('contribution_records')->where('uuid', $u)->value('received_by_id');
        $this->assertSame([$this->o['user_id'], $mate['user_id'], $this->o['user_id']], [$by($a), $by($b), $by($c)]);
    }

    public function test_the_read_only_team_list_for_the_dropdown(): void
    {
        $event = $this->program();
        $seated = $this->teammate();
        $viewer = $this->teammate();
        $outsider = $this->teammate();
        $this->seat($event, $seated, 'treasurer');
        $this->seat($event, $viewer, 'viewer');

        $manager = $this->teammate('manager');
        $team = collect(app(EventBook::class)->team($this->cid(), $event))->keyBy('id');
        $this->assertSame('treasurer', $team[$seated['user_id']]['role']);
        $this->assertSame('chair', $team[$this->o['user_id']]['role'], 'whoever made the event chairs it');
        $this->assertNull($team[$manager['user_id']]['role'], 'full access needs no seat');
        $this->assertFalse($team->has($outsider['user_id']));

        $this->getJson("/api/v1/budget-programs/{$event}/team", $viewer['h'])->assertOk()->assertJsonFragment(['id' => $seated['user_id']]);
        $this->getJson("/api/v1/budget-programs/{$event}/team", $outsider['h'])->assertNotFound();
    }

    public function test_money_totals_by_who_holds_it_and_a_reversal_comes_off_the_holder(): void
    {
        $event = $this->program();
        $a = $this->pledge($event);
        $mate = $this->teammate();
        $this->seat($event, $mate, 'member');
        $svc = new ContributionPayments();
        $svc->record($this->cid(), $a['id'], ['amount' => '100000', 'received_by_id' => $mate['user_id']], $this->o['user_id']);
        $gone = $svc->record($this->cid(), $a['id'], ['amount' => '30000', 'received_by_id' => $mate['user_id']], $this->o['user_id']);
        $svc->record($this->cid(), $a['id'], ['amount' => '50000'], $this->o['user_id']);

        // The owner reverses a payment the teammate took: it leaves the teammate's hand, and the owner is who did it.
        $rev = $svc->reverse($this->cid(), $gone['payment']->id, 'Wrong person', $this->o['user_id']);
        $this->assertSame([$mate['user_id'], $this->o['user_id']], [(int) $rev['payment']->received_by_id, (int) $rev['payment']->created_by_id]);

        // An old reversal, written when received_by_id held who reversed it, still comes off the right person.
        $old = $svc->record($this->cid(), $a['id'], ['amount' => '7000', 'received_by_id' => $mate['user_id']], $this->o['user_id']);
        $oldRev = $svc->reverse($this->cid(), $old['payment']->id, 'Wrong amount', $this->o['user_id']);
        DB::table('contribution_payments')->where('id', $oldRev['payment']->id)->update(['received_by_id' => $this->o['user_id']]);

        // An opening row from before the history has nobody.
        DB::table('contribution_payments')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $this->cid(), 'budget_program_id' => $event, 'contribution_record_id' => $a['id'],
            'amount' => '9000', 'paid_on' => '2026-01-01', 'method' => 'cash', 'source' => 'opening', 'created_at' => now(), 'updated_at' => now()]);
        (new ContributionPayments())->recalculateById($this->cid(), $a['id']);

        $holders = app(EventBook::class)->holders($this->cid(), $event);
        $byId = collect($holders)->keyBy('id');
        $this->assertSame('100000.00', $byId[$mate['user_id']]['amount']);
        $this->assertSame('50000.00', $byId[$this->o['user_id']]['amount']);
        $this->assertSame('9000.00', $byId[0]['amount']);
        $this->assertSame('Not recorded', $byId[0]['name']);

        $sum = array_reduce($holders, fn ($c, $h) => bcadd($c, $h['amount'], 2), '0');
        $this->assertSame((string) $this->pledgeModel($a['id'])->paid_amount, $sum, 'holders add up to Collected');
    }

    public function test_the_team_to_choose_from_is_who_is_on_the_event_and_who_has_full_access(): void
    {
        $event = $this->program();
        $seated = $this->teammate();
        $manager = $this->teammate('manager');
        $outsider = $this->teammate();
        $this->seat($event, $seated, 'secretary');

        $team = app(EventBook::class)->receivers($this->cid(), $event);
        $this->assertArrayHasKey($this->o['user_id'], $team);
        $this->assertArrayHasKey($seated['user_id'], $team);
        $this->assertArrayHasKey($manager['user_id'], $team);
        $this->assertArrayNotHasKey($outsider['user_id'], $team);
    }

    public function test_the_payments_report_names_both_people(): void
    {
        $event = $this->program();
        $p = $this->pledge($event, ['name' => 'Aunt Grace']);
        $mate = $this->teammate();
        $this->seat($event, $mate, 'member');
        (new ContributionPayments())->record($this->cid(), $p['id'], ['amount' => '1000', 'received_by_id' => $mate['user_id'], 'reference' => 'R1'], $this->o['user_id']);
        $row = app(EventBook::class)->payments($this->cid(), $event)->first();
        $this->assertSame(['Aunt Grace', 'R1'], [$row->name, $row->reference]);
        $this->assertNotSame($row->received_by, $row->recorded_by);
    }
}
