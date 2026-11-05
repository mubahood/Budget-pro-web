<?php

namespace Tests\Feature\Rentals;

use App\Models\RentalCharge;
use App\Services\Rentals\Collections\FollowUps;
use App\Services\Rentals\Payments\Payments;
use Illuminate\Support\Facades\DB;

/** The phone's everyday lists: each filter narrows the list, its total is the whole list's on every page, and a caretaker stays in their buildings on every one of them. Sample data. */
class RentalsListFiltersApiTest extends RentalsPhoneApiCase
{
    /** Every number on a list, walking its pages one row at a time. @return list<string> */
    private function walk(string $path, array $h, string $field = 'number'): array
    {
        $out = [];
        for ($page = 1; $page < 40; $page++) {
            $sep = str_contains($path, '?') ? '&' : '?';
            $res = $this->getJson("/api/v1/rentals/{$path}{$sep}per_page=1&page={$page}", $h)->assertOk();
            foreach ($res->json('data') as $row) {
                $out[] = (string) data_get($row, $field);
            }
            if (! $res->json('meta.pagination.has_more')) {
                break;
            }
        }

        return $out;
    }

    /** The world, with money in three ways, one reversed, one forgiven. @return array<string, mixed> */
    private function moneyWorld(): array
    {
        $w = $this->world();
        $owner = $w['owner'];
        $w['carolPaid'] = app(Payments::class)->receive($owner, $w['carol'], ['amount_minor' => 50000, 'method' => 'mobile_money', 'reference' => 'MP2603260001', 'paid_on' => '2026-03-26']);
        $w['brianPaid'] = app(Payments::class)->receive($owner, $w['brian'], ['amount_minor' => 70000, 'method' => 'cash', 'paid_on' => '2026-03-20']);
        $w['brianBack'] = app(Payments::class)->receive($owner, $w['brian'], ['amount_minor' => 11000, 'method' => 'bank', 'paid_on' => '2026-03-21']);
        app(Payments::class)->reverse($owner, $w['brianBack'], 'Wrong tenant');
        $bill = RentalCharge::withoutGlobalScopes()->where('lease_id', $w['carol']->id)->where('status', 'open')->orderBy('due_date')->first();
        app(Payments::class)->waive($owner, $bill, 5000, 'Goodwill');

        return $w;
    }

    public function test_payments_filter_by_method_property_date_and_view_and_the_total_is_the_whole_lists(): void
    {
        $w = $this->moneyWorld();
        $h = $this->h($w['owner']);
        $list = fn (string $query) => $this->getJson("/api/v1/rentals/payments?{$query}", $h)->assertOk();

        $byMethod = $list('method=mobile_money&per_page=1');
        $this->assertSame([$w['carolPaid']->number], array_column($byMethod->json('data'), 'number'));
        $this->assertSame(['count' => 1, 'amount_minor' => 50000, 'reversed_minor' => 0], $byMethod->json('meta.totals'));

        $money = $list('view=money&per_page=1&page=2');
        $this->assertSame(1, count($money->json('data')), 'a page of one');
        $this->assertSame(4, $money->json('meta.totals.count'), 'Amina twice, Carol, Brian: not the reversed or the forgiven');
        $this->assertSame(720000, $money->json('meta.totals.amount_minor'), 'the same on any page');
        $this->assertSame(4, $money->json('meta.pagination.total'));
        $this->assertCount(4, $this->walk('payments?view=money', $h));

        $reversed = $list('view=reversed');
        $this->assertSame([$w['brianBack']->number], array_column($reversed->json('data'), 'number'));
        $this->assertSame(['count' => 1, 'amount_minor' => 0, 'reversed_minor' => 11000], $reversed->json('meta.totals'));

        $this->assertSame(['waiver'], array_values(array_unique(array_column($list('view=other')->json('data'), 'method'))));
        $all = $list('');
        $this->assertSame(6, $all->json('meta.totals.count'), 'with no view, every one, as an older phone reads it');

        $here = $list('view=money&property_id='.$w['p']->id);
        $this->assertSame(670000, $here->json('meta.totals.amount_minor'));
        $this->assertSame(3, $here->json('meta.totals.count'));

        $days = $list('view=money&from=2026-03-20&to=2026-03-25');
        $this->assertSame([$w['brianPaid']->number], array_column($days->json('data'), 'number'));
        $this->assertSame(70000, $days->json('meta.totals.amount_minor'));

        $this->assertSame(0, $list('from=2020-01-01&to=2020-01-02')->json('meta.totals.amount_minor'));
    }

