<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalUnit;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\Figures;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Landlords\StatementBook;
use App\Services\Rentals\Landlords\UnitSheet;
use App\Services\Rentals\Reports\RentalReports;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * A room the landlord or caretaker uses (LC-21): not vacant, not let, not rent lost. It shows on the sheet and the rent roll, and a tenant moves in only after it is cleared.
 */
class RentalsInUseUnitTest extends ApiTestCase
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

    /** Four units: one let, one the caretaker uses, two vacant. @return array<string, mixed> */
    private function setUpUnits(): array
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency');
        [$p, $units] = $this->building($owner, 4, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-10-01', ['deposit_minor' => 0]);
        $used = app(Units::class)->update($owner, $units[1], ['status' => 'in_use', 'in_use_by' => 'The caretaker']);
        Carbon::setTestNow('2026-11-05 09:00:00');

        return ['company' => $company, 'owner' => $owner, 'cid' => (int) $company->id, 'p' => $p, 'units' => $units, 'lease' => $lease, 'used' => $used, 'landlord' => RentalLandlord::withoutGlobalScopes()->findOrFail($lease->landlord_id)];
    }

    public function test_it_says_who_and_clearing_it_forgets_who(): void
    {
        ['owner' => $owner, 'used' => $used, 'units' => $units, 'cid' => $cid] = $this->setUpUnits();
        $this->assertSame('in_use', $used->status);
        $this->assertSame('The caretaker', $used->in_use_by);
        $this->assertSame('in_use', Units::state($used, null));

        $this->refused('in_use_by_required', fn () => app(Units::class)->update($owner, $units[2], ['status' => 'in_use']));
        $this->refused('in_use_by_required', fn () => app(Units::class)->update($owner, $units[2], ['status' => 'in_use', 'in_use_by' => '   ']));

        $cleared = app(Units::class)->update($owner, $used, ['status' => 'available']);
        $this->assertSame('available', $cleared->status);
        $this->assertNull($cleared->in_use_by);
        $this->assertSame('vacant', Units::state($cleared, null));

        // A new unit can start as one, and a word in the box on any other state is not kept.
        $new = app(Units::class)->create($owner, ['property_id' => (int) $units[0]->property_id, 'code' => 'Store', 'rent_minor' => 0, 'status' => 'in_use', 'in_use_by' => 'The landlord']);
        $this->assertSame('The landlord', $new->in_use_by);
        $other = app(Units::class)->create($owner, ['property_id' => (int) $units[0]->property_id, 'code' => 'Spare', 'rent_minor' => 100000, 'status' => 'available', 'in_use_by' => 'Nobody']);
        $this->assertNull($other->in_use_by);
        $this->assertBooksAgree($cid);
    }

    public function test_a_tenant_moves_in_only_once_it_is_cleared_and_a_let_unit_cannot_become_one(): void
    {
        ['owner' => $owner, 'used' => $used, 'units' => $units, 'cid' => $cid] = $this->setUpUnits();
        $this->refused('unit_unavailable', fn () => $this->tenantIn($owner, $used, '2026-11-05', ['deposit_minor' => 0]));
        $this->assertSame(1, RentalLease::where('company_id', $cid)->count());

        $this->refused('unit_occupied', fn () => app(Units::class)->update($owner, $units[0], ['status' => 'in_use', 'in_use_by' => 'The landlord']));

        $cleared = app(Units::class)->update($owner, $used, ['status' => 'available']);
        $lease = $this->tenantIn($owner, $cleared, '2026-11-05', ['deposit_minor' => 0]);
        $this->assertTrue($lease->isLive());
        $this->assertBooksAgree($cid);
    }

    public function test_occupancy_leaves_it_out_of_vacant_and_of_the_rate(): void
    {
        ['p' => $p, 'cid' => $cid, 'landlord' => $landlord] = $this->setUpUnits();
        $f = Figures::units($cid, null);
        $this->assertSame(['units' => 3, 'occupied' => 1, 'vacant' => 2], ['units' => $f['units'], 'occupied' => $f['occupied'], 'vacant' => $f['vacant']]);
        $this->assertSame(33.3, round($f['occupancy_pct'], 1));

        $byProperty = Figures::properties($cid, [(int) $p->id]);
        $this->assertSame(3, $byProperty[(int) $p->id]['units_count']);
        $this->assertSame(2, $byProperty[(int) $p->id]['vacant_count']);

        // The landlord's own figures: live and as of a day.
        $this->assertSame(['units' => 3, 'occupied' => 1, 'vacant' => 2, 'in_use' => 1], LandlordAccounts::occupancy($landlord));
        $this->assertSame(['units' => 3, 'occupied' => 1, 'vacant' => 2, 'in_use' => 1], LandlordAccounts::occupancy($landlord, '2026-11-05'));
    }

    public function test_it_loses_no_rent_on_the_occupancy_report(): void
    {
        ['cid' => $cid, 'units' => $units, 'owner' => $owner] = $this->setUpUnits();
        $report = (new RentalReports)->run('occupancy', $cid, null, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame(3, $report['totals']['units']);
        $this->assertSame(2, $report['totals']['vacant']);
        // Two vacant units for a month at 300,000, and nothing for the room in use.
        $this->assertSame(600000, $report['totals']['rent_lost_minor']);
        $this->assertSame(1, $report['totals']['in_use']);
        $this->assertStringContainsString('used by the landlord or a caretaker', json_encode($report['sections']));

        // Cleared, it is a vacant unit that loses rent again.
        app(Units::class)->update($owner, $units[1], ['status' => 'available']);
        $report = (new RentalReports)->run('occupancy', $cid, null, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame(900000, $report['totals']['rent_lost_minor']);
    }

    public function test_the_rent_roll_and_the_sheet_show_who_uses_it(): void
    {
        ['cid' => $cid, 'landlord' => $landlord, 'used' => $used] = $this->setUpUnits();
        $roll = (new RentalReports)->run('rent_roll', $cid, null, []);
        $row = collect($roll['rows'])->firstWhere('unit', $used->code);
        $this->assertSame('Used by The caretaker', $row['state']);
        $this->assertNull($row['tenant']);
        $this->assertSame(['units' => 4, 'occupied' => 1, 'vacant' => 2, 'in_use' => 1], array_intersect_key($roll['totals'], array_flip(['units', 'occupied', 'vacant', 'in_use'])));
        $this->assertNotContains($used->code, array_column((new RentalReports)->run('rent_roll', $cid, null, ['state' => 'vacant'])['rows'], 'unit'));
        $this->assertContains($used->code, array_column((new RentalReports)->run('rent_roll', $cid, null, ['state' => 'unavailable'])['rows'], 'unit'));

        $sheet = collect(UnitSheet::rows($landlord, '2026-11-05'))->firstWhere('unit_id', (int) $used->id);
        $this->assertSame('The caretaker', $sheet['in_use_by']);
        $this->assertNull($sheet['vacant_since']);
        $this->assertSame(0, $sheet['rent_minor']);
    }

    public function test_the_statement_counts_it_apart_and_freezes_that(): void
    {
        ['cid' => $cid, 'landlord' => $landlord, 'owner' => $owner, 'used' => $used] = $this->setUpUnits();
        $report = (new RentalReports)->run('landlord_statement', $cid, null, ['landlord_id' => (int) $landlord->id, 'from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame([3, 1, 2, 1], [$report['totals']['units'], $report['totals']['occupied'], $report['totals']['vacant'], $report['totals']['in_use']]);
        $units = collect($report['sections'])->firstWhere('key', 'units');
        $this->assertContains('Used by The caretaker', array_column($units['rows'], 'tenant'));

        app(MonthClose::class)->close($owner, '2026-10');
        $copy = StatementBook::current($landlord, '2026-10');
        $this->assertSame(1, (int) $copy->body['occupancy']['in_use']);

        // Clearing the room later does not touch what was issued, and it makes no new version.
        app(Units::class)->update($owner, $used, ['status' => 'available']);
        $read = StatementBook::read($landlord, '2026-10-01', '2026-10-31');
        $this->assertSame(1, (int) $read['occupancy']['in_use']);
        $this->assertBooksAgree($cid);
    }

    public function test_an_issued_statement_from_before_this_still_reads(): void
    {
        ['cid' => $cid, 'landlord' => $landlord, 'owner' => $owner] = $this->setUpUnits();
        app(MonthClose::class)->close($owner, '2026-10');
        $copy = StatementBook::current($landlord, '2026-10');
        $body = (array) $copy->body;
        unset($body['occupancy']['in_use'], $body['totals']['direct_minor']);
        foreach ($body['units'] as $i => $u) {
            unset($body['units'][$i]['in_use_by'], $body['units'][$i]['direct_minor']);
        }
        \App\Models\RentalStatement::withoutGlobalScopes()->whereKey($copy->id)->update(['body' => json_encode($body), 'proof' => \App\Services\Rentals\Landlords\StatementProof::of($body)]);

        $report = (new RentalReports)->run('landlord_statement', $cid, null, ['landlord_id' => (int) $landlord->id, 'from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame(0, $report['totals']['in_use']);
        $this->assertSame(0, $report['totals']['direct_minor']);
        $this->assertBooksAgree($cid);
    }

    public function test_the_api_filters_by_it_and_a_phone_reads_who(): void
    {
        ['owner' => $owner, 'used' => $used] = $this->setUpUnits();
        $unit = RentalUnit::withoutGlobalScopes()->findOrFail($used->id);
        $this->assertSame('in_use', Units::state($unit, null));
        $h = $this->auth($owner->createToken('t')->plainTextToken);
        $rows = $this->getJson('/api/v1/rentals/units?state=in_use', $h)->assertOk()->json('data');
        $this->assertSame([(int) $used->id], array_column($rows, 'id'));
        $this->assertSame('The caretaker', $rows[0]['in_use_by']);
        $this->assertSame('in_use', $rows[0]['state']);
        $this->assertNotContains((int) $used->id, array_column($this->getJson('/api/v1/rentals/units?state=vacant', $h)->assertOk()->json('data'), 'id'));
        $this->assertSame([(int) $used->id], array_column($this->getJson('/api/v1/rentals/units?status=in_use', $h)->assertOk()->json('data'), 'id'));
    }
}
