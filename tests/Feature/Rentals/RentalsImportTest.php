<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Plan;
use App\Models\RentalActivity;
use App\Models\RentalCharge;
use App\Models\RentalEntry;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Models\RentalProperty;
use App\Models\RentalTenant;
use App\Models\RentalUnit;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Rentals\Import\RentalImport;
use App\Services\Rentals\Jobs\RentalDaily;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\Payments\Payments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * Moving in from another system (KR-22, docs/estate-ledger/04-architecture.md §5.10): the templates, a dry run that writes
 * nothing, every refusal in plain words, opening balances and billed_until never billing twice, duplicates, encodings,
 * big files, all or nothing; every import ends with rentals:check finding nothing.
 */
class RentalsImportTest extends ApiTestCase
{
    use MakesRentals;

    private const AS_OF = '2026-03-15';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An account on a plan with room, its clock on the as-of day. @return array{0: Company, 1: User, 2: int} */
    private function account(string $mode = 'agency', array $settings = []): array
    {
        Carbon::setTestNow(self::AS_OF.' 09:00:00');
        [$company, $owner] = $this->rentalsAccount($mode, $settings);

        return [$company, $owner, (int) $company->id];
    }

    private function counts(int $cid): array
    {
        $out = [];
        foreach (['rental_landlords', 'rental_properties', 'rental_units', 'rental_tenants', 'rental_leases', 'rental_charges', 'rental_payments', 'rental_allocations', 'rental_entries', 'rental_activities', 'rental_lease_items', 'rental_rent_changes'] as $t) {
            $out[$t] = DB::table($t)->where('company_id', $cid)->count();
        }

        return $out;
    }

    /** One complete set of books. */
    private function sheets(): array
    {
        return [
            'landlords' => "name,phone,email,address,payout_method,payout_details,commission_percent,opening_balance\n"
                ."Okello Peter,0772123456,okello@example.com,Ntinda,bank,Stanbic 123,10,\"500,000\"\nNamukasa Grace,,,,mobile money,0701000000,,\"(50,000)\"\n",
            'properties' => "name,landlord_name,kind,area,district,address,commission_percent,deposit_held_by,water_rate,power_rate\n"
                ."Ntinda Court,Okello Peter,residential,Ntinda,Kampala,,,agency,\"2,000\",\nKira Shops,Namukasa Grace,commercial,Kira,Wakiso,,5,landlord,,\n",
            'units' => "property_name,code,kind,floor,rent,deposit,cycle_months\n"
                ."Ntinda Court,A1,apartment,1,\"300,000\",,1\nNtinda Court,A2,flat,1,\"200,000\",,monthly\nNtinda Court,A3,single,2,\"100,000\",,\nKira Shops,S1,shop,G,\"500,000\",,quarterly\n",
            'tenancies' => "property_name,unit_code,tenant_name,phone,email,id_number,start_date,rent,cycle_months,deposit_held,owed_now,credit_now,items,end_date\n"
                ."Ntinda Court,A1,Asha Nakato,0701111111,asha@example.com,CF90SECRET789Z,01/02/2025,,,\"300,000\",\"450,000\",,Garbage:10000,\n"
                ."Ntinda Court,A2,Ben Mugisha,0702222222,,,2025-06-15,\"210,000\",,,,\"600,000\",,31/12/2026\n"
                ."Kira Shops,S1,Kira Traders Ltd,0703333333,,,01/01/2026,,,\"500,000\",,,Security:20000:agency,\n",
            'opening_cash' => "method,amount\ncash,\"1,500,000\"\nmobile money,\"250,000\"\n",
        ];
    }

    public function test_templates_name_every_column_and_import_back_empty(): void
    {
        foreach (RentalImport::SHEETS as $sheet => $cols) {
            $csv = RentalImport::templateCsv($sheet);
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
            $this->assertSame(implode(',', $cols), trim(substr($csv, 3)));
            $this->assertSame([], array_values(array_diff(RentalImport::REQUIRED[$sheet], $cols)), 'a required column is in the template');
        }
        $this->assertArrayHasKey('tenancies', RentalImport::templates());
        $this->expectException(BusinessRuleException::class);
        RentalImport::templateCsv('nope');
    }