    public function test_payments_search_finds_a_unit_a_phone_however_written_and_a_lease(): void
    {
        $w = $this->moneyWorld();
        $h = $this->h($w['owner']);
        $numbers = fn (string $q) => array_column($this->getJson('/api/v1/rentals/payments?view=money&q='.urlencode($q), $h)->assertOk()->json('data'), 'number');
        $carol = [$w['carolPaid']->number];
        DB::table('rental_units')->where('id', $w['others'][0]->id)->update(['code' => 'LV-9']);

        $this->assertSame($carol, $numbers('Carol'));
        $this->assertSame($carol, $numbers('MP2603260001'));
        $this->assertSame($carol, $numbers($w['carolPaid']->number));
        $this->assertSame($carol, $numbers($w['carol']->number), 'the lease');
        $this->assertSame($carol, $numbers('LV-9'), 'the unit');
        foreach (['0772100003', '772 100 003', '+256772100003', '256772100003'] as $phone) {
            $this->assertSame($carol, $numbers($phone), $phone);
        }
        $this->assertSame([], $numbers('Nobody here'));
    }

    public function test_a_caretaker_stays_in_their_buildings_on_every_filter_and_total(): void
    {
        $w = $this->moneyWorld();
        $h = $this->h($w['caretaker']);

        $payments = $this->getJson('/api/v1/rentals/payments?view=money', $h)->assertOk();
        $this->assertSame(670000, $payments->json('meta.totals.amount_minor'), 'Lake View is not in their total');
        $this->assertSame([], $this->getJson('/api/v1/rentals/payments?q=Carol', $h)->assertOk()->json('data'));
        $this->assertSame(0, $this->getJson('/api/v1/rentals/payments?property_id='.$w['q']->id, $h)->assertOk()->json('meta.totals.amount_minor'));

        $bills = $this->getJson('/api/v1/rentals/charges?q=0772100003', $h)->assertOk();
        $this->assertSame([], $bills->json('data'));
        $this->assertSame(0, $bills->json('meta.totals.amount_minor'));

        $arrears = $this->getJson('/api/v1/rentals/arrears', $h)->assertOk();
        $this->assertSame([$w['brian']->id], array_column($arrears->json('data'), 'id'));
        $this->assertSame(1, $arrears->json('meta.totals.count'));
        $this->assertSame([], $this->getJson('/api/v1/rentals/arrears?q=Carol', $h)->assertOk()->json('data'));

        $leases = $this->getJson('/api/v1/rentals/leases?status=live', $h)->assertOk();
        $this->assertSame(2, $leases->json('meta.totals.count'));
        $this->assertSame(600000, $leases->json('meta.totals.rent_minor'));
        $this->assertSame([], $this->getJson('/api/v1/rentals/leases?q=Lake', $h)->assertOk()->json('data'));

        $this->assertSame(0, $this->getJson('/api/v1/rentals/units?landlord_id='.$w['peter']->id, $h)->assertOk()->json('meta.totals.count'));
        $this->assertSame([], $this->getJson('/api/v1/rentals/units?q=Carol', $h)->assertOk()->json('data'));
        $this->assertSame(0, $this->getJson('/api/v1/rentals/units?q=Carol', $h)->assertOk()->json('meta.totals.owed_minor'));

        $this->assertSame([], $this->getJson('/api/v1/rentals/properties?landlord_id='.$w['peter']->id, $h)->assertOk()->json('data'));
        $this->assertSame(['Kitchen tap leaking'], array_column($this->getJson('/api/v1/rentals/maintenance?priority=urgent', $h)->assertOk()->json('data'), 'title'));

        // Money out: refused by role; given the rights, still their buildings only.
        $this->getJson('/api/v1/rentals/expenses', $h)->assertStatus(403);
        $this->getJson('/api/v1/rentals/expenses/landlord-effect?property_id='.$w['p']->id.'&amount_minor=5', $h)->assertStatus(403);
        $this->getJson('/api/v1/rentals/payouts', $h)->assertStatus(403);
    }

