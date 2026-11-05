<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Reports\MoneyPosition;
use App\Services\Rentals\Reports\RentalExport;
use App\Services\Rentals\Reports\RentalReports;
use App\Services\Team\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * The report `money_position` (LC-12): its lines sum to the cash, its cash is the journal's, it reads any day, it says plainly when the agency has spent money
 * held for others, and a caretaker never gets it, whatever their role was given. Owner mode has none.
 */
class RentalsMoneyPositionReportTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: int, 1: \App\Models\User} */
    private function world(): array
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [, $units] = $this->building($owner, 2, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 200000]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 550000, 'method' => 'cash', 'paid_on' => '2026-03-02']);
        app(CashBook::class)->transfer($owner, 'cash', 'bank', 100000, '2026-03-03');

        return [(int) $company->id, $owner];
    }

    public function test_its_lines_add_up_to_the_cash_of_the_journal(): void
    {
        [$cid, $owner] = $this->world();
        $r = (new RentalReports)->run('money_position', $cid, $owner, ['as_of' => '2026-03-05']);
        $this->assertSame('As of 5 Mar 2026', $r['subtitle']);
        $this->assertSame(['Owed to landlords', 'Landlords owe you (advances)', 'Deposits you hold', 'Tenants\' rent paid ahead', 'The agency\'s own money'], array_column($r['rows'], 'line'));
        $amounts = array_column($r['rows'], 'amount_minor');
        $this->assertSame([270000, 0, 200000, 50000, 30000], $amounts);
        $this->assertSame((int) DB::table('rental_entries')->where('company_id', $cid)->sum('cash_minor'), array_sum($amounts));
        $this->assertSame(array_sum($amounts), $r['totals']['amount_minor']);
        $this->assertSame(520000, $r['totals']['held_for_others_minor']);
        $this->assertSame('Cash in all accounts', $r['total_label']);

        $where = $r['sections'][0];
        $this->assertSame([['account' => 'Cash', 'amount_minor' => 450000], ['account' => 'Bank', 'amount_minor' => 100000]], $where['rows']);
        $this->assertStringContainsString('Deposits a landlord holds', $r['sections'][1]['rows'][0]['note']);
        $this->assertBooksAgree($cid);
    }

    public function test_it_reads_any_day(): void
    {
        [$cid, $owner] = $this->world();
        $before = (new RentalReports)->run('money_position', $cid, $owner, ['as_of' => '2026-03-01']);
        $this->assertSame(0, $before['totals']['amount_minor']);
        $this->assertSame(['2026-03-01', '2026-03-01'], [$before['from'], $before['to']]);
        $day = (new RentalReports)->run('money_position', $cid, $owner, ['as_of' => '2026-03-02']);
        $this->assertSame(MoneyPosition::asOf($cid, '2026-03-02')['cash_minor'], $day['totals']['amount_minor']);
        $this->assertSame(550000, $day['totals']['amount_minor']);
        $this->expectException(BusinessRuleException::class);
        (new RentalReports)->run('money_position', $cid, $owner, ['as_of' => 'yesterday']);
    }

    public function test_it_says_so_when_the_agency_has_spent_money_held_for_others(): void
    {
        [$cid, $owner] = $this->world();
        app(Expenses::class)->record($owner, ['category' => 'Agency office', 'amount_minor' => 40000, 'method' => 'cash', 'charged_to' => 'agency']);
        $r = (new RentalReports)->run('money_position', $cid, $owner, []);
        $own = collect($r['rows'])->firstWhere('line', 'The agency\'s own money');
        $this->assertSame(-10000, $own['amount_minor']);
        $this->assertSame('You have spent UGX 10,000 of money held for others.', $r['sections'][1]['rows'][0]['note']);
        $this->assertStringContainsString('You have spent UGX 10,000', RentalExport::csv($r, $cid));
        $this->assertBooksAgree($cid);
    }

    public function test_a_caretaker_never_gets_it_whatever_their_role_was_given(): void
    {
        [$cid, $owner] = $this->world();
        $company = \App\Models\Company::withoutGlobalScopes()->findOrFail($cid);
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        DB::table('company_role_permissions')->insert(['company_id' => $cid, 'role' => 'rentals_caretaker', 'permission' => 'rentals_reports', 'allowed' => true, 'created_at' => now(), 'updated_at' => now()]);
        Permissions::flush();
        try {
            (new RentalReports)->run('money_position', $cid, $caretaker, []);
            $this->fail('A caretaker read the whole account\'s money.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('forbidden', $e->errorCode());
        }
    }

    public function test_it_is_the_agencys_own_report(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount('owner');
        $this->assertNotContains('money_position', array_column(RentalReports::registry((int) $company->id), 'key'));
        try {
            (new RentalReports)->run('money_position', (int) $company->id, $owner, []);
            $this->fail('An owner has no such report.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('agency_only', $e->errorCode());
        }
    }
}
