<?php

namespace Tests\Feature\Rentals;

use App\Models\RentalFollowUp;
use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Collections\FollowUps;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\Figures;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Today\RentBook;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the phone reads in the field: the offline rent book (scope, no ID numbers, ETag, queries that do not grow), Today's work (the web's rules, as data), live search (the phone rule, scope),
 * and what it writes: follow-ups replayed by uuid, Bill ahead's preview, Paid to the landlord.
 */
class RentalsFieldApiTest extends RentalsPhoneApiCase
{
    private const POSITION = ['owed_minor', 'overdue_minor', 'overdue_days', 'credit_minor', 'deposit_held_minor', 'paid_up_to', 'next_due', 'state'];

    public function test_the_rent_book_holds_every_door_and_every_id_resolves_inside_it(): void
    {
        $w = $this->world();
        $res = $this->getJson('/api/v1/rentals/rent-book', $this->h($w['owner']))->assertOk();
        $book = $res->json('data');
        $this->assertKeys($book, ['generated_at', 'today', 'currency', 'decimals', 'grace_days', 'mode', 'properties', 'units', 'leases', 'tenants', 'landlords'], 'book');
        $this->assertSame('2026-03-26', $book['today']);
        $this->assertSame('agency', $book['mode']);
        $this->assertCount(2, $book['properties']);
        $this->assertCount(5, $book['units']);
        $this->assertCount(4, $book['leases']);
        $this->assertCount(4, $book['tenants']);
        $this->assertCount(2, $book['landlords']);
        $this->assertKeys($book['properties'][0], ['id', 'code', 'name', 'area', 'landlord_id', 'status', 'units_count', 'occupied_count'], 'property');
        $this->assertKeys($book['units'][0], ['id', 'property_id', 'code', 'floor', 'block', 'kind', 'rent_minor', 'deposit_minor', 'status', 'state', 'lease_id'], 'unit');
        $this->assertKeys($book['leases'][0], ['id', 'number', 'unit_id', 'property_id', 'tenant_id', 'rent_minor', 'cycle_months', 'status', 'start_date', 'end_date', 'notice_on', 'move_out_on', 'position', 'last_paid_on', 'last_follow_up'], 'lease');
        $this->assertKeys($book['leases'][0]['position'], self::POSITION, 'position');
        $this->assertKeys($book['tenants'][0], ['id', 'code', 'name', 'phone', 'phone_e164', 'alt_phone'], 'tenant');
        $this->assertKeys($book['landlords'][0], ['id', 'code', 'name', 'phone', 'phone_e164'], 'landlord');

        // Every id resolves inside the book.
        $ids = fn (string $k) => array_column($book[$k], 'id');
        foreach ($book['units'] as $u) {
            $this->assertContains($u['property_id'], $ids('properties'));
            if ($u['lease_id'] !== null) {
                $this->assertContains($u['lease_id'], $ids('leases'));
            }
        }
        foreach ($book['leases'] as $l) {
            $this->assertContains($l['unit_id'], $ids('units'));
            $this->assertContains($l['tenant_id'], $ids('tenants'));
        }
        foreach ($book['properties'] as $p) {
            $this->assertContains($p['landlord_id'], $ids('landlords'));
        }

        // The balances are the services', and the door's state follows them.
        $lease = collect($book['leases'])->firstWhere('id', (int) $w['brian']->id);
        $this->assertSame(Leases::position($w['brian']->fresh()), $lease['position']);
        $this->assertSame(600000, $lease['position']['owed_minor']);
        $this->assertSame('overdue', collect($book['units'])->firstWhere('id', (int) $w['brian']->unit_id)['state']);
        $this->assertSame('vacant', collect($book['units'])->firstWhere('id', (int) $w['units'][2]->id)['state']);
        $this->assertSame('2026-03-01', collect($book['leases'])->firstWhere('id', (int) $w['amina']->id)['last_paid_on']);
        $kato = collect($book['properties'])->firstWhere('id', (int) $w['p']->id);
        $this->assertSame([3, 2], [$kato['units_count'], $kato['occupied_count']]);

        // Nothing between the staff and the landlord, and no ID number, ever.
        $text = (string) $res->getContent();
        foreach (['CF90SECRET789Z', 'id_number', 'Staff note', 'commission', 'balance_minor', 'payout_details'] as $never) {
            $this->assertStringNotContainsString($never, $text, $never);
        }
        $this->fixture('rent_book', $res->json());
    }