    public function test_bills_filter_by_kind_date_property_and_search_with_their_total(): void
    {
        $w = $this->moneyWorld();
        $h = $this->h($w['owner']);
        $one = app(\App\Services\Rentals\Billing\Billing::class)->charge($w['owner'], $w['brian'], ['amount_minor' => 9000, 'due_date' => '2026-03-26', 'kind' => 'one_off', 'description' => 'Broken window']);
        $list = fn (string $query) => $this->getJson("/api/v1/rentals/charges?{$query}", $h)->assertOk();

        $kind = $list('kind=one_off');
        $this->assertSame([$one->id], array_column($kind->json('data'), 'id'));
        $this->assertSame(['count' => 1, 'amount_minor' => 9000, 'left_minor' => 9000], $kind->json('meta.totals'));

        $this->assertSame([$one->id], array_column($list('q=window')->json('data'), 'id'));
        $this->assertSame([$one->id], array_column($list('kind=one_off&q=0772100002')->json('data'), 'id'), 'the tenant\'s phone');
        $this->assertSame([$one->id], array_column($list('kind=one_off&q='.$w['units'][1]->code)->json('data'), 'id'), 'the unit');
        $this->assertSame([$one->id], array_column($list('kind=one_off&q='.$w['brian']->number)->json('data'), 'id'), 'the lease');
        $this->assertSame([], $list('kind=one_off&property_id='.$w['q']->id)->json('data'));

        $march = $list('kind=rent&status=open&from=2026-03-01&to=2026-03-31&property_id='.$w['p']->id);
        $rows = $march->json('data');
        $this->assertSame(array_sum(array_column($rows, 'amount_minor')), $march->json('meta.totals.amount_minor'));
        $this->assertSame(array_sum(array_column($rows, 'left_minor')), $march->json('meta.totals.left_minor'));
        $paged = $list('kind=rent&per_page=1&page=1');
        $this->assertCount(1, $paged->json('data'));
        $this->assertGreaterThan(1, $paged->json('meta.totals.count'));
        $this->assertSame($paged->json('meta.totals'), $list('kind=rent&per_page=1&page=2')->json('meta.totals'));

        DB::table('rental_charges')->where('id', $one->id)->update(['kind' => 'refund']);
        $this->assertSame([], $list('q=window&no_refunds=1')->json('data'));
        $this->assertSame([$one->id], array_column($list('q=window')->json('data'), 'id'));
    }

    public function test_arrears_filter_by_property_search_and_promises_due_with_their_total(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        app(FollowUps::class)->log($w['owner'], $w['brian'], 'call', null, '2026-03-26');
        app(FollowUps::class)->log($w['owner'], $w['carol'], 'call', null, '2026-04-02');
        $list = fn (string $query) => $this->getJson("/api/v1/rentals/arrears?{$query}", $h)->assertOk();
        $ids = fn (string $query) => array_column($list($query)->json('data'), 'id');

        $all = $list('');
        $this->assertEqualsCanonicalizing([$w['brian']->id, $w['carol']->id], array_column($all->json('data'), 'id'));
        $whole = $all->json('meta.aging.total_minor');

        $here = $list('property_id='.$w['p']->id);
        $this->assertSame([$w['brian']->id], array_column($here->json('data'), 'id'));
        $this->assertSame(1, $here->json('meta.totals.count'));
        $this->assertSame($here->json('data.0.overdue_minor'), $here->json('meta.totals.overdue_minor'));
        $this->assertSame($whole, $here->json('meta.aging.total_minor'), 'the aging stays the whole list');

        $this->assertSame([$w['brian']->id], $ids('promise=due'), 'Carol\'s day has not come');
        $this->assertSame([$w['carol']->id], $ids('q=Madeup'));
        $this->assertSame([$w['carol']->id], $ids('q=Lake'));
        $this->assertSame([$w['carol']->id], $ids('q=0772100003'));
        $this->assertSame([$w['carol']->id], $ids('q='.urlencode('+256 772 100 003')));
        $this->assertSame([$w['carol']->id], $ids('q='.$w['carol']->number));
        $this->assertSame([], $ids('q=Nobody'));

        $paged = $list('per_page=1');
        $this->assertCount(1, $paged->json('data'));
        $this->assertSame(2, $paged->json('meta.totals.count'));
        $this->assertSame($whole, $paged->json('meta.totals.overdue_minor'));
    }

