<?php

namespace Tests\Feature\Budget;

use App\Exceptions\BusinessRuleException;
use App\Models\ContributionPayment;
use App\Services\Budget\ContributionPayments;
use App\Services\Budget\EventBook;
use App\Services\Budget\PledgeService;
use App\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The money rules through the service, the API and sync: one payment however many times it is sent (PB-02), a pledge
 * cannot silently fall below what is paid (PB-03), withdrawing (PB-04), two people with one name (PB-05) and whole
 * shillings (PB-07). The identity Pledged - Collected + Extra = Still owed holds after each.
 */
class PledgeMoneyRulesTest extends PledgeTestCase
{
    private function svc(): ContributionPayments
    {
        return app(ContributionPayments::class);
    }

    private function event(int $id): array
    {
        return app(EventBook::class)->event(Company::withoutGlobalScopes()->findOrFail($this->cid()), $id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── PB-02 ──

    public function test_the_same_reference_on_an_event_asks_before_a_second_payment(): void
    {
        $event = $this->program();
        $a = $this->pledge($event);
        $b = $this->pledge($event);
        $by = $this->o['user_id'];
        $this->svc()->record($this->cid(), $a['id'], ['amount' => '100000', 'reference' => 'MTN-ABC123'], $by);

        try {
            $this->svc()->record($this->cid(), $b['id'], ['amount' => '50000', 'reference' => '  mtn-abc123 '], $by);
            $this->fail('the same reference went through');
        } catch (BusinessRuleException $e) {
            $this->assertSame('possible_duplicate', $e->errorCode());
            $this->assertStringContainsString('PLG-', $e->getMessage());
            $this->assertStringContainsString('Record it again?', $e->getMessage());
            $this->assertArrayHasKey('earlier', $e->meta());
        }
        $this->assertSame(1, ContributionPayment::withoutGlobalScopes()->where('budget_program_id', $event)->count());

        $this->svc()->record($this->cid(), $b['id'], ['amount' => '50000', 'reference' => 'MTN-ABC123', 'confirm_duplicate' => true], $by);
        $this->assertSame(2, ContributionPayment::withoutGlobalScopes()->where('budget_program_id', $event)->count());
    }

    public function test_the_same_pledge_and_amount_within_ten_minutes_asks_and_after_ten_does_not(): void
    {
        $p = $this->pledge($this->program());
        $by = $this->o['user_id'];
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->svc()->record($this->cid(), $p['id'], ['amount' => '100000'], $by);
        Carbon::setTestNow('2026-11-05 10:03:00');
        try {
            $this->svc()->record($this->cid(), $p['id'], ['amount' => '100000'], $by);
            $this->fail('the same amount went through');
        } catch (BusinessRuleException $e) {
            $this->assertSame('possible_duplicate', $e->errorCode());
            $this->assertStringContainsString('3 minutes ago', $e->getMessage());
        }
        Carbon::setTestNow('2026-11-05 10:11:00');
        $this->svc()->record($this->cid(), $p['id'], ['amount' => '100000'], $by);
        $this->assertSame(2, ContributionPayment::withoutGlobalScopes()->where('contribution_record_id', $p['id'])->count());
    }

    public function test_a_reversed_payment_does_not_count_as_a_duplicate(): void
    {
        $p = $this->pledge($this->program());
        $by = $this->o['user_id'];
        $first = $this->svc()->record($this->cid(), $p['id'], ['amount' => '100000', 'reference' => 'REF-9'], $by);
        $this->svc()->reverse($this->cid(), $first['payment']->id, 'Wrong person', $by);
        $this->svc()->record($this->cid(), $p['id'], ['amount' => '100000', 'reference' => 'REF-9'], $by);
        $this->assertSame('100000.00', (string) $this->pledgeModel($p['id'])->paid_amount);
    }

    public function test_the_same_uuid_again_is_the_first_answer(): void
    {
        $p = $this->pledge($this->program());
        $uuid = (string) Str::uuid();
        $first = $this->svc()->record($this->cid(), $p['id'], ['amount' => '100000', 'reference' => 'R1', 'uuid' => $uuid], $this->o['user_id']);
        $again = $this->svc()->record($this->cid(), $p['id'], ['amount' => '100000', 'reference' => 'R1', 'uuid' => $uuid], $this->o['user_id']);
        $this->assertTrue($again['replayed']);
        $this->assertSame($first['payment']->id, $again['payment']->id);
    }

    public function test_a_phone_is_never_refused_by_the_soft_guard_and_the_match_is_noted(): void
    {
        $event = $this->program();
        $p = $this->pledge($event);
        $this->svc()->record($this->cid(), $p['id'], ['amount' => '100000', 'reference' => 'MTN-77'], $this->o['user_id']);
        $r = $this->push([$this->op('contribution_payments', ['contribution_uuid' => $p['uuid'], 'amount' => '100000', 'reference' => 'mtn-77'])]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertSame('200000.00', (string) $this->pledgeModel($p['id'])->paid_amount);

        $notes = app(EventBook::class)->insights(Company::withoutGlobalScopes()->findOrFail($this->cid()), $this->event($event), fn ($v) => (string) $v);
        $note = collect($notes)->firstWhere('key', 'double_payment');
        $this->assertNotNull($note);
        $this->assertStringContainsString('Possible double payment', $note['text']);
    }

    public function test_an_older_client_on_the_api_keeps_working_and_a_newer_one_can_ask(): void
    {
        $p = $this->pledge($this->program());
        $this->pay($p['id'], ['amount' => 1000, 'reference' => 'X1'])->assertStatus(201);
        $this->pay($p['id'], ['amount' => 1000, 'reference' => 'X1'])->assertStatus(201); // an old app has no way to answer the question
        $this->pay($p['id'], ['amount' => 1000, 'reference' => 'X1', 'check_duplicates' => true])->assertStatus(422)->assertJsonPath('errors.code', 'possible_duplicate');
        $this->pay($p['id'], ['amount' => 1000, 'reference' => 'X1', 'check_duplicates' => true, 'confirm_duplicate' => true])->assertStatus(201);
    }

    // ── PB-03 ──

    public function test_lowering_a_pledge_below_what_is_paid_is_refused_on_the_service_and_the_api(): void
    {
        $event = $this->program();
        $p = $this->pledge($event, ['amount' => 500000]);
        $this->pay($p['id'], ['amount' => 201000])->assertStatus(201);
        $model = $this->pledgeModel($p['id']);

        try {
            app(PledgeService::class)->save($this->ownerUser(), $this->programModel($event), ['name' => $model->name, 'amount' => '150000'], $model);
            $this->fail('the pledge fell below what is paid');
        } catch (BusinessRuleException $e) {
            $this->assertSame('below_paid', $e->errorCode());
            $this->assertSame('UGX 201,000 has been paid on this pledge. Reverse a payment first, or keep the pledge at UGX 201,000 or more.', $e->getMessage());
        }
        $this->putJson("/api/v1/contribution-records/{$p['id']}", ['amount' => 150000], $this->oh)->assertStatus(422)->assertJsonPath('errors.code', 'below_paid');
        $this->putJson("/api/v1/contribution-records/{$p['id']}", ['custom_amount' => 150000], $this->oh)->assertStatus(422)->assertJsonPath('errors.code', 'below_paid');
        $this->assertSame('500000.00', (string) $this->pledgeModel($p['id'])->amount);

        // At what is paid, and above, still saves.
        $this->putJson("/api/v1/contribution-records/{$p['id']}", ['amount' => 201000], $this->oh)->assertOk();
        $this->assertIdentity($this->event($event));
    }

    public function test_an_older_phone_lowering_a_pledge_is_accepted_and_shows_as_extra(): void
    {
        $event = $this->program();
        $p = $this->pledge($event, ['amount' => 500000]);
        $this->pay($p['id'], ['amount' => 201000])->assertStatus(201);
        $v = (int) $this->pledgeModel($p['id'])->version;
        $op = $this->op('contribution_records', ['amount' => '150000'], $p['uuid'], 'update');
        $op['version'] = $v;
        $op['changed_fields'] = ['amount'];
        $this->assertSame('applied', $this->push([$op])['status']);

        $e = $this->event($event);
        $this->assertSame(['150000.00', '201000.00', '51000.00', '0.00'], [$e['pledged'], $e['collected'], $e['extra'], $e['still_owed']]);
        $this->assertIdentity($e);
        $totals = app(EventBook::class)->totals(app(EventBook::class)->pledges($this->cid(), $event));
        $this->assertSame('51000.00', $totals['extra']);

        // The figures on the pledge itself say so too.
        $this->getJson("/api/v1/contribution-records/{$p['id']}", $this->oh)->assertJsonPath('data.extra_amount', '51000.00');
    }

    public function test_the_identity_holds_after_overpaying_offline_and_reversing(): void
    {
        $event = $this->program();
        $p = $this->pledge($event, ['amount' => 100000]);
        $q = $this->pledge($event, ['amount' => 70000]);
        $pay = (string) Str::uuid();
        $this->push([$this->op('contribution_payments', ['contribution_uuid' => $p['uuid'], 'amount' => '130000'], $pay)]);
        $this->pay($q['id'], ['amount' => 20000])->assertStatus(201);
        $e = $this->event($event);
        $this->assertSame(['170000.00', '150000.00', '30000.00', '50000.00'], [$e['pledged'], $e['collected'], $e['extra'], $e['still_owed']]);
        $this->assertIdentity($e);

        $this->push([$this->op('contribution_payments', ['reverses_uuid' => $pay, 'reason' => 'Counted twice'])]);
        $e = $this->event($event);
        $this->assertSame(['20000.00', '0.00', '150000.00'], [$e['collected'], $e['extra'], $e['still_owed']]);
        $this->assertIdentity($e);
    }

    public function test_raising_the_pledge_and_recording_is_one_step(): void
    {
        $event = $this->program();
        $p = $this->pledge($event, ['amount' => 100000]);
        $this->pay($p['id'], ['amount' => 80000])->assertStatus(201);
        $uuid = (string) Str::uuid();
        $r = app(PledgeService::class)->raiseAndPay($this->ownerUser(), $this->programModel($event), $this->pledgeModel($p['id']), ['amount' => '50000', 'uuid' => $uuid]);
        $this->assertSame('130000.00', (string) $this->pledgeModel($p['id'])->amount);
        $this->assertSame('130000.00', (string) $this->pledgeModel($p['id'])->paid_amount);
        $this->assertSame('50000.00', (string) $r['payment']->amount);
        $this->assertIdentity($this->event($event));

        // Sent again: one payment, and the pledge is not raised twice.
        $again = app(PledgeService::class)->raiseAndPay($this->ownerUser(), $this->programModel($event), $this->pledgeModel($p['id']), ['amount' => '50000', 'uuid' => $uuid]);
        $this->assertTrue($again['replayed']);
        $this->assertSame('130000.00', (string) $this->pledgeModel($p['id'])->amount);
    }

    // ── PB-04 ──

    public function test_a_wrong_pledge_can_be_withdrawn_and_every_total_falls_and_a_phone_sees_a_tombstone(): void
    {
        $event = $this->program();
        $keep = $this->pledge($event, ['amount' => 300000]);
        $wrong = $this->pledge($event, ['amount' => 700000, 'name' => 'Mistyped Person']);
        $seqBefore = (int) $this->pledgeModel($wrong['id'])->server_seq;

        $this->postJson("/api/v1/contribution-records/{$wrong['id']}/withdraw", ['reason' => 'Entered by mistake'], $this->oh)->assertOk();

        $row = DB::table('contribution_records')->where('id', $wrong['id'])->first();
        $this->assertSame(1, (int) $row->is_deleted);
        $this->assertSame('Entered by mistake', $row->withdrawn_reason);
        $this->assertSame($this->o['user_id'], (int) $row->withdrawn_by_id);
        $this->assertNotNull($row->withdrawn_at);
        $this->assertGreaterThan($seqBefore, (int) $row->server_seq);

        $e = $this->event($event);
        $this->assertSame(['300000.00', '300000.00', 1], [$e['pledged'], $e['still_owed'], $e['pledges']]);
        $stored = DB::table('budget_programs')->where('id', $event)->first();
        $this->assertSame(['300000.00', '300000.00'], [(string) $stored->total_expected, (string) $stored->total_in_pledge]);
        $this->assertSame(1, app(EventBook::class)->withdrawnCount($this->cid(), $event));
        $listed = app(EventBook::class)->withdrawn($this->cid(), $event)->first();
        $this->assertSame(['Mistyped Person', 'Entered by mistake'], [$listed->name, $listed->withdrawn_reason]);
        $this->assertNotEmpty($listed->withdrawn_by);

        // Older phones remove it on their next pull.
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $this->oh)->assertOk();
        $rows = $this->getJson('/api/v1/sync/pull?table=contribution_records&since_seq=0', $this->oh + ['X-Device-Id' => $device])->json('data.rows');
        $this->assertSame(1, collect($rows)->firstWhere('uuid', $wrong['uuid'])['is_deleted']);

        // Lists leave it out, and the same name can be added again.
        $this->getJson("/api/v1/contribution-records/{$wrong['id']}", $this->oh)->assertNotFound();
        $this->pledge($event, ['name' => 'Mistyped Person', 'amount' => 70000]);

        // Delete is still not a thing, and the model's guard stays.
        $this->deleteJson("/api/v1/contribution-records/{$keep['id']}", [], $this->oh)->assertStatus(422);
    }

    public function test_withdrawing_is_refused_while_a_payment_stands_and_works_after_the_reversal(): void
    {
        $event = $this->program();
        $p = $this->pledge($event);
        $payId = $this->pay($p['id'], ['amount' => 1000])->assertStatus(201)->json('data.payment.id');

        $this->postJson("/api/v1/contribution-records/{$p['id']}/withdraw", ['reason' => 'Entered twice'], $this->oh)->assertStatus(422)->assertJsonPath('errors.code', 'has_payments')->assertJsonPath('message', 'Reverse the payments first.');
        $this->postJson("/api/v1/contribution-records/{$p['id']}/withdraw", ['reason' => ''], $this->oh)->assertStatus(422);

        $this->postJson("/api/v1/contribution-payments/{$payId}/reverse", ['reason' => 'Wrong person'], $this->oh)->assertStatus(201);
        $this->postJson("/api/v1/contribution-records/{$p['id']}/withdraw", ['reason' => 'Entered twice'], $this->oh)->assertOk();

        // Both payment rows stay.
        $this->assertSame(2, ContributionPayment::withoutGlobalScopes()->where('contribution_record_id', $p['id'])->count());
    }

    public function test_put_back_restores_it_as_a_new_version(): void
    {
        $event = $this->program();
        $p = $this->pledge($event, ['amount' => 700000]);
        $this->postJson("/api/v1/contribution-records/{$p['id']}/withdraw", ['reason' => 'They cannot pay now'], $this->oh)->assertOk();
        $version = (int) DB::table('contribution_records')->where('id', $p['id'])->value('version');

        $this->postJson("/api/v1/contribution-records/{$p['id']}/put-back", [], $this->oh)->assertOk();

        $row = DB::table('contribution_records')->where('id', $p['id'])->first();
        $this->assertSame([0, null, null, null], [(int) $row->is_deleted, $row->withdrawn_at, $row->withdrawn_by_id, $row->withdrawn_reason]);
        $this->assertGreaterThan($version, (int) $row->version);
        $this->assertSame('700000.00', $this->event($event)['pledged']);
    }

    public function test_who_may_withdraw(): void
    {
        $event = $this->program();
        $treasurer = $this->teammate();
        $secretary = $this->teammate();
        $viewer = $this->teammate();
        $this->seat($event, $treasurer, 'treasurer');
        $this->seat($event, $secretary, 'secretary');
        $this->seat($event, $viewer, 'viewer');
        $own = $this->pledge($event, [], $secretary['h']);
        $others = $this->pledge($event);
        $old = $this->pledge($event, [], $secretary['h']);
        DB::table('contribution_records')->where('id', $old['id'])->update(['created_at' => now()->subDays(2)]);

        $w = fn (array $p, array $h) => $this->postJson("/api/v1/contribution-records/{$p['id']}/withdraw", ['reason' => 'Entered by mistake'], $h);
        $w($own, $viewer['h'])->assertForbidden();
        $w($others, $secretary['h'])->assertForbidden();
        $w($old, $secretary['h'])->assertForbidden();
        $w($own, $secretary['h'])->assertOk();
        $w($others, $treasurer['h'])->assertOk();
    }

    public function test_a_payment_from_a_phone_on_a_withdrawn_pledge_is_never_lost(): void
    {
        $event = $this->program();
        $p = $this->pledge($event, ['amount' => 90000]);
        $this->postJson("/api/v1/contribution-records/{$p['id']}/withdraw", ['reason' => 'Entered by mistake'], $this->oh)->assertOk();

        $r = $this->push([$this->op('contribution_payments', ['contribution_uuid' => $p['uuid'], 'amount' => '40000'])]);
        $this->assertSame('applied', $r['status'], json_encode($r));

        $row = DB::table('contribution_records')->where('id', $p['id'])->first();
        $this->assertSame([0, null], [(int) $row->is_deleted, $row->withdrawn_at]);
        $this->assertSame('40000.00', (string) $row->paid_amount);
        $this->assertSame(1, DB::table('sync_conflicts')->where('company_id', $this->cid())->where('code', 'paid_after_withdrawn')->count());
        $this->assertIdentity($this->event($event));
    }

    public function test_an_older_phone_editing_a_withdrawn_pledge_puts_it_back_cleanly(): void
    {
        $event = $this->program();
        $p = $this->pledge($event);
        $this->postJson("/api/v1/contribution-records/{$p['id']}/withdraw", ['reason' => 'Entered by mistake'], $this->oh)->assertOk();
        $op = $this->op('contribution_records', ['name' => 'Corrected Name'], $p['uuid'], 'update', now()->addMinute()->getTimestampMs());
        $op['version'] = (int) DB::table('contribution_records')->where('id', $p['id'])->value('version');
        $this->assertSame('applied', $this->push([$op])['status']);
        $row = DB::table('contribution_records')->where('id', $p['id'])->first();
        $this->assertSame([0, null, 'Corrected Name'], [(int) $row->is_deleted, $row->withdrawn_reason, $row->name]);
    }

    // ── PB-05 ──

    public function test_two_people_can_share_a_name_on_the_service_and_the_api(): void
    {
        $event = $this->program();
        $this->pledge($event, ['name' => 'Nakato Sarah', 'phone' => '0772123456']);
        $this->pledge($event, ['name' => 'Nakato Sarah', 'phone' => '0701222333']);
        app(PledgeService::class)->save($this->ownerUser(), $this->programModel($event), ['name' => 'nakato sarah', 'amount' => '1000']);
        $this->assertSame(3, DB::table('contribution_records')->where('budget_program_id', $event)->count());

        // An edit to the same name saves too.
        $third = $this->pledge($event, ['name' => 'Other Person']);
        $this->putJson("/api/v1/contribution-records/{$third['id']}", ['name' => 'Nakato Sarah'], $this->oh)->assertOk();
    }

    public function test_the_form_asks_only_for_the_same_name_with_the_same_or_no_phone(): void
    {
        $event = $this->program();
        $this->pledge($event, ['name' => 'Nakato  Sarah', 'phone' => '0772123456', 'amount' => 200000]);
        $svc = app(PledgeService::class);
        $program = $this->programModel($event);
        $base = ['name' => 'nakato sarah', 'amount' => '1000', 'check_same_name' => true];

        foreach ([['phone' => '0772 123 456'], ['phone' => '']] as $case) {
            try {
                $svc->save($this->ownerUser(), $program, $case + $base);
                $this->fail('it did not ask');
            } catch (BusinessRuleException $e) {
                $this->assertSame('possible_same_person', $e->errorCode());
                $this->assertSame('Is this another person?', $e->getMessage());
            }
        }
        $svc->save($this->ownerUser(), $program, ['phone' => '0701222333'] + $base);
        $svc->save($this->ownerUser(), $program, ['phone' => '0772123456'] + ['name' => 'Nakato Sarah', 'amount' => '1000']);
        $this->assertSame(3, DB::table('contribution_records')->where('budget_program_id', $event)->count());

        $matches = $svc->similar($program, ' NAKATO   sarah ');
        $this->assertCount(3, $matches);
        $this->assertSame('200000.00', $matches[0]['amount']);
        $this->assertSame('+256772123456', $matches[0]['phone']);
        $this->assertCount(3, $svc->similar($program, 'Nakat'), 'as the name is typed');
        $this->assertCount(0, $svc->similar($program, 'Na'), 'not on two letters');
    }

    public function test_two_phones_offline_write_the_same_name_and_both_stay_and_the_payment_in_the_batch_is_kept(): void
    {
        $event = $this->program();
        $programUuid = $this->programUuid($event);
        $other = $this->teammate('manager');
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $other['h'])->assertOk();

        $a = (string) Str::uuid();
        $b = (string) Str::uuid();
        $r = $this->push([$this->op('contribution_records', ['name' => 'Same Name', 'program_uuid' => $programUuid, 'amount' => '50000'], $a)]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $r = $this->push([
            $this->op('contribution_records', ['name' => 'Same Name', 'program_uuid' => $programUuid, 'amount' => '60000'], $b),
            $this->op('contribution_payments', ['contribution_uuid' => $b, 'amount' => '10000']),
        ], $other['h'] + ['X-Device-Id' => $device]);
        $this->assertSame('applied', $r['status'], json_encode($r));

        $this->assertSame(2, DB::table('contribution_records')->where('budget_program_id', $event)->where('name', 'Same Name')->count());
        $this->assertSame(1, DB::table('contribution_payments')->where('budget_program_id', $event)->count());

        $notes = app(EventBook::class)->insights(Company::withoutGlobalScopes()->findOrFail($this->cid()), $this->event($event), fn ($v) => (string) $v);
        $note = collect($notes)->firstWhere('key', 'same_name');
        $this->assertNotNull($note);
        $this->assertSame('2 pledges share a name. Same person?', $note['text']);
    }

    public function test_budget_lines_keep_their_name_rule(): void
    {
        $event = $this->program();
        $cat = $this->postJson('/api/v1/budget-item-categories', ['budget_program_id' => $event, 'name' => 'Food'], $this->oh)->assertStatus(201)->json('data.id');
        $this->postJson('/api/v1/budget-items', ['budget_item_category_id' => $cat, 'name' => 'Cake', 'unit_price' => 1000, 'quantity' => 1], $this->oh)->assertStatus(201);
        $this->postJson('/api/v1/budget-items', ['budget_item_category_id' => $cat, 'name' => 'Cake', 'unit_price' => 1000, 'quantity' => 1], $this->oh)->assertStatus(422);
    }

    // ── PB-07 ──

    public function test_a_fraction_of_a_shilling_is_refused_on_the_service_and_the_api_and_accepted_in_dollars(): void
    {
        $event = $this->program();
        $p = $this->pledge($event, ['amount' => 300000]);
        try {
            $this->svc()->record($this->cid(), $p['id'], ['amount' => '1000.75'], $this->o['user_id']);
            $this->fail('a fraction of a shilling went through');
        } catch (BusinessRuleException $e) {
            $this->assertSame(['invalid_amount', 'Whole shillings only.'], [$e->errorCode(), $e->getMessage()]);
        }
        $this->pay($p['id'], ['amount' => '1000.75'])->assertStatus(422);
        $this->postJson('/api/v1/contribution-records', ['budget_program_id' => $event, 'name' => 'Cents', 'amount' => '1000.75'], $this->oh)->assertStatus(422);
        $this->putJson("/api/v1/contribution-records/{$p['id']}", ['amount' => '300000.50'], $this->oh)->assertStatus(422);
        try {
            app(PledgeService::class)->save($this->ownerUser(), $this->programModel($event), ['name' => 'Cents', 'amount' => '1000.75']);
            $this->fail('a fraction of a shilling went through');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Whole shillings only.', $e->getMessage());
        }
        $cat = $this->postJson('/api/v1/budget-item-categories', ['budget_program_id' => $event, 'name' => 'Food'], $this->oh)->assertStatus(201)->json('data.id');
        $this->postJson('/api/v1/budget-items', ['budget_item_category_id' => $cat, 'name' => 'Cake', 'unit_price' => '1000.50', 'quantity' => 1], $this->oh)->assertStatus(422);

        $usd = $this->registerTenant(['currency' => 'USD']);
        $h = $this->auth($usd['token']);
        $id = (int) $this->postJson('/api/v1/budget-programs', ['name' => 'Fund '.uniqid()], $h)->assertStatus(201)->json('data.id');
        $q = $this->postJson('/api/v1/contribution-records', ['budget_program_id' => $id, 'name' => 'Cents', 'amount' => '1000.75'], $h)->assertStatus(201)->json('data');
        $this->postJson("/api/v1/contribution-records/{$q['id']}/payments", ['amount' => '500.25'], $h)->assertStatus(201);
    }

    public function test_a_fraction_from_a_phone_is_kept_exactly_because_money_on_a_phone_is_never_refused(): void
    {
        $p = $this->pledge($this->program(), ['amount' => 300000]);
        $this->assertSame('applied', $this->push([$this->op('contribution_payments', ['contribution_uuid' => $p['uuid'], 'amount' => '1000.75'])])['status']);
        $this->assertSame('1000.75', (string) $this->pledgeModel($p['id'])->paid_amount);
        $this->assertSame('298999.25', (string) $this->pledgeModel($p['id'])->not_paid_amount);
    }
}