    public function test_the_rent_book_answers_304_when_nothing_changed_and_a_new_etag_when_something_did(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $first = $this->getJson('/api/v1/rentals/rent-book', $h)->assertOk();
        $etag = (string) $first->headers->get('ETag');
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{40}"$/', $etag);

        Carbon::setTestNow('2026-03-26 11:00:00'); // later the same day: the moment it was read is no change
        $same = $this->getJson('/api/v1/rentals/rent-book', $h + ['If-None-Match' => $etag])->assertStatus(304);
        $this->assertSame('', (string) $same->getContent());
        $this->assertSame($etag, $same->headers->get('ETag'));
        // A compressing web server marks the ETag it passes on: still the same book.
        $this->getJson('/api/v1/rentals/rent-book', $h + ['If-None-Match' => 'W/'.substr($etag, 0, -1).'-gzip"'])->assertStatus(304);
        $this->getJson('/api/v1/rentals/rent-book', $h + ['If-None-Match' => '"nothing"'])->assertOk();

        app(Payments::class)->receive($w['owner'], $w['brian'], ['amount_minor' => 1000, 'method' => 'cash']);
        $changed = $this->getJson('/api/v1/rentals/rent-book', $h + ['If-None-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $changed->headers->get('ETag'));

        Carbon::setTestNow('2026-03-27 09:00:00'); // overdue depends on the day
        $next = (string) $changed->headers->get('ETag');
        $this->assertNotSame($next, $this->getJson('/api/v1/rentals/rent-book', $h + ['If-None-Match' => $next])->assertOk()->headers->get('ETag'));

        $this->assertTrue(RentBook::matches('"a", "b"', '"b"'));
        $this->assertFalse(RentBook::matches(null, '"b"'));
    }

    public function test_a_caretakers_rent_book_holds_only_their_properties(): void
    {
        $w = $this->world();
        $res = $this->getJson('/api/v1/rentals/rent-book', $this->h($w['caretaker']))->assertOk();
        $book = $res->json('data');
        $this->assertSame([(int) $w['p']->id], array_column($book['properties'], 'id'));
        $this->assertEqualsCanonicalizing([(int) $w['amina']->id, (int) $w['brian']->id], array_column($book['leases'], 'id'));
        $this->assertEqualsCanonicalizing([(int) $w['amina']->tenant_id, (int) $w['brian']->tenant_id], array_column($book['tenants'], 'id'));
        $this->assertEqualsCanonicalizing(array_map(fn ($u) => (int) $u->id, $w['units']), array_column($book['units'], 'id'));
        $this->assertSame([(int) $w['margaret']->id], array_column($book['landlords'], 'id'));
        foreach (['Lake View', 'Carol', 'Peter Sample', 'Dan Invented', 'balance_minor', 'owed_to_landlords'] as $never) {
            $this->assertStringNotContainsString($never, (string) $res->getContent(), $never);
        }
    }

    public function test_a_moved_out_tenant_who_still_owes_stays_in_the_book_and_one_who_does_not_leaves(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $owing = RentalLease::findOrFail($w['carol']->id);
        $owing->forceFill(['status' => 'ended', 'ended_on' => '2026-03-20'])->save();
        DB::table('rental_units')->where('id', $owing->unit_id)->update(['status' => 'available']);
        $clear = RentalLease::findOrFail($w['amina']->id);
        $clear->forceFill(['status' => 'ended', 'ended_on' => '2026-03-20'])->save();

        $book = $this->getJson('/api/v1/rentals/rent-book', $h)->assertOk()->json('data');
        $this->assertContains((int) $owing->id, array_column($book['leases'], 'id'));
        $this->assertNotContains((int) $clear->id, array_column($book['leases'], 'id'));
        $unit = collect($book['units'])->firstWhere('id', (int) $owing->unit_id);
        $this->assertNull($unit['lease_id'], 'an ended lease does not hold its door');
        $this->assertSame('vacant', $unit['state']);
    }

    public function test_an_owner_run_accounts_book_has_no_landlords(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [, $owner] = $this->rentalsAccount('owner');
        [, $units] = $this->building($owner, 1, 100000);
        $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        $book = $this->getJson('/api/v1/rentals/rent-book', $this->h($owner))->assertOk()->json('data');
        $this->assertSame('owner', $book['mode']);
        $this->assertSame([], $book['landlords']);
        $this->assertNull($book['properties'][0]['landlord_id']);
    }

    public function test_the_rent_books_reads_do_not_grow_with_the_doors(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $count = function () use ($h) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/rentals/rent-book', $h)->assertOk();
            $n = count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'rental_'))); // the book's own: who the person is may be read once already
            DB::disableQueryLog();