    public function test_an_empty_template_filled_in_nothing_is_nothing_and_says_so(): void
    {
        [, $owner, $cid] = $this->account();
        $empty = array_map(fn ($s) => RentalImport::templateCsv($s), array_combine(array_keys(RentalImport::SHEETS), array_keys(RentalImport::SHEETS)));
        $r = app(RentalImport::class)->dryRun($cid, $owner, $empty, self::AS_OF);
        $this->assertSame([], $r['errors']);
        $this->assertSame(0, $r['ok']);
        $this->assertSame(0, $r['summary']['rows']);
    }

    public function test_a_dry_run_writes_nothing_and_the_import_writes_all_of_it(): void
    {
        [$company, $owner, $cid] = $this->account();
        $before = $this->counts($cid);
        $settingsBefore = (array) DB::table('rental_settings')->where('company_id', $cid)->first();
        $r = app(RentalImport::class)->dryRun($cid, $owner, $this->sheets(), self::AS_OF);
        $this->assertSame([], $r['errors']);
        $this->assertSame(13, $r['ok']);
        $this->assertSame(['as_of' => self::AS_OF, 'rows' => 13, 'landlords' => 2, 'properties' => 2, 'units' => 4, 'tenants' => 3, 'tenancies' => 3, 'landlords_owed_minor' => 450000, 'cash_minor' => 1750000,
            'cash' => ['cash' => 1500000, 'mobile_money' => 250000], 'owed_minor' => 450000, 'credit_minor' => 600000, 'deposits_minor' => 800000], $r['summary']);
        $this->assertSame($before, $this->counts($cid), 'a dry run writes nothing');
        $this->assertSame($settingsBefore, (array) DB::table('rental_settings')->where('company_id', $cid)->first(), 'not even a number was used up');

        $done = app(RentalImport::class)->run($cid, $owner, $this->sheets(), self::AS_OF);
        $this->assertSame(['landlords' => 2, 'properties' => 2, 'units' => 4, 'tenants' => 3, 'tenancies' => 3, 'opening_cash' => 2], $done['created']);
        $this->assertSame(1, RentalActivity::where('company_id', $cid)->where('action', 'import.done')->count());

        // Names and terms arrive as typed: the amounts in shillings, the kinds by their words, the unit's rent where blank.
        $this->assertSame(['Namukasa Grace', 'Okello Peter'], RentalLandlord::where('company_id', $cid)->orderBy('name')->pluck('name')->all());
        $this->assertSame('mobile_money', RentalLandlord::where('name', 'Namukasa Grace')->where('company_id', $cid)->value('payout_method'));
        $this->assertSame(['percent', 1000], [RentalLandlord::where('name', 'Okello Peter')->where('company_id', $cid)->value('commission_type'), (int) RentalLandlord::where('name', 'Okello Peter')->where('company_id', $cid)->value('commission_bp')]);
        $this->assertSame([3, 2000], [(int) RentalUnit::where('company_id', $cid)->where('code', 'S1')->value('cycle_months'), (int) RentalProperty::where('company_id', $cid)->where('name', 'Ntinda Court')->value('water_rate_minor')]);
        $this->assertSame('apartment', RentalUnit::where('company_id', $cid)->where('code', 'A2')->value('kind'));
        $this->assertSame('single_room', RentalUnit::where('company_id', $cid)->where('code', 'A3')->value('kind'));
        $this->assertNotSame('CF90SECRET789Z', RentalTenant::where('company_id', $cid)->where('name', 'Asha Nakato')->first()->getRawOriginal('id_number'), 'encrypted at rest');
        $this->assertBooksAgree($cid);
    }