    public function test_leases_filter_by_status_ending_soon_property_with_their_totals(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        DB::table('rental_leases')->where('id', $w['amina']->id)->update(['end_date' => '2026-04-10']); // ending in 30 days
        DB::table('rental_leases')->where('id', $w['carol']->id)->update(['end_date' => '2026-03-01']); // past its term, still there
        $ids = fn (string $query) => array_column($this->getJson("/api/v1/rentals/leases?{$query}", $h)->assertOk()->json('data'), 'id');

        $this->assertSame([$w['amina']->id], $ids('status=ending'));
        $this->assertSame([$w['carol']->id], $ids('status=term'));
        $this->assertEqualsCanonicalizing([$w['amina']->id, $w['brian']->id], $ids('status=live&property_id='.$w['p']->id));
        $this->assertSame([$w['carol']->id], $ids('status=live&q=0772100003'));
        $this->assertEqualsCanonicalizing([$w['carol']->id, $w['dan']->id], $ids('status=live&q=Lake'));
        $this->assertSame([], $ids('status=cancelled'));

        $live = $this->getJson('/api/v1/rentals/leases?status=live&per_page=1', $h)->assertOk();
        $this->assertCount(1, $live->json('data'));
        $this->assertSame(4, $live->json('meta.totals.count'));
        $this->assertSame(1000000, $live->json('meta.totals.rent_minor'));
        $owed = 0;
        foreach ($this->getJson('/api/v1/rentals/leases?status=live', $h)->json('data') as $l) {
            $owed += $l['position']['owed_minor'];
        }
        $this->assertSame($owed, $live->json('meta.totals.owed_minor'), 'what the rows owe, on any page');

        $doors = array_column($this->getJson('/api/v1/rentals/leases?status=live&sort=door', $h)->json('data'), 'id');
        $this->assertSame([$w['amina']->id, $w['brian']->id, $w['carol']->id, $w['dan']->id], $doors, 'by property, then unit');
    }

    public function test_expenses_filter_by_status_category_date_and_search_with_their_total(): void
    {
        $w = $this->moneyWorld();
        $h = $this->h($w['owner']);
        $post = fn (array $body) => $this->postJson('/api/v1/rentals/expenses', $body + ['method' => 'cash', 'charged_to' => 'landlord'], $h)->assertCreated()->json('data');
        $a = $post(['property_id' => $w['p']->id, 'category' => 'Repairs', 'description' => 'Gate hinge', 'vendor' => 'Okello Metal', 'amount_minor' => 12000, 'paid_on' => '2026-03-02']);
        $b = $post(['property_id' => $w['q']->id, 'category' => 'Cleaning', 'description' => 'Stairs', 'amount_minor' => 3000, 'paid_on' => '2026-03-20']);
        $c = $post(['property_id' => $w['p']->id, 'category' => 'Cleaning', 'description' => 'Drains', 'amount_minor' => 4000, 'paid_on' => '2026-03-21']);
        $this->postJson('/api/v1/rentals/expenses/'.$c['id'].'/reverse', ['reason' => 'Paid twice'], $h)->assertOk();
        $list = fn (string $query) => $this->getJson("/api/v1/rentals/expenses?{$query}", $h)->assertOk();
        $ids = fn (string $query) => array_column($list($query)->json('data'), 'id');

        $this->assertSame([$b['id'], $a['id']], $ids('status=live'));
        $this->assertSame(['count' => 2, 'amount_minor' => 15000], $list('status=live&per_page=1')->json('meta.totals'));
        $this->assertSame([$c['id']], $ids('status=reversed'));
        $this->assertSame(0, $list('status=reversed')->json('meta.totals.amount_minor'), 'money reversed is no money out');
        $this->assertCount(3, $ids(''), 'with no status, every one, as an older phone reads it');
        $this->assertSame([$b['id']], $ids('status=live&category=Cleaning'));
        $this->assertSame([$a['id']], $ids('status=live&from=2026-03-01&to=2026-03-10'));
        $this->assertSame([$a['id']], $ids('q=Okello'));
        $this->assertSame([$b['id']], $ids('status=live&q=Lake'), 'the property\'s name');
        $this->assertSame(3000, $list('status=live&property_id='.$w['q']->id)->json('meta.totals.amount_minor'));
    }

