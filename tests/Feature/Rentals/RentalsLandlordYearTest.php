<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalLandlord;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\LandlordReports;
use App\Services\Rentals\Reports\RentalExport;
use App\Services\Rentals\Reports\RentalReports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * A landlord's year (LC-18): its monthly columns are the twelve statements, by property and by category tie to them, a year still running stops at this month,
 * it says it is no tax advice and works out no tax, it holds no one else's money, and an owner reads it in owner's words.
 */
class RentalsLandlordYearTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Two properties of one landlord through a year with receipts, a deposit, expenses, payouts and corrections. @return array{0: int, 1: \App\Models\User, 2: RentalLandlord, 3: RentalLandlord} */
    private function aYear(): array
    {
        Carbon::setTestNow('2025-01-11 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency', ['deposit_held_by' => 'landlord']);
        [$p1, $u1] = $this->building($owner, 2, 300000, ['name' => 'First Court']);
        $landlord = RentalLandlord::withoutGlobalScopes()->findOrFail($p1->landlord_id);
        $p2 = app(\App\Services\Rentals\Directory\Properties::class)->create($owner, ['name' => 'Second Court', 'landlord_id' => $landlord->id, 'kind' => 'residential']);
        $u2 = app(\App\Services\Rentals\Directory\Units::class)->createMany($owner, (int) $p2->id, 'B', 1, 1, ['rent_minor' => 200000, 'kind' => 'apartment']);
        [$otherProperty, $otherUnits] = $this->building($owner, 1, 900000, ['name' => 'Someone Else Court']);
        $other = RentalLandlord::withoutGlobalScopes()->findOrFail($otherProperty->landlord_id);

        $held = $this->tenantIn($owner, $u1[0], '2025-01-01', ['deposit_minor' => 300000, 'deposit_held_by' => 'landlord']);
        $plain = $this->tenantIn($owner, $u2[0], '2025-01-01', ['deposit_minor' => 0]);
        $elsewhere = $this->tenantIn($owner, $otherUnits[0], '2025-01-01', ['deposit_minor' => 0]);
        $pay = fn ($lease, int $amount, string $on) => app(Payments::class)->receive($owner, $lease, ['amount_minor' => $amount, 'method' => 'cash', 'paid_on' => $on]);

        $pay($held, 600000, '2025-01-10'); // rent and the deposit, which goes to the landlord
        $pay($plain, 200000, '2025-01-10');
        $pay($elsewhere, 900000, '2025-01-10');
        Carbon::setTestNow('2025-02-14 09:00:00');
        app(Billing::class)->raiseAll((int) $company->id, Carbon::now());
        $pay($held, 300000, '2025-02-14');
        $wrong = $pay($plain, 200000, '2025-02-14');
        app(Expenses::class)->record($owner, ['property_id' => $p1->id, 'category' => 'Repairs', 'description' => 'Gate', 'amount_minor' => 50000, 'method' => 'cash']);
        app(Expenses::class)->record($owner, ['property_id' => $p2->id, 'category' => 'Rental income tax', 'amount_minor' => 30000, 'method' => 'cash']);
        Carbon::setTestNow('2025-03-20 09:00:00');
        app(Payments::class)->reverse($owner, $wrong, 'Bounced'); // a correction in a later month
        $gate = app(Expenses::class)->record($owner, ['property_id' => $p1->id, 'category' => 'Repairs', 'description' => 'Twice', 'amount_minor' => 10000, 'method' => 'cash']);
        app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 400000, 'method' => 'cash']);
        Carbon::setTestNow('2025-06-02 09:00:00');
        app(Expenses::class)->reverse($owner, $gate, 'Typed twice');
        Carbon::setTestNow('2026-02-10 09:00:00');

        return [(int) $company->id, $owner, $landlord, $other];
    }

    public function test_its_columns_are_the_twelve_statements(): void
    {
        [$cid, $owner, $landlord] = $this->aYear();
        $r = (new RentalReports)->run('landlord_year', $cid, $owner, ['landlord_id' => $landlord->id, 'year' => '2025']);
        $this->assertCount(12, $r['rows']);
        $this->assertSame('January 2025', $r['rows'][0]['month']);
        $this->assertSame('December 2025', $r['rows'][11]['month']);
        $this->assertStringContainsString($landlord->name, $r['subtitle']);
        $sum = array_fill_keys(['collected_minor', 'commission_minor', 'deposits_minor', 'expenses_minor', 'payouts_minor', 'other_minor'], 0);
        foreach ($r['rows'] as $i => $row) {
            $start = Carbon::create(2025, $i + 1, 1);
            $st = LandlordAccounts::statement($landlord, $start->toDateString(), $start->copy()->endOfMonth()->toDateString());
            foreach (array_keys($sum) as $k) {
                $this->assertSame((int) $st['totals'][$k], $row[$k], $row['month'].' '.$k);
                $sum[$k] += (int) $st['totals'][$k];
            }
            $this->assertSame((int) $st['closing_minor'], $row['closing_minor'], $row['month']);
        }
        foreach ($sum as $k => $v) {
            $this->assertSame($v, $r['totals'][$k], $k);
        }
        // And to the journal: opening + the year = closing, every shilling of it this landlord's.
        $this->assertSame(0, $r['totals']['opening_minor']);
        $this->assertSame(LandlordAccounts::balance($landlord, '2025-12-31'), $r['totals']['closing_minor']);
        $this->assertSame($r['totals']['closing_minor'], $sum['collected_minor'] - $sum['commission_minor'] + $sum['deposits_minor'] - $sum['expenses_minor'] - $sum['payouts_minor'] + $sum['other_minor']);
        $this->assertSame(300000, $sum['deposits_minor'], 'the deposit passed to the landlord');
        $this->assertSame(300000 + 200000 + 300000 + 200000 - 200000, $sum['collected_minor'], 'rent less the correction');
        $this->assertBooksAgree($cid);
    }

    public function test_every_new_report_stays_under_the_query_cap(): void
    {
        [$cid, $owner, $landlord] = $this->aYear();
        foreach (['landlord_year' => ['landlord_id' => $landlord->id, 'year' => '2025'], 'money_position' => [], 'journal_export' => ['from' => '2025-01-01', 'to' => '2025-12-31'], 'trial_balance' => ['from' => '2025-01-01', 'to' => '2025-12-31']] as $key => $params) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            (new RentalReports)->run($key, $cid, $owner, $params);
            $this->assertLessThanOrEqual(30, count(DB::getQueryLog()), $key);
            DB::disableQueryLog();
        }
    }

    public function test_by_property_and_by_category_tie_to_the_statements(): void
    {
        [$cid, $owner, $landlord] = $this->aYear();
        $r = (new RentalReports)->run('landlord_year', $cid, $owner, ['landlord_id' => $landlord->id, 'year' => '2025']);
        [$byProperty, $byCategory, $notes] = $r['sections'];

        $this->assertSame(['First Court', 'Second Court'], array_column($byProperty['rows'], 'property'), 'and no one else\'s');
        $this->assertSame($r['totals']['collected_minor'], $byProperty['totals']['total_minor']);
        $first = $byProperty['rows'][0];
        $second = $byProperty['rows'][1];
        $this->assertSame(300000, $first['m01']);
        $this->assertSame(300000, $first['m02']);
        $this->assertSame(200000, $second['m01']);
        $this->assertSame(200000, $second['m02'], 'the receipt that later bounced');
        $this->assertSame(-200000, $second['m03'], 'its correction, in the month of the correction');
        foreach ($r['rows'] as $i => $row) {
            $key = 'm'.sprintf('%02d', $i + 1);
            $this->assertSame($row['collected_minor'], (int) ($byProperty['totals'][$key] ?? 0), $key);
        }

        $this->assertSame([['category' => 'Rental income tax', 'amount_minor' => 30000], ['category' => 'Repairs', 'amount_minor' => 50000]], $byCategory['rows'], 'the reversed one nets out');
        $this->assertSame($r['totals']['expenses_minor'], $byCategory['totals']['amount_minor']);

        $words = implode(' ', array_column($notes['rows'], 'note'));
        $this->assertStringContainsString('not tax advice', $words);
        $this->assertStringContainsString('works out no tax', $words);
        $this->assertStringContainsString('accountant', $words);
        $this->assertStringContainsString('Cash basis', $words);
        $this->assertStringContainsString('"Rental income tax"', $words);
        $this->assertStringNotContainsString('—', $words);
    }

    public function test_a_year_still_running_stops_at_this_month_and_its_opening_is_last_years_closing(): void
    {
        [$cid, $owner, $landlord] = $this->aYear();
        $r = (new RentalReports)->run('landlord_year', $cid, $owner, ['landlord_id' => $landlord->id, 'year' => '2026']);
        $this->assertSame(['January 2026', 'February 2026'], array_column($r['rows'], 'month'));
        $this->assertStringContainsString('to 10 Feb 2026', $r['subtitle']);
        $this->assertSame(LandlordAccounts::balance($landlord, '2025-12-31'), $r['totals']['opening_minor']);
        $this->assertSame(LandlordAccounts::balance($landlord), $r['totals']['closing_minor']);

        $ahead = (new RentalReports)->run('landlord_year', $cid, $owner, ['landlord_id' => $landlord->id, 'year' => '2027']);
        $this->assertSame([], $ahead['rows']);
        $this->assertSame(LandlordAccounts::balance($landlord), $ahead['totals']['closing_minor']);
    }

    public function test_it_needs_a_landlord_of_this_account_and_refuses_a_caretaker(): void
    {
        [$cid, $owner, $landlord] = $this->aYear();
        $this->assertRefused(fn () => (new RentalReports)->run('landlord_year', $cid, $owner, ['year' => '2025']), 'landlord_required');
        [$strangerCompany, $stranger] = $this->rentalsAccount();
        $this->assertRefused(fn () => (new RentalReports)->run('landlord_year', (int) $strangerCompany->id, $stranger, ['landlord_id' => $landlord->id, 'year' => '2025']), 'not_found');
        $company = \App\Models\Company::withoutGlobalScopes()->findOrFail($cid);
        $this->assertRefused(fn () => (new RentalReports)->run('landlord_year', $cid, $this->rentalsStaff($company, 'rentals_caretaker'), ['landlord_id' => $landlord->id]), 'forbidden');
    }

    private function assertRefused(callable $fn, string $code): void
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode());

            return;
        }
        $this->fail("Expected {$code}.");
    }

    public function test_a_landlords_file_holds_only_their_own_and_names_no_one_else(): void
    {
        [$cid, , $landlord, $other] = $this->aYear();
        [$pdf, $pdfType, $pdfName] = LandlordReports::yearFile($landlord, '2025', 'pdf');
        $this->assertSame('application/pdf', $pdfType);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringEndsWith('.pdf', $pdfName);
        [$xlsx, $xlsxType] = LandlordReports::yearFile($landlord, '2025', 'xlsx');
        $this->assertSame(RentalExport::TYPES['xlsx'], $xlsxType);
        $this->assertStringStartsWith('PK', $xlsx);
        $csv = LandlordReports::yearFile($landlord, '2025', 'csv')[0];
        $this->assertStringContainsString('First Court', $csv);
        $this->assertStringNotContainsString('Someone Else Court', $csv);
        $this->assertStringNotContainsString($other->name, $csv);
        $this->assertStringNotContainsString('Estate Ledger', $csv);
        $this->assertStringNotContainsString('Budget Pro', $csv);
        $this->assertStringContainsString('not tax advice', $csv);
    }

    public function test_an_owner_reads_it_in_owners_words(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount('owner', ['commission_type' => 'none']);
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 1, 300000, ['name' => 'My Own Court']);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 300000, 'method' => 'cash', 'paid_on' => '2026-03-05']);
        app(Expenses::class)->record($owner, ['property_id' => $property->id, 'category' => 'Repairs', 'amount_minor' => 20000, 'method' => 'cash']);
        app(Payouts::class)->record($owner, RentalsModule::selfLandlord($cid), ['amount_minor' => 100000, 'method' => 'cash']);

        $this->assertSame(['year'], collect(RentalReports::registry($cid))->firstWhere('key', 'landlord_year')['filters']);
        $r = (new RentalReports)->run('landlord_year', $cid, $owner, ['year' => '2026']);
        $labels = array_column($r['columns'], 'label');
        $this->assertContains('Owner drawings', $labels);
        $this->assertNotContains('Commission', $labels);
        $this->assertNotContains('Paid to the landlord', $labels);
        $this->assertNotContains('Deposits passed to the landlord', $labels);
        $this->assertSame('Year 2026, to 5 Mar 2026', $r['subtitle']);
        $march = $r['rows'][2];
        $this->assertSame([300000, 20000, 100000, 180000], [$march['collected_minor'], $march['expenses_minor'], $march['payouts_minor'], $march['closing_minor']]);
        $this->assertStringContainsString('not tax advice', $r['sections'][2]['rows'][0]['note']);
        $this->assertStringNotContainsString('andlord', RentalExport::csv($r, $cid));
        $this->assertBooksAgree($cid);
    }
}
