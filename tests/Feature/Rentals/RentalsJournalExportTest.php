<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\RentalEntry;
use App\Models\User;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\MoneyPosition;
use App\Services\Rentals\Reports\RentalExport;
use App\Services\Rentals\Reports\RentalReports;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;
use ZipArchive;

/**
 * A file for the accountant (LC-19): one line per posting per account, debits equal credits over every posting and overall, the trial balance closes on what the
 * money position says, reversals show as their own lines, an open period is stamped draft, and a cell a spreadsheet would run is made harmless. Rights are the
 * reports' own, and a caretaker never gets it.
 */
class RentalsJournalExportTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Every kind of posting across two months: [cid, owner, landlord]. @return array{0: int, 1: User, 2: \App\Models\RentalLandlord} */
    private function books(): array
    {
        Carbon::setTestNow('2026-02-20 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 2, 300000, ['name' => 'Court A']);
        $landlord = $this->landlordOf($property);
        app(CashBook::class)->opening($owner, 'cash', 500000, Carbon::parse('2026-02-01'));
        $lease = $this->tenantIn($owner, $units[0], '2026-02-01', ['deposit_minor' => 200000]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 550000, 'method' => 'cash', 'paid_on' => '2026-02-20']);

        Carbon::setTestNow('2026-03-10 09:00:00');
        app(CashBook::class)->transfer($owner, 'cash', 'bank', 100000, '2026-03-02', 'Banked');
        $bounced = app(Payments::class)->receive($owner, $this->tenantIn($owner, $units[1], '2026-03-01', ['deposit_minor' => 0]), ['amount_minor' => 300000, 'method' => 'mobile_money', 'paid_on' => '2026-03-03']);
        $payout = app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 120000, 'method' => 'cash']);
        app(Expenses::class)->record($owner, ['property_id' => $property->id, 'category' => 'Repairs', 'description' => 'Gate', 'amount_minor' => 20000, 'method' => 'cash']);
        app(Expenses::class)->record($owner, ['category' => 'Agency office', 'description' => 'Paper', 'amount_minor' => 5000, 'method' => 'cash', 'charged_to' => 'agency']);
        $drawing = app(CashBook::class)->agencyDrawing($owner, 'cash', 7000, '2026-03-10', 'Share');
        app(Payments::class)->reverse($owner, $bounced, 'Bounced');
        app(Payouts::class)->reverse($owner, $payout, 'Typed wrong');
        app(CashBook::class)->reverseDrawing($owner, (int) $drawing->id, 'Not yet');

        return [$cid, $owner, $landlord];
    }

    private function landlordOf($property)
    {
        return \App\Models\RentalLandlord::withoutGlobalScopes()->findOrFail($property->landlord_id);
    }

    private const RANGE = ['from' => '2026-03-01', 'to' => '2026-03-31'];

    public function test_one_line_per_posting_per_account_with_debits_equal_to_credits_over_every_group(): void
    {
        [$cid, $owner] = $this->books();
        $r = (new RentalReports)->run('journal_export', $cid, $owner, self::RANGE);
        $this->assertSame(['date', 'group', 'number', 'description', 'account', 'debit_minor', 'credit_minor', 'landlord', 'property'], array_column($r['columns'], 'key'));

        $entries = RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->whereBetween('occurred_on', ['2026-03-01', '2026-03-31'])->get();
        $lines = 0;
        foreach ($entries as $e) {
            foreach (RentalEntry::EFFECTS as $col) {
                $lines += (int) $e->{$col} !== 0 ? 1 : 0;
            }
        }
        $this->assertCount($lines, $r['rows'], 'one line per posting per account');

        $groups = [];
        foreach ($r['rows'] as $row) {
            $this->assertSame(0, min($row['debit_minor'], $row['credit_minor']), 'a line is a debit or a credit');
            $this->assertGreaterThan(0, $row['debit_minor'] + $row['credit_minor']);
            $groups[$row['group']] = ($groups[$row['group']] ?? 0) + $row['debit_minor'] - $row['credit_minor'];
        }
        $this->assertSame($entries->pluck('group_id')->unique()->count(), count($groups));
        $this->assertSame([0], array_values(array_unique($groups)), 'every posting, transfers too, nets to zero');

        $this->assertSame(array_sum(array_column($r['rows'], 'debit_minor')), $r['totals']['debit_minor']);
        $this->assertSame(array_sum(array_column($r['rows'], 'credit_minor')), $r['totals']['credit_minor']);
        $this->assertSame($r['totals']['debit_minor'], $r['totals']['credit_minor']);
        $this->assertSame(0, $r['totals']['difference_minor']);
        $this->assertStringContainsString('debits', $r['sections'][0]['rows'][0]['note']);
        $this->assertStringContainsString('equal credits', $r['sections'][0]['rows'][0]['note']);
        $this->assertBooksAgree($cid);
    }

    public function test_signs_follow_the_chart_and_each_line_is_the_journal(): void
    {
        [$cid, $owner] = $this->books();
        $r = (new RentalReports)->run('journal_export', $cid, $owner, self::RANGE);
        $cash = RentalEntry::withoutGlobalScopes()->where('company_id', $cid)->whereBetween('occurred_on', ['2026-03-01', '2026-03-31'])->get();
        $net = fn (string $label) => array_sum(array_map(fn ($row) => $row['account'] === $label ? $row['debit_minor'] - $row['credit_minor'] : 0, $r['rows']));
        $this->assertSame((int) $cash->sum('cash_minor'), $net('Cash and bank'), 'cash +: a debit');
        $this->assertSame((int) $cash->sum('expense_minor'), $net('Agency expenses'), 'expense +: a debit');
        $this->assertSame(-(int) $cash->sum('landlord_minor'), $net('Owed to landlords'), 'landlord +: a credit');
        $this->assertSame(-(int) $cash->sum('income_minor'), $net('Income'), 'income +: a credit');
        $this->assertSame(-(int) $cash->sum('equity_minor'), $net('The agency\'s own money'), 'equity +: a credit');

        $gate = collect($r['rows'])->firstWhere('description', 'Repairs: Gate');
        $this->assertNotNull($gate['number']);
        $this->assertSame('Court A', $gate['property']);
        $this->assertNotNull($gate['landlord']);
    }

    public function test_a_reversal_is_its_own_lines_and_the_original_stays(): void
    {
        [$cid, $owner] = $this->books();
        $r = (new RentalReports)->run('journal_export', $cid, $owner, self::RANGE);
        $drawing = collect($r['rows'])->filter(fn ($row) => str_starts_with((string) $row['description'], 'Taken out by the agency'))->values();
        $back = collect($r['rows'])->filter(fn ($row) => $row['description'] === 'Reversal: Not yet')->values();
        $this->assertCount(2, $drawing);
        $this->assertCount(2, $back);
        $cashOut = $drawing->firstWhere('account', 'Cash and bank');
        $cashBack = $back->firstWhere('account', 'Cash and bank');
        $this->assertSame([0, 7000], [$cashOut['debit_minor'], $cashOut['credit_minor']]);
        $this->assertSame([7000, 0], [$cashBack['debit_minor'], $cashBack['credit_minor']]);
    }

    public function test_the_trial_balance_closes_on_the_money_position_and_the_journal(): void
    {
        [$cid, $owner, $landlord] = $this->books();
        $t = (new RentalReports)->run('trial_balance', $cid, $owner, self::RANGE);
        $by = collect($t['rows'])->keyBy('account');
        $close = fn (string $label) => (int) $by[$label]['closing_debit_minor'] - (int) $by[$label]['closing_credit_minor'];
        $p = MoneyPosition::asOf($cid, '2026-03-31');
        $this->assertSame($p['cash_minor'], $close('Cash and bank'));
        $this->assertSame($p['owed_to_landlords_minor'] - $p['owed_back_by_landlords_minor'], -$close('Owed to landlords'));
        $this->assertSame($p['deposits_minor'], -$close('Deposits held'));
        $this->assertSame($p['credit_minor'], -$close('Tenants\' money paid ahead'));
        $this->assertSame($p['agency_own_minor'], -$close('Income') - $close('Agency expenses') - $close('The agency\'s own money'));

        $open = MoneyPosition::asOf($cid, '2026-02-28');
        $this->assertSame($open['cash_minor'], (int) $by['Cash and bank']['opening_debit_minor'] - (int) $by['Cash and bank']['opening_credit_minor']);

        $this->assertSame($t['totals']['opening_debit_minor'], $t['totals']['opening_credit_minor']);
        $this->assertSame($t['totals']['debit_minor'], $t['totals']['credit_minor']);
        $this->assertSame($t['totals']['closing_debit_minor'], $t['totals']['closing_credit_minor']);

        $j = (new RentalReports)->run('journal_export', $cid, $owner, self::RANGE);
        $this->assertSame($j['totals']['debit_minor'], $t['totals']['debit_minor'], 'the same movement as the journal');

        $section = $t['sections'][0];
        $this->assertSame('Owed to landlords, by landlord', $section['title']);
        $this->assertSame([$landlord->name], array_column($section['rows'], 'landlord'));
        $this->assertSame(-$close('Owed to landlords'), $section['totals']['closing_minor']);
        $this->assertSame($by['Owed to landlords']['debit_minor'], $section['totals']['debit_minor']);
        $this->assertSame($by['Owed to landlords']['credit_minor'], $section['totals']['credit_minor']);
        $this->assertBooksAgree($cid);
    }

    public function test_an_open_period_is_stamped_draft_and_a_closed_one_is_not(): void
    {
        [$cid, $owner] = $this->books();
        foreach (['journal_export', 'trial_balance'] as $key) {
            $open = (new RentalReports)->run($key, $cid, $owner, self::RANGE);
            $this->assertStringContainsString('DRAFT', $open['subtitle'], $key);
            $this->assertStringContainsString('DRAFT', RentalExport::csv($open, $cid), $key);
        }
        Carbon::setTestNow('2026-04-02 09:00:00');
        RentalsModule::settings($cid)->forceFill(['closed_through' => '2026-03-31'])->save();
        foreach (['journal_export', 'trial_balance'] as $key) {
            $closed = (new RentalReports)->run($key, $cid, $owner, self::RANGE);
            $this->assertStringNotContainsString('DRAFT', $closed['subtitle'], $key);
            $this->assertStringNotContainsString('DRAFT', RentalExport::csv($closed, $cid), $key);
        }
        $spans = (new RentalReports)->run('journal_export', $cid, $owner, ['from' => '2026-03-01', 'to' => '2026-04-02']);
        $this->assertStringContainsString('DRAFT', $spans['subtitle'], 'a period running past the close is still open');
    }

    public function test_it_states_no_tax_and_says_an_accountant_should_check_it(): void
    {
        [$cid, $owner] = $this->books();
        foreach (['journal_export', 'trial_balance'] as $key) {
            $r = (new RentalReports)->run($key, $cid, $owner, self::RANGE);
            $notes = implode(' ', array_column(collect($r['sections'])->firstWhere('title', 'Notes')['rows'], 'note'));
            $this->assertStringContainsString('states no tax rule and works out no tax', $notes);
            $this->assertStringContainsString('Ask your accountant to check it', $notes);
            $csv = RentalExport::csv($r, $cid);
            $this->assertStringContainsString('Ask your accountant', $csv);
            $this->assertStringNotContainsString('Estate Ledger', $csv);
        }
    }

    public function test_a_cell_a_spreadsheet_would_run_is_made_harmless(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        $landlord = app(Landlords::class)->create($owner, ['name' => '=HYPERLINK("http://x","owed")', 'phone' => '0772100200']);
        $property = app(Properties::class)->create($owner, ['name' => '@SUM(A1:A9)', 'landlord_id' => $landlord->id, 'kind' => 'residential']);
        $units = app(Units::class)->createMany($owner, (int) $property->id, 'A', 1, 1, ['rent_minor' => 100000, 'kind' => 'apartment']);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash', 'paid_on' => '2026-03-05']);
        app(Expenses::class)->record($owner, ['property_id' => $property->id, 'category' => 'Repairs', 'description' => '-1+1+cmd', 'amount_minor' => 1000, 'method' => 'cash']);
        app(CashBook::class)->agencyDrawing($owner, 'cash', 500, null, '+evil', true);
        RentalsModule::settings($cid)->forceFill(['export_accounts' => ['cash' => '=1+1']])->save();

        foreach (['journal_export', 'trial_balance', 'money_position'] as $key) {
            $csv = RentalExport::csv((new RentalReports)->run($key, $cid, $owner, ['from' => '2026-03-01', 'to' => '2026-03-31']), $cid);
            $handle = fopen('php://memory', 'r+');
            fwrite($handle, substr($csv, 3));
            rewind($handle);
            while (($cells = fgetcsv($handle)) !== false) {
                foreach ($cells as $cell) {
                    if ($cell !== null && $cell !== '' && ! is_numeric($cell)) {
                        $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', (string) $cell, $key.': '.$cell);
                    }
                }
            }
            fclose($handle);
        }
        $csv = RentalExport::csv((new RentalReports)->run('journal_export', $cid, $owner, ['from' => '2026-03-01', 'to' => '2026-03-31']), $cid);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'@SUM", $csv);
        $this->assertStringContainsString("'=1+1", $csv);

        // Excel writes text as text, never a formula.
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test');
        file_put_contents($path, RentalExport::xlsx((new RentalReports)->run('journal_export', $cid, $owner, ['from' => '2026-03-01', 'to' => '2026-03-31']), $cid));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'xl/worksheets/')) {
                $this->assertStringNotContainsString('<f>', (string) $zip->getFromIndex($i), $name);
            }
        }
        $zip->close();
        unlink($path);
        $this->assertBooksAgree($cid);
    }

    public function test_account_codes_print_beside_each_account_only_when_set(): void
    {
        [$cid, $owner] = $this->books();
        $this->assertNotContains('code', array_column((new RentalReports)->run('journal_export', $cid, $owner, self::RANGE)['columns'], 'key'));
        app(RentalsModule::class)->saveSettings(Company::withoutGlobalScopes()->findOrFail($cid), $owner, ['export_accounts' => ['cash' => '1000', 'income' => '4000']]);
        $j = (new RentalReports)->run('journal_export', $cid, $owner, self::RANGE);
        $this->assertContains('code', array_column($j['columns'], 'key'));
        $this->assertSame('1000', collect($j['rows'])->firstWhere('account', 'Cash and bank')['code']);
        $this->assertNull(collect($j['rows'])->firstWhere('account', 'Owed to landlords')['code']);
        $t = (new RentalReports)->run('trial_balance', $cid, $owner, self::RANGE);
        $this->assertSame('4000', collect($t['rows'])->firstWhere('account', 'Income')['code']);
    }

    public function test_rights_are_the_reports_own_and_a_caretaker_never_gets_it(): void
    {
        [$cid, $owner] = $this->books();
        $company = Company::withoutGlobalScopes()->findOrFail($cid);
        foreach (['journal_export', 'trial_balance'] as $key) {
            foreach (['rentals_manager', 'rentals_accountant', 'viewer'] as $role) {
                $this->assertSame($key, (new RentalReports)->run($key, $cid, $this->rentalsStaff($company, $role), self::RANGE)['key'], $role);
            }
            foreach (['rentals_caretaker'] as $role) {
                try {
                    (new RentalReports)->run($key, $cid, $this->rentalsStaff($company, $role), self::RANGE);
                    $this->fail($role.' read '.$key);
                } catch (BusinessRuleException $e) {
                    $this->assertSame('forbidden', $e->errorCode());
                }
            }
            [, $stranger] = $this->rentalsAccount();
            try {
                (new RentalReports)->run($key, $cid, $stranger, self::RANGE);
                $this->fail('Another account read '.$key);
            } catch (BusinessRuleException $e) {
                $this->assertSame('not_found', $e->errorCode());
            }
        }
    }

    public function test_an_owner_gets_it_with_no_landlords_in_it(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount('owner', ['commission_type' => 'none']);
        $cid = (int) $company->id;
        [$property, $units] = $this->building($owner, 1, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 300000, 'method' => 'cash', 'paid_on' => '2026-03-05']);
        $j = (new RentalReports)->run('journal_export', $cid, $owner, ['from' => '2026-03-01', 'to' => '2026-03-31']);
        $this->assertSame($j['totals']['debit_minor'], $j['totals']['credit_minor']);
        $this->assertNotContains('landlord', array_column($j['columns'], 'key'));
        $this->assertStringNotContainsString('andlord', RentalExport::csv($j, $cid));
        $t = (new RentalReports)->run('trial_balance', $cid, $owner, ['from' => '2026-03-01', 'to' => '2026-03-31']);
        $this->assertSame(['Notes'], array_column($t['sections'], 'title'));
        $this->assertStringNotContainsString('andlord', RentalExport::csv($t, $cid));
        $this->assertBooksAgree($cid);
    }
}