    public function test_opening_balances_land_in_the_journal_and_nothing_is_billed_twice(): void
    {
        [$company, $owner, $cid] = $this->account();
        app(RentalImport::class)->run($cid, $owner, $this->sheets(), self::AS_OF);
        $a1 = RentalLease::where('company_id', $cid)->where('unit_id', RentalUnit::where('company_id', $cid)->where('code', 'A1')->value('id'))->firstOrFail();
        $a2 = RentalLease::where('company_id', $cid)->where('unit_id', RentalUnit::where('company_id', $cid)->where('code', 'A2')->value('id'))->firstOrFail();
        $s1 = RentalLease::where('company_id', $cid)->where('unit_id', RentalUnit::where('company_id', $cid)->where('code', 'S1')->value('id'))->firstOrFail();

        // The running period was billed by the other system: billed up to its last day, nothing raised for it.
        $this->assertSame('2026-03-31', $a1->billed_until->toDateString(), '1st to the last of the month');
        $this->assertSame('2026-04-14', $a2->billed_until->toDateString(), '15th to the 14th');
        $this->assertSame('2026-03-31', $s1->billed_until->toDateString(), 'quarterly from 1 January');
        $this->assertSame(0, RentalCharge::where('lease_id', $a1->id)->where('kind', 'rent')->count());
        $this->assertSame(0, RentalCharge::where('lease_id', $a1->id)->where('kind', 'item')->count());
        // Arrears as one opening bill; credit as credit; a deposit held as held, not a bill to pay.
        $this->assertSame([['opening', 450000, 'open']], RentalCharge::where('lease_id', $a1->id)->where('kind', 'opening')->get()->map(fn ($c) => [$c->kind, (int) $c->amount_minor, $c->status])->all());
        $pos = \App\Services\Rentals\Leases\Leases::position($a1);
        $this->assertSame([450000, 0, 300000], [$pos['owed_minor'], $pos['credit_minor'], $pos['deposit_held_minor']]);
        $this->assertSame(600000, Payments::credit($a2));
        $this->assertSame(500000, Deposits::held($s1));
        $this->assertSame('landlord', $s1->deposit_held_by);

        // Journal: cash 1,750,000 = landlord 450,000 + deposit 300,000 + credit 600,000 + equity 400,000.
        //   Okello 500,000 owed; Namukasa −50,000 owed, and she holds 500,000 of deposit: her opening row is −550,000, the deposit row +500,000.
        $t = RentalLedger::totals($cid);
        $this->assertSame(1750000, $t['cash_minor']);
        $this->assertSame(450000, $t['landlord_minor']);
        $this->assertSame(300000, $t['deposit_minor'], 'only what the agency holds');
        $this->assertSame(600000, $t['credit_minor']);
        $this->assertSame(400000, $t['equity_minor']);
        $this->assertSame(0, $t['income_minor']);
        $this->assertSame(['cash' => 1500000, 'bank' => 0, 'mobile_money' => 250000, 'cheque' => 0, 'total' => 1750000], RentalLedger::cash($cid));
        $this->assertSame(['Namukasa Grace' => -50000, 'Okello Peter' => 500000], DB::table('rental_entries as e')->join('rental_landlords as l', 'l.id', '=', 'e.landlord_id')->where('e.company_id', $cid)
            ->groupBy('l.name')->orderBy('l.name')->selectRaw('l.name, SUM(e.landlord_minor) AS b')->pluck('b', 'name')->map(fn ($x) => (int) $x)->all());
        $this->assertBooksAgree($cid);

        // The daily run, as it would have run that night and twice more: nothing for the running period.
        $this->assertSame(['bills' => 0, 'late_fees' => 0], app(RentalDaily::class)->run($cid, Carbon::parse(self::AS_OF)));
        $this->assertSame(['bills' => 0, 'late_fees' => 0], app(RentalDaily::class)->run($cid, Carbon::parse(self::AS_OF)));

        // Five days before April, April's bills come: one rent and one garbage for A1, at the right rents; the credit pays A2's.
        Carbon::setTestNow('2026-03-27 09:00:00');
        app(RentalDaily::class)->run($cid);
        app(RentalDaily::class)->run($cid);
        $this->assertSame([['rent', '2026-04-01', 300000], ['item', '2026-04-01', 10000]], RentalCharge::where('lease_id', $a1->id)->whereIn('kind', ['rent', 'item'])->orderBy('id')->get()->map(fn ($c) => [$c->kind, $c->due_date->toDateString(), (int) $c->amount_minor])->all());
        $this->assertSame(0, RentalCharge::where('lease_id', $a2->id)->where('kind', 'rent')->count(), 'A2\'s next period starts on 15 April, not yet in the horizon');
        $this->assertSame(1500000, RentalCharge::where('lease_id', $s1->id)->where('kind', 'rent')->value('amount_minor'), 'a quarter of the rent, for the shop');
        Carbon::setTestNow('2026-04-11 09:00:00');
        app(RentalDaily::class)->run($cid);
        $rent = RentalCharge::where('lease_id', $a2->id)->where('kind', 'rent')->firstOrFail();
        $this->assertSame([210000, 'paid'], [(int) $rent->amount_minor, $rent->status], 'the typed rent, paid from the credit');
        $this->assertSame(390000, Payments::credit($a2->fresh()));
        // Paying the arrears: oldest first, commission on the landlord's rent, an opening bill like any other.
        $p = app(Payments::class)->receive($owner, $a1->fresh(), ['amount_minor' => 450000, 'method' => 'cash']);
        $this->assertSame(450000, (int) $p->allocations()->sum('amount_minor'));
        $this->assertBooksAgree($cid);
        $this->assertSame(0, Artisan::call('rentals:check', ['--company' => $cid]));
    }

