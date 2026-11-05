<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalEntry;
use App\Models\RentalExpense;
use App\Models\RentalLandlord;
use App\Models\RentalSetting;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Landlords\StatementBook;
use App\Services\Rentals\Landlords\Withholding;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\RentalReports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * Holding money back for a landlord (LC-23, EL-29): off until the agency turns it on, its own percent per landlord, and recorded as an expense charged to the
 * landlord when it is actually paid over. The system states no law and works out nothing but the agency's percent of the rent collected. Invented rate and figures.
 */
class RentalsWithholdingTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function refused(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected refusal {$code}");
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode(), $e->getMessage());
        }
    }

    /** Rent 500,000 collected in cash in October for a landlord at 5%, switched on. @return array<string, mixed> */
    private function collected(array $settings = ['withholding_on' => true], ?int $bp = 500): array
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency', $settings);
        [$p, $units] = $this->building($owner, 2, 500000);
        $lease = $this->tenantIn($owner, $units[0], '2026-10-01', ['deposit_minor' => 0]);
        $landlord = RentalLandlord::withoutGlobalScopes()->findOrFail($lease->landlord_id);
        if ($bp !== null) {
            app(Landlords::class)->update($owner, $landlord, ['withholding_bp' => $bp]);
        }
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 500000, 'method' => 'cash', 'paid_on' => '2026-10-05']);
        Carbon::setTestNow('2026-11-05 09:00:00');

        return ['company' => $company, 'owner' => $owner, 'cid' => (int) $company->id, 'p' => $p, 'units' => $units, 'lease' => $lease, 'landlord' => $landlord->fresh()];
    }

    public function test_it_is_off_for_every_account_until_turned_on(): void
    {
        ['cid' => $cid, 'owner' => $owner, 'landlord' => $landlord] = $this->collected([], 500);
        $this->assertFalse(Withholding::on($cid));
        $this->assertFalse((bool) RentalSetting::withoutGlobalScopes()->where('company_id', $cid)->value('withholding_on'));
        $this->assertSame([], Withholding::suggestions($cid, '2026-10'));
        $this->refused('withholding_off', fn () => app(Withholding::class)->record($owner, $landlord, '2026-10', ['amount_minor' => 25000, 'method' => 'cash']));

        // The columns are there and empty on a landlord nobody set.
        $this->assertTrue(Schema::hasColumn('rental_landlords', 'withholding_bp'));
        $this->assertNull(app(Landlords::class)->create($owner, ['name' => 'New landlord'])->fresh()->withholding_bp);
    }

    public function test_an_owner_run_account_never_has_it(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount('owner', ['withholding_on' => true]);
        $this->assertFalse(Withholding::on((int) $company->id));
        $this->assertSame([], Withholding::suggestions((int) $company->id, '2026-10'));
    }

    public function test_the_percent_is_the_agencys_and_is_checked(): void
    {
        ['owner' => $owner, 'landlord' => $landlord] = $this->collected();
        $this->assertSame(500, (int) $landlord->withholding_bp);
        $this->refused('invalid', fn () => app(Landlords::class)->update($owner, $landlord, ['withholding_bp' => 10001]));
        $this->refused('invalid', fn () => app(Landlords::class)->update($owner, $landlord, ['withholding_bp' => -1]));
        $off = app(Landlords::class)->update($owner, $landlord, ['withholding_bp' => null]);
        $this->assertNull($off->withholding_bp);
    }

    public function test_the_suggestion_is_the_percent_of_rent_the_agency_collected_and_writes_nothing(): void
    {
        ['cid' => $cid, 'owner' => $owner, 'landlord' => $landlord, 'lease' => $lease] = $this->collected();
        $entries = RentalEntry::where('company_id', $cid)->count();

        $rows = Withholding::suggestions($cid, '2026-10');
        $this->assertCount(1, $rows);
        $this->assertSame((int) $landlord->id, $rows[0]['landlord_id']);
        $this->assertSame([500, 500000, 25000, 0, 25000], [$rows[0]['rate_bp'], $rows[0]['rent_minor'], $rows[0]['suggested_minor'], $rows[0]['recorded_minor'], $rows[0]['left_minor']]);
        $this->assertSame($entries, RentalEntry::where('company_id', $cid)->count(), 'Suggesting posts nothing');

        // A month with no rent suggests nothing to pay, and a landlord with no percent is not listed.
        $this->assertSame(0, Withholding::suggestions($cid, '2026-09')[0]['suggested_minor']);
        app(Landlords::class)->update($owner, $landlord, ['withholding_bp' => null]);
        $this->assertSame([], Withholding::suggestions($cid, '2026-10'));
    }

    public function test_fees_a_reversal_and_money_paid_to_the_landlord_are_not_rent_collected(): void
    {
        ['cid' => $cid, 'owner' => $owner, 'lease' => $lease, 'units' => $units] = $this->collected();
        $this->assertSame(500000, Withholding::suggestions($cid, '2026-10')[0]['rent_minor']);

        Carbon::setTestNow('2026-10-20 09:00:00');
        $second = $this->tenantIn($owner, $units[1], '2026-10-01', ['deposit_minor' => 0]);
        app(Payments::class)->receiveDirect($owner, $second, ['amount_minor' => 500000, 'paid_on' => '2026-10-20']);
        $this->assertSame(500000, Withholding::suggestions($cid, '2026-10')[0]['rent_minor'], 'The agency cannot hold back what it never held');

        $p = \App\Models\RentalPayment::where('lease_id', $lease->id)->firstOrFail();
        app(Payments::class)->reverse($owner, $p, 'Bounced');
        $this->assertSame(0, Withholding::suggestions($cid, '2026-10')[0]['rent_minor']);
    }

    public function test_recording_it_is_an_expense_charged_to_the_landlord_when_paid_over(): void
    {
        ['cid' => $cid, 'owner' => $owner, 'landlord' => $landlord] = $this->collected();
        $balance = LandlordAccounts::balance($landlord);
        $e = app(Withholding::class)->record($owner, $landlord, '2026-10', ['amount_minor' => 25000, 'method' => 'cash', 'paid_on' => '2026-11-05', 'vendor' => 'Paid over by the office']);

        $this->assertSame(Withholding::CATEGORY, $e->category);
        $this->assertSame('2026-10', $e->for_month);
        $this->assertSame('landlord', $e->charged_to);
        $this->assertSame((int) $landlord->id, (int) $e->landlord_id);
        $this->assertNull($e->property_id);
        $row = RentalEntry::where('expense_id', $e->id)->firstOrFail();
        $this->assertSame(['expense_landlord', -25000, -25000], [$row->type, (int) $row->cash_minor, (int) $row->landlord_minor]);
        $this->assertSame($balance - 25000, LandlordAccounts::balance($landlord));
        $this->assertStringContainsString('October', (string) $e->description);

        // It counts against the suggestion, whatever figure the office typed.
        $rows = Withholding::suggestions($cid, '2026-10');
        $this->assertSame([25000, 0], [$rows[0]['recorded_minor'], $rows[0]['left_minor']]);

        // On the statement, the expense list and the books.
        $s = StatementBook::compose($landlord, '2026-11-01', '2026-11-30');
        $this->assertSame(25000, (int) $s['totals']['expenses_minor']);
        $this->assertStringContainsString(Withholding::CATEGORY, $s['expenses'][0]['what']);
        $this->assertBooksAgree($cid);

        $report = (new RentalReports)->run('expenses', $cid, null, ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame(25000, (int) $report['totals']['amount_minor']);
        $year = (new RentalReports)->run('landlord_year', $cid, null, ['landlord_id' => (int) $landlord->id, 'year' => '2026']);
        $this->assertSame(25000, (int) $year['totals']['expenses_minor']);
        $this->assertStringContainsString(Withholding::CATEGORY, json_encode($year['sections']));
    }

    public function test_reversing_it_puts_the_suggestion_back(): void
    {
        ['cid' => $cid, 'owner' => $owner, 'landlord' => $landlord] = $this->collected();
        $before = LandlordAccounts::balance($landlord);
        $e = app(Withholding::class)->record($owner, $landlord, '2026-10', ['amount_minor' => 25000, 'method' => 'cash']);
        app(Expenses::class)->reverse($owner, $e, 'Not paid over after all');
        $this->assertSame($before, LandlordAccounts::balance($landlord));
        $this->assertSame([0, 25000], [Withholding::suggestions($cid, '2026-10')[0]['recorded_minor'], Withholding::suggestions($cid, '2026-10')[0]['left_minor']]);
        $this->assertBooksAgree($cid);
    }

    public function test_it_needs_the_money_and_an_open_day_and_no_tax_is_worked_out_of_the_air(): void
    {
        ['cid' => $cid, 'owner' => $owner, 'landlord' => $landlord] = $this->collected();
        $this->refused('insufficient_cash', fn () => app(Withholding::class)->record($owner, $landlord, '2026-10', ['amount_minor' => 900000, 'method' => 'bank']));
        $this->refused('month_invalid', fn () => app(Withholding::class)->record($owner, $landlord, 'October', ['amount_minor' => 1000, 'method' => 'cash']));
        app(MonthClose::class)->close($owner, '2026-10');
        // Paid over after the month closed: the money goes out today, for the closed month.
        $e = app(Withholding::class)->record($owner, $landlord, '2026-10', ['amount_minor' => 25000, 'method' => 'cash', 'paid_on' => '2026-11-05']);
        $this->assertSame('2026-11-05', $e->paid_on->toDateString());
        $this->refused('period_closed', fn () => app(Withholding::class)->record($owner, $landlord, '2026-10', ['amount_minor' => 1000, 'method' => 'cash', 'paid_on' => '2026-10-31']));
        // October's statement stays as issued.
        $this->assertSame(0, (int) StatementBook::current($landlord, '2026-10')->expenses_minor);
        $this->assertBooksAgree($cid);
    }

    public function test_only_who_records_expenses_may_record_it_and_a_landlord_elsewhere_is_not_found(): void
    {
        ['company' => $company, 'owner' => $owner, 'landlord' => $landlord] = $this->collected();
        $viewer = $this->rentalsStaff($company, 'viewer');
        $this->refused('forbidden', fn () => app(Withholding::class)->record($viewer, $landlord, '2026-10', ['amount_minor' => 25000, 'method' => 'cash']));
        [, $elsewhere] = $this->rentalsAccount('agency', ['withholding_on' => true]);
        $this->refused('not_found', fn () => app(Withholding::class)->record($elsewhere, $landlord, '2026-10', ['amount_minor' => 25000, 'method' => 'cash']));
        unset($owner);
    }

    public function test_the_caretakers_landlord_payload_hides_the_percent(): void
    {
        ['company' => $company, 'owner' => $owner, 'landlord' => $landlord, 'p' => $p] = $this->collected();
        $h = $this->auth($owner->createToken('t')->plainTextToken);
        $this->assertSame(500, $this->getJson("/api/v1/rentals/landlords/{$landlord->id}", $h)->assertOk()->json('data.withholding_bp'));
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        app(\App\Services\Rentals\Directory\Properties::class)->assignStaff($owner, $p, [(int) $caretaker->id]);
        $res = $this->getJson("/api/v1/rentals/landlords/{$landlord->id}", $this->auth($caretaker->createToken('t')->plainTextToken));
        if ($res->status() === 200) {
            $this->assertNull($res->json('data.withholding_bp'));
        } else {
            $res->assertStatus(403);
        }
    }

    public function test_the_settings_door_takes_the_switch(): void
    {
        ['company' => $company, 'owner' => $owner, 'cid' => $cid] = $this->collected([], null);
        $this->assertFalse(Withholding::on($cid));
        app(RentalsModule::class)->saveSettings($company, $owner, ['withholding_on' => true]);
        $this->assertTrue(Withholding::on($cid));
        app(RentalsModule::class)->saveSettings($company, $owner, ['withholding_on' => false]);
        $this->assertFalse(Withholding::on($cid));
        $this->assertSame(0, RentalExpense::where('company_id', $cid)->count());
    }

    public function test_the_cash_book_shows_the_money_leaving(): void
    {
        ['cid' => $cid, 'owner' => $owner, 'landlord' => $landlord] = $this->collected();
        app(Withholding::class)->record($owner, $landlord, '2026-10', ['amount_minor' => 25000, 'method' => 'cash', 'paid_on' => '2026-11-05']);
        $book = CashBook::statement($cid, '2026-11-01', '2026-11-30');
        $this->assertSame(1, count(array_filter((array) $book['rows'], fn ($r) => (string) ($r['type'] ?? '') === 'expense_landlord')));
        $this->assertBooksAgree($cid);
    }
}