    public function test_the_landlord_effect_warns_as_the_web_and_holds_figures_back_from_a_tied_login(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        DB::table('rental_landlords')->where('id', $w['margaret']->id)->update(['expense_limit_minor' => 50000]);
        $path = '/api/v1/rentals/expenses/landlord-effect?property_id='.$w['p']->id;

        $fine = $this->getJson("{$path}&amount_minor=1000", $h)->assertOk()->json('data');
        $this->assertFalse($fine['needs_reason']);
        $this->assertNull($fine['below_zero_minor'], 'their balance covers it');

        $over = $this->getJson("{$path}&amount_minor=99000000", $h)->assertOk()->json('data');
        $this->assertTrue($over['needs_reason']);
        $this->assertSame('Margaret Fictional', $over['landlord']);
        $this->assertSame(50000, $over['limit_minor']);
        $this->assertLessThan(0, $over['below_zero_minor']);

        // A repair the landlord agreed this much on needs no reason, as Save would not ask for one.
        $repair = \App\Models\RentalMaintenance::withoutGlobalScopes()->findOrFail($w['urgentHere']->id);
        DB::table('rental_maintenance')->where('id', $repair->id)->update(['approval_status' => 'yes', 'approval_landlord_id' => $w['margaret']->id, 'quote_minor' => 99000000]);
        $agreed = $this->getJson("{$path}&amount_minor=99000000&maintenance_id={$repair->id}", $h)->assertOk()->json('data');
        $this->assertFalse($agreed['needs_reason']);

        $this->getJson("{$path}&amount_minor=abc", $h)->assertStatus(422);
        $this->getJson('/api/v1/rentals/expenses/landlord-effect?property_id=999999&amount_minor=5', $h)->assertStatus(404);
        $this->getJson("{$path}&amount_minor=5", $this->stranger())->assertStatus(404);
        $this->getJson("{$path}&amount_minor=5", $this->h($w['viewer']))->assertStatus(403);

        // A caretaker given the right: their own building, without the landlord's figures.
        DB::table('company_role_permissions')->insert(['company_id' => $w['company']->id, 'role' => 'rentals_caretaker', 'permission' => 'rentals_expenses', 'allowed' => true, 'created_at' => now(), 'updated_at' => now()]);
        \App\Services\Team\Permissions::flush();
        $tiedH = $this->h($w['caretaker']);
        $own = $this->getJson("{$path}&amount_minor=99000000", $tiedH)->assertOk()->json('data');
        $this->assertTrue($own['needs_reason']);
        $this->assertNull($own['limit_minor']);
        $this->assertNull($own['below_zero_minor']);
        $this->getJson('/api/v1/rentals/expenses/landlord-effect?property_id='.$w['q']->id.'&amount_minor=5', $tiedH)->assertStatus(404);
        $this->postJson('/api/v1/rentals/expenses', ['property_id' => $w['q']->id, 'category' => 'Repairs', 'amount_minor' => 5, 'method' => 'cash', 'charged_to' => 'landlord'], $h)->assertCreated();
        $this->assertSame(0, $this->getJson('/api/v1/rentals/expenses?status=live&property_id='.$w['q']->id, $tiedH)->assertOk()->json('meta.totals.amount_minor'));
    }

    public function test_payouts_of_all_landlords_filter_by_reversed_and_search_with_their_total(): void
    {
        $w = $this->moneyWorld();
        $h = $this->h($w['owner']);
        $post = fn (int $landlord, int $amount, string $ref) => $this->postJson('/api/v1/rentals/payouts', ['landlord_id' => $landlord, 'amount_minor' => $amount, 'method' => 'cash', 'reference' => $ref, 'paid_on' => '2026-03-26'], $h)->assertCreated()->json('data');
        $a = $post($w['margaret']->id, 100000, 'BK-MARGARET');
        $b = $post($w['margaret']->id, 20000, 'BK-AGAIN');
        $c = $post($w['peter']->id, 10000, 'BK-PETER');
        $this->postJson('/api/v1/rentals/payouts/'.$b['id'].'/reverse', ['reason' => 'Wrong account'], $h)->assertOk();
        $list = fn (string $query) => $this->getJson("/api/v1/rentals/payouts?{$query}", $h)->assertOk();
        $ids = fn (string $query) => array_column($list($query)->json('data'), 'id');

        $this->assertEqualsCanonicalizing([$a['id'], $c['id']], $ids('status=live'));
        $this->assertSame(['count' => 2, 'amount_minor' => 110000], $list('status=live&per_page=1&page=2')->json('meta.totals'));
        $this->assertSame([$b['id']], $ids('status=reversed'));
        $this->assertCount(3, $ids('status=all'));
        $this->assertSame([$c['id']], $ids('q=Peter'), 'the landlord\'s name');
        $this->assertSame([$a['id']], $ids('q=BK-MARGARET'));
        $this->assertSame([$c['id']], $ids('status=live&landlord_id='.$w['peter']->id));
        $this->assertSame(110000, $list('status=all')->json('meta.totals.amount_minor'), 'money reversed is no money out');
    }