    public function test_every_refusal_is_in_plain_words_and_names_its_row(): void
    {
        [, $owner, $cid] = $this->account();
        $cases = [
            ['landlords', "name,payout_method\n,bank\n", 'Give the landlord a name.'],
            ['landlords', "name,payout_method\nA,bitcoin\n", 'payout_method must be bank, mobile_money or cash.'],
            ['landlords', "name,commission_percent\nA,150\n", 'commission_percent cannot be above 100.'],
            ['landlords', "name,commission_percent\nA,lots\n", 'commission_percent must be a number from 0 to 100, for example 10.'],
            ['landlords', "name,commission_percent,commission_flat\nA,10,5000\n", 'Give either commission_percent or commission_flat, not both.'],
            ['landlords', "name,opening_balance\nA,lots\n", 'opening_balance: Enter an amount in figures, for example 350,000.'],
            ['landlords', "name,email\nA,not-an-email\n", 'The email address is not valid.'],
            ['landlords', "name,phone\nA,12\n", 'The phone number is not valid.'],
            ['landlords', "name\nA\nA\n", 'A is in the list twice (or is already in Estate Ledger).'],
            ['landlords', "name\n".str_repeat('x', 151)."\n", 'The name is too long (at most 150 characters).'],
            ['properties', "name,landlord_name\n,X\n", 'Give the property a name.'],
            ['properties', "name,landlord_name\nP,\n", 'Say whose property it is (landlord_name).'],
            ['properties', "name,landlord_name\nP,Nobody\n", 'No landlord called "Nobody" in the landlords sheet or in Estate Ledger.'],
            ['units', "property_name,code,rent\nNowhere,A1,100\n", 'No property called "Nowhere" in the properties sheet or in Estate Ledger.'],
            ['tenancies', "property_name,unit_code,tenant_name,start_date\nNowhere,A1,X,2026-01-01\n", 'No unit "A1" in "Nowhere" (in the units sheet or in Estate Ledger).'],
            ['opening_cash', "method,amount\ngold,5\n", 'method must be cash, bank, mobile_money or cheque.'],
            ['opening_cash', "method,amount\ncash,0\n", 'Give the amount in hand (above zero).'],
            ['opening_cash', "method,amount\ncash,-5\n", 'amount: it cannot be below zero.'],
            ['opening_cash', "method,amount\ncash,5\ncash,6\n", 'cash is in the list twice: give one amount per account.'],
            ['opening_cash', "method,amount\ncash,12.5\n", 'amount: This currency has no cents: enter a whole amount.'],
        ];
        foreach ($cases as [$sheet, $csv, $words]) {
            $r = app(RentalImport::class)->dryRun($cid, $owner, [$sheet => $csv], self::AS_OF);
            $this->assertContains($words, array_column($r['errors'], 'message'), $sheet.': '.json_encode($r['errors']));
            $this->assertSame($sheet, $r['errors'][0]['sheet']);
            $this->assertGreaterThanOrEqual(2, $r['errors'][0]['row'], 'the row in the file, the header being row 1');
        }
        // On a row with a landlord and a property to hang it on.
        $base = ['landlords' => "name\nL\n", 'properties' => "name,landlord_name\nP,L\n", 'units' => "property_name,code,kind,rent,cycle_months\nP,A1,apartment,100,1\nP,A2,apartment,100,1\n"];
        $tenancies = [
            ["P,A1,,,,,2026-01-01,,,,,", "Give the tenant's name."],
            ["P,A1,X,12,,,2026-01-01,,,,,", 'The phone number is not valid.'],
            ["P,A1,X,,bad,,2026-01-01,,,,,", 'The email address is not valid.'],
            ["P,A1,X,,,,someday,,,,,", 'start_date must look like 31/10/2024 or 2024-10-31.'],
            ["P,A1,X,,,,31/02/2026,,,,,", 'start_date must look like 31/10/2024 or 2024-10-31.'],
            ["P,A1,X,,,,45000,,,,,", 'start_date must look like 31/10/2024 or 2024-10-31.'],
            ["P,A1,X,,,,2026-06-01,,,,,", 'The tenant moves in after the as-of date: record that move-in in Estate Ledger instead.'],
            ["P,A1,X,,,,2026-01-01,,,,10,10", 'A tenant either owes or has paid ahead: give the difference in one of owed_now or credit_now.'],
            ["P,A1,X,,,,2026-01-01,0,,,,", "Give the rent a month (or the unit's rent in the units sheet)."],
            ["P,A1,X,,,,2026-01-01,,2,,,", 'cycle_months must be 1, 3, 6 or 12.'],
            ["P,A1,X,,,,2026-01-01,,,-5,,", 'deposit_held: it cannot be below zero.'],
            ["P,A1,".str_repeat('x', 151).",,,,2026-01-01,,,,,", 'The tenant\'s name is too long (at most 150 characters).'],
            ["P,A9,X,,,,2026-01-01,,,,,", 'No unit "A9" in "P" (in the units sheet or in Estate Ledger).'],
        ];
        $head = "property_name,unit_code,tenant_name,phone,email,id_number,start_date,rent,cycle_months,deposit_held,owed_now,credit_now\n";
        foreach ($tenancies as [$row, $words]) {
            $r = app(RentalImport::class)->dryRun($cid, $owner, $base + ['tenancies' => $head.$row."\n"], self::AS_OF);
            $this->assertContains($words, array_column($r['errors'], 'message'), $row.': '.json_encode($r['errors']));
        }
        $r = app(RentalImport::class)->dryRun($cid, $owner, $base + ['tenancies' => $head."P,A1,X,0701000000,,,2026-01-01,,,,,\nP,A2,Y,0701000000,,,2026-01-01,,,,,\n"], self::AS_OF);
        $this->assertStringContainsString("is X's in an earlier row", $r['errors'][0]['message']);
        $r = app(RentalImport::class)->dryRun($cid, $owner, $base + ['tenancies' => $head."P,A1,X,,,,2026-01-01,,,,,\nP,A1,Y,,,,2026-01-01,,,,,\n"], self::AS_OF);
        $this->assertSame('Unit A1 in P already has a tenant.', $r['errors'][0]['message']);
        $this->assertSame(3, $r['errors'][0]['row']);

        // A file with its columns wrong, a sheet that does not exist, a day to come.
        foreach ([[['units' => "foo,bar\n1,2\n"], 'The first row must name the columns; missing: property_name, code, rent.'],
            [['flats' => "a\n1\n"], 'There is no sheet called "flats". The sheets are: landlords, properties, units, tenancies, opening_cash.'],
            [['tenancies' => "name\n"], 'The first row must name the columns; missing: property_name, unit_code, tenant_name, start_date.']] as [$sheets, $words]) {
            $this->assertContains($words, array_column(app(RentalImport::class)->dryRun($cid, $owner, $sheets, self::AS_OF)['errors'], 'message'));
        }
        $this->assertSame('The as-of date cannot be after today.', app(RentalImport::class)->dryRun($cid, $owner, [], '2026-12-31')['errors'][0]['message']);
        $this->assertSame('The as-of date must look like 31/10/2026 or 2026-10-31.', app(RentalImport::class)->dryRun($cid, $owner, [], 'soon')['errors'][0]['message']);

        // The import refuses what the dry run refuses, naming the first; nothing is written.
        $before = $this->counts($cid);
        try {
            app(RentalImport::class)->run($cid, $owner, ['landlords' => "name,email\nA,bad\nB,\n"], self::AS_OF);
            $this->fail('refused');
        } catch (BusinessRuleException $e) {
            $this->assertSame('import_invalid', $e->errorCode());
            $this->assertSame('1 row(s) need fixing first. landlords, row 2: The email address is not valid.', $e->getMessage());
            $this->assertSame('landlords', $e->meta()['errors'][0]['sheet']);
        }
        $this->assertSame($before, $this->counts($cid));
        $this->assertBooksAgree($cid);
    }

