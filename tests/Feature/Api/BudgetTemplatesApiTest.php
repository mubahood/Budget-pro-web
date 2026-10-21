<?php

namespace Tests\Feature\Api;

use App\Services\Budget\BudgetTemplates;
use App\Services\Team\Permissions;
use App\Support\Sync\SyncSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Budget templates on the API (read only, ETag), applying one to an event (merge rules, who may, other accounts),
 * and an event's settings: through the API and through sync, by the chair (settings) and the treasurer (payment
 * details), older apps' payloads without the new fields, and odd values cleaned rather than refused.
 */
class BudgetTemplatesApiTest extends ApiTestCase
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

    private function teammate(string $phone): array
    {
        $link = $this->postJson('/api/v1/team/invites', ['role' => 'viewer', 'phone' => $phone, 'name' => 'Helper'], $this->oh)->assertStatus(201)->json('data.link');
        $r = $this->postJson('/api/v1/invites/'.basename($link).'/accept', ['first_name' => 'Helper', 'last_name' => uniqid(), 'password' => 'secret123'])->assertStatus(201);
        Permissions::flush();

        return ['user_id' => (int) $r->json('data.user.id'), 'h' => $this->auth($r->json('data.token'))];
    }

    private function program(array $extra = []): int
    {
        return (int) $this->postJson('/api/v1/budget-programs', $extra + ['name' => 'Wedding '.uniqid()], $this->oh)->assertStatus(201)->json('data.id');
    }

    private function seat(int $program, int $userId, string $role): void
    {
        $this->postJson("/api/v1/budget-programs/{$program}/members", ['user_id' => $userId, 'role' => $role], $this->oh)->assertStatus(201);
    }

    public function test_templates_are_listed_filtered_and_cached_by_etag(): void
    {
        $r = $this->getJson('/api/v1/budget-templates', $this->oh)->assertOk();
        $etag = $r->headers->get('ETag');
        $this->assertSame(BudgetTemplates::etag(), $etag);
        $this->assertSame(count(BudgetTemplates::all()), count($r->json('data.templates')));
        $kw = collect($r->json('data.templates'))->firstWhere('key', 'kwanjula-buganda');
        $this->assertSame(300, $kw['guests']);
        $this->assertSame(BudgetTemplates::summary(BudgetTemplates::find('kwanjula-buganda'))['total'], $kw['total']);
        $this->assertSame('UGX', $r->json('data.currency'));

        $this->getJson('/api/v1/budget-templates', $this->oh + ['If-None-Match' => $etag])->assertStatus(304);
        $this->assertSame(['nikah-walima'], array_values(array_intersect(['nikah-walima', 'catholic-wedding'], array_column($this->getJson('/api/v1/budget-templates?religion=muslim', $this->oh)->json('data.templates'), 'key'))));
        $full = $this->getJson('/api/v1/budget-templates?full=1', $this->oh)->assertOk();
        $this->assertSame(BudgetTemplates::version(), $full->json('data.version'));
        $this->assertNotEmpty($full->json('data.messages.reminder.lg'));

        $one = $this->getJson('/api/v1/budget-templates/garden-wedding?guests=150', $this->oh)->assertOk();
        $this->assertSame(150, $one->json('data.guests'));
        $this->assertSame(300, $one->json('data.default_guests'));
        $this->assertSame(BudgetTemplates::total(BudgetTemplates::plan(BudgetTemplates::find('garden-wedding'), 150)), $one->json('data.total'));
        $this->getJson('/api/v1/budget-templates/no-such-thing', $this->oh)->assertNotFound();

        // An account in dollars sees dollar prices.
        $usd = $this->registerTenant(['currency' => 'USD']);
        $d = $this->getJson('/api/v1/budget-templates/garden-wedding', $this->auth($usd['token']))->assertOk();
        $this->assertSame('USD', $d->json('data.currency'));
        $this->assertLessThan($one->json('data.total'), $d->json('data.total'));
    }

    public function test_applying_a_template_merges_into_the_budget_and_never_deletes(): void
    {
        $program = $this->program();
        // Already there: a "food and drinks" category (other case) with the buffet in it, and a category of their own.
        $food = (int) $this->postJson('/api/v1/budget-item-categories', ['budget_program_id' => $program, 'name' => 'food AND drinks'], $this->oh)->assertStatus(201)->json('data.id');
        $mine = (int) $this->postJson('/api/v1/budget-item-categories', ['budget_program_id' => $program, 'name' => 'Our own extras'], $this->oh)->assertStatus(201)->json('data.id');
        $buffet = (int) $this->postJson('/api/v1/budget-items', ['budget_item_category_id' => $food, 'name' => 'BUFFET FOR THE GUESTS', 'unit_price' => 18000, 'quantity' => 100], $this->oh)->assertStatus(201)->json('data.id');

        $r = $this->postJson("/api/v1/budget-programs/{$program}/apply-template", ['template' => 'kwanjula-buganda', 'guests' => 200], $this->oh)->assertOk();
        $tpl = BudgetTemplates::find('kwanjula-buganda');
        $lines = array_sum(array_map(fn ($c) => count($c['items']), $tpl['categories']));
        $this->assertSame(1, $r->json('data.categories_reused'));
        $this->assertSame(count($tpl['categories']) - 1, $r->json('data.categories_added'));
        $this->assertSame($lines - 1, $r->json('data.items_added'));
        $this->assertSame(['Food and drinks / Buffet for the guests'], $r->json('data.items_skipped'));
        $this->assertEquals(18000, DB::table('budget_items')->where('id', $buffet)->value('unit_price'), 'their own buffet is untouched');
        $this->assertSame(0, (int) DB::table('budget_item_categories')->where('id', $mine)->value('is_deleted'));
        $this->assertSame(1, DB::table('budget_item_categories')->where('budget_program_id', $program)->whereRaw('LOWER(name) = ?', ['food and drinks'])->count(), 'never twice');
        $sodas = DB::table('budget_items')->where('budget_program_id', $program)->where('name', 'Sodas (crate of 24)')->first();
        $this->assertEquals(17, $sodas->quantity, '200 guests: 17 crates');
        $this->assertStringContainsString('from template kwanjula-buganda', (string) $sodas->details);
        $event = DB::table('budget_programs')->where('id', $program)->first();
        $this->assertSame('introduction', $event->event_type);
        $this->assertSame('kwanjula-buganda', $event->template_key);
        $this->assertSame($tpl['groups'], json_decode((string) $event->groups, true));
        $this->assertSame(200, json_decode((string) $event->settings, true)['guests']);
        $budget = DB::table('budget_items')->where('budget_program_id', $program)->where('is_deleted', 0)->sum('target_amount');
        $this->assertEquals($budget, $event->budget_total, 'the event total follows');

        // Again: nothing new, nothing doubled.
        $again = $this->postJson("/api/v1/budget-programs/{$program}/apply-template", ['template' => 'kwanjula-buganda', 'guests' => 200], $this->oh)->assertOk();
        $this->assertSame(0, $again->json('data.items_added'));
        $this->assertSame($lines, count($again->json('data.items_skipped')));

        // A reviewed plan: only what is left ticked, with the edited figures; the groups the event has stay.
        $this->putJson("/api/v1/budget-programs/{$program}/settings", ['groups' => ['Family', 'Friends']], $this->oh)->assertOk();
        $r = $this->postJson("/api/v1/budget-programs/{$program}/apply-template", ['template' => 'catholic-wedding', 'guests' => 100, 'plan' => [
            ['name' => 'Church', 'items' => [['name' => 'Parish wedding fee', 'unit' => 'wedding', 'quantity' => 1, 'unit_price' => 350000]]],
        ]], $this->oh)->assertOk();
        $this->assertSame(1, $r->json('data.items_added'));
        $this->assertSame('350000.00', $r->json('data.total_added'));
        $this->assertSame(['Family', 'Friends'], json_decode((string) DB::table('budget_programs')->where('id', $program)->value('groups'), true));
        $this->assertSame('introduction', DB::table('budget_programs')->where('id', $program)->value('event_type'), 'the type is kept once set');
        $this->postJson("/api/v1/budget-programs/{$program}/apply-template", ['template' => 'catholic-wedding', 'plan' => [['name' => 'Church', 'items' => [['name' => 'Fee', 'quantity' => 0, 'unit_price' => 1]]]]], $this->oh)->assertStatus(422);
        $this->postJson("/api/v1/budget-programs/{$program}/apply-template", ['template' => 'nope'], $this->oh)->assertNotFound();
    }

    public function test_who_may_apply_and_other_accounts_cannot(): void
    {
        $program = $this->program();
        $chair = $this->teammate('0772700001');
        $treasurer = $this->teammate('0772700002');
        $this->seat($program, $chair['user_id'], 'chair');
        $this->seat($program, $treasurer['user_id'], 'treasurer');

        $this->postJson("/api/v1/budget-programs/{$program}/apply-template", ['template' => 'civil-wedding'], $treasurer['h'])->assertStatus(403);
        $this->postJson("/api/v1/budget-programs/{$program}/apply-template", ['template' => 'civil-wedding'], $chair['h'])->assertOk()->assertJsonPath('data.categories_added', 4);

        $stranger = $this->registerTenant();
        $this->postJson("/api/v1/budget-programs/{$program}/apply-template", ['template' => 'civil-wedding'], $this->auth($stranger['token']))->assertNotFound();
        $this->getJson("/api/v1/budget-programs/{$program}/settings", $this->auth($stranger['token']))->assertNotFound();
        $this->assertSame(0, DB::table('budget_item_categories')->where('company_id', $stranger['company_id'])->count());
    }

    public function test_settings_belong_to_the_chair_and_payment_details_also_to_the_treasurer(): void
    {
        $program = $this->program();
        $chair = $this->teammate('0772700011');
        $treasurer = $this->teammate('0772700012');
        $viewer = $this->teammate('0772700013');
        $this->seat($program, $chair['user_id'], 'chair');
        $this->seat($program, $treasurer['user_id'], 'treasurer');
        $this->seat($program, $viewer['user_id'], 'viewer');

        $read = $this->getJson("/api/v1/budget-programs/{$program}/settings", $viewer['h'])->assertOk();
        $this->assertSame(['settings' => false, 'payment_details' => false], $read->json('data.can'));
        $this->assertSame(BudgetTemplates::defaultGroups(), $read->json('data.groups'));
        $this->assertFalse($read->json('data.custom_groups'));
        $this->assertStringContainsString('{name}', $read->json('data.messages.reminder'));

        $saved = $this->putJson("/api/v1/budget-programs/{$program}/settings", [
            'event_type' => 'wedding', 'venue' => 'Mukono, Garden of Eden', 'groups' => ["Groom's family", "Bride's family", ' Friends '],
            'settings' => ['messages' => ['reminder' => 'Hi {name}, {balance} left'], 'message_lang' => 'lg', 'presets' => [50000, 100000], 'currency_display' => 'USh'],
        ], $chair['h'])->assertOk();
        $this->assertSame(["Groom's family", "Bride's family", 'Friends'], $saved->json('data.groups'));
        $this->assertSame('Hi {name}, {balance} left', $saved->json('data.messages.reminder'));
        $this->assertSame(\App\Services\Budget\MessageTemplates::defaultText('thanks', 'lg'), $saved->json('data.messages.thanks'), 'the other kinds: the default in Luganda');
        $this->putJson("/api/v1/budget-programs/{$program}/settings", ['settings' => ['messages' => ['shout' => 'x']]], $chair['h'])->assertStatus(422);

        $this->putJson("/api/v1/budget-programs/{$program}/settings", ['venue' => 'Elsewhere'], $treasurer['h'])->assertStatus(403);
        $this->putJson("/api/v1/budget-programs/{$program}/settings", ['payment_details' => ['mtn' => '0772 123 456', 'mtn_name' => 'Doreen', 'treasurer_phone' => 'not a phone']], $treasurer['h'])
            ->assertStatus(422)->assertJsonPath('errors.code', 'invalid_phone');
        $pd = $this->putJson("/api/v1/budget-programs/{$program}/settings", ['payment_details' => ['mtn' => '0772 123 456', 'mtn_name' => 'Doreen', 'bank' => 'Stanbic Mukono']], $treasurer['h'])->assertOk();
        $this->assertSame(['mtn' => '+256772123456', 'mtn_name' => 'Doreen', 'bank' => 'Stanbic Mukono'], $pd->json('data.payment_details'));
        $this->putJson("/api/v1/budget-programs/{$program}/settings", ['payment_details' => ['mtn' => '0772 000 000']], $viewer['h'])->assertStatus(403);

        // A partial settings object keeps what it does not mention; the plain event edit keeps the settings.
        $this->putJson("/api/v1/budget-programs/{$program}/settings", ['settings' => ['guests' => 250]], $chair['h'])->assertOk();
        $this->putJson("/api/v1/budget-programs/{$program}", ['name' => 'Brian & Grace'], $this->oh)->assertOk();
        $row = DB::table('budget_programs')->where('id', $program)->first();
        $s = json_decode((string) $row->settings, true);
        $this->assertSame([50000, 100000], $s['presets']);
        $this->assertSame(250, $s['guests']);
        $this->assertSame('Mukono, Garden of Eden', $row->venue);
    }

    // ── Sync ─────────────────────────────────────────────────────────

    private function push(array $who, array $ops): array
    {
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $who['h'])->assertOk();

        return $this->postJson('/api/v1/sync/push', ['batches' => [['batch_uuid' => (string) Str::uuid(), 'kind' => 'master', 'ops' => $ops]]], $who['h'] + ['X-Device-Id' => $device])
            ->assertOk()->json('data.results.0');
    }

    private function op(string $table, string $action, array $data, string $uuid, ?array $changed = null): array
    {
        return ['op_uuid' => (string) Str::uuid(), 'table' => $table, 'uuid' => $uuid, 'action' => $action, 'client_updated_at' => SyncSequence::nowMs(), 'version' => 0,
            'data' => $data] + ($changed === null ? [] : ['changed_fields' => $changed]);
    }

    public function test_settings_travel_through_sync_with_the_same_rules_and_older_apps_keep_working(): void
    {
        $program = $this->program();
        $uuid = (string) DB::table('budget_programs')->where('id', $program)->value('uuid');
        $chair = $this->teammate('0772700021');
        $treasurer = $this->teammate('0772700022');
        $this->seat($program, $chair['user_id'], 'chair');
        $this->seat($program, $treasurer['user_id'], 'treasurer');

        // The chair (no manage_budget) pushes settings only: applied.
        $settings = json_encode(['v' => 1, 'messages' => ['thanks' => 'Webale {name}'], 'presets' => [20000]]);
        $r = $this->push($chair, [$this->op('budget_programs', 'update', ['groups' => '["Church","Friends"]', 'venue' => 'Namirembe', 'event_type' => 'wedding', 'settings' => $settings], $uuid, ['groups', 'venue', 'event_type', 'settings'])]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        // …but not the event's name, nor payment details mixed in by someone who may not.
        $r = $this->push($chair, [$this->op('budget_programs', 'update', ['name' => 'Renamed', 'venue' => 'X'], $uuid, ['name', 'venue'])]);
        $this->assertSame('manage_budget', $r['ops'][0]['permission'] ?? null, json_encode($r));
        $r = $this->push($treasurer, [$this->op('budget_programs', 'update', ['venue' => 'Elsewhere'], $uuid, ['venue'])]);
        $this->assertSame('rejected', $r['status']);
        $r = $this->push($treasurer, [$this->op('budget_programs', 'update', ['payment_details' => '{"airtel":"0701 234 567","airtel_name":"Doreen","junk":1}'], $uuid, ['payment_details'])]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        // A new event from a phone still needs manage_budget, whatever it carries.
        $r = $this->push($chair, [$this->op('budget_programs', 'insert', ['venue' => 'X'], (string) Str::uuid())]);
        $this->assertSame('rejected', $r['status']);

        $row = DB::table('budget_programs')->where('id', $program)->first();
        $this->assertSame('["Church","Friends"]', $row->groups);
        $this->assertSame('Namirembe', $row->venue);
        $this->assertSame(['airtel' => '+256701234567', 'airtel_name' => 'Doreen'], json_decode((string) $row->payment_details, true), 'cleaned: E.164, unknown keys dropped');

        // An older app (no new fields) edits the name: the settings stay.
        $r = $this->push(['h' => $this->oh], [$this->op('budget_programs', 'update', ['name' => 'Brian & Grace', 'title' => 'Brian & Grace', 'deadline' => '2027-01-02', 'status' => 'Active'], $uuid, ['name', 'title', 'deadline'])]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $row = DB::table('budget_programs')->where('id', $program)->first();
        $this->assertSame('Brian & Grace', $row->name);
        $this->assertSame('Namirembe', $row->venue);
        $this->assertSame([20000], json_decode((string) $row->settings, true)['presets']);

        // Odd values are cleaned, never refused.
        $r = $this->push(['h' => $this->oh], [$this->op('budget_programs', 'update', ['settings' => '{not json', 'groups' => "Family\nFriends"], $uuid, ['settings', 'groups'])]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $row = DB::table('budget_programs')->where('id', $program)->first();
        $this->assertNull($row->settings);
        $this->assertSame('["Family","Friends"]', $row->groups);

        // Phones get the columns as strings.
        $device = (string) Str::uuid();
        $this->postJson('/api/v1/devices/register', ['device_id' => $device], $this->oh)->assertOk();
        $rows = $this->getJson('/api/v1/sync/pull?table=budget_programs&since_seq=0', $this->oh + ['X-Device-Id' => $device])->assertOk()->json('data.rows');
        $pulled = collect($rows)->firstWhere('uuid', $uuid);
        $this->assertSame('["Family","Friends"]', $pulled['groups']);
        $this->assertSame('wedding', $pulled['event_type']);
        $this->assertIsString($pulled['payment_details']);
        $this->assertArrayHasKey('template_key', $pulled);
    }
}