    public function test_properties_filter_by_status_and_landlord(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        DB::table('rental_properties')->where('id', $w['q']->id)->update(['status' => 'archived']);
        $ids = fn (string $query) => array_column($this->getJson("/api/v1/rentals/properties?{$query}", $h)->assertOk()->json('data'), 'id');

        $this->assertSame([$w['p']->id], $ids('status=active'));
        $this->assertSame([$w['q']->id], $ids('status=archived'));
        $this->assertSame([$w['q']->id], $ids('landlord_id='.$w['peter']->id));
        $this->assertSame([], $ids('status=active&landlord_id='.$w['peter']->id));
    }

    public function test_units_across_properties_filter_by_landlord_state_and_search_with_their_totals(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        DB::table('rental_units')->where('id', $w['units'][2]->id)->update(['status' => 'maintenance']);
        $list = fn (string $query) => $this->getJson("/api/v1/rentals/units?{$query}", $h)->assertOk();
        $ids = fn (string $query) => array_column($list($query)->json('data'), 'id');

        $peter = $list('landlord_id='.$w['peter']->id);
        $this->assertEqualsCanonicalizing([$w['others'][0]->id, $w['others'][1]->id], array_column($peter->json('data'), 'id'));
        $this->assertSame(2, $peter->json('meta.totals.count'));
        $this->assertSame(2, $peter->json('meta.totals.let'));
        $this->assertSame(400000, $peter->json('meta.totals.rent_minor'));
        $this->assertSame([], $ids('landlord_id=999999'));

        $this->assertSame([$w['units'][2]->id], $ids('state=not_for_letting'));
        $this->assertCount(4, $ids('state=let'), 'overdue or not');
        $this->assertCount(5, $ids('state=listed'));
        DB::table('rental_units')->where('id', $w['units'][2]->id)->update(['status' => 'inactive']);
        $this->assertCount(4, $ids('state=listed'), 'switched off is not listed');
        $this->assertCount(5, $ids(''), 'with no state, every one, as an older phone reads it');

        $this->assertSame([$w['others'][0]->id], $ids('q=Carol'));
        $this->assertSame([$w['others'][0]->id], $ids('q=772100003'));
        $this->assertCount(2, $ids('q=Lake'), 'the property\'s name');

        $paged = $list('per_page=1&landlord_id='.$w['margaret']->id);
        $this->assertCount(1, $paged->json('data'));
        $this->assertSame(3, $paged->json('meta.totals.count'));
        $this->assertSame(2, $paged->json('meta.totals.let'));
    }

    public function test_repairs_filter_by_priority_and_search(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $titles = fn (string $query) => array_column($this->getJson("/api/v1/rentals/maintenance?{$query}", $h)->assertOk()->json('data'), 'title');

        $this->assertEqualsCanonicalizing(['Kitchen tap leaking', 'Roof at Lake View'], $titles('priority=urgent'));
        $this->assertSame([], $titles('priority=low'));
        $this->assertSame(['Roof at Lake View'], $titles('q=Roof'));
        $this->assertSame([], $titles('q=Roof&property_id='.$w['p']->id));
    }

    public function test_the_cash_book_narrows_its_rows_and_keeps_its_figures(): void
    {
        $w = $this->moneyWorld();
        $h = $this->h($w['owner']);
        $path = '/api/v1/rentals/cash?from=2026-03-01&to=2026-03-31';
        $whole = $this->getJson($path, $h)->assertOk()->json('data');
        $this->assertGreaterThan(1, count($whole['rows']));
        $word = $whole['rows'][0]['description'];

        $found = $this->getJson($path.'&q='.urlencode($word), $h)->assertOk()->json('data');
        $this->assertNotEmpty($found['rows']);
        $this->assertLessThanOrEqual(count($whole['rows']), count($found['rows']));
        foreach ($found['rows'] as $r) {
            $this->assertStringContainsString(mb_strtolower($word), mb_strtolower($r['description']));
        }
        $this->assertSame($whole['closing_minor'], $found['closing_minor'], 'the period\'s figures stay the period\'s');

        $none = $this->getJson($path.'&q=nothing-like-this', $h)->assertOk()->json('data');
        $this->assertSame([], $none['rows']);
        $this->assertSame($whole['in_minor'], $none['in_minor']);
    }

