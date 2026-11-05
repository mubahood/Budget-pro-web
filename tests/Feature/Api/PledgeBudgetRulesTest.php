<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\ApiController;
use App\Models\BudgetItem;
use App\Models\BudgetItemCategory;
use App\Models\ContributionRecord;
use App\Models\SyncConflict;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A right is held on the event a row is on, not only the event a request names. Each test is one way past that, or past another rule of the
 * event books, that a real request could take.
 */
class PledgeBudgetRulesTest extends ApiTestCase
{
    private array $owner;

    private array $oh;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::flush();
        $this->owner = $this->registerTenant();
        $this->oh = $this->auth($this->owner['token']);
    }

    private function teammate(): array
    {
        $phone = '0772'.random_int(100000, 999999);
        $link = $this->postJson('/api/v1/team/invites', ['role' => 'viewer', 'phone' => $phone, 'name' => 'Helper'], $this->oh)->assertStatus(201)->json('data.link');
        $r = $this->postJson('/api/v1/invites/'.basename($link).'/accept', ['first_name' => 'Helper', 'last_name' => uniqid(), 'password' => 'secret123'])->assertStatus(201);
        Permissions::flush();

        return ['user_id' => (int) $r->json('data.user.id'), 'h' => $this->auth($r->json('data.token'))];
    }

    private function event(string $name = 'Wedding'): array
    {
        return $this->postJson('/api/v1/budget-programs', ['name' => $name.' '.uniqid()], $this->oh)->assertStatus(201)->json('data');
    }

    private function seat(int $event, int $userId, string $role): array
    {
        return $this->postJson("/api/v1/budget-programs/{$event}/members", ['user_id' => $userId, 'role' => $role], $this->oh)->assertStatus(201)->json('data');
    }

    private function pledge(int $event, array $extra = [], ?array $h = null): array
    {
        return $this->postJson('/api/v1/contribution-records', $extra + ['budget_program_id' => $event, 'name' => 'Pledger '.uniqid(), 'amount' => 100000, 'phone' => '0772123456'], $h ?? $this->oh)->assertStatus(201)->json('data');
    }

    private function pay(int $pledge, int $amount = 20000): array
    {
        return $this->postJson("/api/v1/contribution-records/{$pledge}/payments", ['amount' => $amount, 'method' => 'cash'], $this->oh)->assertStatus(201)->json('data.payment');
    }

    private function line(array $event, string $price = '500000'): BudgetItem
    {
        $cat = new BudgetItemCategory();
        $cat->forceFill(['company_id' => $this->owner['company_id'], 'budget_program_id' => $event['id'], 'name' => 'Venue '.uniqid()])->save();
        $item = new BudgetItem();
        $item->forceFill(['company_id' => $this->owner['company_id'], 'budget_item_category_id' => $cat->id, 'name' => 'Hall', 'unit_price' => $price, 'quantity' => '1', 'created_by_id' => $this->owner['user_id']])->save();

        return $item->fresh();
    }

    private function op(string $table, array $data, ?string $uuid = null, string $action = 'upsert', ?int $at = null): array
    {
        $at ??= SyncSequence::nowMs();

        return ['op_uuid' => (string) Str::uuid(), 'table' => $table, 'uuid' => $uuid ?? (string) Str::uuid(), 'action' => $action, 'client_updated_at' => $at, 'client_created_at' => $at, 'version' => 0, 'data' => $data];
    }

    private function push(array $h, array $op): array
    {
        $device = 'phone-'.uniqid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $h)->assertOk();
        $result = $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'master', 'ops' => [$op]]]], $h + ['X-Device-Id' => $device])->assertOk()->json('data.results.0');

        return ($result['ops'][0] ?? []) + $result;
    }

    private function collected(int $event): string
    {
        return (string) $this->getJson("/api/v1/budget-programs/{$event}/figures", $this->oh)->assertOk()->json('data.collected');
    }

    public function test_a_treasurer_of_one_event_cannot_reverse_money_on_another(): void
    {
        $a = $this->event('A');
        $b = $this->event('B');
        $t = $this->teammate();
        $this->seat($a['id'], $t['user_id'], 'treasurer');
        $onA = $this->pledge($a['id']);
        $onB = $this->pledge($b['id']);
        $paidOnB = $this->pay($onB['id']);
        $before = $this->collected($b['id']);

        $r = $this->push($t['h'], $this->op('contribution_payments', ['contribution_uuid' => $onA['uuid'], 'reverses_uuid' => $paidOnB['uuid'], 'reason' => 'Mine now'], null, 'insert'));

        $this->assertSame('rejected', $r['status']);
        $this->assertSame($before, $this->collected($b['id']));

        // On their own event, in the short shape the registry documents, it works.
        $paidOnA = $this->pay($onA['id']);
        $this->assertSame('applied', $this->push($t['h'], $this->op('contribution_payments', ['reverses_uuid' => $paidOnA['uuid'], 'reason' => 'Recorded twice'], null, 'insert'))['status']);
    }

    public function test_a_member_cannot_reach_a_pledge_on_another_event_by_naming_their_own(): void
    {
        $a = $this->event('A');
        $b = $this->event('B');
        $m = $this->teammate();
        $this->seat($a['id'], $m['user_id'], 'member');
        $onB = $this->pledge($b['id'], ['name' => 'Kept Name']);

        $r = $this->push($m['h'], $this->op('contribution_records', ['name' => 'Taken', 'program_uuid' => $a['uuid']], $onB['uuid']));

        $this->assertSame('rejected', $r['status']);
        $row = DB::table('contribution_records')->where('id', $onB['id'])->first();
        $this->assertSame((int) $b['id'], (int) $row->budget_program_id);
        $this->assertSame('Kept Name', $row->name);
    }

    public function test_a_chair_of_one_event_cannot_touch_the_seats_or_the_budget_of_another(): void
    {
        $a = $this->event('A');
        $b = $this->event('B');
        $c = $this->teammate();
        $this->seat($a['id'], $c['user_id'], 'chair');
        $other = $this->teammate();
        $seatOnB = $this->seat($b['id'], $other['user_id'], 'treasurer');
        $lineOnB = $this->line($b);
        Permissions::flush();

        $seat = $this->push($c['h'], $this->op('budget_program_members', ['program_uuid' => $a['uuid'], 'role' => 'viewer'], $seatOnB['uuid']));
        $this->assertSame('rejected', $seat['status']);
        $this->assertSame('treasurer', DB::table('budget_program_members')->where('id', $seatOnB['id'])->value('role'));

        $gone = $this->push($c['h'], $this->op('budget_program_members', ['program_uuid' => $a['uuid']], $seatOnB['uuid'], 'delete'));
        $this->assertSame('rejected', $gone['status']);
        $this->assertSame(0, (int) DB::table('budget_program_members')->where('id', $seatOnB['id'])->value('is_deleted'));

        $catOnB = DB::table('budget_item_categories')->where('id', $lineOnB->budget_item_category_id)->value('uuid');
        $newUuid = (string) Str::uuid();
        $line = $this->push($c['h'], $this->op('budget_items', ['name' => 'Planted', 'unit_price' => '1000', 'quantity' => '1', 'program_uuid' => $a['uuid'], 'category_uuid' => $catOnB], $newUuid, 'insert'));
        $this->assertSame('rejected', $line['status']);
        $this->assertFalse(DB::table('budget_items')->where('uuid', $newUuid)->exists());
    }

    public function test_a_closed_event_is_not_reached_by_naming_an_open_one(): void
    {
        $open = $this->event('Open');
        $closed = $this->event('Closed');
        $pledge = $this->pledge($closed['id'], ['name' => 'Before Close']);
        $this->postJson("/api/v1/budget-programs/{$closed['id']}/close", ['held_by' => 'Doreen'], $this->oh)->assertOk();

        $r = $this->push($this->oh, $this->op('contribution_records', ['name' => 'After Close', 'program_uuid' => $open['uuid']], $pledge['uuid'], 'upsert', SyncSequence::nowMs() + 5000));

        $this->assertSame('event_closed', $r['code'] ?? null, json_encode($r));
        $this->assertSame('Before Close', DB::table('contribution_records')->where('id', $pledge['id'])->value('name'));
    }

    public function test_only_who_may_take_money_can_post_it_by_a_paid_figure(): void
    {
        $e = $this->event();
        $m = $this->teammate();
        $this->seat($e['id'], $m['user_id'], 'member');
        $p = $this->pledge($e['id'], [], $m['h']);

        // By the flag, over sync.
        $flag = $this->push($m['h'], $this->op('contribution_records', ['fully_paid' => 'Yes'], $p['uuid']));
        $this->assertSame('rejected', $flag['status']);
        // By a zero next to a real figure, over sync.
        $mix = $this->push($m['h'], $this->op('contribution_records', ['custom_paid_amount' => 0, 'paid_amount' => 5000], $p['uuid']));
        $this->assertSame('rejected', $mix['status']);
        // And over REST.
        $this->putJson("/api/v1/contribution-records/{$p['id']}", ['custom_paid_amount' => 0, 'paid_amount' => 5000], $m['h'])->assertForbidden();

        $this->assertSame(0, DB::table('contribution_payments')->where('contribution_record_id', $p['id'])->count());
        $this->assertSame('0.00', (string) DB::table('contribution_records')->where('id', $p['id'])->value('paid_amount'));

        // An ordinary edit from the same person still goes through.
        $this->assertSame('applied', $this->push($m['h'], $this->op('contribution_records', ['name' => 'Corrected'], $p['uuid']))['status']);
    }

    public function test_a_member_cannot_bring_back_a_pledge_the_chair_withdrew(): void
    {
        $e = $this->event();
        $m = $this->teammate();
        $this->seat($e['id'], $m['user_id'], 'member');
        $p = $this->pledge($e['id']);
        $this->postJson("/api/v1/contribution-records/{$p['id']}/withdraw", ['reason' => 'Entered by mistake'], $this->oh)->assertOk();

        $r = $this->push($m['h'], $this->op('contribution_records', ['name' => 'Back Again'], $p['uuid'], 'upsert', SyncSequence::nowMs() + 5000));

        $this->assertSame('rejected', $r['status']);
        $this->assertSame(1, (int) DB::table('contribution_records')->where('id', $p['id'])->value('is_deleted'));
    }

    public function test_conflicts_show_event_rows_only_to_full_access(): void
    {
        $viewer = $this->teammate();
        SyncConflict::withoutGlobalScopes()->create(['company_id' => $this->owner['company_id'], 'device_id' => 'd1', 'table_name' => 'contribution_records', 'row_uuid' => (string) Str::uuid(),
            'code' => 'stale_version', 'title' => 'Pledge', 'local_json' => ['phone' => '+256772000111'], 'server_json' => ['phone' => '+256772000111'], 'state' => 'open']);

        $this->assertCount(1, $this->getJson('/api/v1/sync/conflicts', $this->oh)->assertOk()->json('data'));
        $seen = $this->getJson('/api/v1/sync/conflicts', $viewer['h'])->assertOk()->json('data');
        $this->assertStringNotContainsString('+256772000111', json_encode($seen));
    }

    public function test_a_viewer_gets_no_phone_number_from_the_committee_list(): void
    {
        $e = $this->event();
        $v = $this->teammate();
        $this->seat($e['id'], $v['user_id'], 'viewer');
        $t = $this->teammate();
        $this->seat($e['id'], $t['user_id'], 'treasurer');
        DB::table('admin_users')->where('id', $t['user_id'])->update(['phone_e164' => '+256772000222']);

        $forViewer = $this->getJson("/api/v1/budget-programs/{$e['id']}/members", $v['h'])->assertOk()->json('data.members');
        $this->assertNotEmpty($forViewer);
        $this->assertStringNotContainsString('+256772000222', json_encode($forViewer));
        $this->assertArrayHasKey('phone', $forViewer[0], 'the field stays, for older phones');

        $forTreasurer = $this->getJson("/api/v1/budget-programs/{$e['id']}/members", $t['h'])->assertOk()->json('data.members');
        $this->assertStringContainsString('+256772000222', json_encode($forTreasurer));
    }

    public function test_nested_people_and_the_account_carry_no_contact_for_someone_without_full_access(): void
    {
        $e = $this->event();
        $v = $this->teammate();
        $this->seat($e['id'], $v['user_id'], 'viewer');
        $p = $this->pledge($e['id']);
        DB::table('admin_users')->where('id', $this->owner['user_id'])->update(['phone_number' => '0772000333', 'phone_e164' => '+256772000333', 'address' => 'Plot 9 Secret Road']);
        DB::table('companies')->where('id', $this->owner['company_id'])->update(['phone_number' => '0772000444', 'momo_payout_phone' => '+256772000555', 'momo_subaccount_id' => 'RS_SECRET']);

        foreach ([
            "/api/v1/contribution-records/{$p['id']}",
            '/api/v1/contribution-records',
            "/api/v1/budget-programs/{$e['id']}",
            '/api/v1/budget-programs',
        ] as $url) {
            $body = json_encode($this->getJson($url, $v['h'])->assertOk()->json('data'));
            foreach (['0772000333', '+256772000333', 'Secret Road', '0772000444', '+256772000555', 'RS_SECRET', '0772123456'] as $private) {
                $this->assertStringNotContainsString($private, str_replace('\\/', '/', $body), $url);
            }
        }

        // Someone with full access still has them, as older phones expect.
        $this->assertStringContainsString('0772000333', json_encode($this->getJson("/api/v1/contribution-records/{$p['id']}", $this->oh)->assertOk()->json('data')));
    }

    public function test_a_reversal_after_the_line_was_removed_brings_paid_out_back(): void
    {
        $e = $this->event();
        $item = $this->line($e);
        $paid = $this->postJson("/api/v1/budget-items/{$item->id}/payments", ['amount' => '50000'], $this->oh)->assertStatus(201)->json('data.payment.id');
        // The API refuses to remove a line with money on it; a phone that removed it offline is how this happens.
        $this->assertSame('applied', $this->push($this->oh, $this->op('budget_items', [], $item->uuid, 'delete'))['status']);
        $this->assertSame(50000.0, (float) $this->getJson("/api/v1/budget-programs/{$e['id']}/figures", $this->oh)->json('data.paid_out'));

        $this->postJson("/api/v1/budget-payments/{$paid}/reverse", ['reason' => 'Recorded twice'], $this->oh)->assertStatus(201);

        $this->assertSame(0.0, (float) $this->getJson("/api/v1/budget-programs/{$e['id']}/figures", $this->oh)->json('data.paid_out'));
    }

    public function test_a_pull_says_where_phones_may_be_seen_so_a_role_change_can_refetch(): void
    {
        $e = $this->event();
        $m = $this->teammate();
        $seat = $this->seat($e['id'], $m['user_id'], 'viewer');

        $pull = $this->getJson('/api/v1/sync/pull?table=contribution_records&since=0', $m['h'])->assertOk()->json('data');
        $this->assertSame([], $pull['budget_phones']);
        $this->assertSame([(int) $e['id']], $pull['budget_seats']);

        $this->putJson("/api/v1/budget-programs/{$e['id']}/members/{$seat['id']}", ['role' => 'treasurer'], $this->oh)->assertOk();
        $this->assertSame([(int) $e['id']], $this->getJson('/api/v1/sync/pull?table=contribution_records&since=0', $m['h'])->assertOk()->json('data.budget_phones'));
        $this->assertNull($this->getJson('/api/v1/sync/pull?table=contribution_records&since=0', $this->oh)->assertOk()->json('data.budget_phones'), 'all of them');
    }

    public function test_money_from_an_older_phone_is_credited_to_who_pushed_it(): void
    {
        $e = $this->event();
        $t = $this->teammate();
        $this->seat($e['id'], $t['user_id'], 'treasurer');
        $p = $this->pledge($e['id']);

        $this->assertSame('applied', $this->push($t['h'], $this->op('contribution_records', ['paid_amount' => '5000'], $p['uuid']))['status']);

        $row = DB::table('contribution_payments')->where('contribution_record_id', $p['id'])->first();
        $this->assertNotNull($row);
        $this->assertSame($t['user_id'], (int) $row->received_by_id);
    }

    public function test_a_retried_id_does_not_read_a_row_on_another_event(): void
    {
        $a = $this->event('A');
        $b = $this->event('B');
        $c = $this->teammate();
        $this->seat($a['id'], $c['user_id'], 'chair');
        $other = $this->teammate();
        DB::table('admin_users')->where('id', $other['user_id'])->update(['phone_e164' => '+256772000666']);
        $seatOnB = $this->seat($b['id'], $other['user_id'], 'treasurer');
        $third = $this->teammate();

        $r = $this->postJson("/api/v1/budget-programs/{$a['id']}/members", ['user_id' => $third['user_id'], 'role' => 'viewer', 'uuid' => $seatOnB['uuid']], $c['h']);
        $this->assertStringNotContainsString('+256772000666', json_encode($r->json()));
        $this->assertNotSame(200, $r->status());

        $itemA = $this->line($a);
        $itemB = $this->line($b);
        $uuid = (string) Str::uuid();
        $this->postJson("/api/v1/budget-items/{$itemB->id}/payments", ['amount' => '1000', 'uuid' => $uuid], $this->oh)->assertStatus(201);
        $this->postJson("/api/v1/budget-items/{$itemA->id}/payments", ['amount' => '1000', 'uuid' => $uuid], $this->oh)->assertStatus(422);
    }

    public function test_sync_refuses_a_negative_budget_and_money_that_rounds_to_nothing(): void
    {
        $e = $this->event();
        $item = $this->line($e);
        $p = $this->pledge($e['id']);

        $this->assertSame('rejected', $this->push($this->oh, $this->op('budget_items', ['unit_price' => '-900000'], $item->uuid))['status']);
        $this->assertSame('500000.00', (string) DB::table('budget_items')->where('id', $item->id)->value('unit_price'));

        $this->assertSame('rejected', $this->push($this->oh, $this->op('contribution_payments', ['contribution_uuid' => $p['uuid'], 'amount' => '0.001'], null, 'insert'))['status']);
        $this->assertSame(0, DB::table('contribution_payments')->where('contribution_record_id', $p['id'])->count());
    }

    public function test_a_guest_from_another_event_does_not_carry_that_events_name(): void
    {
        $a = $this->event('Public');
        $b = $this->event('Private Family Matter');
        $this->pledge($b['id'], ['name' => 'Namubiru Sarah']);
        $user = User::withoutGlobalScopes()->find($this->owner['user_id']);
        $program = \App\Models\BudgetProgram::withoutGlobalScopes()->find($a['id']);

        (new \App\Services\Budget\EventInvitations())->cardMade($user, $program, 'Namubiru Sarah', 'png');

        $note = (string) DB::table('event_invitations')->where('budget_program_id', $a['id'])->value('source_note');
        $this->assertStringNotContainsString('Private Family Matter', $note);
    }

    public function test_an_edit_made_offline_before_a_payment_still_goes_through(): void
    {
        $e = $this->event();
        $p = $this->pledge($e['id'], ['name' => 'Before']);
        $version = (int) DB::table('contribution_records')->where('id', $p['id'])->value('version');
        $madeAt = SyncSequence::nowMs();
        usleep(5000);
        $this->pay($p['id']);

        $op = $this->op('contribution_records', ['name' => 'Corrected Offline', 'phone' => '0772999888'], $p['uuid'], 'update', $madeAt);
        $op['version'] = $version;
        $op['changed_fields'] = ['name', 'phone'];
        $this->assertSame('applied', $this->push($this->oh, $op)['status']);
        $this->assertSame('Corrected Offline', DB::table('contribution_records')->where('id', $p['id'])->value('name'));
        $this->assertSame('20000.00', (string) DB::table('contribution_records')->where('id', $p['id'])->value('paid_amount'), 'the money stands');

        // A figure from that time would take money back, so it stays a conflict.
        $old = $this->op('contribution_records', ['paid_amount' => '0'], $p['uuid'], 'update', $madeAt);
        $old['version'] = $version;
        $this->assertSame('conflict', $this->push($this->oh, $old)['status']);
        $this->assertSame('20000.00', (string) DB::table('contribution_records')->where('id', $p['id'])->value('paid_amount'));
    }

    public function test_a_seat_comes_down_with_its_persons_name_for_every_role(): void
    {
        $e = $this->event();
        $v = $this->teammate();
        $seat = $this->seat($e['id'], $v['user_id'], 'viewer');
        DB::table('admin_users')->where('id', $v['user_id'])->update(['name' => 'Nalwoga Winnie', 'phone_e164' => '+256772000777']);

        foreach ([$this->oh, $v['h']] as $h) {
            $rows = collect($this->getJson('/api/v1/sync/pull?table=budget_program_members&since=0', $h)->assertOk()->json('data.rows'))->keyBy('id');
            $this->assertSame('Nalwoga Winnie', $rows[$seat['id']]['name']);
            $this->assertStringNotContainsString('+256772000777', json_encode($rows));
        }
    }

    public function test_the_last_chair_cannot_step_down_from_a_phone_either(): void
    {
        $e = $this->event();
        $c = $this->teammate();
        $seat = $this->seat($e['id'], $c['user_id'], 'chair');
        // The event's maker holds a chair too, on this path; the account owner keeps the event either way, so take that seat away first.
        DB::table('budget_program_members')->where('budget_program_id', $e['id'])->where('id', '!=', $seat['id'])->update(['is_deleted' => 1]);

        $this->assertSame('last_chair', $this->push($c['h'], $this->op('budget_program_members', ['role' => 'viewer'], $seat['uuid']))['code'] ?? null);
        $this->assertSame('last_chair', $this->push($c['h'], $this->op('budget_program_members', [], $seat['uuid'], 'delete'))['code'] ?? null);
        $this->assertSame('chair', DB::table('budget_program_members')->where('id', $seat['id'])->value('role'));
        $this->assertSame(0, (int) DB::table('budget_program_members')->where('id', $seat['id'])->value('is_deleted'));

        // With a second chair, they may.
        $other = $this->teammate();
        $this->seat($e['id'], $other['user_id'], 'chair');
        $this->assertSame('applied', $this->push($c['h'], $this->op('budget_program_members', ['role' => 'viewer'], $seat['uuid']))['status']);
    }

    public function test_the_withdrawn_list_says_who_may_put_each_one_back(): void
    {
        $e = $this->event();
        $s = $this->teammate();
        $this->seat($e['id'], $s['user_id'], 'secretary');
        $theirs = $this->pledge($e['id'], [], $s['h']);
        $other = $this->pledge($e['id']);
        foreach ([$theirs, $other] as $p) {
            $this->postJson("/api/v1/contribution-records/{$p['id']}/withdraw", ['reason' => 'Entered by mistake'], $this->oh)->assertOk();
        }

        $seen = collect($this->getJson("/api/v1/budget-programs/{$e['id']}/withdrawn", $s['h'])->assertOk()->json('data.pledges'))->keyBy('id');
        $this->assertTrue($seen[$theirs['id']]['may_put_back'], 'theirs, entered today');
        $this->assertFalse($seen[$other['id']]['may_put_back']);
        $this->assertTrue(collect($this->getJson("/api/v1/budget-programs/{$e['id']}/withdrawn", $this->oh)->json('data.pledges'))->every('may_put_back'));

        $this->postJson("/api/v1/budget-programs/{$e['id']}/close", ['held_by' => 'Doreen'], $this->oh)->assertOk();
        $this->assertFalse(collect($this->getJson("/api/v1/budget-programs/{$e['id']}/withdrawn", $this->oh)->json('data.pledges'))->contains('may_put_back', true), 'closed');
    }

    public function test_the_old_routes_never_change_a_password_or_a_sign_in_detail(): void
    {
        foreach (['password', 'remember_token', 'pos_pin_hash', 'email', 'username', 'phone_number', 'phone_e164', 'status', 'company_id', 'password_pending_at', 'sessions_valid_from'] as $column) {
            $this->assertFalse(ApiController::legacyMayWrite(User::class, $column), $column);
        }
        $this->assertTrue(ApiController::legacyMayWrite(User::class, 'first_name'));
        $this->assertTrue(ApiController::legacyMayWrite(ContributionRecord::class, 'name'));
    }
}
