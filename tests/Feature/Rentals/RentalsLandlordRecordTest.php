<?php

namespace Tests\Feature\Rentals;

use App\Models\RentalLandlord;
use App\Models\RentalPayout;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Landlords\UnitSheet;
use App\Services\Rentals\Payments\Payments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/** A landlord's record (LC-24): the second phone, kept away from a caretaker; a payout's period filled silently; what the list adds up comes from the statement's rule. */
class RentalsLandlordRecordTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_second_phone_normalises_and_clears(): void
    {
        [, $owner] = $this->rentalsAccount();
        $l = app(Landlords::class)->create($owner, ['name' => 'Amina', 'phone' => '0772 000111', 'phone_2' => '0755 123 456']);
        $this->assertSame('0755 123 456', $l->phone_2);
        $this->assertSame('+256755123456', $l->phone_2_e164);
        $this->assertSame('+256772000111', $l->phone_e164);

        $l = app(Landlords::class)->update($owner, $l, ['phone_2' => '0701 222333']);
        $this->assertSame('+256701222333', $l->phone_2_e164);
        $this->assertSame('+256772000111', $l->phone_e164, 'the first phone stays');

        $l = app(Landlords::class)->update($owner, $l, ['phone_2' => null]);
        $this->assertNull($l->phone_2);
        $this->assertNull($l->phone_2_e164);
    }

    public function test_the_top_search_finds_the_second_phone(): void
    {
        [$company, $owner] = $this->rentalsAccount();
        $l = app(Landlords::class)->create($owner, ['name' => 'Amina', 'phone' => '0772 000111', 'phone_2' => '0755 123 456']);
        $entry = DB::table('rental_search_entries')->where('company_id', $company->id)->where('title', 'Amina')->value('id');
        $this->assertNotNull($entry);
        $this->assertSame(1, DB::table('rental_search_keys')->where('company_id', $company->id)->where('entry_id', $entry)->where('term', 'p:755123456')->count());
        $this->assertSame((int) $l->company_id, (int) $company->id);
    }

    public function test_the_api_gives_it_to_staff_and_hides_it_from_a_caretaker(): void
    {
        [$company, $owner] = $this->rentalsAccount();
        [$property] = $this->building($owner, 1, 100000);
        RentalLandlord::withoutGlobalScopes()->whereKey($property->landlord_id)->update(['phone_2' => '0755 123 456']);
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        app(Properties::class)->assignStaff($owner, $property, [$caretaker->id]);

        $staff = $this->auth($owner->createToken('t')->plainTextToken);
        $this->getJson('/api/v1/rentals/landlords/'.$property->landlord_id, $staff)->assertOk()->assertJsonPath('data.phone_2', '0755 123 456');

        $care = $this->auth($caretaker->createToken('t')->plainTextToken);
        $seen = $this->getJson('/api/v1/rentals/landlords/'.$property->landlord_id, $care)->assertOk();
        $seen->assertJsonPath('data.phone_2', null);
        $this->assertStringNotContainsString('0755', (string) $seen->getContent());
    }

    public function test_the_api_writes_it_and_refuses_an_advance_without_a_reason(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$property, $units] = $this->building($owner, 1, 100000);
        $h = $this->auth($owner->createToken('t')->plainTextToken);
        $this->patchJson('/api/v1/rentals/landlords/'.$property->landlord_id, ['phone_2' => '0755 123 456'], $h)->assertOk()->assertJsonPath('data.phone_2', '0755 123 456');

        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 50000]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 150000, 'method' => 'cash']);
        $body = ['landlord_id' => $property->landlord_id, 'amount_minor' => 101000, 'method' => 'cash', 'pay_ahead' => true]; // 90,000 owed, 11,000 ahead, 10,000 of the agency's own
        $this->postJson('/api/v1/rentals/payouts', $body, $h)->assertStatus(422)->assertJsonPath('errors.code', 'advance_reason_required');
        $this->postJson('/api/v1/rentals/payouts', $body + ['advance_reason' => 'Fees'], $h)->assertStatus(422)->assertJsonPath('errors.code', 'advance_from_others');
        $this->postJson('/api/v1/rentals/payouts', $body + ['advance_reason' => 'Fees', 'advance_others_ok' => true], $h)->assertCreated()->assertJsonPath('data.advance_minor', 11000);
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_a_payouts_period_is_filled_silently_and_not_printed(): void
    {
        Carbon::setTestNow('2026-03-20 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$property, $units] = $this->building($owner, 1, 100000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash']);
        $landlord = RentalLandlord::withoutGlobalScopes()->findOrFail($property->landlord_id);

        $p = app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 1000, 'method' => 'cash', 'paid_on' => '2026-03-15']);
        $this->assertSame('2026-03-01', $p->period_from->toDateString());
        $this->assertSame('2026-03-31', $p->period_to->toDateString());
        $given = app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 1000, 'method' => 'cash', 'period_from' => '2026-01-01', 'period_to' => '2026-01-31']);
        $this->assertSame('2026-01-31', $given->period_to->toDateString(), 'what the demo and the phone give stays');
        $this->assertArrayNotHasKey('period', \App\Services\Rentals\Landlords\PayoutAdvice::parts($p));
        $this->assertInstanceOf(RentalPayout::class, $p);
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_what_the_list_adds_up_is_each_landlords_statement(): void
    {
        Carbon::setTestNow('2026-03-20 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$pa, $ua] = $this->building($owner, 1, 100000);
        [$pb, $ub] = $this->building($owner, 1, 200000);
        app(Payments::class)->receive($owner, $this->tenantIn($owner, $ua[0], '2026-03-01', ['deposit_minor' => 0]), ['amount_minor' => 60000, 'method' => 'cash']);
        app(Payments::class)->receive($owner, $this->tenantIn($owner, $ub[0], '2026-03-01', ['deposit_minor' => 0]), ['amount_minor' => 200000, 'method' => 'cash']);

        $got = UnitSheet::collected($cid, [(int) $pa->landlord_id, (int) $pb->landlord_id], '2026-03-01', '2026-03-20');
        $this->assertSame([(int) $pa->landlord_id => 60000, (int) $pb->landlord_id => 200000], $got);
        $this->assertSame([], UnitSheet::collected($cid, [], '2026-03-01', '2026-03-20'));
    }
}