    public function test_the_activity_finds_by_words_kind_day_and_named_person(): void
    {
        $w = $this->moneyWorld();
        $h = $this->h($w['owner']);
        DB::table('rental_activities')->insert([
            ['company_id' => $w['company']->id, 'actor_type' => 'staff', 'actor_id' => $w['accountant']->id, 'action' => 'cash.moved', 'summary' => 'Banked the week\'s takings', 'created_at' => '2026-03-20 08:00:00'],
            ['company_id' => $w['company']->id, 'actor_type' => 'staff', 'actor_id' => $w['owner']->id, 'action' => 'settings.changed', 'summary' => 'Changed the grace days', 'created_at' => '2026-03-25 08:00:00'],
        ]);
        $list = fn (string $query) => $this->getJson("/api/v1/rentals/activity?{$query}", $h)->assertOk();
        $summaries = fn (string $query) => array_column($list($query)->json('data'), 'summary');

        $this->assertSame(['Banked the week\'s takings'], $summaries('q=takings'));
        $this->assertContains('Changed the grace days', $summaries('action=settings.'));
        $this->assertNotContains('Banked the week\'s takings', $summaries('action=settings.'));
        $this->assertSame(['Banked the week\'s takings'], $summaries('actor_type=staff&actor_id='.$w['accountant']->id));
        $this->assertSame([], $summaries('q=takings&actor_type=staff&actor_id='.$w['owner']->id));
        $this->assertSame(['Banked the week\'s takings'], $summaries('q=takings&from=2026-03-20&to=2026-03-20'));
        $this->assertSame([], $summaries('q=takings&from=2026-03-21&to=2026-03-31'));

        $people = $list('per_page=1')->json('meta.people');
        $this->assertContains($w['accountant']->id, array_column($people, 'id'));
        $this->assertSame($w['accountant']->name, collect($people)->firstWhere('id', $w['accountant']->id)['name']);
        $this->assertNull($list('per_page=1&page=2')->json('meta.people'), 'once is enough');

        $this->getJson('/api/v1/rentals/activity?q=takings', $this->h($w['caretaker']))->assertStatus(403);
        $this->getJson('/api/v1/rentals/activity?q=takings', $this->stranger())->assertOk()->assertJsonPath('data', []);
    }

    public function test_settings_say_how_far_the_books_are_closed_the_currencies_and_each_message_blanks(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        $s = $this->getJson('/api/v1/rentals/settings', $h)->assertOk()->json('data');
        $this->assertNull($s['closed_through']);
        $this->assertGreaterThan(5, count($s['lists']['currencies']));
        $this->assertContains('UGX', $s['lists']['currencies']);
        $this->assertSame(['landlord', 'period', 'link', 'account'], $s['lists']['message_tags']['year_summary']);

        DB::table('rental_settings')->where('company_id', $w['company']->id)->update(['closed_through' => '2026-02-28']);
        $this->assertSame('2026-02-28', $this->getJson('/api/v1/rentals/settings', $this->h($w['caretaker']))->assertOk()->json('data.closed_through'));

        // A message's own words: saved, read back; a blank it cannot fill is refused, in the web's words.
        $this->putJson('/api/v1/rentals/settings', ['messages' => ['overdue' => 'Hello {tenant}, {amount} is overdue.']], $h)->assertOk()->assertJsonPath('data.messages.overdue', 'Hello {tenant}, {amount} is overdue.');
        $refused = $this->putJson('/api/v1/rentals/settings', ['messages' => ['overdue' => 'Hello {landlord}']], $h)->assertStatus(422);
        $this->assertSame('message_blank_unknown', $refused->json('errors.code'));
        $this->assertSame('overdue', $refused->json('errors.kind'));
        $this->assertStringContainsString('{landlord}', $refused->json('message'));
        $this->putJson('/api/v1/rentals/settings', ['messages' => null], $h)->assertOk();
        $this->assertSame([], (array) $this->getJson('/api/v1/rentals/settings', $h)->json('data.messages'), 'the standard words again');
    }

