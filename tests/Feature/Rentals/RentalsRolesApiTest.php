<?php

namespace Tests\Feature\Rentals;

use App\Models\RentalExpense;
use App\Services\Rentals\Landlords\Handover;
use App\Services\Rentals\Maintenance;
use App\Services\Team\TeamService;
use Illuminate\Support\Facades\DB;

/** Each role gets on the phone what it gets on the web: who may read what, a person's sign-in, a repair's work, a waiting handover. All of it sample data. */
class RentalsRolesApiTest extends RentalsPhoneApiCase
{
    public function test_a_property_manager_reads_the_team_and_finishes_the_setup(): void
    {
        $w = $this->world();
        $manager = $this->rentalsStaff($w['company'], 'rentals_manager');
        $h = $this->h($manager);

        $this->getJson('/api/v1/team', $h)->assertStatus(403); // managing the team stays the owner's
        $team = $this->getJson('/api/v1/rentals/team', $h)->assertOk()->json('data');
        $care = collect($team)->firstWhere('id', $w['caretaker']->id);
        $this->assertSame('Caretaker', $care['role_label']);
        $this->assertSame('rentals_caretaker', $care['role']);
        $this->assertTrue($care['active']);
        $this->assertFalse($care['is_owner']);
        $this->assertTrue(collect($team)->firstWhere('id', $w['owner']->id)['is_owner']);
        $this->assertSame([], array_values(array_diff(array_keys($care), ['id', 'name', 'role', 'role_label', 'active', 'is_owner'])), 'a name and a role, no email or phone');
        $this->fixture('team_rentals', $team);

        $this->putJson('/api/v1/rentals/properties/'.$w['q']->id.'/staff', ['user_ids' => [$w['caretaker']->id]], $h)->assertOk();

        // The very body the phone sends at Finish (roles_test.dart).
        $this->postJson('/api/v1/rentals/setup', ['mode' => 'agency', 'currency' => 'UGX', 'grace_days' => 7, 'bill_days_before' => 5, 'deposit_months' => 1, 'commission_type' => 'percent', 'commission_bp' => 1000], $h)->assertOk()->assertJsonPath('data.grace_days', 7);
        $this->postJson('/api/v1/rentals/setup', ['grace_days' => 7], $this->h($w['accountant']))->assertStatus(403)->assertJsonPath('errors.permission', 'rentals_settings');
    }

    public function test_someone_deactivated_is_marked_and_who_may_read_the_team(): void
    {
        $w = $this->world();
        app(TeamService::class)->setActive($w['company'], $w['caretaker'], false);
        $team = $this->getJson('/api/v1/rentals/team', $this->h($w['owner']))->assertOk()->json('data');
        $this->assertNull(collect($team)->firstWhere('id', $w['caretaker']->id), 'someone deactivated is not offered');
        $this->assertTrue(collect($team)->firstWhere('id', $w['accountant']->id)['active']);

        $other = $this->rentalsStaff($w['company'], 'rentals_caretaker');
        $this->getJson('/api/v1/rentals/team', $this->h($other))->assertOk(); // the web lets them assign a repair
        $this->getJson('/api/v1/rentals/team', $this->h($w['accountant']))->assertStatus(403);
        $this->getJson('/api/v1/rentals/team', $this->h($w['viewer']))->assertStatus(403);
        $this->assertNull(collect($this->getJson('/api/v1/rentals/team', $this->stranger())->assertOk()->json('data'))->firstWhere('id', $w['accountant']->id), 'another account has its own team');
    }

    public function test_a_landlords_money_is_for_those_who_may_open_a_landlord(): void
    {
        $w = $this->world();
        DB::table('rental_landlords')->where('id', $w['margaret']->id)->update(['commission_type' => 'percent', 'commission_bp' => 1500]);
        $id = $w['margaret']->id;

        foreach (['owner', 'accountant'] as $who) {
            $l = $this->getJson("/api/v1/rentals/landlords/{$id}", $this->h($w[$who]))->assertOk()->json('data');
            $this->assertIsInt($l['balance_minor'], $who);
            $this->assertSame(1500, $l['commission']['bp'], $who);
            $this->assertSame('bank', $l['payout_method'], $who);
        }
        foreach (['caretaker', 'viewer'] as $who) {
            $h = $this->h($w[$who]);
            $l = $this->getJson("/api/v1/rentals/landlords/{$id}", $h)->assertOk()->json('data');
            foreach (['balance_minor', 'commission', 'payout_method', 'payout_details', 'expense_limit_minor', 'notes'] as $key) {
                $this->assertNull($l[$key], "{$who}: {$key}");
            }
            $this->assertSame('Margaret Fictional', $l['name']);
            $row = collect($this->getJson('/api/v1/rentals/landlords', $h)->assertOk()->json('data'))->firstWhere('id', $id);
            $this->assertNull($row['balance_minor'], $who);
            $this->assertNull($row['commission'], $who);
        }
    }

