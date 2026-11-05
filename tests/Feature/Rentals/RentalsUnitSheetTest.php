<?php

namespace Tests\Feature\Rentals;

use App\Models\RentalLandlord;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\UnitSheet;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Payments\Payments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * A landlord's units as they stood on a day (LC-06): a past month reads the same whenever it is asked for, the paid column is the
 * statement's "collected", and each kind of unit reads true: vacant, part paid, paid from credit, moved out in the month.
 */
class RentalsUnitSheetTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A tenant with no deposit, so that money goes on the rent. */
    private function renter(\App\Models\User $by, \App\Models\RentalUnit $unit, string $start): \App\Models\RentalLease
    {
        return $this->tenantIn($by, $unit, $start, ['deposit_minor' => 0]);
    }

    private function landlordOf(int $cid, int $id): RentalLandlord
    {
        return RentalLandlord::withoutGlobalScopes()->where('company_id', $cid)->findOrFail($id);
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function rowOf(array $rows, int $unitId): array
    {
        foreach ($rows as $r) {
            if ($r['unit_id'] === $unitId) {
                return $r;
            }
        }
        $this->fail('No row for unit '.$unitId);
    }

    public function test_figures_as_of_a_day_do_not_change_when_a_tenant_pays_later(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$property, $units] = $this->building($owner, 2, 300000);
        $lease = $this->renter($owner, $units[0], '2026-03-01');
        $landlord = $this->landlordOf((int) $company->id, (int) $property->landlord_id);
        Carbon::setTestNow('2026-03-05 09:00:00');
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash', 'paid_on' => '2026-03-05']);

        $march = UnitSheet::rows($landlord, '2026-03-31');
        $row = $this->rowOf($march, (int) $units[0]->id);
        $this->assertSame(300000, $row['rent_minor']);
        $this->assertSame(300000, $row['billed_minor']);
        $this->assertSame(100000, $row['paid_minor']);
        $this->assertSame(200000, $row['owed_minor']);
        $this->assertSame(0, $row['months_behind']);
        $this->assertSame(200000, $row['behind_rest_minor']);
        $this->assertSame('2026-03-05', $row['last_paid_on']);
        $this->assertSame(100000, $row['last_paid_minor']);
        $this->assertSame('2026-03-10', $row['paid_up_to']); // 100,000 of 300,000 covers a third of March's 31 days

        // April: billing runs, the tenant pays everything, a reversal happens, and March is still March.
        Carbon::setTestNow('2026-04-20 09:00:00');
        app(\App\Services\Rentals\Billing\Billing::class)->raiseAll((int) $company->id);
        $paid = app(Payments::class)->receive($owner, $lease->fresh(), ['amount_minor' => 500000, 'method' => 'cash', 'paid_on' => '2026-04-20']);
        $this->assertSame($march, UnitSheet::rows($landlord, '2026-03-31'));
        app(Payments::class)->reverse($owner, $paid, 'Bounced');
        $this->assertSame($march, UnitSheet::rows($landlord, '2026-03-31'));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_paid_total_is_the_statements_collected_across_part_payments_credit_reversals_and_a_former_tenant(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 3, 300000);
        $landlord = $this->landlordOf($cid, (int) $property->landlord_id);
        $a = $this->renter($owner, $units[0], '2026-03-01');
        $b = $this->renter($owner, $units[1], '2026-03-01');
        $c = $this->tenantIn($owner, $units[2], '2026-03-01', ['deposit_minor' => 300000]);
        $pays = app(Payments::class);
        Carbon::setTestNow('2026-03-04 09:00:00');
        $pays->receive($owner, $a, ['amount_minor' => 450000, 'method' => 'cash', 'paid_on' => '2026-03-02']); // 150,000 ahead
        $bounced = $pays->receive($owner, $b, ['amount_minor' => 300000, 'method' => 'cash', 'paid_on' => '2026-03-03']);
        $pays->receive($owner, $c, ['amount_minor' => 300000, 'method' => 'bank', 'paid_on' => '2026-03-04']); // deposit first or rent: either way
        Carbon::setTestNow('2026-03-21 09:00:00');
        $pays->reverse($owner, $bounced, 'Bounced');
        $pays->receive($owner, $b, ['amount_minor' => 120000, 'method' => 'cash', 'paid_on' => '2026-03-21']);
        $this->assertTotalsMatch($landlord, '2026-03-01', '2026-03-31');

        // April: credit pays part of a bill, one tenant leaves owing, and pays after leaving.
        Carbon::setTestNow('2026-04-01 09:00:00');
        app(\App\Services\Rentals\Billing\Billing::class)->raiseAll($cid);
        $this->assertTotalsMatch($landlord, '2026-04-01', '2026-04-30');
        Carbon::setTestNow('2026-05-10 09:00:00');
        app(Leases::class)->moveOut($owner, $b->fresh(), ['move_out_on' => '2026-05-10', 'reason' => 'Left']);
        Carbon::setTestNow('2026-06-02 09:00:00');
        $pays->receive($owner, $b->fresh(), ['amount_minor' => 50000, 'method' => 'cash', 'paid_on' => '2026-06-02']);
        $june = $this->assertTotalsMatch($landlord, '2026-06-01', '2026-06-30');
        $former = array_values(array_filter($june, fn ($r) => $r['lease_id'] === (int) $b->id));
        $this->assertCount(1, $former);
        $this->assertSame(50000, $former[0]['paid_minor']);
        $this->assertBooksAgree($cid);
    }

    /** @return list<array<string, mixed>> */
    private function assertTotalsMatch(RentalLandlord $landlord, string $from, string $to): array
    {
        $rows = UnitSheet::rows($landlord, $to);
        $st = LandlordAccounts::statement($landlord, $from, $to);
        $this->assertSame($st['totals']['collected_minor'], array_sum(array_column($rows, 'paid_minor')), 'paid against collected, '.$from);
        $this->assertSame(array_sum(array_column($st['arrears'], 'owed_minor')), array_sum(array_column($rows, 'owed_minor')), 'owed against still owing, '.$from);

        return $rows;
    }

    public function test_vacant_part_paid_credit_paid_and_moved_out_units(): void
    {
        Carbon::setTestNow('2026-01-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 4, 300000);
        $landlord = $this->landlordOf($cid, (int) $property->landlord_id);
        $behind = $this->renter($owner, $units[0], '2026-01-01');
        $ahead = $this->renter($owner, $units[1], '2026-01-01');
        $leaving = $this->renter($owner, $units[2], '2026-01-01');
        $pays = app(Payments::class);
        $billing = app(\App\Services\Rentals\Billing\Billing::class);
        $pays->receive($owner, $ahead, ['amount_minor' => 1200000, 'method' => 'cash', 'paid_on' => '2026-01-01']); // January and three months ahead
        $pays->receive($owner, $leaving, ['amount_minor' => 300000, 'method' => 'cash', 'paid_on' => '2026-01-01']);
        foreach (['2026-02-01', '2026-03-01'] as $day) {
            Carbon::setTestNow($day.' 09:00:00');
            $billing->raiseAll($cid);
        }
        // Three bills less 260,000 paid: 640,000 is two months and 40,000, floored.
        Carbon::setTestNow('2026-03-10 09:00:00');
        $pays->receive($owner, $behind->fresh(), ['amount_minor' => 260000, 'method' => 'cash', 'paid_on' => '2026-03-02']);
        $leaving = $leaving->fresh();
        $pays->receive($owner, $leaving, ['amount_minor' => 600000, 'method' => 'cash', 'paid_on' => '2026-03-10']);
        app(Leases::class)->moveOut($owner, $leaving->fresh(), ['move_out_on' => '2026-03-10', 'reason' => 'Left']);
        Carbon::setTestNow('2026-03-31 09:00:00');

        $rows = UnitSheet::rows($landlord, '2026-03-31');

        $vacant = $this->rowOf($rows, (int) $units[3]->id);
        $this->assertNull($vacant['tenant']);
        $this->assertNull($vacant['lease_id']);
        $this->assertSame('2026-01-01', $vacant['vacant_since']);
        $this->assertSame(0, $vacant['rent_minor']);

        $b = $this->rowOf($rows, (int) $units[0]->id);
        $this->assertSame(640000, $b['owed_minor']);
        $this->assertSame(2, $b['months_behind']);
        $this->assertSame(40000, $b['behind_rest_minor']);
        $this->assertSame('2 months + 40,000', UnitSheet::behindWords($b['months_behind'], $b['behind_rest_minor'], $cid));
        $this->assertSame('', UnitSheet::behindWords(0, 0, $cid));
        $this->assertSame('2026-01-26', $b['paid_up_to']);
        $this->assertSame('2026-03-02', $b['last_paid_on']);

        $a = $this->rowOf($rows, (int) $units[1]->id);
        $this->assertSame(0, $a['owed_minor']);
        $this->assertSame(300000, $a['paid_minor'], 'March paid from credit');
        $this->assertSame('2026-04-30', $a['paid_up_to'], 'the credit carries it through April');
        $this->assertSame('2026-01-01', $a['last_paid_on']);

        // Moved out in the month: their own row with what they paid, and the unit's vacant row.
        $mine = array_values(array_filter($rows, fn ($r) => $r['unit_id'] === (int) $units[2]->id));
        $this->assertCount(2, $mine);
        $this->assertSame((int) $leaving->id, $mine[0]['lease_id']);
        $this->assertSame('2026-03-10', $mine[0]['moved_out_on']);
        $this->assertNull($mine[1]['lease_id']);
        $this->assertSame('2026-03-11', $mine[1]['vacant_since']);
        $this->assertSame((int) LandlordAccounts::statement($landlord, '2026-03-01', '2026-03-31')['totals']['collected_minor'], array_sum(array_column($rows, 'paid_minor')));

        // February, asked for later: the leaving tenant was still there, and the prepaid tenant's credit carried further.
        $feb = UnitSheet::rows($landlord, '2026-02-28');
        $this->assertSame((int) $leaving->id, $this->rowOf($feb, (int) $units[2]->id)['lease_id']);
        $this->assertSame('2026-01-31', $this->rowOf($feb, (int) $units[2]->id)['paid_up_to']);
        $this->assertSame(300000, $this->rowOf($feb, (int) $units[2]->id)['owed_minor']);
        $this->assertSame('2026-04-30', $this->rowOf($feb, (int) $units[1]->id)['paid_up_to']);
        $this->assertBooksAgree($cid);
    }

    public function test_a_rent_change_shows_from_its_day_and_units_are_grouped_by_property_in_order(): void
    {
        Carbon::setTestNow('2026-01-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 2, 300000, ['name' => 'Z Court']);
        $landlord = $this->landlordOf($cid, (int) $property->landlord_id);
        $second = app(\App\Services\Rentals\Directory\Properties::class)->create($owner, ['name' => 'A Court', 'landlord_id' => $landlord->id, 'kind' => 'residential']);
        $more = app(\App\Services\Rentals\Directory\Units::class)->createMany($owner, (int) $second->id, 'B', 9, 10, ['rent_minor' => 100000, 'kind' => 'shop']);
        $lease = $this->renter($owner, $units[0], '2026-01-01');
        app(Leases::class)->changeRent($owner, $lease, 350000, '2026-03-01', 'Yearly rise');

        $this->assertSame(['A Court', 'A Court', 'Z Court', 'Z Court'], array_column(UnitSheet::rows($landlord, '2026-03-31'), 'property'));
        $this->assertSame(['B9', 'B10'], array_slice(array_column(UnitSheet::rows($landlord, '2026-03-31'), 'unit'), 0, 2));
        $this->assertSame(300000, $this->rowOf(UnitSheet::rows($landlord, '2026-02-28'), (int) $units[0]->id)['rent_minor']);
        $this->assertSame(350000, $this->rowOf(UnitSheet::rows($landlord, '2026-03-31'), (int) $units[0]->id)['rent_minor']);
        $this->assertSame((int) $second->id, $this->rowOf(UnitSheet::rows($landlord, '2026-03-31'), (int) $more[0]->id)['property_id']);
    }

    public function test_a_landlord_held_deposit_stays_out_of_the_rent_figures(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency', ['deposit_held_by' => 'landlord']);
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 1, 300000);
        $landlord = $this->landlordOf($cid, (int) $property->landlord_id);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 200000, 'deposit_held_by' => 'landlord']);

        $row = $this->rowOf($this->assertTotalsMatch($landlord, '2026-03-01', '2026-03-31'), (int) $units[0]->id);
        $this->assertSame(300000, $row['owed_minor'], 'the deposit is no month of rent');
        $this->assertSame(1, $row['months_behind']);
        $this->assertSame(0, $row['behind_rest_minor']);

        Carbon::setTestNow('2026-03-05 09:00:00');
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 500000, 'method' => 'cash', 'paid_on' => '2026-03-05']);
        $row = $this->rowOf($this->assertTotalsMatch($landlord, '2026-03-01', '2026-03-31'), (int) $units[0]->id);
        $this->assertSame(300000, $row['paid_minor'], 'the deposit is not rent');
        $this->assertSame(0, $row['owed_minor']);
    }

    public function test_it_stays_inside_the_account_and_under_the_query_budget(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$property, $units] = $this->building($owner, 40, 100000);
        $landlord = $this->landlordOf((int) $company->id, (int) $property->landlord_id);
        foreach (array_slice($units, 0, 30) as $i => $u) {
            $l = $this->renter($owner, $u, '2026-03-01');
            if ($i % 2 === 0) {
                app(Payments::class)->receive($owner, $l, ['amount_minor' => 60000, 'method' => 'cash', 'paid_on' => '2026-03-02']);
            }
        }
        [, $otherOwner] = $this->rentalsAccount();
        [$otherProperty, $otherUnits] = $this->building($otherOwner, 2, 100000);
        $this->tenantIn($otherOwner, $otherUnits[0], '2026-03-01');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = UnitSheet::rows($landlord, '2026-03-31');
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(40, $rows);
        $this->assertLessThan(15, $n, 'queries for a 40-unit landlord');
        $this->assertSame([], array_values(array_filter($rows, fn ($r) => $r['property_id'] === (int) $otherProperty->id)));
    }

    public function test_after_a_handover_old_and_new_landlord_each_read_their_own(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 1, 300000);
        $old = $this->landlordOf($cid, (int) $property->landlord_id);
        $new = app(\App\Services\Rentals\Directory\Landlords::class)->create($owner, ['name' => 'New owner']);
        $lease = $this->renter($owner, $units[0], '2026-03-01');
        Carbon::setTestNow('2026-03-10 09:00:00');
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash', 'paid_on' => '2026-03-10']);
        $march = UnitSheet::rows($old, '2026-03-31');

        Carbon::setTestNow('2026-04-02 09:00:00');
        app(\App\Services\Rentals\Billing\Billing::class)->raiseAll($cid);
        app(\App\Services\Rentals\Landlords\Handover::class)->handOver($owner, $property->fresh(), ['landlord_id' => $new->id, 'from' => '2026-04-01', 'from_date' => '2026-04-01', 'reason' => 'Sold']);

        // March still reads as it did, for the old landlord; the new one had nothing in March.
        $this->assertSame($march, UnitSheet::rows($old->fresh(), '2026-03-31'));
        $this->assertSame([], UnitSheet::rows($new->fresh(), '2026-03-31'));

        // April: the part-paid March bill stays with the old landlord, whose sheet shows only that; the new one holds the unit.
        $oldApril = UnitSheet::rows($old->fresh(), '2026-04-30');
        $this->assertCount(1, $oldApril);
        $this->assertSame((int) $lease->id, $oldApril[0]['lease_id']);
        $this->assertSame(200000, $oldApril[0]['owed_minor']);
        $newApril = UnitSheet::rows($new->fresh(), '2026-04-30');
        $this->assertCount(1, $newApril);
        $this->assertSame((int) $lease->id, $newApril[0]['lease_id']);
        $this->assertSame(300000, $newApril[0]['owed_minor']);

        // A later receipt follows each bill, so each sheet's paid is its own statement's collected.
        Carbon::setTestNow('2026-04-20 09:00:00');
        app(Payments::class)->receive($owner, $lease->fresh(), ['amount_minor' => 350000, 'method' => 'cash', 'paid_on' => '2026-04-20']);
        $this->assertTotalsMatch($old->fresh(), '2026-04-01', '2026-04-30');
        $this->assertTotalsMatch($new->fresh(), '2026-04-01', '2026-04-30');
        $this->assertBooksAgree($cid);
    }

    public function test_the_report_matches_the_rows_and_prints_with_the_landlord_named(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 2, 300000);
        $landlord = $this->landlordOf($cid, (int) $property->landlord_id);
        $lease = $this->renter($owner, $units[0], '2026-03-01');
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash', 'paid_on' => '2026-03-05']);

        $report = (new \App\Services\Rentals\Reports\RentalReports)->run('landlord_rent_sheet', $cid, $owner, ['landlord_id' => $landlord->id, 'month' => '2026-03']);
        $this->assertStringContainsString($landlord->name, $report['subtitle']);
        $this->assertStringContainsString('as of 31 Mar 2026', $report['subtitle']);
        $this->assertSame(100000, $report['totals']['paid_minor']);
        $this->assertSame(200000, $report['totals']['owed_minor']);
        $this->assertSame(300000, $report['totals']['rent_minor']);
        $this->assertStringContainsString('Vacant, since', $report['rows'][1]['tenant']);
        $this->assertStringStartsWith('%PDF', \App\Services\Rentals\Reports\RentalExport::pdf($report, $cid));
        $this->assertStringContainsString($landlord->name, \App\Services\Rentals\Reports\RentalExport::csv($report, $cid));

        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        (new \App\Services\Rentals\Reports\RentalReports)->run('landlord_rent_sheet', $cid, $owner, ['month' => '2026-03']);
    }

    public function test_the_report_refuses_a_caretaker_and_an_owner_run_account(): void
    {
        [$company, $owner] = $this->rentalsAccount();
        [$property] = $this->building($owner, 1);
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        try {
            (new \App\Services\Rentals\Reports\RentalReports)->run('landlord_rent_sheet', (int) $company->id, $caretaker, ['landlord_id' => $property->landlord_id]);
            $this->fail('A caretaker cannot run it.');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('forbidden', $e->errorCode());
        }
        [$selfCompany, $selfOwner] = $this->rentalsAccount('owner');
        try {
            (new \App\Services\Rentals\Reports\RentalReports)->run('landlord_rent_sheet', (int) $selfCompany->id, $selfOwner, []);
            $this->fail('An owner-run account has no such report.');
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->assertSame('agency_only', $e->errorCode());
        }
    }
}