            return $n;
        };
        $small = $count();
        foreach (app(Units::class)->createMany($w['owner'], (int) $w['q']->id, 'B', 1, 12, ['rent_minor' => 150000, 'kind' => 'apartment']) as $i => $unit) {
            $lease = $this->tenantIn($w['owner'], $unit, '2026-03-20', ['deposit_minor' => 0, 'tenant' => ['name' => 'Sample Person '.$i, 'phone' => '07721002'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]]);
            app(FollowUps::class)->log($w['owner'], $lease, 'call', 'Called');
            if ($i % 2 === 0) {
                app(Payments::class)->receive($w['owner'], $lease, ['amount_minor' => 50000, 'method' => 'cash']);
            }
        }
        $big = $count();
        $this->assertSame($small, $big, 'twelve more doors cost no more reads');
        $this->assertLessThan(25, $big);
    }

    public function test_the_rent_book_refuses_a_person_without_the_permission_or_a_token(): void
    {
        $w = $this->world();
        $this->getJson('/api/v1/rentals/rent-book')->assertStatus(401);
        [$tenant] = $this->person($w['owner'], 'tenant', (int) $w['amina']->tenant_id);
        $this->getJson('/api/v1/rentals/rent-book', $tenant)->assertStatus(401);
        $book = $this->getJson('/api/v1/rentals/rent-book', $this->stranger())->assertOk()->json('data');
        $this->assertSame([], $book['leases'], 'another account has its own empty book');
        $this->assertSame([], $book['properties']);
    }

    public function test_today_is_the_webs_today_as_data(): void
    {
        $w = $this->world();
        app(FollowUps::class)->log($w['owner'], $w['brian'], 'call', 'Said month end', '2026-03-26');
        $w['amina']->forceFill(['end_date' => '2026-04-15'])->save();
        $today = $this->getJson('/api/v1/rentals/today', $this->h($w['owner']))->assertOk();
        $d = $today->json('data');
        $this->assertKeys($d, ['set_up', 'first_steps', 'groups', 'figures', 'board', 'money_position', 'my_cash', 'paid_reports_waiting', 'month_end_next'], 'today');
        $this->assertTrue($d['set_up']);
        $this->assertNull($d['first_steps']);
        $this->assertSame(['chase', 'take_in', 'renew', 'fix', 'fill', 'month_end'], array_values(array_intersect(['chase', 'take_in', 'renew', 'fix', 'fill', 'month_end'], array_column($d['groups'], 'key'))));
        $group = fn (string $k) => collect($d['groups'])->firstWhere('key', $k);

        // Chase: the promise first, then the biggest overdue, with the next step.
        $chase = $group('chase');
        $this->assertSame('Chase', $chase['title']);
        $this->assertSame(2, $chase['total']);
        $first = $chase['lines'][0];
        $this->assertKeys($first, ['kind', 'title', 'detail', 'amount_minor', 'overdue', 'promise_on', 'tenant_id', 'next_step', 'done', 'link'], 'line');
        $this->assertSame('promise_today', $first['kind']);
        $this->assertSame('2026-03-26', $first['promise_on']);
        $this->assertSame((int) $w['brian']->tenant_id, $first['tenant_id']);
        $this->assertSame(['type' => 'lease', 'id' => (int) $w['brian']->id], $first['link']);
        $this->assertTrue($first['overdue']);
        $this->assertStringContainsString('Promised for today', $first['detail']);
        $this->assertSame('overdue', $chase['lines'][1]['kind']);
        $this->assertContains($chase['lines'][1]['next_step'], ['remind', 'call', 'visit', 'final']);

        // Take in: a bill due today.
        $this->assertSame('due_today', $group('take_in')['lines'][0]['kind']);
        $this->assertSame((int) $w['dan']->id, $group('take_in')['lines'][0]['link']['id']);

        // Renew: a term ending within 30 days.
        $this->assertSame('term_ending', $group('renew')['lines'][0]['kind']);
        $this->assertStringContainsString('Term ends 15 Apr. Renew at', $group('renew')['lines'][0]['detail']);

        // Fix: urgent repairs, by id, with a link to the repair.
        $this->assertSame(2, $group('fix')['total']);
        $this->assertSame(['type' => 'maintenance', 'id' => (int) $w['urgentHere']->id], $group('fix')['lines'][0]['link']);

        // Fill: one line, whatever the number.
        $this->assertCount(1, $group('fill')['lines']);
        $this->assertSame('vacant', $group('fill')['lines'][0]['kind']);
        $this->assertSame(['type' => 'units', 'id' => null, 'params' => ['state' => 'vacant']], $group('fill')['lines'][0]['link']);
        $this->assertSame(300000, $group('fill')['lines'][0]['amount_minor']);
        $this->assertStringContainsString('1 vacant, asking', $group('fill')['lines'][0]['title']);

        // From the 25th: the month's checklist.
        $kinds = array_column($group('month_end')['lines'], 'kind');
        $this->assertSame(['arrears_chased', 'bills_raised', 'expenses_in', 'landlords_paid', 'monthly_report', 'close_month'], $kinds);
        $this->assertNotContains(null, array_column($group('month_end')['lines'], 'done'));
        $this->assertSame('month_end', collect($group('month_end')['lines'])->firstWhere('kind', 'close_month')['link']['type']);
        $this->assertSame('2026-02', $d['month_end_next']);

        // The figures are the overview's; whose money and my cash beside them.
        $overview = Figures::overview($w['owner']);
        unset($overview['today']);
        $this->assertSame(array_keys($overview), array_values(array_diff(array_keys($d['figures']), ['today'])));
        foreach ($overview as $k => $v) {
            $this->assertEquals($v, $d['figures'][$k], $k);
        }
        // The web's Today, line for line: this month's bills without deposits, and what came in.
        $board = $d['board'];
        $this->assertSame(['units', 'occupied', 'vacant', 'held_back', 'occupancy_pct', 'billed_minor', 'billed_paid_minor', 'collected_minor', 'rate_pct', 'arrears', 'credit_minor', 'deposits_minor', 'deposits_landlords_minor', 'landlords_owed_minor', 'landlords_owe_minor'], array_keys($board));
        $this->assertSame([5, 4, 1, 0, 80], [$board['units'], $board['occupied'], $board['vacant'], $board['held_back'], $board['occupancy_pct']]);
        $billed = (int) DB::table('rental_charges')->where('company_id', $w['company']->id)->where('status', '!=', 'void')->whereNotIn('kind', ['deposit', 'refund'])->whereBetween('due_date', ['2026-03-01', '2026-03-31'])->sum('amount_minor');
        $this->assertGreaterThan(0, $billed);
        $this->assertSame($billed, $board['billed_minor']);
        $this->assertSame(300000, $board['billed_paid_minor']);
        $this->assertSame(300000, $board['collected_minor']);
        $this->assertSame((int) floor(300000 * 100 / $billed), $board['rate_pct']);
        $this->assertSame(540000, $board['landlords_owed_minor']);
        $this->assertSame(0, $board['landlords_owe_minor']);
        $this->assertKeys($d['money_position'], ['as_of', 'cash_minor', 'owed_to_landlords_minor', 'owed_back_by_landlords_minor', 'deposits_minor', 'credit_minor', 'agency_own_minor', 'held_for_others_minor'], 'money');
        $this->assertSame(600000, $d['money_position']['cash_minor']);
        $this->assertSame(['date', 'total_minor', 'count', 'by_method'], array_keys($d['my_cash']));
        $this->assertSame(0, $d['my_cash']['total_minor'], 'nothing taken today yet');
        $this->fixture('today', $today->json());
    }

    public function test_a_tenant_has_one_line_and_a_second_reason_joins_it(): void
    {
        $w = $this->world();
        // Give Dan a term that ends soon, on top of his bill due today.
        $w['dan']->forceFill(['end_date' => '2026-04-10'])->save();
        $d = $this->getJson('/api/v1/rentals/today', $this->h($w['owner']))->assertOk()->json('data');
        $takeIn = collect($d['groups'])->firstWhere('key', 'take_in');
        $this->assertSame('Due today · Term ends 10 Apr. Renew at '.Minor::format(200000, (int) $w['company']->id), $takeIn['lines'][0]['detail']);
        $this->assertNull(collect($d['groups'])->firstWhere('key', 'renew'), 'no row of its own');
    }

    public function test_my_cash_is_only_what_this_person_took_today(): void
    {
        $w = $this->world();
        $h = $this->h($w['caretaker']);
        app(Payments::class)->receive($w['caretaker'], $w['brian'], ['amount_minor' => 50000, 'method' => 'cash']);
        $reversed = app(Payments::class)->receive($w['caretaker'], $w['brian'], ['amount_minor' => 7000, 'method' => 'mobile_money']);
        app(Payments::class)->reverse($w['owner'], $reversed, 'Typed wrong');
        app(Payments::class)->receive($w['owner'], $w['brian'], ['amount_minor' => 11000, 'method' => 'cash']);
        $d = $this->getJson('/api/v1/rentals/today', $h)->assertOk()->json('data');
        $this->assertSame(['date' => '2026-03-26', 'total_minor' => 50000, 'count' => 1, 'by_method' => ['cash' => 50000, 'bank' => 0, 'mobile_money' => 0, 'cheque' => 0]], $d['my_cash']);
    }

    public function test_a_caretakers_today_holds_only_their_properties_and_no_landlord_figures(): void
    {
        $w = $this->world();
        $res = $this->getJson('/api/v1/rentals/today', $this->h($w['caretaker']))->assertOk();
        $d = $res->json('data');
        $this->assertSame(['chase', 'fix'], array_column($d['groups'], 'key'), 'nothing to renew, fill or close for them');
        $this->assertSame(1, collect($d['groups'])->firstWhere('key', 'chase')['total']);
        $this->assertSame(1, collect($d['groups'])->firstWhere('key', 'fix')['total']);
        $this->assertNull($d['money_position']);
        $this->assertNull($d['month_end_next']);
        $this->assertNull($d['figures']['landlords_minor']);
        $this->assertNull($d['figures']['cash']);
        $this->assertSame(3, $d['figures']['units']);
        $this->assertSame(3, $d['board']['units']);
        $this->assertNull($d['board']['landlords_owed_minor']);
        $this->assertNull($d['board']['landlords_owe_minor']);
        foreach (['Lake View', 'Carol', 'Dan Invented', 'Roof', 'owed_to_landlords'] as $never) {
            $this->assertStringNotContainsString($never, (string) $res->getContent(), $never);
        }
    }

    public function test_a_viewer_sees_the_figures_and_no_work_to_act_on(): void
    {
        $w = $this->world();
        $d = $this->getJson('/api/v1/rentals/today', $this->h($w['viewer']))->assertOk()->json('data');
        $this->assertSame(['month_end'], array_column($d['groups'], 'key'));
        $this->assertSame(0, $d['paid_reports_waiting']);
        $this->assertNull($d['money_position']);
        $this->assertSame(['arrears_chased', 'bills_raised', 'monthly_report'], array_column($d['groups'][0]['lines'], 'kind'));
    }

    public function test_in_the_first_ten_days_today_offers_last_months_report(): void
    {
        $w = $this->world();
        Carbon::setTestNow('2026-04-05 09:00:00');
        $d = $this->getJson('/api/v1/rentals/today', $this->h($w['owner']))->assertOk()->json('data');
        $lines = collect($d['groups'])->firstWhere('key', 'month_end')['lines'];
        $this->assertSame('last_month_report', $lines[0]['kind']);
        $this->assertSame('March’s report is ready.', $lines[0]['title']);
        $this->assertSame(['key' => 'monthly_building', 'month' => '2026-03'], $lines[0]['link']['params']);
    }

    public function test_a_new_account_sees_its_first_steps_and_no_groups(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [, $owner] = $this->rentalsAccount();
        $h = $this->h($owner);
        $d = $this->getJson('/api/v1/rentals/today', $h)->assertOk()->json('data');
        $this->assertSame(['add_property', 'add_units', 'move_in'], array_column($d['first_steps'], 'key'));
        $this->assertSame([false, false, false], array_column($d['first_steps'], 'done'));
        $this->assertSame([], $d['groups']);
        $this->building($owner, 1, 100000);
        $d = $this->getJson('/api/v1/rentals/today', $h)->assertOk()->json('data');
        $this->assertSame([true, true, false], array_column($d['first_steps'], 'done'));
    }

    public function test_search_reads_a_phone_every_way_and_finds_by_name_code_and_amount(): void
    {
        $w = $this->world();
        $cid = (int) $w['company']->id;
        $this->indexed($cid);
        $h = $this->h($w['owner']);
        $find = fn (string $q) => $this->getJson('/api/v1/rentals/search?q='.urlencode($q), $h)->assertOk()->json('data');
        $tenantId = (int) $w['brian']->tenant_id;
        foreach (['0772100002', '772100002', '+256772100002', '256 772 100 002', '100002'] as $typed) {
            $found = collect($find($typed)['groups'])->firstWhere('kind', 'tenant');
            $this->assertNotNull($found, $typed);
            $this->assertContains($tenantId, array_column($found['results'], 'id'), $typed);
        }
        $d = $find('Brian');
        $this->assertSame(['q', 'total', 'groups'], array_keys($d));
        $this->assertKeys($d['groups'][0], ['kind', 'label', 'total', 'results'], 'group');
        $tenant = collect($d['groups'])->firstWhere('kind', 'tenant')['results'][0];
        $this->assertSame(['kind', 'id', 'title', 'detail', 'owes', 'close', 'link'], array_keys($tenant));
        $this->assertSame(['type' => 'tenant', 'id' => $tenantId], $tenant['link']);
        $this->assertTrue($tenant['owes']);
        $this->assertStringContainsString('Kato Plaza', $tenant['detail']);
        $this->assertStringContainsString('owes', $tenant['detail']);

        // The repair's index kind has the API's name.
        $repair = collect($find('kitchen')['groups'])->firstWhere('kind', 'maintenance');
        $this->assertSame(['type' => 'maintenance', 'id' => (int) $w['urgentHere']->id], $repair['results'][0]['link']);

        // A receipt by its amount, and one kind only.
        $amount = collect($find('300k')['groups'])->firstWhere('kind', 'payment');
        $this->assertNotNull($amount);
        $this->assertSame(['tenant'], array_column($find('tenants: amina')['groups'], 'kind'));

        // An ID number, exact, for those who manage tenants only.
        $this->assertContains((int) $w['amina']->tenant_id, array_column(collect($find('CF90SECRET789Z')['groups'])->firstWhere('kind', 'tenant')['results'], 'id'));
        $this->assertSame([], $this->getJson('/api/v1/rentals/search?q=CF90SECRET789Z', $this->h($w['caretaker']))->assertOk()->json('data.groups'));

        // Too short, nothing.
        $this->assertSame([], $find('b')['groups']);
        $this->fixture('search', $this->getJson('/api/v1/rentals/search?q=amina', $h)->assertOk()->json());
    }

    public function test_a_name_that_sounds_alike_shows_marked_as_close(): void
    {
        $w = $this->world();
        $this->indexed((int) $w['company']->id);
        $d = $this->getJson('/api/v1/rentals/search?q=brayan', $this->h($w['owner']))->assertOk()->json('data');
        $tenant = collect($d['groups'])->firstWhere('kind', 'tenant');
        $this->assertNotNull($tenant);
        $this->assertTrue($tenant['results'][0]['close']);
    }

    public function test_search_keeps_to_a_caretakers_properties_and_each_kinds_permission(): void
    {
        $w = $this->world();
        $this->indexed((int) $w['company']->id);
        $h = $this->h($w['caretaker']);
        $find = fn (string $q) => $this->getJson('/api/v1/rentals/search?q='.urlencode($q), $h)->assertOk();
        $this->assertSame([], $find('Carol')->json('data.groups'), 'a tenant at the other building');
        $this->assertSame([], $find('0772100003')->json('data.groups'));
        $this->assertSame([], $find('Lake')->json('data.groups'));
        $this->assertNull(collect($find('Margaret')->json('data.groups'))->firstWhere('kind', 'landlord'), 'a landlord is the office\'s');
        $this->assertNotNull(collect($find('Brian')->json('data.groups'))->firstWhere('kind', 'tenant'));
        $this->assertSame([], $this->getJson('/api/v1/rentals/search?q=Brian', $this->stranger())->assertOk()->json('data.groups'), 'another account');
    }

    public function test_a_follow_up_can_be_a_visit_and_its_uuid_makes_a_resend_the_same_one(): void
    {
        $w = $this->world();
        $h = $this->h($w['caretaker']);
        $uuid = (string) Str::uuid();
        $body = ['lease_id' => $w['brian']->id, 'channel' => 'visit', 'note' => 'Not home', 'promise_on' => '2026-03-28', 'uuid' => $uuid];
        $first = $this->postJson('/api/v1/rentals/follow-ups', $body, $h)->assertStatus(201);
        $this->assertSame('visit', $first->json('data.channel'));
        $this->assertSame($uuid, $first->json('data.uuid'));
        $again = $this->postJson('/api/v1/rentals/follow-ups', $body, $h)->assertStatus(200);
        $this->assertSame('Already saved.', $again->json('message'));
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, RentalFollowUp::withoutGlobalScopes()->where('uuid', $uuid)->count());

        // Sent again days later: still the same one, though its promise is in the past by then.
        Carbon::setTestNow('2026-04-02 09:00:00');
        $this->postJson('/api/v1/rentals/follow-ups', $body, $h)->assertStatus(200);
        $this->assertSame(1, RentalFollowUp::withoutGlobalScopes()->where('uuid', $uuid)->count());
        // A new follow-up whose promise has passed comes back for a person.
        $this->postJson('/api/v1/rentals/follow-ups', ['uuid' => (string) Str::uuid()] + $body, $h)->assertStatus(422)->assertJsonPath('errors.code', 'date_invalid');
        Carbon::setTestNow('2026-03-26 09:00:00');

        // The same id for another lease: refused. A bad one: refused. None: as it always was.
        $this->postJson('/api/v1/rentals/follow-ups', ['lease_id' => $w['amina']->id] + $body, $h)->assertStatus(409)->assertJsonPath('errors.code', 'idempotency_conflict');
        $this->postJson('/api/v1/rentals/follow-ups', ['uuid' => 'nope'] + $body, $h)->assertStatus(422)->assertJsonPath('errors.code', 'uuid_invalid');
        $plain = $this->postJson('/api/v1/rentals/follow-ups', ['lease_id' => $w['brian']->id, 'channel' => 'call'], $h)->assertStatus(201);
        $this->assertNull($plain->json('data.uuid'));

        // Another account can't reach this lease, whatever the uuid.
        $this->postJson('/api/v1/rentals/follow-ups', $body, $this->stranger())->assertStatus(404);
        // Another building is not the caretaker's.
        $this->postJson('/api/v1/rentals/follow-ups', ['lease_id' => $w['carol']->id, 'channel' => 'call', 'uuid' => (string) Str::uuid()], $h)->assertStatus(404);
        // Reading it back in the book.
        $book = $this->getJson('/api/v1/rentals/rent-book', $h)->assertOk()->json('data');
        $this->assertSame('call', collect($book['leases'])->firstWhere('id', (int) $w['brian']->id)['last_follow_up']['channel']);
    }

    public function test_the_payment_preview_says_what_bill_ahead_and_paid_to_the_landlord_would_do(): void
    {
        $w = $this->world();
        $url = '/api/v1/rentals/leases/'.$w['brian']->id.'/payment-preview?amount_minor=';
        $d = $this->getJson($url.'1200000', $this->h($w['owner']))->assertOk()->json('data');
        $this->assertKeys($d, ['lines', 'credit_minor', 'currency', 'ahead', 'direct'], 'preview');
        $this->assertSame(600000, $d['ahead']['open_minor']);
        $this->assertSame(600000, $d['ahead']['over_minor']);
        $this->assertSame(2, $d['ahead']['max_periods'], 'what is left over covers two more months');
        $this->assertKeys($d['ahead']['periods'][0], ['start', 'end', 'label', 'amount_minor', 'commission_minor', 'landlord_minor', 'agency_minor'], 'period');
        $this->assertSame(600000, $d['direct']['open_minor']);
        $this->assertFalse($d['direct']['ok'], 'more than is open can\'t be paid that way');
        $small = $this->getJson($url.'300000', $this->h($w['owner']))->assertOk()->json('data');
        $this->assertTrue($small['direct']['ok']);
        $this->assertSame(30000, $small['direct']['commission_minor'], 'what the agency earns on it');

        // A caretaker can't bill and doesn't read how the money is shared.
        $care = $this->getJson($url.'300000', $this->h($w['caretaker']))->assertOk()->json('data');
        $this->assertNull($care['ahead']);
        $this->assertSame(['open_minor', 'amount_minor', 'ok'], array_keys($care['direct']));
        // Somebody who bills, who reads no landlords' money, sees no commission.
        $biller = $this->rentalsStaff($w['company'], 'rentals_manager');
        DB::table('admin_users')->where('id', $biller->id)->update(['updated_at' => now()]);
        $this->assertArrayHasKey('commission_minor', $this->getJson($url.'1200000', $this->h($biller))->assertOk()->json('data.ahead.periods.0'));

        $this->getJson('/api/v1/rentals/leases/'.$w['carol']->id.'/payment-preview?amount_minor=100', $this->h($w['caretaker']))->assertStatus(404);
    }

    public function test_paid_to_the_landlord_is_a_payment_method_with_its_own_rules(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $uuid = (string) Str::uuid();
        $body = ['uuid' => $uuid, 'lease_id' => $w['brian']->id, 'amount_minor' => 300000, 'method' => 'paid_landlord', 'reference' => 'Told by the landlord'];
        $cashBefore = CashBook::balances((int) $w['company']->id)['total'];
        $first = $this->postJson('/api/v1/rentals/payments', $body, $h)->assertStatus(201);
        $this->assertSame('paid_landlord', $first->json('data.method'));
        $this->assertSame($cashBefore, CashBook::balances((int) $w['company']->id)['total'], 'no money came in');
        $this->assertSame(300000, Leases::position($w['brian']->fresh())['owed_minor']);
        $again = $this->postJson('/api/v1/rentals/payments', $body, $h)->assertStatus(200);
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, RentalPayment::withoutGlobalScopes()->where('uuid', $uuid)->count());

        $next = ['uuid' => (string) Str::uuid()] + $body;
        $this->postJson('/api/v1/rentals/payments', ['bill_ahead_periods' => 1] + $next, $h)->assertStatus(422)->assertJsonPath('errors.code', 'ahead_invalid');
        $this->postJson('/api/v1/rentals/payments', ['amount_minor' => 900000] + $next, $h)->assertStatus(422)->assertJsonPath('errors.code', 'direct_too_much');
        $this->assertBooksAgree((int) $w['company']->id);
    }

    public function test_bill_ahead_through_the_api_bills_once_on_a_resend(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $uuid = (string) Str::uuid();
        $body = ['uuid' => $uuid, 'lease_id' => $w['brian']->id, 'amount_minor' => 900000, 'method' => 'cash', 'bill_ahead_periods' => 1];
        $first = $this->postJson('/api/v1/rentals/payments', $body, $h)->assertStatus(201);
        $bills = DB::table('rental_charges')->where('lease_id', $w['brian']->id)->count();
        $this->assertSame($first->json('data.id'), $this->postJson('/api/v1/rentals/payments', $body, $h)->assertStatus(200)->json('data.id'));
        $this->assertSame($bills, DB::table('rental_charges')->where('lease_id', $w['brian']->id)->count());
        $this->assertBooksAgree((int) $w['company']->id);
    }
}