    public function test_recent_and_pinned_records_are_each_persons_and_stay_in_their_buildings(): void
    {
        $w = $this->world();
        $this->indexed((int) $w['company']->id);
        $h = $this->h($w['owner']);
        $marks = fn (array $headers) => $this->getJson('/api/v1/rentals/search/marks', $headers)->assertOk()->json('data');
        $this->assertSame(['pinned' => [], 'recent' => []], $marks($h));

        $tenant = (int) $w['carol']->tenant_id;
        $this->postJson('/api/v1/rentals/search/opened', ['kind' => 'tenant', 'id' => $tenant], $h)->assertOk();
        $this->postJson('/api/v1/rentals/search/opened', ['kind' => 'property', 'id' => $w['p']->id], $h)->assertOk();
        $m = $marks($h);
        $this->assertSame(['property', 'tenant'], array_column($m['recent'], 'kind'), 'newest first');
        $this->assertSame('Carol Madeup', $m['recent'][1]['title']);
        $this->assertSame(['type' => 'tenant', 'id' => $tenant], $m['recent'][1]['link']);
        $this->assertFalse($m['recent'][1]['pinned']);

        $this->postJson('/api/v1/rentals/search/pin', ['kind' => 'tenant', 'id' => $tenant], $h)->assertOk()->assertJsonPath('data.pinned', true);
        $m = $marks($h);
        $this->assertSame([$tenant], array_column($m['pinned'], 'id'));
        $this->assertTrue($m['pinned'][0]['pinned']);
        $this->assertSame(['property'], array_column($m['recent'], 'kind'), 'a pinned one is not also recent');
        $this->postJson('/api/v1/rentals/search/pin', ['kind' => 'tenant', 'id' => $tenant], $h)->assertOk()->assertJsonPath('data.pinned', false);
        $this->assertSame([], $marks($h)['pinned']);
        $this->postJson('/api/v1/rentals/search/pin', ['kind' => 'tenant', 'id' => $tenant], $h)->assertOk();

        // Someone else's marks are theirs; a caretaker cannot pin or read another building's.
        $this->assertSame(['pinned' => [], 'recent' => []], $marks($this->h($w['accountant'])));
        $care = $this->h($w['caretaker']);
        $this->postJson('/api/v1/rentals/search/pin', ['kind' => 'tenant', 'id' => $tenant], $care)->assertStatus(404);
        $this->postJson('/api/v1/rentals/search/opened', ['kind' => 'tenant', 'id' => $tenant], $care)->assertOk();
        $this->assertSame(['pinned' => [], 'recent' => []], $marks($care), 'noted for no one: it is not theirs to see');
        $this->postJson('/api/v1/rentals/search/opened', ['kind' => 'property', 'id' => $w['p']->id], $care)->assertOk();
        $this->assertSame([$w['p']->id], array_column($marks($care)['recent'], 'id'));
        // A building taken from them leaves their list.
        DB::table('rental_property_staff')->where('user_id', $w['caretaker']->id)->delete();
        $this->assertSame([], $marks($care)['recent']);

        foreach ([['kind' => 'nonsense', 'id' => 1], ['kind' => 'tenant', 'id' => 'abc'], ['kind' => ['x'], 'id' => 1], ['kind' => 'tenant'], []] as $bad) {
            $this->postJson('/api/v1/rentals/search/pin', $bad, $h)->assertStatus(422);
        }
        $this->postJson('/api/v1/rentals/search/pin', ['kind' => 'tenant', 'id' => 99999999], $h)->assertStatus(404);
        $this->postJson('/api/v1/rentals/search/pin', ['kind' => 'tenant', 'id' => $tenant], $this->stranger())->assertStatus(404);

        // The web's limit.
        for ($i = 1; $i <= 12; $i++) {
            DB::table('rental_search_marks')->insert(['company_id' => $w['company']->id, 'user_id' => $w['owner']->id, 'kind' => 'unit', 'record_id' => 100000 + $i, 'pinned' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->postJson('/api/v1/rentals/search/pin', ['kind' => 'unit', 'id' => $w['units'][0]->id], $h)->assertStatus(422)->assertJsonPath('errors.code', 'pin_limit');
    }
}
