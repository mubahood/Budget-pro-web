<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\RentalCharge;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Import\RentalImport;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\RentalExport;
use App\Services\Rentals\Reports\RentalReports;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * The one-page monthly building report and the deposit register, every figure worked out by hand in the test; what the
 * reports say to an owner; and the settings rules the admin screens stand on (one currency, the way of working).
 */
class RentalsAdminReportsTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $localTime): void
    {
        Carbon::setTestNow(Carbon::parse($localTime, 'Africa/Kampala')->utc());
    }

    /** @return array<string, int> */
    private function lines(array $rows): array
    {
        return array_column($rows, 'amount_minor', 'line');
    }

    public function test_the_month_by_hand_and_a_payment_at_23_59_on_its_last_day_is_in_it(): void
    {
        $this->at('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount('owner', ['anchor' => 'first', 'prorate_first' => true]);
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 3, 300000);

        // Tenant A, a whole month: deposit 300,000 + rent 300,000 + garbage 10,000 = 610,000, and 40,000 paid ahead.
        $a = $this->tenantIn($owner, $units[0], '2026-03-01', ['anchor' => 'first', 'deposit_minor' => 300000, 'items' => [['name' => 'Garbage', 'amount_minor' => 10000, 'goes_to' => 'landlord']]]);
        app(Payments::class)->receive($owner, $a, ['amount_minor' => 650000, 'method' => 'cash']);

        // Expense on the 20th, 50,000; deposit refund on the 25th, 100,000.
        $this->at('2026-03-20 10:00:00');
        app(Expenses::class)->record($owner, ['property_id' => $property->id, 'category' => 'Repairs', 'description' => 'Tap', 'amount_minor' => 50000, 'method' => 'cash']);
        $this->at('2026-03-25 10:00:00');
        Deposits::refund($owner, $a, 100000, 'cash', Carbon::parse('2026-03-25'));

        // Tenant B moves in on the 16th: 16 of 31 days of 310,000 = 160,000 of part-month rent, paid at 23:59 on the last day.
        $this->at('2026-03-16 09:00:00');
        $b = $this->tenantIn($owner, $units[1], '2026-03-16', ['anchor' => 'first', 'rent_minor' => 310000, 'deposit_minor' => 0]);
        $this->assertSame(160000, (int) RentalCharge::where('lease_id', $b->id)->where('kind', 'rent')->value('amount_minor'));
        $this->at('2026-03-31 23:59:00');
        app(Payments::class)->receive($owner, $b, ['amount_minor' => 160000, 'method' => 'mobile_money']);
        app(Expenses::class)->record($owner, ['property_id' => $property->id, 'category' => 'Bank charges', 'amount_minor' => 5000, 'method' => 'cash']);

        // One minute later it is April: 20,000 more paid ahead belongs to April, not March.
        $this->at('2026-04-01 00:00:00');
        app(Payments::class)->receive($owner, $a, ['amount_minor' => 20000, 'method' => 'cash']);

        $r = (new RentalReports)->run('monthly_building', $cid, $owner, ['month' => '2026-03']);

        $this->assertSame('2026-03-01', $r['from']);
        $this->assertSame('2026-03-31', $r['to']);
        $this->assertSame(['Rent' => 300000, 'Part-month rent' => 160000, 'Garbage' => 10000], $this->lines($r['rows']));
        $this->assertSame(470000, $r['totals']['amount_minor']);
        $this->assertSame(['Repairs', 'Bank charges'], array_column($r['sections'][0]['rows'], 'category'));
        $this->assertSame(55000, $r['sections'][0]['totals']['amount_minor']);
        $this->assertSame(['Total income' => 470000, 'Less expenses' => -55000], $this->lines($r['sections'][1]['rows']));
        $this->assertSame(415000, $r['sections'][1]['totals']['amount_minor'], 'income 470,000 less expenses 55,000');
        $this->assertSame(415000, $r['figures']['final_balance_minor']);
        $this->assertSame(['Deposits received' => 300000, 'Deposits used for what was owed' => 0, 'Deposits refunded' => 100000,
            'Deposits held at the end of the month' => 200000, 'Paid ahead by tenants, less what was used' => 40000], $this->lines($r['sections'][2]['rows']));

        // Everything accounted for: 470,000 + 300,000 − 100,000 + 40,000 − 55,000 = the journal's cash up to the last day.
        $cash = (int) DB::table('rental_entries')->where('company_id', $cid)->whereBetween('occurred_on', ['2026-03-01', '2026-03-31'])->sum('cash_minor');
        $this->assertSame(655000, $cash);

        // April holds only the new money, and March's deposit still held; the deposit never reaches income.
        $april = (new RentalReports)->run('monthly_building', $cid, $owner, ['month' => '2026-04']);
        $this->assertSame(0, $april['figures']['income_minor']);
        $this->assertSame(20000, $april['figures']['paid_ahead_minor']);
        $this->assertSame(200000, $april['figures']['deposits_held_minor']);

        // A reversal counts in the month it was made, and takes the income back out.
        $this->at('2026-04-02 09:00:00');
        $paidB = \App\Models\RentalPayment::where('lease_id', $b->id)->firstOrFail();
        app(Payments::class)->reverse($owner, $paidB, 'Wrong tenant');
        $april = (new RentalReports)->run('monthly_building', $cid, $owner, ['month' => '2026-04']);
        $this->assertSame(['Part-month rent' => -160000], $this->lines($april['rows']));
        $this->assertSame(415000, (new RentalReports)->run('monthly_building', $cid, $owner, ['month' => '2026-03'])['figures']['final_balance_minor']);

        $this->assertBooksAgree($cid);
    }

    public function test_an_agency_month_takes_off_commission_and_kept_fees_and_leaves_its_own_costs_out(): void
    {
        $this->at('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$p1, $u1] = $this->building($owner, 1, 300000);
        [$p2, $u2] = $this->building($owner, 1, 200000);
        $l1 = $this->tenantIn($owner, $u1[0], '2026-03-01', ['deposit_minor' => 0, 'items' => [['name' => 'Service', 'amount_minor' => 10000, 'goes_to' => 'agency']]]);
        $l2 = $this->tenantIn($owner, $u2[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $l1, ['amount_minor' => 310000, 'method' => 'cash']);
        app(Payments::class)->receive($owner, $l2, ['amount_minor' => 200000, 'method' => 'cash']);
        app(Expenses::class)->record($owner, ['property_id' => $p1->id, 'category' => 'Meals', 'amount_minor' => 7000, 'method' => 'cash']);
        app(Expenses::class)->record($owner, ['category' => 'Agency office', 'amount_minor' => 9000, 'method' => 'cash', 'charged_to' => 'agency']);

        // All: income 300,000 + 10,000 + 200,000 = 510,000; commission 10 % of rent = 50,000; 10,000 kept; expense 7,000.
        $all = (new RentalReports)->run('monthly_building', $cid, $owner, ['month' => '2026-03']);
        $this->assertSame(510000, $all['figures']['income_minor']);
        $this->assertSame(50000, $all['figures']['commission_minor']);
        $this->assertSame(10000, $all['figures']['fees_kept_minor']);
        $this->assertSame(7000, $all['figures']['expenses_minor']);
        $this->assertSame(443000, $all['figures']['final_balance_minor']);
        $this->assertStringContainsString('All properties', $all['subtitle']);

        // One building: 310,000 − 30,000 − 10,000 − 7,000 = 263,000; the other: 200,000 − 20,000 = 180,000.
        $one = (new RentalReports)->run('monthly_building', $cid, $owner, ['month' => '2026-03', 'property_id' => $p1->id]);
        $this->assertSame(263000, $one['figures']['final_balance_minor']);
        $two = (new RentalReports)->run('monthly_building', $cid, $owner, ['month' => '2026-03', 'property_id' => $p2->id]);
        $this->assertSame(180000, $two['figures']['final_balance_minor']);
        $this->assertStringContainsString($p2->name, $two['subtitle']);

        // A caretaker's "all" is their own property, and no other building's money or costs.
        $caretaker = $this->rentalsStaff($company, 'rentals_caretaker');
        app(\App\Services\Rentals\Directory\Properties::class)->assignStaff($owner, $p2, [(int) $caretaker->id]);
        DB::table('company_role_permissions')->insert(['company_id' => $cid, 'role' => 'rentals_caretaker', 'permission' => 'rentals_reports', 'allowed' => 1, 'created_at' => now(), 'updated_at' => now()]);
        \App\Services\Team\Permissions::flush();
        $theirs = (new RentalReports)->run('monthly_building', $cid, $caretaker, ['month' => '2026-03']);
        $this->assertSame(180000, $theirs['figures']['final_balance_minor']);
    }

    public function test_the_monthly_report_exports_and_prints_on_one_page_however_long_the_list(): void
    {
        $this->at('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount('owner');
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 1, 900000);
        $l = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $l, ['amount_minor' => 900000, 'method' => 'cash']);
        foreach (range(1, 60) as $i) {
            app(Expenses::class)->record($owner, ['property_id' => $property->id, 'category' => $i % 2 ? 'Repairs' : 'Meals', 'description' => "Line {$i}", 'amount_minor' => 1000, 'method' => 'cash']);
        }
        $r = (new RentalReports)->run('monthly_building', $cid, $owner, ['month' => '2026-03']);
        $this->assertSame(840000, $r['figures']['final_balance_minor']);

        $csv = RentalExport::csv($r, $cid);
        $this->assertStringContainsString('"Total income",900000', $csv);
        $this->assertStringContainsString('Total expenses', $csv);
        $this->assertStringContainsString('"Balance for the owner",840000', $csv);
        $this->assertStringContainsString('Line 60', $csv, 'the CSV keeps every expense');

        $pdf = RentalExport::pdf($r, $cid);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(1, preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf), 'one sheet of paper');

        // With an empty month every format still opens.
        $empty = (new RentalReports)->run('monthly_building', $cid, $owner, ['month' => '2026-01']);
        $this->assertSame(0, $empty['figures']['final_balance_minor']);
        $this->assertStringStartsWith('%PDF', RentalExport::pdf($empty, $cid));
        foreach (['2026-13', 'March', '2026-3'] as $bad) {
            try {
                (new RentalReports)->run('monthly_building', $cid, $owner, ['month' => $bad]);
                $this->fail("{$bad} should be refused");
            } catch (BusinessRuleException $e) {
                $this->assertSame('invalid_date', $e->errorCode());
            }
        }
    }

    public function test_the_deposit_register_by_hand_and_a_forgiven_deposit_was_never_received(): void
    {
        $this->at('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [, $units] = $this->building($owner, 3, 300000);
        $a = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 300000, 'tenant' => ['name' => 'Asha', 'phone' => '0701000001']]);
        $b = $this->tenantIn($owner, $units[1], '2026-03-01', ['deposit_minor' => 200000, 'tenant' => ['name' => 'Bosco', 'phone' => '0701000002']]);
        $c = $this->tenantIn($owner, $units[2], '2026-03-01', ['deposit_minor' => 100000, 'tenant' => ['name' => 'Cleo', 'phone' => '0701000003']]);
        app(Payments::class)->receive($owner, $a, ['amount_minor' => 600000, 'method' => 'cash']);
        Deposits::refund($owner, $a, 50000, 'cash', Carbon::parse('2026-03-01'));
        app(Payments::class)->receive($owner, $b, ['amount_minor' => 150000, 'method' => 'cash']);
        // Bosco's rent is still open; 30,000 of what is held pays it at move-out.
        app(Payments::class)->useDeposit($b, 30000, Carbon::parse('2026-03-01'), (int) $owner->id);
        app(Payments::class)->waive($owner, RentalCharge::where('lease_id', $c->id)->where('kind', 'deposit')->firstOrFail(), 100000, 'Family friend');

        $r = (new RentalReports)->run('deposit_register', $cid, $owner);
        $rows = [];
        foreach ($r['rows'] as $row) {
            $rows[$row['tenant']] = [$row['agreed_minor'], $row['received_minor'], $row['used_minor'], $row['refunded_minor'], $row['held_minor']];
        }
        $this->assertSame(['Asha' => [300000, 300000, 0, 50000, 250000], 'Bosco' => [200000, 150000, 30000, 0, 120000], 'Cleo' => [100000, 0, 0, 0, 0]], $rows);
        $this->assertSame(['agreed_minor' => 600000, 'received_minor' => 450000, 'used_minor' => 30000, 'refunded_minor' => 50000, 'held_minor' => 370000, 'leases' => 3], $r['totals']);
        foreach ([$a, $b, $c] as $i => $lease) {
            $this->assertSame([250000, 120000, 0][$i], Deposits::held($lease->fresh()), 'the register agrees with the deposit service');
        }
        $this->assertBooksAgree($cid);
    }

    public function test_an_owner_reads_no_landlord_or_agency_words_in_any_report(): void
    {
        $this->at('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount('owner');
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 2, 300000);
        $l = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 300000]);
        app(Payments::class)->receive($owner, $l, ['amount_minor' => 900000, 'method' => 'cash']);
        app(Expenses::class)->record($owner, ['property_id' => $property->id, 'category' => 'Repairs', 'amount_minor' => 5000, 'method' => 'cash']);
        app(Payouts::class)->record($owner, RentalsModule::selfLandlord($cid), ['amount_minor' => 10000, 'method' => 'cash']);

        foreach (RentalReports::registry($cid) as $entry) {
            $this->assertStringNotContainsStringIgnoringCase('landlord', $entry['title'].' '.$entry['description'], $entry['key']);
            $params = $entry['key'] === 'tenant_statement' ? ['lease_id' => $l->id] : [];
            $csv = RentalExport::csv((new RentalReports)->run($entry['key'], $cid, $owner, $params + ['from' => '2026-03-01', 'to' => '2026-03-31', 'month' => '2026-03']), $cid);
            $this->assertDoesNotMatchRegularExpression('/landlord|agency|commission|payout/i', $csv, $entry['key']);
        }
    }

    public function test_the_currency_stays_one_with_the_company_and_locks_at_the_first_payment(): void
    {
        $this->at('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $module = app(RentalsModule::class);

        // Same way of counting: allowed, and every figure on screen follows.
        $module->saveSettings($company, $owner, ['currency' => 'kes']);
        $this->assertSame('KES', RentalsModule::settings((int) $company->id)->currency);
        $this->assertSame('KES', Company::withoutGlobalScopes()->find($company->id)->currency);
        $this->assertSame('KES', Money::symbol((int) $company->id));

        [, $units] = $this->building($owner, 1, 300000);
        $this->refusal('currency_locked', fn () => $module->saveSettings($company, $owner, ['currency' => 'USD']), 'the rent would read as 3,000.00');
        $this->refusal('invalid', fn () => $module->saveSettings($company, $owner, ['currency' => 'XXX']));

        $l = $this->tenantIn($owner, $units[0], '2026-03-01');
        app(Payments::class)->receive($owner, $l, ['amount_minor' => 1000, 'method' => 'cash']);
        $this->refusal('currency_locked', fn () => $module->saveSettings($company, $owner, ['currency' => 'UGX']));
        $this->assertSame('KES', Company::withoutGlobalScopes()->find($company->id)->currency);
        $module->saveSettings($company, $owner, ['currency' => 'KES', 'grace_days' => 9]); // the same currency again is no change
        $this->assertSame(9, RentalsModule::settings((int) $company->id)->grace_days);
    }

    public function test_the_way_of_working_can_change_only_before_the_first_tenant(): void
    {
        $this->at('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        $module = app(RentalsModule::class);
        [$property, $units] = $this->building($owner, 1, 300000);

        // One landlord and no tenancy: the property becomes the owner's.
        $this->assertNull(RentalsModule::modeLock($cid, 'owner'));
        $module->saveSettings($company, $owner, ['mode' => 'owner']);
        $this->assertSame((int) RentalsModule::selfLandlord($cid)->id, (int) $property->fresh()->landlord_id);
        $module->saveSettings($company, $owner, ['mode' => 'agency']);

        // Several landlords stay an agency.
        $this->building($owner, 1, 100000);
        $this->building($owner, 1, 100000);
        $this->refusal('mode_locked', fn () => $module->saveSettings($company, $owner, ['mode' => 'owner']));

        // A tenant on the books fixes it either way.
        $this->tenantIn($owner, $units[0], '2026-03-01');
        $this->assertNotNull(RentalsModule::modeLock($cid, 'owner'));
        $this->refusal('mode_locked', fn () => $module->saveSettings($company, $owner, ['mode' => 'owner']));
    }

    public function test_templates_for_an_owner_have_no_landlord_columns_and_the_categories_are_there(): void
    {
        $this->assertContains('landlords', array_keys(RentalImport::templates()));
        $this->assertNotContains('landlords', array_keys(RentalImport::templates(true)));
        $this->assertStringNotContainsString('landlord', RentalImport::templateCsv('properties', true));
        $this->assertStringContainsString('landlord_name', RentalImport::templateCsv('properties'));
        foreach (['Bank charges', 'Meals'] as $category) {
            $this->assertContains($category, Expenses::categories());
        }
    }

    private function refusal(string $code, callable $fn, string $why = ''): void
    {
        try {
            $fn();
            $this->fail("Expected {$code}. {$why}");
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode(), $e->getMessage());
        }
    }
}
