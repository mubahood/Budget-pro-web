<?php

namespace Tests\Feature\Rentals;

use App\Models\Company;
use App\Models\RentalLease;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Payments\Payments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** What the phone's screens found missing from existing answers: each an addition, each kept from those who should not read it. */
class RentalsPhoneAdditionsApiTest extends RentalsPhoneApiCase
{
    public function test_a_landlords_limit_on_repairs_is_the_offices_alone(): void
    {
        $w = $this->world();
        $owner = $this->h($w['owner']);
        $url = '/api/v1/rentals/landlords/'.$w['margaret']->id;
        $this->assertNull($this->getJson($url, $owner)->assertOk()->json('data.expense_limit_minor'));
        $this->assertSame(150000, $this->putJson($url, ['expense_limit_minor' => 150000], $owner)->assertOk()->json('data.expense_limit_minor'));
        $this->assertSame(150000, $this->getJson($url, $this->h($w['accountant']))->assertOk()->json('data.expense_limit_minor'));
        $this->assertSame(150000, collect($this->getJson('/api/v1/rentals/landlords', $owner)->assertOk()->json('data'))->firstWhere('id', (int) $w['margaret']->id)['expense_limit_minor']);

        // A caretaker: never, on the record or the list.
        $care = $this->h($w['caretaker']);
        $this->assertNull($this->getJson($url, $care)->assertOk()->json('data.expense_limit_minor'));
        $this->assertNull(collect($this->getJson('/api/v1/rentals/landlords', $care)->assertOk()->json('data'))->firstWhere('id', (int) $w['margaret']->id)['expense_limit_minor']);

        // Not on the landlord's own view, as on the web.
        [$landlord] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);
        $me = $this->getJson('/api/v1/rentals/me', $landlord)->assertOk();
        $this->assertArrayHasKey('expense_limit_minor', $me->json('data.landlord'));
        $this->assertNull($me->json('data.landlord.expense_limit_minor'));
    }

    public function test_a_propertys_notes_are_for_staff(): void
    {
        $w = $this->world();
        $owner = $this->h($w['owner']);
        $url = '/api/v1/rentals/properties/'.$w['p']->id;
        $this->assertSame('Gate key with the guard', $this->putJson($url, ['notes' => 'Gate key with the guard'], $owner)->assertOk()->json('data.notes'));
        $this->assertSame('Gate key with the guard', $this->getJson($url, $this->h($w['caretaker']))->assertOk()->json('data.notes'));
        [$landlord] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);
        $own = $this->getJson('/api/v1/rentals/me/properties', $landlord)->assertOk();
        $this->assertNull($own->json('data.0.notes'));
        $this->assertStringNotContainsString('Gate key', (string) $own->getContent());
    }

    public function test_the_list_of_bills_says_whose_bill_it_is_for_a_few_reads_whatever_its_length(): void
    {
        $w = $this->world();
        $h = $this->h($w['owner']);
        // A tenant who has left: the row still says who.
        RentalLease::withoutGlobalScopes()->whereKey($w['brian']->id)->update(['status' => 'ended', 'ended_on' => '2026-03-20']);
        $rows = $this->getJson('/api/v1/rentals/charges?lease_id='.$w['brian']->id, $h)->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        $this->assertSame(['id' => (int) $w['brian']->tenant_id, 'name' => 'Brian Sample'], $rows[0]['tenant'], 'a name, and no phone');
        $this->assertSame(['id' => (int) $w['brian']->unit_id, 'code' => 'A2'], $rows[0]['unit']);
        $this->assertKeys($rows[0], ['id', 'lease_id', 'kind', 'description', 'amount_minor', 'left_minor', 'status', 'overdue'], 'charge');

        $count = function (int $perPage) use ($h) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $seen = count($this->getJson('/api/v1/rentals/charges?per_page='.$perPage, $h)->assertOk()->json('data'));
            $n = count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'rental_') || str_contains($q['query'], 'admin_users')));
            DB::disableQueryLog();

            return [$seen, $n];
        };
        [$one, $few] = $count(1);
        [$all, $many] = $count(100);
        $this->assertSame(1, $one);
        $this->assertGreaterThan(4, $all);
        $this->assertSame($few, $many, 'more bills cost no more reads');

        // A caretaker's list stays theirs. A bill inside a lease keeps its own shape.
        $care = $this->getJson('/api/v1/rentals/charges', $this->h($w['caretaker']))->assertOk()->json('data');
        $this->assertNotContains('Carol Madeup', array_column(array_column($care, 'tenant'), 'name'));
        $this->assertArrayNotHasKey('tenant', $this->getJson('/api/v1/rentals/leases/'.$w['amina']->id, $h)->assertOk()->json('data.charges.0'));
    }

    public function test_a_payouts_advice_is_a_signed_link_and_words_for_ones_own_phone(): void
    {
        $w = $this->world();
        $payout = app(Payouts::class)->record($w['owner'], $w['margaret'], ['amount_minor' => 50000, 'method' => 'cash', 'paid_on' => '2026-03-20', 'reference' => 'CHQ-77']);
        $url = '/api/v1/rentals/payouts/'.$payout->id.'/advice';
        $res = $this->getJson($url, $this->h($w['accountant']))->assertOk();
        $d = $res->json('data');
        $this->assertSame(['payout_id', 'number', 'landlord', 'amount_minor', 'paid_on', 'reversed', 'url', 'expires_in', 'text', 'phone', 'whatsapp', 'sms'], array_keys($d));
        $this->assertSame(50000, $d['amount_minor']);
        $this->assertSame('2026-03-20', $d['paid_on']);
        $this->assertSame(10080 * 60, $d['expires_in']);
        $this->assertStringContainsString('/rentals/pdf/payout-advice', $d['url']);
        $this->assertStringContainsString($d['url'], $d['text']);
        $this->assertStringContainsString('Hello Margaret Fictional, we paid you', $d['text']);
        $this->assertStringContainsString('20 Mar 2026', $d['text']);
        $this->assertStringContainsString($d['number'], $d['text']);
        $this->assertSame('+256700000201', $d['phone']);
        $this->assertStringStartsWith('https://wa.me/', (string) $d['whatsapp']);
        $pdf = $this->get(str_replace(config('app.url'), '', $d['url']))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->fixture('payout_advice', $res->json());

        // A reversed payout says so.
        app(Payouts::class)->reverse($w['owner'], $payout->fresh(), 'Wrong amount');
        $back = $this->getJson($url, $this->h($w['owner']))->assertOk()->json('data');
        $this->assertTrue($back['reversed']);
        $this->assertStringContainsString('was reversed', $back['text']);

        // Who may not.
        $this->getJson($url, $this->h($w['caretaker']))->assertStatus(403);
        $this->getJson($url, $this->h($w['viewer']))->assertStatus(403);
        $this->getJson($url, $this->stranger())->assertStatus(404);
        $this->getJson('/api/v1/rentals/payouts/999999/advice', $this->h($w['owner']))->assertStatus(404);
        [$tenant] = $this->person($w['owner'], 'tenant', (int) $w['amina']->tenant_id);
        $this->getJson($url, $tenant)->assertStatus(401);
        DB::table('company_role_permissions')->insert(['company_id' => $w['company']->id, 'role' => 'rentals_caretaker', 'permission' => 'rentals_landlords', 'allowed' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson($url, $this->h($w['caretaker']))->assertStatus(403);

        Carbon::setTestNow('2026-03-01 09:00:00');
        [, $solo] = $this->rentalsAccount('owner');
        $this->getJson('/api/v1/rentals/payouts/1/advice', $this->h($solo))->assertStatus(422)->assertJsonPath('errors.code', 'agency_only');
    }

    public function test_a_person_reads_the_accounts_own_phone(): void
    {
        $w = $this->world();
        [$tenant] = $this->person($w['owner'], 'tenant', (int) $w['amina']->tenant_id);
        [$landlord] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);
        $this->assertSame('0700 000 100', $this->getJson('/api/v1/rentals/me/home', $tenant)->assertOk()->json('data.account.phone'));
        $this->assertSame('0700 000 100', $this->getJson('/api/v1/rentals/me', $tenant)->assertOk()->json('data.account.phone'));
        $this->assertSame('0700 000 100', $this->getJson('/api/v1/rentals/me/home', $landlord)->assertOk()->json('data.account.phone'));
        Company::withoutGlobalScopes()->whereKey($w['company']->id)->update(['phone_number' => null, 'phone_number_2' => '0700 000 101']);
        $this->assertSame('0700 000 101', $this->getJson('/api/v1/rentals/me/home', $tenant)->assertOk()->json('data.account.phone'), 'the second number, when it is the only one');
        Company::withoutGlobalScopes()->whereKey($w['company']->id)->update(['phone_number_2' => '']);
        $this->assertNull($this->getJson('/api/v1/rentals/me/home', $tenant)->assertOk()->json('data.account.phone'));
    }

    public function test_rent_paid_straight_to_the_landlord_is_on_their_home_and_their_statement(): void
    {
        $w = $this->world();
        [$h] = $this->person($w['owner'], 'landlord', (int) $w['margaret']->id);
        $this->assertSame(0, $this->getJson('/api/v1/rentals/me/home', $h)->assertOk()->json('data.month.direct_minor'), 'always there');
        $this->assertSame(0, $this->getJson('/api/v1/rentals/me/statement?month=2026-03', $h)->assertOk()->json('data.totals.direct_minor'));

        app(Payments::class)->receiveDirect($w['owner'], $w['brian'], ['amount_minor' => 300000]);
        $month = $this->getJson('/api/v1/rentals/me/home', $h)->assertOk()->json('data.month');
        $this->assertSame(300000, $month['direct_minor']);
        $this->assertSame(300000, $month['collected_minor'], 'beside the figures, never in them: only Amina\'s came through the office');
        $statement = $this->getJson('/api/v1/rentals/me/statement?month=2026-03', $h)->assertOk()->json('data');
        $this->assertSame(300000, $statement['totals']['direct_minor']);
        $this->assertSame(300000, $statement['totals']['collected_minor']);

        // February's statement knows nothing of it.
        $this->assertSame(0, $this->getJson('/api/v1/rentals/me/statement?month=2026-02', $h)->assertOk()->json('data.totals.direct_minor'));
        // Another landlord's home neither.
        [$peter] = $this->person($w['owner'], 'landlord', (int) $w['peter']->id);
        $this->assertSame(0, $this->getJson('/api/v1/rentals/me/home', $peter)->assertOk()->json('data.month.direct_minor'));
        $this->assertBooksAgree((int) $w['company']->id);
    }
}