    public function test_it_is_all_or_nothing_when_a_service_refuses_after_the_checks(): void
    {
        [, $owner, $cid] = $this->account();
        // 151 characters of an address the checks do not look at: Landlords::create refuses it at the write.
        $sheets = $this->sheets();
        $sheets['landlords'] = "name,address\nOkello Peter,".str_repeat('x', 300)."\nNamukasa Grace,\n";
        $before = $this->counts($cid);
        try {
            app(RentalImport::class)->run($cid, $owner, $sheets, self::AS_OF);
            $this->fail('refused');
        } catch (BusinessRuleException $e) {
            $this->assertContains($e->errorCode(), ['import_failed', 'import_invalid']);
            $this->assertStringContainsString('landlords, row 2', $e->getMessage());
        }
        $this->assertSame($before, $this->counts($cid), 'nothing was imported');
        $this->assertSame(0, (int) RentalLedger::totals($cid)['cash_minor']);
    }

    public function test_bringing_it_in_twice_is_refused_and_adding_on_purpose_works(): void
    {
        [, $owner, $cid] = $this->account();
        $imp = app(RentalImport::class);
        $imp->run($cid, $owner, $this->sheets(), self::AS_OF);
        $cash = RentalLedger::cash($cid);
        $again = $imp->dryRun($cid, $owner, $this->sheets(), self::AS_OF);
        $this->assertContains('Okello Peter is in the list twice (or is already in Estate Ledger).', array_column($again['errors'], 'message'));
        $this->assertContains('Ntinda Court is in the list twice (or is already in Estate Ledger).', array_column($again['errors'], 'message'));
        $this->assertContains('Ntinda Court has two units called A1 (or one is already in Estate Ledger).', array_column($again['errors'], 'message'));
        $this->assertContains('This account already has tenancies. Import into a new account, or say that adding to the ones there is meant (allow existing).', array_column($again['errors'], 'message'));
        // The opening cash alone, a second time, would double the money in the book.
        $cashOnly = $imp->dryRun($cid, $owner, ['opening_cash' => "method,amount\ncash,5\n"], self::AS_OF);
        $this->assertSame('The opening balance of cash is already in Estate Ledger: it cannot be brought in twice.', $cashOnly['errors'][0]['message']);
        $this->assertSame($cash, RentalLedger::cash($cid));

        // More tenancies on purpose, into a unit already there; the same tenant by phone stays one tenant with two homes.
        $one = $imp->run($cid, $owner, ['tenancies' => "property_name,unit_code,tenant_name,phone,start_date\nNtinda Court,A3,Asha Nakato,0701111111,2026-01-01\n"], self::AS_OF, ['allow_existing' => true]);
        $this->assertSame(0, $one['created']['tenants']);
        $this->assertSame(1, $one['created']['tenancies']);
        $this->assertSame(2, RentalLease::where('company_id', $cid)->where('tenant_id', RentalTenant::where('company_id', $cid)->where('name', 'Asha Nakato')->value('id'))->count());
        $this->assertSame(3, RentalTenant::where('company_id', $cid)->count());
        $this->assertSame('Unit A3 in Ntinda Court already has a tenant.', $imp->dryRun($cid, $owner, ['tenancies' => "property_name,unit_code,tenant_name,start_date\nNtinda Court,A3,X,2026-01-01\n"], self::AS_OF, ['allow_existing' => true])['errors'][0]['message']);
        $this->assertBooksAgree($cid);
    }

