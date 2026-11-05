<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\ApiPermissionMap;
use App\Models\RentalLandlord;
use App\Models\RentalPayout;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\RentalLinks;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\RentalExport;
use App\Services\Rentals\Reports\RentalReports;
use App\Services\Team\Permissions;
use App\Support\Rules\TeamRules;
use App\Support\ShopMenu;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * What the landlord and commission work stands on: who holds the new permissions, the reports and which class owns each, the settings it added, its links and papers.
 */
class RentalsLandlordWorkPlumbingTest extends ApiTestCase
{
    use MakesRentals;

    private const THE_EIGHTEEN = ['monthly_building', 'rent_roll', 'collections', 'arrears_aging', 'occupancy', 'landlord_statement', 'commission', 'expenses', 'deposits_held',
        'deposit_register', 'cash_book', 'tenant_statement', 'lease_expiries', 'maintenance', 'income_statement', 'tenant_list', 'lease_list', 'receipts'];

    private const NEW_REPORTS = ['landlord_rent_sheet', 'landlord_advances', 'money_position', 'landlord_year', 'journal_export', 'trial_balance'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_new_permissions_and_who_holds_them(): void
    {
        [$company] = $this->rentalsAccount();
        $this->assertContains('rentals_close', Permissions::availableFor((int) $company->id));
        $this->assertContains('rentals_reopen', Permissions::availableFor((int) $company->id));
        foreach (['rentals_manager' => true, 'rentals_accountant' => true, 'rentals_caretaker' => false] as $role => $closes) {
            $held = Permissions::defaultsFor($role);
            $this->assertSame($closes, in_array('rentals_close', $held, true), $role);
            $this->assertNotContains('rentals_reopen', $held, $role);
        }
        $this->assertContains('rentals_reopen', Permissions::forRole((int) $company->id, 'owner'));
        $this->assertContains('rentals_reopen', TeamRules::OWNER_ONLY, 'no role override can hand it out (EL-28)');

        // A shop is offered neither.
        $t = $this->registerTenant();
        $this->assertNotContains('rentals_close', Permissions::availableFor((int) $t['company_id']));
    }

    public function test_the_handover_has_its_permission_line_ready(): void
    {
        $found = null;
        foreach (ApiPermissionMap::RULES as [$methods, $pattern, $perm]) {
            if (in_array('POST', $methods, true) && preg_match($pattern, 'rentals/properties/7/handover')) {
                $found = $perm;
                break;
            }
        }
        $this->assertSame('rentals_manage', $found);
    }

    public function test_the_menu_has_each_page_by_its_own_permission(): void
    {
        [$company, $owner] = $this->rentalsAccount();
        $keys = fn () => array_column(ShopMenu::pagesFor($owner, $company), 'key');
        foreach (['rentals.month-end', 'rentals.payouts.run', 'rentals.commission-change'] as $page) {
            $this->assertContains($page, $keys(), $page);
        }

        // Each by its own permission: a caretaker has none of them, an accountant closes but changes no commission.
        $this->assertSame([], array_intersect(['rentals.month-end', 'rentals.payouts.run', 'rentals.commission-change'],
            array_column(ShopMenu::pagesFor($this->rentalsStaff($company, 'rentals_caretaker'), $company), 'key')));
        $accountant = array_column(ShopMenu::pagesFor($this->rentalsStaff($company, 'rentals_accountant'), $company), 'key');
        $this->assertContains('rentals.month-end', $accountant);
        $this->assertNotContains('rentals.commission-change', $accountant);

        // An owner-run account closes months but pays out to no one.
        [$own, $me] = $this->rentalsAccount('owner');
        $mine = array_column(ShopMenu::pagesFor($me, $own), 'key');
        $this->assertContains('rentals.month-end', $mine);
        $this->assertNotContains('rentals.payouts.run', $mine);
        $this->assertNotContains('rentals.commission-change', $mine);
    }

    public function test_each_report_reaches_the_class_that_owns_it(): void
    {
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        $this->assertSame([...self::THE_EIGHTEEN, ...self::NEW_REPORTS], RentalReports::keys());
        $this->assertSame([...self::THE_EIGHTEEN, ...self::NEW_REPORTS], array_column(RentalReports::registry($cid), 'key'));
        $this->assertFalse(RentalReports::open('no_such_report'));
        [$property] = $this->building($owner, 1);
        $landlord = (int) $property->landlord_id;
        foreach (self::NEW_REPORTS as $key) {
            $this->assertSame($key, (new RentalReports)->run($key, $cid, $owner, ['landlord_id' => $landlord])['key']);
        }
        $year = (new RentalReports)->run('landlord_year', $cid, $owner, ['landlord_id' => $landlord, 'year' => '2025']);
        $this->assertSame(['2025-01-01', '2025-12-31'], [$year['from'], $year['to']]);
        $this->assertContains('year', RentalLinks::REPORT_PARAMS);
    }

    public function test_settings_take_the_new_fields_and_refuse_bad_ones(): void
    {
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        $this->assertSame('hold', RentalsModule::settings($cid)->ahead_default, 'hold is the default (EL-28)');
        $saved = app(RentalsModule::class)->saveSettings($company, $owner, ['ahead_default' => 'bill', 'export_accounts' => ['cash' => '1000', 'income' => '4000']]);
        $this->assertSame('bill', $saved->ahead_default);
        $this->assertSame(['cash' => '1000', 'income' => '4000'], $saved->export_accounts);
        foreach ([['ahead_default' => 'later'], ['export_accounts' => ['petty' => '1']], ['export_accounts' => ['cash' => str_repeat('1', 21)]]] as $bad) {
            try {
                app(RentalsModule::class)->saveSettings($company, $owner, $bad);
                $this->fail('saved '.json_encode($bad));
            } catch (BusinessRuleException $e) {
                $this->assertSame('invalid', $e->errorCode());
            }
        }

        $this->assertSame('ST-0001', RentalsModule::nextNumber($cid, 'statement'));
        $this->assertSame('PR-0001', RentalsModule::nextNumber($cid, 'payout_run'));
        foreach (array_keys(RentalsModule::NUMBERS) as $sequence) {
            $this->assertTrue(Schema::hasColumn('rental_settings', 'seq_'.$sequence), $sequence);
        }
    }

    public function test_the_links_gain_their_options_without_changing_what_there_was(): void
    {
        [$company, $owner] = $this->rentalsAccount();
        $l = new RentalLandlord;
        $l->forceFill(['id' => 7, 'company_id' => $company->id]);

        $plain = RentalLinks::statementPdf($l, '2026-10-01', '2026-10-31', 'staff', (int) $owner->id)['url'];
        $this->assertStringNotContainsString('statement_id', $plain);
        $issued = RentalLinks::statementPdf($l, '2026-10-01', '2026-10-31', 'staff', (int) $owner->id, RentalLinks::SHARED_MINUTES, 42);
        $this->assertStringContainsString('statement_id=42', $issued['url']);
        $this->assertSame(RentalLinks::SHARED_MINUTES * 60, $issued['expires_in']);

        $payout = new RentalPayout;
        $payout->forceFill(['id' => 9, 'company_id' => $company->id]);
        $advice = RentalLinks::payoutAdvicePdf($payout, 'portal', 3);
        $this->assertStringContainsString('payout_id=9', $advice['url']);
        $this->assertSame(RentalLinks::MINUTES * 60, $advice['expires_in']);
        // A valid link to an advice that is not built yet opens nothing.
        $path = (string) parse_url($advice['url'], PHP_URL_PATH).'?'.(string) parse_url($advice['url'], PHP_URL_QUERY);
        $this->get($path)->assertNotFound();
    }

    public function test_a_report_can_carry_its_own_paper(): void
    {
        [$company] = $this->rentalsAccount();
        $cid = (int) $company->id;
        $columns = [['key' => 'a', 'label' => 'A', 'type' => 'text'], ['key' => 'secret', 'label' => 'Left off', 'type' => 'text']];
        $report = ['key' => 'not_in_paper', 'title' => 'T', 'subtitle' => '', 'columns' => $columns, 'rows' => [['a' => 'shown', 'secret' => 'hidden']], 'totals' => [], 'sections' => [], 'currency' => 'UGX', 'decimals' => 0,
            'from' => null, 'to' => null, 'account' => ['id' => $cid, 'name' => 'X'], 'generated_at' => now()->toIso8601String()];
        $html = new \ReflectionMethod(RentalExport::class, 'html');
        $this->assertStringContainsString('Left off', (string) $html->invoke(null, $report, $cid, 0));
        $this->assertStringNotContainsString('Left off', (string) $html->invoke(null, $report + ['paper' => ['hide' => ['secret']]], $cid, 0));
        $this->assertSame(['property', 'cycle_months', 'asking_minor'], RentalExport::paperHidden('rent_roll'), 'the table of old is as it was');
    }

    public function test_what_the_agency_takes_out_is_a_line_under_the_income_statement_and_never_income(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        $range = ['from' => '2026-03-01', 'to' => '2026-03-31'];
        $before = (new RentalReports)->run('income_statement', $cid, null, $range);
        $this->assertSame([], $before['sections'], 'none taken, no line');
        $this->assertSame('Taken out by the agency', RentalReports::ENTRY_LABELS['agency_drawing']);
        $this->assertArrayHasKey('collected_direct', RentalReports::ENTRY_LABELS);

        $rows = RentalLedger::locked($cid, fn () => RentalLedger::post($cid, RentalLedger::group(), [
            ['type' => 'agency_drawing', 'occurred_on' => '2026-03-05', 'cash_minor' => -50000, 'equity_minor' => -50000, 'method' => 'cash', 'description' => 'Taken out'],
        ], (int) $owner->id));
        $after = (new RentalReports)->run('income_statement', $cid, null, $range);
        $this->assertSame($before['totals'], $after['totals'], 'not income, not an expense');
        $this->assertSame('Taken out by the agency', $after['sections'][0]['title']);
        $this->assertSame(50000, $after['sections'][0]['rows'][0]['amount_minor']);

        RentalLedger::locked($cid, fn () => RentalLedger::reverse($cid, $rows, '2026-03-06', 'Wrong month', (int) $owner->id));
        $this->assertSame([], (new RentalReports)->run('income_statement', $cid, null, $range)['sections'], 'a reversal takes it back');
    }

}
