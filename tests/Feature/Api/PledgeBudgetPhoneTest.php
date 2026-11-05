<?php

namespace Tests\Feature\Api;

use App\Models\EventCardTemplate;
use App\Models\EventInvitation;
use App\Models\PledgeFollowUp;
use App\Services\Team\Permissions;
use App\Support\Sync\SyncSequence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the Pledge & Budget phone app needs (budget-pro-mobo, docs in pledge-and-budget/docs/MOBILE_PARITY_PLAN.md): sync by seat, viewers' phone numbers, follow-ups and the guest
 * list over sync, the default event, the documents, a receipt's link, a pasted list, closing, card designs. Each with who may not, and another account.
 */
class PledgeBudgetPhoneTest extends ApiTestCase
{
    private array $owner;

    private array $oh;

    private string $cardsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Permissions::flush();
        $this->owner = $this->registerTenant();
        $this->oh = $this->auth($this->owner['token']);
        $this->cardsRoot = sys_get_temp_dir().'/cards-'.uniqid();
        config(['budget.cards_root' => $this->cardsRoot]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cardsRoot)) {
            exec('rm -rf '.escapeshellarg($this->cardsRoot));
        }
        parent::tearDown();
    }

    private function teammate(string $accountRole = 'viewer'): array
    {
        $phone = '0772'.random_int(100000, 999999);
        $link = $this->postJson('/api/v1/team/invites', ['role' => $accountRole, 'phone' => $phone, 'name' => 'Helper'], $this->oh)->assertStatus(201)->json('data.link');
        $r = $this->postJson('/api/v1/invites/'.basename($link).'/accept', ['first_name' => 'Helper', 'last_name' => uniqid(), 'password' => 'secret123'])->assertStatus(201);
        Permissions::flush();

        return ['user_id' => (int) $r->json('data.user.id'), 'h' => $this->auth($r->json('data.token'))];
    }

    private function event(string $name = 'Wedding'): array
    {
        return $this->postJson('/api/v1/budget-programs', ['name' => $name.' '.uniqid()], $this->oh)->assertStatus(201)->json('data');
    }

    private function seat(int $event, int $userId, string $role): int
    {
        return (int) $this->postJson("/api/v1/budget-programs/{$event}/members", ['user_id' => $userId, 'role' => $role], $this->oh)->assertStatus(201)->json('data.id');
    }

    private function pledge(int $event, array $extra = []): array
    {
        return $this->postJson('/api/v1/contribution-records', $extra + ['budget_program_id' => $event, 'name' => 'Pledger '.uniqid(), 'amount' => 100000, 'phone' => '0772123456'], $this->oh)->assertStatus(201)->json('data');
    }

    /** A file: as get(), with the person read afresh, as json() does in ApiTestCase. */
    private function fetch(string $uri, array $headers = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->get($uri, $headers);
    }

    private function pullMany(array $h, array $tables): array
    {
        return $this->postJson('/api/v1/sync/pull-many', ['tables' => array_fill_keys($tables, 0)], $h)->assertOk()->json('data');
    }

    private function push(array $h, array $ops): array
    {
        $device = 'phone-'.uniqid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $h)->assertOk();

        return $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'master', 'ops' => $ops]]], $h + ['X-Device-Id' => $device])->assertOk()->json('data.results.0');
    }

    private function op(string $table, array $data, ?string $uuid = null, string $action = 'insert'): array
    {
        return ['op_uuid' => (string) Str::uuid(), 'table' => $table, 'uuid' => $uuid ?? (string) Str::uuid(), 'action' => $action,
            'client_updated_at' => SyncSequence::nowMs(), 'client_created_at' => SyncSequence::nowMs(), 'version' => 0, 'data' => $data];
    }

    public function test_a_phone_holds_only_the_events_its_person_has_a_seat_on_and_is_told_which(): void
    {
        $mine = $this->event('Mine');
        $other = $this->event('Other');
        $this->pledge($mine['id']);
        $this->pledge($other['id']);
        $member = $this->teammate();
        $this->seat($mine['id'], $member['user_id'], 'member');

        $tables = ['budget_programs', 'contribution_records', 'budget_program_members'];
        $data = $this->pullMany($member['h'], $tables);
        $this->assertSame([$mine['id']], $data['budget_seats']);
        $this->assertSame([$mine['id']], array_column($data['tables']['budget_programs']['rows'], 'id'));
        $this->assertSame([$mine['id']], array_unique(array_column($data['tables']['contribution_records']['rows'], 'budget_program_id')));

        // The owner has full access: every event, and null says "all".
        $full = $this->pullMany($this->oh, $tables);
        $this->assertNull($full['budget_seats']);
        $this->assertCount(2, $full['tables']['budget_programs']['rows']);

        // A single table's pull and the first-install snapshot are held to the same seats.
        $one = $this->getJson('/api/v1/sync/pull?table=contribution_records&since_seq=0', $member['h'])->assertOk()->json('data');
        $this->assertSame([$mine['id']], array_values(array_unique(array_column($one['rows'], 'budget_program_id'))));
        $this->assertSame([$mine['id']], $one['budget_seats']);
        $boot = $this->postJson('/api/v1/sync/bootstrap', ['tables' => $tables], $member['h'])->assertOk()->json('data');
        $this->assertSame([$mine['id']], array_column($boot['tables']['budget_programs']['rows'], 'id'));
        $this->assertSame([$mine['id']], $boot['budget_seats']);

        // Someone with no seat holds nothing, and a lost seat shows at once.
        $none = $this->teammate();
        $this->assertSame([], $this->pullMany($none['h'], $tables)['tables']['budget_programs']['rows']);
        $this->assertSame([], $this->pullMany($none['h'], $tables)['budget_seats']);
    }

    public function test_a_viewers_phone_never_receives_a_phone_number(): void
    {
        $e = $this->event();
        $this->pledge($e['id'], ['phone' => '0772123456']);
        $viewer = $this->teammate();
        $secretary = $this->teammate();
        $this->seat($e['id'], $viewer['user_id'], 'viewer');
        $this->seat($e['id'], $secretary['user_id'], 'secretary');

        $seen = $this->pullMany($viewer['h'], ['contribution_records'])['tables']['contribution_records']['rows'];
        $this->assertCount(1, $seen);
        $this->assertNull($seen[0]['phone']);
        $this->assertNotEmpty($seen[0]['name']);
        $this->assertNotEmpty($this->pullMany($secretary['h'], ['contribution_records'])['tables']['contribution_records']['rows'][0]['phone']);
    }

    public function test_a_chair_changes_the_budget_from_a_phone_and_a_treasurer_does_not(): void
    {
        $e = $this->event();
        $chair = $this->teammate();
        $treasurer = $this->teammate();
        $this->seat($e['id'], $chair['user_id'], 'chair');
        $this->seat($e['id'], $treasurer['user_id'], 'treasurer');
        $cat = (string) Str::uuid();

        $done = $this->push($chair['h'], [
            $this->op('budget_item_categories', ['name' => 'Food', 'program_uuid' => $e['uuid']], $cat),
            $this->op('budget_items', ['name' => 'Lunch', 'program_uuid' => $e['uuid'], 'category_uuid' => $cat, 'unit_price' => '5000', 'quantity' => '100']),
        ]);
        $this->assertSame('applied', $done['status'], json_encode($done));

        $refused = $this->push($treasurer['h'], [$this->op('budget_items', ['name' => 'Soda', 'program_uuid' => $e['uuid'], 'category_uuid' => $cat, 'unit_price' => '1000', 'quantity' => '1'])]);
        $this->assertSame('rejected', $refused['status']);
        $this->assertSame('forbidden', $refused['ops'][0]['code']);

        // A chair still can't make a new event: that stays with full access.
        $this->assertSame('rejected', $this->push($chair['h'], [$this->op('budget_programs', ['name' => 'Another'])])['status']);
    }

    public function test_follow_ups_sync_once_by_who_may_remind(): void
    {
        $e = $this->event();
        $p = $this->pledge($e['id']);
        $member = $this->teammate();
        $viewer = $this->teammate();
        $this->seat($e['id'], $member['user_id'], 'member');
        $this->seat($e['id'], $viewer['user_id'], 'viewer');
        $uuid = (string) Str::uuid();
        $data = ['contribution_uuid' => $p['uuid'], 'kind' => 'reminder', 'channel' => 'whatsapp', 'outcome' => 'will_pay', 'promised_on' => now()->addDays(3)->toDateString(), 'note' => 'Friday'];

        $this->assertSame('applied', ($r = $this->push($member['h'], [$this->op('pledge_follow_ups', $data, $uuid)]))['status'], json_encode($r));
        $this->assertSame('replayed', $this->push($member['h'], [$this->op('pledge_follow_ups', $data, $uuid)])['ops'][0]['status']);
        $this->assertSame(1, PledgeFollowUp::withoutGlobalScopes()->where('uuid', $uuid)->count());
        $this->assertSame('rejected', $this->push($viewer['h'], [$this->op('pledge_follow_ups', $data)])['status']);

        $row = $this->pullMany($member['h'], ['pledge_follow_ups'])['tables']['pledge_follow_ups']['rows'][0];
        $this->assertSame($uuid, $row['uuid']);
        $this->assertSame($p['uuid'], $row['contribution_uuid']);
        $this->assertSame('will_pay', $row['outcome']);
    }

    public function test_two_phones_logging_one_guest_make_one_row_and_a_design_cannot_be_pushed(): void
    {
        $e = $this->event();
        $this->pledge($e['id'], ['name' => 'Kato Paul', 'phone' => '']);
        $member = $this->teammate();
        $this->seat($e['id'], $member['user_id'], 'member');

        $a = $this->push($this->oh, [$this->op('event_invitation_ops', ['program_uuid' => $e['uuid'], 'act' => 'made', 'name' => 'Kato Paul', 'format' => 'png'])]);
        $this->assertSame('applied', $a['status'], json_encode($a));
        $b = $this->push($member['h'], [$this->op('event_invitation_ops', ['program_uuid' => $e['uuid'], 'act' => 'invited', 'name' => 'Mr. & Mrs. Kato Paul'])]);
        $this->assertSame('applied', $b['status'], json_encode($b));

        $rows = EventInvitation::withoutGlobalScopes()->where('budget_program_id', $e['id'])->get();
        $this->assertCount(1, $rows, 'a title does not make a second guest');
        $this->assertSame('invited', $rows[0]->status);
        $this->assertSame('Mr. & Mrs. Kato Paul', $rows[0]->name);
        $this->assertSame(1, (int) $rows[0]->cards_made);

        $down = $this->pullMany($member['h'], ['event_invitations', 'event_invitation_ops']);
        $this->assertSame('kato paul', $down['tables']['event_invitations']['rows'][0]['name_key']);
        $this->assertSame([], $down['tables']['event_invitation_ops']['rows']);

        $this->assertSame('applied', $this->push($member['h'], [$this->op('event_invitation_ops', ['program_uuid' => $e['uuid'], 'act' => 'unmark', 'name' => 'Kato Paul'])])['status']);
        $this->assertSame('made', EventInvitation::withoutGlobalScopes()->where('budget_program_id', $e['id'])->value('status'));

        // A viewer's phone can't write on the list; and designs come down only.
        $viewer = $this->teammate();
        $this->seat($e['id'], $viewer['user_id'], 'viewer');
        $this->assertSame('rejected', $this->push($viewer['h'], [$this->op('event_invitation_ops', ['program_uuid' => $e['uuid'], 'act' => 'invited', 'name' => 'Someone Else'])])['status']);
        $this->assertSame('read_only_table', $this->push($this->oh, [$this->op('event_card_templates', ['name' => 'x'])])['ops'][0]['code']);
    }

    public function test_home_says_who_they_are_on_each_event_and_the_default_follows_them(): void
    {
        $soon = $this->event('Soon');
        $later = $this->event('Later');
        $this->putJson("/api/v1/budget-programs/{$soon['id']}", ['deadline' => now()->addDays(10)->toDateString()], $this->oh)->assertOk();
        $this->putJson("/api/v1/budget-programs/{$later['id']}", ['deadline' => now()->addDays(90)->toDateString()], $this->oh)->assertOk();
        $this->event('Nobody sits here');
        $viewer = $this->teammate();
        $this->seat($later['id'], $viewer['user_id'], 'viewer');

        $home = $this->getJson('/api/v1/budget/home', $this->oh)->assertOk()->json('data');
        $this->assertSame($soon['id'], $home['main_event_id'], 'the nearest upcoming event with a seat');
        $this->assertNull($home['chosen_event_id']);
        $this->assertTrue($home['can_create']);
        $this->assertTrue($home['events'][0]['can']['change_lines']);
        $this->assertNotEmpty($home['titles']);

        $this->putJson('/api/v1/budget/main-event', ['event_id' => $later['id']], $this->oh)->assertOk();
        $this->assertSame($later['id'], $this->getJson('/api/v1/budget/home', $this->oh)->json('data.main_event_id'));
        $this->putJson('/api/v1/budget/main-event', ['event_id' => null], $this->oh)->assertOk();
        $this->assertSame($soon['id'], $this->getJson('/api/v1/budget/home', $this->oh)->json('data.main_event_id'));

        $mine = $this->getJson('/api/v1/budget/home', $viewer['h'])->assertOk()->json('data');
        $this->assertSame([$later['id']], array_column($mine['events'], 'id'));
        $this->assertSame('viewer', $mine['events'][0]['role']);
        $this->assertFalse($mine['events'][0]['can']['see_phones']);
        $this->assertFalse($mine['events'][0]['can']['record_payments']);
        $this->assertFalse($mine['can_create']);
        $this->putJson('/api/v1/budget/main-event', ['event_id' => $soon['id']], $viewer['h'])->assertNotFound();

        $stranger = $this->registerTenant();
        $this->putJson('/api/v1/budget/main-event', ['event_id' => $soon['id']], $this->auth($stranger['token']))->assertNotFound();
    }

    public function test_documents_go_by_right_and_a_receipt_link_opens_with_no_account(): void
    {
        $e = $this->event();
        $p = $this->pledge($e['id'], ['name' => 'Uncle Sam']);
        $pay = $this->postJson("/api/v1/contribution-records/{$p['id']}/payments", ['amount' => 20000, 'method' => 'cash'], $this->oh)->assertStatus(201)->json('data.payment');
        $viewer = $this->teammate();
        $secretary = $this->teammate();
        $this->seat($e['id'], $viewer['user_id'], 'viewer');
        $this->seat($e['id'], $secretary['user_id'], 'secretary');
        $url = "/api/v1/budget-programs/{$e['id']}/documents";

        $list = collect($this->getJson($url, $viewer['h'])->assertOk()->json('data.documents'))->keyBy('doc');
        $this->assertTrue($list['committee']['allowed']);
        $this->assertFalse($list['pledges']['allowed']);

        $this->fetch("{$url}/pledges.csv", $viewer['h'])->assertForbidden();
        $this->fetch("{$url}/committee.pdf?names=1", $secretary['h'])->assertForbidden();
        $this->fetch("{$url}/committee.pdf", $viewer['h'])->assertOk()->assertHeader('content-type', 'application/pdf');
        $csv = $this->fetch("{$url}/pledges.csv", $secretary['h'])->assertOk();
        $this->assertStringContainsString('Uncle Sam', $csv->getContent());
        $this->assertStringContainsString('attachment', (string) $csv->headers->get('content-disposition'));
        $this->fetch("{$url}/committee.xlsx", $this->oh)->assertNotFound();

        $this->getJson("/api/v1/budget-programs/{$e['id']}/summary", $viewer['h'])->assertOk()->assertJsonMissing(['Uncle Sam']);
        $this->getJson("/api/v1/budget-programs/{$e['id']}/summary?names=1", $viewer['h'])->assertForbidden();
        $this->assertStringContainsString('Uncle Sam', $this->getJson("/api/v1/budget-programs/{$e['id']}/summary?names=1", $this->oh)->assertOk()->json('data.text'));

        $link = $this->postJson("/api/v1/contribution-payments/{$pay['id']}/receipt-link", [], $secretary['h'])->assertOk()->json('data');
        $this->flushHeaders();
        $this->get(parse_url($link['url'], PHP_URL_PATH).'?'.parse_url($link['url'], PHP_URL_QUERY))->assertOk();
        $this->postJson("/api/v1/contribution-payments/{$pay['id']}/receipt-link", [], $viewer['h'])->assertForbidden();

        $stranger = $this->auth($this->registerTenant()['token']);
        $this->getJson($url, $stranger)->assertNotFound();
        $this->postJson("/api/v1/contribution-payments/{$pay['id']}/receipt-link", [], $stranger)->assertNotFound();
    }

    public function test_a_pasted_list_is_read_then_added_by_what_the_server_read(): void
    {
        $e = $this->event();
        $url = "/api/v1/budget-programs/{$e['id']}/pledges";
        $text = "Peter Okello, 772123456, 200,000/=\nGrace, 50000, Friends\nno amount here";
        $read = $this->postJson("{$url}/paste", ['text' => $text], $this->oh)->assertOk()->json('data');
        $this->assertSame('200000', rtrim(rtrim((string) $read['rows'][0]['amount'], '0'), '.') === '2' ? '200000' : (string) (int) $read['rows'][0]['amount']);
        $this->assertNotEmpty($read['rows'][0]['phone'], 'a bare number is a phone, never money');
        $this->assertSame(0, DB::table('contribution_records')->where('budget_program_id', $e['id'])->count(), 'reading writes nothing');

        $added = $this->postJson("{$url}/import", ['text' => $text], $this->oh)->assertStatus(201)->json('data.added');
        $this->assertSame($read['ready'], $added);
        $this->assertSame($added, DB::table('contribution_records')->where('budget_program_id', $e['id'])->where('is_deleted', 0)->count());

        $viewer = $this->teammate();
        $this->seat($e['id'], $viewer['user_id'], 'viewer');
        $this->postJson("{$url}/paste", ['text' => $text], $viewer['h'])->assertForbidden();
    }

    public function test_closing_and_reopening_and_the_withdrawn_list(): void
    {
        $e = $this->event();
        $p = $this->pledge($e['id']);
        $treasurer = $this->teammate();
        $this->seat($e['id'], $treasurer['user_id'], 'treasurer');
        $this->postJson("/api/v1/budget-programs/{$e['id']}/close", ['held_by' => 'Doreen'], $treasurer['h'])->assertForbidden();

        $this->postJson("/api/v1/contribution-records/{$p['id']}/withdraw", ['reason' => 'Entered by mistake'], $this->oh)->assertOk();
        $gone = $this->getJson("/api/v1/budget-programs/{$e['id']}/withdrawn", $this->oh)->assertOk()->json('data.pledges');
        $this->assertSame('Entered by mistake', $gone[0]['withdrawn_reason']);

        $this->postJson("/api/v1/budget-programs/{$e['id']}/close", ['held_by' => 'Doreen', 'note' => 'At the bank'], $this->oh)->assertOk();
        $row = DB::table('budget_programs')->where('id', $e['id'])->first();
        $this->assertSame('Inactive', $row->status);
        $this->assertNotNull($row->closed_at);
        $this->assertStringContainsString('Doreen', (string) $row->settings);
        $this->postJson("/api/v1/budget-programs/{$e['id']}/pledges/paste", ['text' => 'Kato, 5000'], $this->oh)->assertStatus(422);

        $this->postJson("/api/v1/budget-programs/{$e['id']}/reopen", [], $this->oh)->assertOk();
        $this->assertSame('Active', DB::table('budget_programs')->where('id', $e['id'])->value('status'));
    }

    public function test_card_designs_save_with_their_files_and_come_down_by_sync_without_their_paths(): void
    {
        $e = $this->event();
        $url = "/api/v1/budget-programs/{$e['id']}/card-templates";
        $figures = ['name' => 'Gold card', 'font_key' => 'great-vibes', 'font_size' => 70, 'color' => '#8a560c', 'name_y' => 57.9, 'letter_case' => 'asis', 'letter_spacing' => 0, 'max_width' => 72, 'pdf_page' => 1];
        $card = UploadedFile::fake()->image('card.png', 800, 1200);

        $made = $this->post($url, $figures + ['card' => $card], $this->oh + ['Accept' => 'application/json'])->assertStatus(201)->json('data');
        $this->assertTrue($made['is_default'], 'the first design is the default');
        $this->assertSame(800, $made['background_w']);

        $this->fetch("/api/v1/card-templates/{$made['id']}/file/picture", $this->oh)->assertOk()->assertHeader('content-type', 'image/png');

        $row = $this->pullMany($this->oh, ['event_card_templates'])['tables']['event_card_templates']['rows'][0];
        $this->assertSame($made['uuid'], $row['uuid']);
        $this->assertSame($e['uuid'], $row['program_uuid']);
        $this->assertSame($made['file_version'], $row['file_version']);
        $this->assertArrayNotHasKey('background_path', $row);
        $this->assertArrayNotHasKey('source_path', $row);

        $moved = $this->postJson("/api/v1/card-templates/{$made['id']}", ['name_y' => 60] + $figures, $this->oh)->assertOk()->json('data');
        $this->assertSame(60.0, (float) $moved['name_y']);
        $this->assertSame($made['file_version'], $moved['file_version'], 'moving the name keeps the file');

        $second = $this->post($url, ['name' => 'Second'] + $figures + ['card' => UploadedFile::fake()->image('b.jpg', 800, 1200)], $this->oh + ['Accept' => 'application/json'])->assertStatus(201)->json('data');
        $this->assertFalse($second['is_default']);
        $this->postJson("/api/v1/card-templates/{$second['id']}/default", ['default' => true], $this->oh)->assertOk();
        $this->assertFalse((bool) EventCardTemplate::withoutGlobalScopes()->find($made['id'])->is_default);

        // A member reads and makes cards; only the chair changes designs; another account sees nothing.
        $member = $this->teammate();
        $this->seat($e['id'], $member['user_id'], 'member');
        $list = $this->getJson($url, $member['h'])->assertOk()->json('data');
        $this->assertFalse($list['may_change']);
        $this->assertCount(2, $list['templates']);
        $this->fetch("/api/v1/card-templates/{$made['id']}/file/picture", $member['h'])->assertOk();
        $this->postJson("/api/v1/card-templates/{$made['id']}", $figures, $member['h'])->assertForbidden();
        $this->deleteJson("/api/v1/card-templates/{$made['id']}", [], $member['h'])->assertForbidden();

        $stranger = $this->auth($this->registerTenant()['token']);
        $this->getJson($url, $stranger)->assertNotFound();
        $this->fetch("/api/v1/card-templates/{$made['id']}/file/picture", $stranger)->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->post($url, ['font_key' => 'no-such-font'] + $figures + ['card' => UploadedFile::fake()->image('c.png', 800, 1200)], $this->oh + ['Accept' => 'application/json'])->assertStatus(422);
        $this->deleteJson("/api/v1/card-templates/{$second['id']}", [], $this->oh)->assertOk();
        $this->assertTrue((bool) EventCardTemplate::withoutGlobalScopes()->find($made['id'])->is_default, 'the next design takes over as default');
    }

    public function test_a_waiting_seat_can_be_resent_and_cancelled(): void
    {
        $e = $this->event();
        $url = "/api/v1/budget-programs/{$e['id']}/members";
        $seat = $this->postJson($url, ['phone' => '0772'.random_int(100000, 999999), 'name' => 'New Person', 'role' => 'member'], $this->oh)->assertStatus(201)->json('data');
        $this->assertSame('invited', $seat['status']);
        $this->assertNotEmpty($seat['invite_link']);

        $again = $this->postJson("{$url}/{$seat['id']}/resend", [], $this->oh)->assertOk()->json('data');
        $this->assertNotEmpty($again['invite_link']);

        $this->deleteJson("{$url}/{$seat['id']}", [], $this->oh)->assertOk()->assertJsonFragment(['message' => 'Invitation cancelled. The link no longer works.']);
        $this->assertSame([], array_filter($this->getJson($url, $this->oh)->json('data.members') ?? [], fn ($m) => $m['id'] === $seat['id']));
    }
}