    public function test_encodings_and_spreadsheet_habits(): void
    {
        [, $owner, $cid] = $this->account();
        $imp = app(RentalImport::class);
        // Windows-1252 from Excel, UTF-16 "Unicode text", UTF-8 with a BOM: "Müller" arrives whole each time.
        $utf8 = "name\nMüller Söhne\n";
        foreach (['cp1252' => mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8'), 'utf16' => "\xFF\xFE".mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8'), 'utf16be' => "\xFE\xFF".mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8'), 'bom' => "\xEF\xBB\xBF".$utf8] as $how => $csv) {
            $rows = RentalImport::parse($csv);
            $this->assertSame('Müller Söhne', $rows[0]['name'], $how);
        }
        $imp->run($cid, $owner, ['landlords' => mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8')], self::AS_OF);
        $this->assertSame('Müller Söhne', RentalLandlord::where('company_id', $cid)->value('name'));

        // Blank lines, CRLF, spaces and capitals in headers, thousand separators, a name with a comma in quotes.
        $rows = RentalImport::parse("Property Name , Code,RENT\r\n\r\n\" Court, Main \",A1,\"1,000\"\r\n,,\r\n");
        $this->assertCount(1, $rows);
        $this->assertSame(['_row' => '3', 'property_name' => 'Court, Main', 'code' => 'A1', 'rent' => '1,000'], $rows[0]);
        // Dates both ways; a formula someone typed stays plain text and is neutralised when it goes back out.
        $this->assertSame('2026-02-01', RentalImport::date('01/02/2026')->toDateString());
        $this->assertSame('2026-02-01', RentalImport::date('1.2.2026')->toDateString());
        $imp->run($cid, $owner, ['landlords' => "name\n\"=HYPERLINK(\"\"x\"\")\"\n"], self::AS_OF);
        $this->assertSame('=HYPERLINK("x")', RentalLandlord::where('company_id', $cid)->orderByDesc('id')->value('name'));
        $this->assertBooksAgree($cid);
    }

    public function test_a_big_file_and_the_plans_limit(): void
    {
        [$company, $owner, $cid] = $this->account();
        $imp = app(RentalImport::class);
        // 1,500 units (the agency plan allows 2,000), with 200 tenancies: the dry run in seconds, then the whole import.
        $units = "property_name,code,rent\n";
        for ($i = 1; $i <= 1500; $i++) {
            $units .= "Big Estate,U{$i},100000\n";
        }
        $tenancies = "property_name,unit_code,tenant_name,phone,start_date,owed_now,deposit_held\n";
        for ($i = 1; $i <= 200; $i++) {
            $tenancies .= "Big Estate,U{$i},Tenant {$i},07".str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT).",2026-01-01,".($i % 2 ? '5000' : '').",".($i % 3 ? '' : '100000')."\n";
        }
        $sheets = ['landlords' => "name\nBig Landlord\n", 'properties' => "name,landlord_name\nBig Estate,Big Landlord\n", 'units' => $units, 'tenancies' => $tenancies, 'opening_cash' => "method,amount\nbank,\"99,000,000\"\n"];
        $t = microtime(true);
        $r = $imp->dryRun($cid, $owner, $sheets, self::AS_OF);
        $this->assertLessThan(20, microtime(true) - $t);
        $this->assertSame([], $r['errors']);
        $this->assertSame(1703, $r['ok']);
        $done = $imp->run($cid, $owner, $sheets, self::AS_OF);
        $this->assertSame(['landlords' => 1, 'properties' => 1, 'units' => 1500, 'tenants' => 200, 'tenancies' => 200, 'opening_cash' => 1], $done['created']);
        $this->assertBooksAgree($cid);
        $this->assertSame(1500, app(\App\Services\Billing\Quotas::class)->used($company, 'units'));

        // Past the plan: the count is refused in the dry run, in words, with nothing written.
        $small = $this->rentalsAccount()[0];
        Subscription::where('company_id', $small->id)->update(['plan_id' => Plan::where('slug', 'estate-ledger-free')->value('id')]);
        $smallOwner = User::withoutGlobalScopes()->findOrFail($small->owner_id);
        $eleven = "property_name,code,rent\n";
        for ($i = 1; $i <= 11; $i++) {
            $eleven .= "Small,U{$i},1000\n";
        }
        $files = ['landlords' => "name\nL\n", 'properties' => "name,landlord_name\nSmall,L\n", 'units' => $eleven];
        $small = Company::withoutGlobalScopes()->find($small->id);
        $this->assertSame('Your plan allows 10 units and this import would take the account past it. Choose a bigger plan first.', $imp->dryRun((int) $small->id, $smallOwner, $files, self::AS_OF)['errors'][0]['message']);
        $this->assertSame(0, RentalUnit::where('company_id', $small->id)->count());
    }

    public function test_an_owner_account_has_no_landlords_sheet_and_a_person_without_the_right_cannot(): void
    {
        [$company, $owner, $cid] = $this->account('owner');
        $imp = app(RentalImport::class);
        $this->assertSame('This account manages its own property, so it has no landlords: leave the landlords sheet out.', $imp->dryRun($cid, $owner, ['landlords' => "name\nA\n"], self::AS_OF)['errors'][0]['message']);
        $imp->run($cid, $owner, ['properties' => "name\nMy House\n", 'units' => "property_name,code,rent\nMy House,1,500000\n",
            'tenancies' => "property_name,unit_code,tenant_name,start_date,deposit_held,credit_now\nMy House,1,Asha,2026-03-01,500000,100000\n", 'opening_cash' => "method,amount\ncash,600000\n"], self::AS_OF);
        $lease = RentalLease::where('company_id', $cid)->firstOrFail();
        $this->assertSame('agency', $lease->deposit_held_by, 'the owner holds it: one set of books');
        $this->assertEquals(['credit_minor' => 100000, 'deposit_minor' => 500000, 'cash_minor' => 600000, 'equity_minor' => 0], array_intersect_key(RentalLedger::totals($cid), array_flip(['credit_minor', 'deposit_minor', 'cash_minor', 'equity_minor'])));
        $this->assertBooksAgree($cid);

        foreach (['rentals_accountant', 'rentals_caretaker', 'viewer'] as $role) {
            $staff = $this->rentalsStaff($company, $role);
            foreach (['dryRun', 'run'] as $m) {
                try {
                    $imp->{$m}($cid, $staff, ['landlords' => "name\nA\n"], self::AS_OF);
                    $this->fail($role.' '.$m);
                } catch (BusinessRuleException $e) {
                    $this->assertSame('forbidden', $e->errorCode());
                }
            }
        }
        // Someone of another account, whatever they are there.
        [, $stranger] = $this->rentalsAccount();
        foreach (['dryRun', 'run'] as $m) {
            try {
                $imp->{$m}($cid, $stranger, [], self::AS_OF);
                $this->fail('another account');
            } catch (BusinessRuleException $e) {
                $this->assertSame('not_found', $e->errorCode());
            }
        }
    }

    public function test_the_command_reads_a_folder_of_files_and_a_dry_run_writes_nothing(): void
    {
        $this->withoutMockingConsoleOutput();
        [, $owner, $cid] = $this->account();
        $dir = sys_get_temp_dir().'/kr'.uniqid();
        mkdir($dir);
        foreach ($this->sheets() as $sheet => $csv) {
            file_put_contents("{$dir}/{$sheet}.csv", $csv);
        }
        $before = $this->counts($cid);
        $this->assertSame(0, Artisan::call('rentals:import', ['path' => $dir, '--company' => $cid, '--as-of' => self::AS_OF, '--dry-run' => true]));
        $this->assertStringContainsString('13 row(s)', Artisan::output());
        $this->assertSame($before, $this->counts($cid));
        $this->assertSame(0, Artisan::call('rentals:import', ['path' => $dir, '--company' => $cid, '--as-of' => self::AS_OF]));
        $this->assertSame(2, RentalLandlord::where('company_id', $cid)->count());
        $this->assertSame(1, Artisan::call('rentals:import', ['path' => $dir, '--company' => $cid, '--as-of' => self::AS_OF]));
        $this->assertStringContainsString('need fixing', Artisan::output());
        $this->assertSame(1, Artisan::call('rentals:import', ['path' => $dir.'/nope', '--company' => $cid]));
        $this->assertSame(1, Artisan::call('rentals:import', ['path' => $dir, '--company' => 99999999]));
        foreach (glob("{$dir}/*") as $f) {
            unlink($f);
        }
        rmdir($dir);
        $this->assertBooksAgree($cid);
    }
}