    public function test_a_tied_login_given_extra_rights_still_reads_none_of_the_whole_accounts_money(): void
    {
        $w = $this->world();
        foreach (['rentals_expenses', 'rentals_landlords', 'rentals_settings'] as $permission) {
            DB::table('company_role_permissions')->insert(['company_id' => $w['company']->id, 'role' => 'rentals_caretaker', 'permission' => $permission, 'allowed' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        \App\Services\Team\Permissions::flush();
        $h = $this->h($w['caretaker']);
        foreach (['cash', 'payouts', 'activity'] as $path) {
            $this->getJson("/api/v1/rentals/{$path}", $h)->assertStatus(403)->assertJsonPath('errors.code', 'forbidden');
            $this->getJson("/api/v1/rentals/{$path}", $this->h($w['owner']))->assertOk();
        }
    }

    public function test_a_backdated_repair_posts_its_expense_on_the_day_with_the_vendor(): void
    {
        $w = $this->world();
        $m = app(Maintenance::class)->open($w['owner'], (int) $w['company']->id, ['unit_id' => $w['units'][0]->id, 'title' => 'Door lock']);
        $body = ['status' => 'done', 'cost_minor' => 45000, 'resolution' => 'New lock', 'resolved_on' => '2026-03-20', 'charged_to' => 'landlord', 'method' => 'cash', 'paid_on' => '2026-03-20', 'vendor' => 'Musa the locksmith'];
        $this->putJson('/api/v1/rentals/maintenance/'.$m->id, $body, $this->h($w['owner']))->assertOk()->assertJsonPath('data.status', 'done');
        $e = RentalExpense::withoutGlobalScopes()->where('maintenance_id', $m->id)->firstOrFail();
        $this->assertSame('2026-03-20', $e->paid_on->toDateString());
        $this->assertSame('Musa the locksmith', $e->vendor);
    }

    public function test_a_repair_is_assigned_to_someone_on_the_team_or_outside_it_and_its_priority_changes(): void
    {
        $w = $this->world();
        $h = $this->h($w['caretaker']);
        $id = $w['urgentHere']->id;
        $m = $this->putJson("/api/v1/rentals/maintenance/{$id}", ['assigned_to_id' => $w['caretaker']->id, 'assignee_name' => null, 'priority' => 'low'], $h)->assertOk()->json('data');
        $this->assertSame($w['caretaker']->id, $m['assigned_to']['id']);
        $this->assertSame('low', $m['priority']);
        $this->assertNull($m['assignee_name']);
        $m = $this->putJson("/api/v1/rentals/maintenance/{$id}", ['assigned_to_id' => null, 'assignee_name' => 'Musa the plumber', 'priority' => 'normal'], $h)->assertOk()->json('data');
        $this->assertNull($m['assigned_to']);
        $this->assertSame('Musa the plumber', $m['assignee_name']);
    }

    public function test_a_caretaker_is_refused_a_repair_cost_and_may_close_one_without(): void
    {
        $w = $this->world();
        $h = $this->h($w['caretaker']);
        $id = $w['urgentHere']->id;
        $this->putJson("/api/v1/rentals/maintenance/{$id}", ['status' => 'done', 'cost_minor' => 1000, 'resolved_on' => '2026-03-26'], $h)->assertStatus(403);
        $this->putJson("/api/v1/rentals/maintenance/{$id}", ['status' => 'done', 'cost_minor' => 0, 'resolved_on' => '2026-03-26'], $h)->assertOk()->assertJsonPath('data.status', 'done');
    }

    public function test_a_form_refused_by_the_directory_names_the_field(): void
    {
        $w = $this->world();
        $res = $this->postJson('/api/v1/rentals/landlords', ['name' => str_repeat('x', 300)], $this->h($w['owner']))->assertStatus(422);
        $res->assertJsonPath('errors.code', 'invalid');
        $this->assertNotEmpty($res->json('errors.errors.name'));
    }

    public function test_staff_read_a_persons_sign_in_and_open_it_as_the_web_does(): void
    {
        $w = $this->world();
        config(['rentals.portal_url' => 'https://rent.example.test/']);
        $h = $this->h($w['owner']);
        $tid = $w['amina']->tenant_id;

        $d = $this->getJson("/api/v1/rentals/tenants/{$tid}/login", $h)->assertOk()->json('data');
        $this->assertFalse($d['has_login']);
        $this->assertNull($d['login']);
        $this->assertSame('https://rent.example.test/my', $d['sign_in_url']);
        $this->assertSame('0772100001', $d['person']['phone']);
        $this->assertIsBool($d['portal_on_plan']);
        $this->assertIsBool($d['codes_on']);
        $this->fixture('login_details', $d);

        $phone = $h + ['X-App-Version' => '3.3.0'];
        // Mail is off in tests, so a code can't reach them: the phone hears what the web says.
        config(['messaging.mail.driver' => 'log']);
        $this->postJson("/api/v1/rentals/tenants/{$tid}/login", ['enabled' => true, 'email' => 'amina@example.com'], $phone)->assertStatus(422)
            ->assertJsonPath('errors.code', 'password_required')->assertJsonPath('errors.errors.password.0', 'Email is switched off on this service, so no code can reach them. Set a first password and give it to them yourself.');
        // With mail on and an email, no password is needed.
        config(['messaging.mail.driver' => 'mail', 'mail.default' => 'array']);
        $this->postJson("/api/v1/rentals/tenants/{$tid}/login", ['enabled' => true, 'email' => 'amina@example.com'], $phone)->assertOk()->assertJsonPath('data.login.has_password', false);
        config(['messaging.mail.driver' => 'log']);
        // A first password opens it, then the details say so; later changes need none again.
        $this->postJson("/api/v1/rentals/tenants/{$tid}/login", ['enabled' => true, 'phone' => '0772100001', 'password' => self::PASS], $phone)->assertOk()->assertJsonPath('data.has_login', true)->assertJsonPath('data.login.has_password', true);
        $this->postJson("/api/v1/rentals/tenants/{$tid}/login", ['enabled' => true, 'phone' => '0772100001'], $phone)->assertOk();
        $this->getJson("/api/v1/rentals/tenants/{$tid}/login", $h)->assertOk()->assertJsonPath('data.has_login', true)->assertJsonPath('data.login.phone', '+256772100001');
        // Closed: seen as closed.
        $this->postJson("/api/v1/rentals/tenants/{$tid}/login", ['enabled' => false], $phone)->assertOk()->assertJsonPath('data.has_login', false);
        $this->getJson("/api/v1/rentals/tenants/{$tid}/login", $h)->assertOk()->assertJsonPath('data.login.enabled', false);

        // A landlord, the same.
        $lid = $w['peter']->id;
        $this->getJson("/api/v1/rentals/landlords/{$lid}/login", $h)->assertOk()->assertJsonPath('data.person.phone', '0700000202');
        $this->postJson("/api/v1/rentals/landlords/{$lid}/login", ['enabled' => true], $phone)->assertStatus(422)->assertJsonPath('errors.code', 'password_required')
            ->assertJsonPath('errors.errors.password.0', 'With no email, set a password: sign-in codes go by email only.');
        $this->postJson("/api/v1/rentals/landlords/{$lid}/login", ['enabled' => true, 'password' => self::PASS], $phone)->assertOk()->assertJsonPath('data.has_login', true);

        // Who may not.
        foreach (['accountant', 'caretaker', 'viewer'] as $who) {
            $this->getJson("/api/v1/rentals/tenants/{$tid}/login", $this->h($w[$who]))->assertStatus(403)->assertJsonPath('errors.permission', 'rentals_settings');
            $this->getJson("/api/v1/rentals/landlords/{$lid}/login", $this->h($w[$who]))->assertStatus(403);
        }
        $this->getJson("/api/v1/rentals/tenants/{$tid}/login", $this->h($this->rentalsStaff($w['company'], 'rentals_manager')))->assertOk();
        $this->getJson("/api/v1/rentals/tenants/{$tid}/login", $this->stranger())->assertStatus(404);
    }

    public function test_a_waiting_handover_is_seen_on_the_property_and_cancelled(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $id = $w['p']->id;
        $this->getJson("/api/v1/rentals/properties/{$id}", $h)->assertOk()->assertJsonPath('data.waiting_handover', null);
        $this->postJson("/api/v1/rentals/properties/{$id}/handover/cancel", [], $h)->assertStatus(422)->assertJsonPath('errors.code', 'nothing_waiting');

        $this->postJson("/api/v1/rentals/properties/{$id}/handover", ['landlord_id' => $w['peter']->id, 'from' => '2026-04-20', 'reason' => 'Sold to Peter'], $h)->assertOk();
        $this->getJson("/api/v1/rentals/properties/{$id}", $h)->assertOk()->assertJsonPath('data.waiting_handover.from', '2026-04-20')->assertJsonPath('data.waiting_handover.landlord.name', 'Peter Sample');
        // A second one is refused until this one is cancelled.
        $this->postJson("/api/v1/rentals/properties/{$id}/handover", ['landlord_id' => $w['peter']->id, 'from' => '2026-04-25', 'reason' => 'Again'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'handover_waiting');
        // Staff without rentals_manage are refused.
        $this->postJson("/api/v1/rentals/properties/{$id}/handover/cancel", [], $this->h($w['caretaker']))->assertStatus(403);
        $this->postJson("/api/v1/rentals/properties/{$id}/handover/cancel", [], $this->h($w['accountant']))->assertStatus(403);
        $this->postJson('/api/v1/rentals/properties/'.$id.'/handover/cancel', [], $this->stranger())->assertStatus(404);

        $this->postJson("/api/v1/rentals/properties/{$id}/handover/cancel", [], $h)->assertOk()->assertJsonPath('data.waiting_handover', null)->assertJsonPath('message', 'The handover is cancelled. Nothing moved.');
        $this->assertNull(Handover::scheduled((int) $w['company']->id, (int) $id));
        $this->assertSame((int) $w['margaret']->id, (int) DB::table('rental_properties')->where('id', $id)->value('landlord_id'), 'nothing moved');
        $this->postJson("/api/v1/rentals/properties/{$id}/handover", ['landlord_id' => $w['peter']->id, 'from' => '2026-04-25', 'reason' => 'Again'], $h)->assertOk();
    }

    public function test_doors_come_shortest_first_so_a2_is_before_a10_on_every_page(): void
    {
        $w = $this->world();
        app(\App\Services\Rentals\Directory\Units::class)->createMany($w['owner'], (int) $w['p']->id, 'A', 10, 11, ['rent_minor' => 100000, 'kind' => 'apartment']);
        $codes = array_column($this->getJson('/api/v1/rentals/units?property_id='.$w['p']->id.'&per_page=3', $this->h($w['owner']))->assertOk()->json('data'), 'code');
        $this->assertSame(['A1', 'A2', 'A3'], $codes);
    }

    public function test_a_vacant_door_of_an_archived_property_is_not_offered(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        [$p, $units] = $this->building($w['owner'], 1, 100000, ['name' => 'Old Block', 'landlord_id' => $w['peter']->id]);
        $this->assertContains($units[0]->id, array_column($this->getJson('/api/v1/rentals/units?state=vacant', $h)->assertOk()->json('data'), 'id'));
        $this->putJson('/api/v1/rentals/properties/'.$p->id, ['status' => 'archived'], $h)->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertNotContains($units[0]->id, array_column($this->getJson('/api/v1/rentals/units?state=vacant', $h)->assertOk()->json('data'), 'id'));
        $this->getJson('/api/v1/rentals/units/'.$units[0]->id, $h)->assertOk(); // its records stay
    }

    public function test_a_meter_reading_keeps_its_photo(): void
    {
        $w = $this->world();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $photo = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/rentals/meter-readings', ['uuid' => $uuid, 'unit_id' => $w['units'][0]->id, 'utility' => 'water', 'reading' => '120.5', 'read_on' => '2026-03-26', 'photo_uuid' => $photo], $this->h($w['owner']))->assertCreated();
        $this->assertSame($photo, DB::table('rental_meter_readings')->where('uuid', $uuid)->value('photo_uuid'));
        $seen = collect($this->getJson('/api/v1/rentals/meter-readings?unit_id='.$w['units'][0]->id, $this->h($w['owner']))->assertOk()->json('data'))->firstWhere('uuid', $uuid);
        $this->assertArrayHasKey('photo_url', $seen);
    }
}
