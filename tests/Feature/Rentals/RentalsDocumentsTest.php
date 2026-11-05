<?php

namespace Tests\Feature\Rentals;

use App\Models\Company;
use App\Models\RentalLease;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Landlords\StatementPdf;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Payments\Receipt;
use App\Services\Rentals\Reports\RentalDocument;
use App\Services\Rentals\Reports\RentalExport;
use App\Services\Rentals\Reports\RentalReports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * Reports and documents on paper and in files (docs/estate-ledger/03-improvements.md §8, §9): the batched lease position
 * equals the single one and its reports stay under a query cap; subset fonts; one layout with page numbers and a fixed
 * type scale; the monthly report on one page however long the list; the Excel and CSV rules.
 */
class RentalsDocumentsTest extends ApiTestCase
{
    use MakesRentals;

    private const CAP = 30;

    /**
     * Not an N+1: a statement read live from the journal fetches each section it prints once (lines, what tenants owed, the unit table, expenses, payouts, what is held, corrections,
     * LC-08), the test below holds it flat as leases grow, and an issued one reads only its copy.
     */
    private const STATEMENT_CAP = 46;

    private const PERIOD = ['from' => '2026-03-01', 'to' => '2026-03-31', 'month' => '2026-03'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $localTime): void
    {
        Carbon::setTestNow(Carbon::parse($localTime, 'Africa/Kampala')->utc());
    }

    /** An account with every kind of lease: overdue, paid ahead, a rent change, a fee, under notice, moved out with its deposit back. */
    private function books(int $extra = 0): object
    {
        $this->at('2026-01-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$p, $u] = $this->building($owner, 6 + $extra, 300000);
        $l = [];
        foreach (array_slice($u, 0, 6 + $extra) as $i => $unit) {
            $l[$i] = $this->tenantIn($owner, $unit, '2026-01-01', ['deposit_minor' => 200000 + $i]);
        }
        $pay = app(Payments::class);
        $pay->receive($owner, $l[1], ['amount_minor' => 2000000, 'method' => 'cash']); // the deposit, then paid ahead
        $pay->receive($owner, $l[2], ['amount_minor' => 350000, 'method' => 'mobile_money', 'reference' => 'MP1']); // part
        $pay->receive($owner, $l[3], ['amount_minor' => 900000, 'method' => 'bank']);
        app(Leases::class)->addItem($owner, $l[4], ['name' => 'Garbage', 'amount_minor' => 5000]);
        app(Leases::class)->changeRent($owner, $l[5], 350000, '2026-06-01', 'Yearly rise');
        foreach (array_slice($l, 6) as $lease) {
            $pay->receive($owner, $lease, ['amount_minor' => 500000, 'method' => 'cash']);
        }
        $this->at('2026-03-10 09:00:00');
        app(Expenses::class)->record($owner, ['property_id' => $p->id, 'category' => 'Repairs', 'description' => 'Gate', 'amount_minor' => 30000, 'method' => 'cash']);
        app(Leases::class)->notice($owner, $l[3]->fresh(), '2026-03-10', '2026-04-30');
        $pay->receive($owner, $l[0], ['amount_minor' => 500000, 'method' => 'cash']);
        $this->at('2026-03-20 09:00:00');
        app(Leases::class)->moveOut($owner, $l[0]->fresh(), ['date' => '2026-03-20', 'refund_deposit' => 'cash']);

        return (object) ['company' => $company, 'cid' => $cid, 'owner' => $owner, 'p' => $p, 'units' => $u, 'leases' => $l];
    }

    /** @param array<string, mixed> $params */
    private function report(object $b, string $key, array $params = []): array
    {
        return (new RentalReports)->run($key, $b->cid, $b->owner, $params + self::PERIOD + ['landlord_id' => $b->p->landlord_id, 'lease_id' => $b->leases[2]->id]);
    }

    private function html(array $report, int $cid): string
    {
        return (string) (new \ReflectionMethod(RentalExport::class, 'html'))->invoke(null, $report, $cid);
    }

    private function pageCount(string $pdf): int
    {
        return (int) preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf);
    }

    /** The text of a PDF where this machine can read one; null where it cannot. */
    private function pdfText(string $pdf): ?string
    {
        $tool = trim((string) shell_exec('command -v pdftotext || ls /opt/homebrew/bin/pdftotext 2>/dev/null'));
        if ($tool === '') {
            return null;
        }
        $file = tempnam(sys_get_temp_dir(), 'kr');
        file_put_contents($file, $pdf);
        $text = (string) shell_exec(escapeshellarg($tool).' -layout '.escapeshellarg($file).' -');
        unlink($file);

        return $text;
    }

    /** @return array<string, \SimpleXMLElement|string> the parts of an xlsx */
    private function xlsxParts(string $bytes): array
    {
        $file = tempnam(sys_get_temp_dir(), 'kr');
        file_put_contents($file, $bytes);
        $zip = new \ZipArchive;
        $zip->open($file);
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $parts[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($file);

        return $parts;
    }

    public function test_positions_equal_position_for_every_kind_of_lease_on_any_day(): void
    {
        $b = $this->books();
        $leases = RentalLease::withoutGlobalScopes()->where('company_id', $b->cid)->get();
        foreach (['2026-03-10', '2026-01-15', '2026-05-20', '2027-01-02'] as $day) {
            $this->at($day.' 10:00:00');
            $batch = Leases::positions($leases->pluck('id')->all());
            $this->assertCount($leases->count(), $batch);
            foreach ($leases as $l) {
                $this->assertSame(Leases::position($l), $batch[$l->id], "lease {$l->number} on {$day}");
                $this->assertSame(Deposits::held($l), Leases::depositsHeld([$l])[$l->id]);
            }
            $this->assertSame($batch, Leases::positions($leases->all()), 'leases already read give the same');
        }
        $today = Carbon::parse('2026-04-01');
        $this->assertSame(Leases::position($leases[1], $today), Leases::positions([$leases[1]->id], $today)[$leases[1]->id], 'a given day');
        $this->assertSame([], Leases::positions([]));
        $this->assertBooksAgree($b->cid);
    }

    public function test_every_report_stays_under_the_query_cap_and_does_not_grow_with_the_leases(): void
    {
        $small = $this->books();
        $counts = [];
        $run = function (object $b) {
            $out = [];
            foreach (RentalReports::keys() as $key) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->report($b, $key);
                $out[$key] = count(DB::getQueryLog());
                DB::disableQueryLog();
            }

            return $out;
        };
        $counts = $run($small);
        foreach ($counts as $key => $n) {
            $this->assertLessThanOrEqual($key === 'landlord_statement' ? self::STATEMENT_CAP : self::CAP, $n, "{$key} runs {$n} queries");
        }
        $large = $this->books(20);
        foreach ($run($large) as $key => $n) {
            $this->assertLessThanOrEqual($key === 'landlord_statement' ? self::STATEMENT_CAP : self::CAP, $n, "{$key} runs {$n} queries with more leases");
            if (in_array($key, ['rent_roll', 'deposits_held', 'deposit_register', 'lease_expiries', 'lease_list', 'tenant_list', 'arrears_aging', 'landlord_statement'], true)) {
                $this->assertLessThanOrEqual($counts[$key] + 3, $n, "{$key} grows with the leases");
            }
        }
    }

    public function test_every_pdf_is_small_with_subset_fonts_in_one_layout_with_page_numbers(): void
    {
        $b = $this->books();
        $this->at('2026-04-01 09:00:00');
        foreach (RentalReports::keys() as $key) {
            $report = $this->report($b, $key);
            $pdf = RentalExport::pdf($report, $b->cid);
            $this->assertStringStartsWith('%PDF', $pdf);
            $this->assertLessThan(120 * 1024, strlen($pdf), "{$key} is ".strlen($pdf).' bytes');
            $this->assertSame(1, preg_match('#/FontName\s*/[A-Z]{6}\+DejaVuSans#', $pdf), "{$key} carries subset fonts");
            $html = $this->html($report, $b->cid);
            $this->assertStringContainsString((string) $report['account']['name'], $html);
            $this->assertStringContainsString('DejaVu Sans Mono', $html);
            foreach (['Budget Pro', 'BudgetPro', 'Shelfie', 'Kuku Tally', 'Pledge', 'Akiba'] as $other) {
                $this->assertStringNotContainsStringIgnoringCase($other, $html, "{$key} names {$other}");
            }
            // The only em dash on paper is the dash for zero in a figure's cell.
            $this->assertStringNotContainsString('—', (string) preg_replace('#<td class="n[^"]*"[^>]*>—(\x{00A0})?</td>#u', '', $html), "{$key} has an em dash in its words");
            if (($text = $this->pdfText($pdf)) !== null) {
                $this->assertMatchesRegularExpression('/Page 1 of \d+ · generated /', $text, $key);
                $this->assertStringContainsString((string) $report['title'], $text, $key);
            }
        }
    }

    public function test_paper_has_one_type_scale_a_dash_for_zero_brackets_for_negatives_and_rows_of_figures_that_add_up(): void
    {
        $layout = File::get(resource_path('views/rentals/document.blade.php'));
        preg_match_all('/font-size:\s*([\d.]+)px/', $layout, $m);
        $this->assertEqualsCanonicalizing(['8', '9.5', '10.5', '12', '14', '18'], array_values(array_unique($m[1])));

        $this->assertSame('—', RentalExport::paperMoney(0, 0));
        $this->assertSame('0', RentalExport::paperMoney(0, 0, true));
        $this->assertSame('(5,400,000)', RentalExport::paperMoney(-5400000, 0));
        $this->assertSame('(1,250.50)', RentalExport::paperMoney(-125050, 2));
        $this->assertSame('1 Oct 2026', RentalExport::paperDate('2026-10-01'));
        $this->assertSame('0700 000474', RentalExport::phone('+256700000474'));
        $this->assertSame('0772 123456', RentalExport::phone('0772123456'));
        $this->assertSame('+1 415 555', RentalExport::phone('+1 415 555'));

        $b = $this->books();
        $this->at('2026-04-01 09:00:00');
        $roll = $this->report($b, 'rent_roll');
        $this->assertSame(['property', 'cycle_months', 'asking_minor'], RentalExport::paperHidden('rent_roll'));
        $html = $this->html($roll, $b->cid);
        $this->assertStringContainsString('Total, '.$b->p->name, $html, 'a subtotal per building');
        $this->assertStringNotContainsString('Months per bill', $html);
        $this->assertStringNotContainsString('+256', $html);
        // Grouped subtotals add up to the report's own total.
        $sum = 0;
        foreach (collect($roll['rows'])->groupBy('property') as $rows) {
            $sum += $rows->sum(fn ($r) => (int) ($r['owed_minor'] ?? 0));
        }
        $this->assertSame((int) $roll['totals']['owed_minor'], $sum);
        $this->assertStringContainsString(RentalExport::money((int) $roll['totals']['owed_minor'], 0), $html);

        $income = $this->html($this->report($b, 'income_statement'), $b->cid);
        $this->assertStringContainsString('Total income', $income);
        $this->assertStringContainsString('>Net<', $income);

        $receipts = $this->report($b, 'receipts');
        $this->assertNotContains('Standing', array_column($receipts['rows'], 'status'));
        $this->assertStringStartsWith('payment:', (string) $receipts['rows'][0]['_peek'], 'a report row carries its record for the preview');
        $this->assertStringStartsWith('lease:', (string) collect($roll['rows'])->firstWhere('lease', '!=', null)['_peek']);
    }

    public function test_the_monthly_report_is_one_page_to_sign_with_the_figures_named_and_deposits_never_income(): void
    {
        $this->at('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$p, $u] = $this->building($owner, 3, 400000);
        $l1 = $this->tenantIn($owner, $u[0], '2026-03-01', ['deposit_minor' => 100000]);
        $l2 = $this->tenantIn($owner, $u[1], '2026-01-01', ['deposit_minor' => 0]);
        $this->at('2026-03-05 09:00:00');
        app(Payments::class)->receive($owner, $l1, ['amount_minor' => 500000, 'method' => 'cash']);
        foreach (range(1, 40) as $i) {
            app(Expenses::class)->record($owner, ['property_id' => $p->id, 'category' => 'Kind number '.($i % 30), 'description' => "Expense {$i} with a long description that goes on", 'amount_minor' => 100 * $i, 'method' => 'cash']);
        }
        $this->at('2026-03-20 09:00:00');
        app(Payouts::class)->record($owner, \App\Models\RentalLandlord::withoutGlobalScopes()->findOrFail($p->landlord_id), ['amount_minor' => 50000, 'method' => 'cash']);
        // The whole of the last day counts, at 23:59.
        $this->at('2026-03-31 23:59:00');
        $paidLast = app(Payments::class)->receive($owner, $l2, ['amount_minor' => 40000, 'method' => 'cash']);
        $this->assertSame('2026-03-31', $paidLast->paid_on->toDateString());
        $this->at('2026-04-02 09:00:00');

        $r = (new RentalReports)->run('monthly_building', $cid, $owner, ['month' => '2026-03']);
        $f = $r['figures'];
        $this->assertSame(440000, $f['income_minor'], 'rent 400,000 + 40,000 on the last day; the deposit is apart');
        $this->assertSame(100000, $f['deposits_received_minor']);
        $this->assertSame(50000, $f['paid_out_minor']);
        $this->assertSame(['units' => 3, 'occupied' => 2], ['units' => $f['units'], 'occupied' => $f['occupied']]);
        $aging = (new RentalReports)->run('arrears_aging', $cid, $owner);
        $this->assertSame((int) $aging['totals']['total_minor'], $f['overdue_minor'], 'the same figure as the arrears report');
        $this->assertGreaterThan(0, $f['overdue_tenants']);
        $this->assertSame('Balance for the landlords', $r['sections'][1]['total_label']);

        $pdf = RentalExport::pdf($r, $cid);
        $this->assertSame(1, $this->pageCount($pdf), 'one A4 page at 40 expenses in 30 categories');
        $this->assertLessThan(120 * 1024, strlen($pdf));
        $html = $this->html($r, $cid);
        foreach (['Collected', 'Paid to landlords', 'Balance for the landlords', 'not income', 'Prepared by', 'Checked by', '2 of 3 units occupied', 'overdue on'] as $words) {
            $this->assertStringContainsString($words, $html);
        }
        if (($text = $this->pdfText($pdf)) !== null) {
            $this->assertStringContainsString('Page 1 of 1', $text);
            $this->assertStringContainsString('Other categories', $text, 'the longest categories, the rest added up');
            $this->assertStringContainsString(RentalExport::money($f['expenses_minor'], 0), $text);
        }
        $this->assertStringContainsString('Expense 40', RentalExport::csv($r, $cid), 'the CSV keeps each one');

        // A caretaker's report keeps to their buildings: no whole-account payouts.
        config(['permissions.roles.rentals_caretaker.permissions' => array_merge((array) config('permissions.roles.rentals_caretaker.permissions'), ['rentals_reports'])]);
        $caretaker = $this->rentalsStaff($company->fresh(), 'rentals_caretaker');
        app(Properties::class)->assignStaff($owner, $p, [$caretaker->id]);
        $theirs = (new RentalReports)->run('monthly_building', $cid, $caretaker->fresh(), ['month' => '2026-03']);
        $this->assertArrayNotHasKey('paid_out_minor', $theirs['figures']);
        $this->assertStringNotContainsString('Paid to', $this->html($theirs, $cid));
        $this->assertBooksAgree($cid);
    }

    public function test_the_excel_file_is_finished_and_its_sections_have_their_own_sheets(): void
    {
        $b = $this->books();
        $this->at('2026-04-01 09:00:00');
        $parts = $this->xlsxParts(RentalExport::xlsx($this->report($b, 'collections'), $b->cid));
        $book = (string) $parts['xl/workbook.xml'];
        foreach (['Collections', 'By property', 'By method', 'By who received it'] as $sheet) {
            $this->assertStringContainsString('name="'.$sheet.'"', $book);
        }
        $this->assertStringContainsString('_xlnm._FilterDatabase', $book);

        $styles = simplexml_load_string((string) $parts['xl/styles.xml']);
        $formats = [];
        foreach ($styles->numFmts->numFmt as $n) {
            $formats[(int) $n['numFmtId']] = (string) $n['formatCode'];
        }
        $this->assertEqualsCanonicalizing(['#,##0', '#,##0.00', '0.0%', 'd mmm yyyy'], array_values($formats));
        $xfs = [];
        foreach ($styles->cellXfs->xf as $xf) {
            $xfs[] = $xf;
        }

        $roll = simplexml_load_string((string) $this->xlsxParts(RentalExport::xlsx($this->report($b, 'rent_roll'), $b->cid))['xl/worksheets/sheet1.xml']);
        $this->assertNotEmpty($roll->cols->col, 'column widths');
        $this->assertGreaterThan(8, (float) $roll->cols->col[0]['width']);
        $pane = $roll->sheetViews->sheetView->pane;
        $this->assertSame(['4', 'frozen'], [(string) $pane['ySplit'], (string) $pane['state']]);
        $this->assertStringStartsWith('A4:', (string) $roll->autoFilter['ref']);

        $heading = $roll->sheetData->row[3];
        $this->assertSame('4', (string) $heading['r']);
        $this->assertSame('Property', (string) $heading->c[0]->is->t);
        $this->assertSame('1', (string) $xfs[(int) $heading->c[0]['s']]['fontId'], 'bold heading');

        // Row 5 on: a real date and real money, with their formats; no formula anywhere.
        $seen = ['date' => false, 'money' => false];
        foreach ($roll->sheetData->row as $row) {
            foreach ($row->c ?? [] as $c) {
                $format = $formats[(int) $xfs[(int) $c['s']]['numFmtId']] ?? null;
                if ($format === 'd mmm yyyy') {
                    $seen['date'] = true;
                    $this->assertNotSame('inlineStr', (string) $c['t'], 'a date cell is a number');
                    $this->assertSame(46023, (int) Carbon::parse('1899-12-30', 'UTC')->diffInDays(Carbon::parse('2026-01-01', 'UTC')));
                }
                if ($format === '#,##0' && (int) $c->v >= 300000) {
                    $seen['money'] = true;
                }
                $this->assertFalse(isset($c->f), 'no formulas');
            }
        }
        $this->assertSame(['date' => true, 'money' => true], $seen);

        // Percentages are real percentages; totals are bold.
        $occ = simplexml_load_string((string) $this->xlsxParts(RentalExport::xlsx($this->report($b, 'occupancy'), $b->cid))['xl/worksheets/sheet1.xml']);
        $pct = [];
        $boldTotal = false;
        foreach ($occ->sheetData->row as $row) {
            foreach ($row->c ?? [] as $c) {
                if (($formats[(int) $xfs[(int) $c['s']]['numFmtId']] ?? null) === '0.0%') {
                    $pct[] = (float) $c->v;
                }
                if ((string) ($c->is->t ?? '') === 'Total') {
                    $boldTotal = (string) $xfs[(int) $c['s']]['fontId'] === '1';
                }
            }
        }
        $this->assertNotEmpty($pct);
        $this->assertLessThanOrEqual(1.0, max($pct));
        $this->assertTrue($boldTotal);
    }

    public function test_the_csv_has_its_headings_on_row_1_neutralises_formulas_and_keeps_a_phone_as_text(): void
    {
        $b = $this->books();
        $this->at('2026-04-01 09:00:00');
        DB::table('rental_tenants')->where('id', $b->leases[1]->tenant_id)->update(['name' => '=cmd|calc', 'phone' => '+256700000474']);
        foreach (RentalReports::keys() as $key) {
            $report = $this->report($b, $key);
            $h = fopen('php://temp', 'r+');
            fwrite($h, substr(RentalExport::csv($report, $b->cid), 3));
            rewind($h);
            $first = fgetcsv($h);
            fclose($h);
            $this->assertSame(array_column($report['columns'], 'label'), $first, "{$key}: the headings are row 1");
        }
        $csv = RentalExport::csv($this->report($b, 'rent_roll'), $b->cid);
        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringNotContainsString(',=cmd', $csv);
        $this->assertStringContainsString('0700 000474', $csv);
        $this->assertStringNotContainsString('+256700000474', $csv);
        $this->assertStringContainsString((string) Company::withoutGlobalScopes()->find($b->cid)->name, $csv, 'what the file is comes last');
    }

    public function test_a_receipt_prints_as_issued_on_a_till_or_a5_with_the_logo_it_was_issued_with(): void
    {
        $b = $this->books();
        $media = sys_get_temp_dir().'/rental-docs-'.uniqid();
        File::ensureDirectoryExists($media);
        config(['budgetpro.media_root' => $media]);
        $image = imagecreatetruecolor(1400, 700);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 90, 130));
        imagepng($image, $media.'/first.png');
        imagepng($image, $media.'/second.png');
        Company::withoutGlobalScopes()->whereKey($b->cid)->update(['logo' => 'first.png']);

        $this->at('2026-03-25 09:00:00');
        $p = app(Payments::class)->receive($b->owner, $b->leases[4], ['amount_minor' => 123456789, 'method' => 'cash']);
        $this->assertSame('first.png', $p->fresh()->receipt_snapshot['logo']);
        $parts = Receipt::parts($p->fresh());
        $text = Receipt::text($p->fresh());
        $till = Receipt::pdf($p->fresh());
        $a5 = Receipt::pdf($p->fresh(), 'a5');
        foreach ([$till, $a5] as $pdf) {
            $this->assertSame(1, $this->pageCount($pdf));
            $this->assertLessThan(90 * 1024, strlen($pdf), 'tens of KB, logo included');
            $this->assertSame(1, preg_match('#/Subtype\s*/Image#', $pdf), 'the logo is on it');
            $this->assertSame(1, preg_match('#/FontName\s*/[A-Z]{6}\+DejaVuSans#', $pdf));
        }
        $this->assertSame(1, preg_match('#/MediaBox\s*\[\s*0(?:\.0+)? 0(?:\.0+)? 226\.77\d* ([\d.]+)#', $till, $m));
        $this->assertLessThan(620, (float) $m[1], 'the page is cut to its content');
        if (($read = $this->pdfText($till)) !== null) {
            $this->assertStringContainsString('UGX 123,456,789', $read, 'the figure stays on one line');
        }

        // Everything may change: a reprint does not.
        Company::withoutGlobalScopes()->whereKey($b->cid)->update(['logo' => 'second.png', 'name' => 'Renamed Agency', 'address' => 'Somewhere else']);
        $this->assertSame($parts, Receipt::parts($p->fresh()));
        $this->assertSame($text, Receipt::text($p->fresh()));
        $this->assertStringNotContainsString('Renamed Agency', (string) $this->pdfText(Receipt::pdf($p->fresh())));
        File::deleteDirectory($media);
    }

    public function test_there_is_one_landlord_statement_and_a_tenant_statement_leads_with_what_is_owed(): void
    {
        $b = $this->books();
        $this->at('2026-04-01 09:00:00');
        $landlord = \App\Models\RentalLandlord::withoutGlobalScopes()->findOrFail($b->p->landlord_id);
        $pdf = StatementPdf::render($landlord, '2026-03-01', '2026-03-31');
        $this->assertStringStartsWith('%PDF', $pdf);
        $report = $this->report($b, 'landlord_statement');
        $this->assertSame('To landlord', collect($report['columns'])->firstWhere('key', 'amount_minor')['label']);
        $html = $this->html($report, $b->cid);
        foreach (['Owed to the landlord at the end', 'Balance at the start', 'Rent and fees collected', 'Each line', 'vacant'] as $words) {
            $this->assertStringContainsString($words, $html);
        }
        $this->assertStringNotContainsString('Nothing to show', $html);
        if (($a = $this->pdfText($pdf)) !== null) {
            $b2 = (string) $this->pdfText(RentalExport::pdf($report, $b->cid));
            $strip = fn (string $t) => preg_replace('/generated .*/', '', $t);
            $this->assertSame($strip($b2), $strip($a), 'the statement from the landlord\'s page is the report');
        }

        $tenant = $this->html($this->report($b, 'tenant_statement', ['from' => '2026-01-01']), $b->cid);
        $this->assertStringContainsString('Owed', $tenant);
        $this->assertStringNotContainsString('Opening balance', $tenant, 'nothing carried in, no opening line');

        $letterhead = RentalDocument::letterhead($b->cid);
        $this->assertSame((string) Company::withoutGlobalScopes()->find($b->cid)->name, $letterhead['name']);
        $this->assertNull($letterhead['logo']);
    }

    public function test_the_statement_a_landlord_reads_in_one_look_lc08(): void
    {
        $b = $this->books();
        $this->at('2026-04-02 09:00:00');
        $cid = (int) $b->cid;
        $landlord = \App\Models\RentalLandlord::withoutGlobalScopes()->findOrFail($b->p->landlord_id);
        $report = StatementPdf::report($landlord, '2026-03-01', '2026-03-31');
        $keys = array_column($report['sections'], 'key');
        foreach (['units', 'held', 'arrears'] as $key) {
            $this->assertContains($key, $keys);
        }
        $this->assertSame('Commission: 10% of rent collected', $report['figures']['statement']['commission_rule']);
        $units = collect($report['sections'])->firstWhere('key', 'units');
        $this->assertSame(['unit', 'tenant', 'rent_minor', 'paid_minor', 'owed_minor', 'behind', 'paid_up_to'], array_values(array_diff(array_column($units['columns'], 'key'), ['property'])));

        // In the order the paper promises, with what is empty left out.
        $html = $this->html($report, $cid);
        $at = fn (string $words) => strpos($html, $words);
        $this->assertLessThan($at('Each line'), $at('Unit by unit'));
        $this->assertLessThan($at('Held for you, not in your balance'), $at('Each line'));
        $this->assertStringContainsString('It is not in your balance.', $html);
        $this->assertStringNotContainsString('Corrections from earlier months', $html);
        $this->assertStringContainsString('Commission: 10% of rent collected', $html);
        $this->assertStringNotContainsString('What tenants still owe', $html, 'the unit table says it, former tenants included');

        // Held for you is the deposit register and the lease credit, and is no part of the balance.
        $held = collect($report['sections'])->firstWhere('key', 'held');
        $deposits = (new \App\Services\Rentals\Reports\RentalReports)->run('deposit_register', $cid, null, ['landlord_id' => (int) $landlord->id]);
        $this->assertSame((int) $deposits['totals']['held_minor'], (int) $held['totals']['deposit_minor']);
        $credit = (int) \Illuminate\Support\Facades\DB::table('rental_entries')->where('company_id', $cid)->where('occurred_on', '<=', '2026-03-31')->sum('credit_minor');
        $this->assertSame($credit, (int) $held['totals']['credit_minor']);
        $this->assertSame(\App\Services\Rentals\Landlords\LandlordAccounts::balance($landlord, '2026-03-31'), (int) $report['totals']['closing_minor'], 'the closing line is the journal, with or without what is held');

        // Every file of it opens: sheet names cut to what a spreadsheet allows, and no other app named.
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            [$bytes] = RentalExport::render($report, $cid, $format);
            $this->assertNotSame('', $bytes);
        }
        foreach (['Budget Pro', 'Shelfie', 'Kuku Tally', 'Akiba', 'Pledge', 'Solavia'] as $other) {
            $this->assertStringNotContainsString($other, $html);
        }

        // Frozen: a later payment moves nothing on the issued paper; the unit table says what it said.
        app(\App\Services\Rentals\Landlords\MonthClose::class)->close($b->owner, '2026-01');
        $this->at('2026-04-03 09:00:00');
        app(\App\Services\Rentals\Landlords\MonthClose::class)->close($b->owner, '2026-02');
        app(\App\Services\Rentals\Landlords\MonthClose::class)->close($b->owner, '2026-03');
        $issued = StatementPdf::report($landlord, '2026-03-01', '2026-03-31');
        $before = collect($issued['sections'])->firstWhere('key', 'units');
        $lease = \App\Models\RentalLease::withoutGlobalScopes()->where('company_id', $cid)->whereIn('status', \App\Models\RentalLease::LIVE)->firstOrFail();
        app(Payments::class)->receive($b->owner, $lease, ['amount_minor' => 123000, 'method' => 'cash']);
        $this->assertEquals($before, collect(StatementPdf::report($landlord, '2026-03-01', '2026-03-31')['sections'])->firstWhere('key', 'units'));
        $this->assertBooksAgree($cid);
    }

    public function test_many_properties_go_under_headings_expenses_and_payouts_show_and_owner_mode_has_no_commission_line(): void
    {
        $this->at('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        $landlord = app(\App\Services\Rentals\Directory\Landlords::class)->create($owner, ['name' => 'Two Buildings', 'phone' => '0772444555', 'commission_type' => 'percent', 'commission_bp' => 1000]);
        $first = app(\App\Services\Rentals\Directory\Properties::class)->create($owner, ['name' => 'First Court', 'landlord_id' => $landlord->id, 'kind' => 'residential']);
        $second = app(\App\Services\Rentals\Directory\Properties::class)->create($owner, ['name' => 'Second Court', 'landlord_id' => $landlord->id, 'kind' => 'residential', 'commission_type' => 'flat', 'commission_minor' => 15000]);
        $u1 = app(\App\Services\Rentals\Directory\Units::class)->createMany($owner, (int) $first->id, 'A', 1, 1, ['rent_minor' => 300000, 'kind' => 'apartment']);
        $u2 = app(\App\Services\Rentals\Directory\Units::class)->createMany($owner, (int) $second->id, 'B', 1, 1, ['rent_minor' => 200000, 'kind' => 'apartment']);
        $l1 = $this->tenantIn($owner, $u1[0], '2026-03-01', ['deposit_minor' => 0]);
        $l2 = $this->tenantIn($owner, $u2[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $l1, ['amount_minor' => 300000, 'method' => 'cash']);
        app(Payments::class)->receive($owner, $l2, ['amount_minor' => 200000, 'method' => 'cash']);
        app(\App\Services\Rentals\Expenses::class)->record($owner, ['amount_minor' => 20000, 'method' => 'cash', 'category' => 'Repairs', 'description' => 'Tap', 'vendor' => 'Musa', 'charged_to' => 'landlord', 'property_id' => $first->id]);
        app(\App\Services\Rentals\Landlords\Payouts::class)->record($owner, $landlord, ['amount_minor' => 50000, 'method' => 'cash', 'reference' => 'SL-1']);
        $this->at('2026-04-02 09:00:00');
        $report = StatementPdf::report($landlord, '2026-03-01', '2026-03-31');
        $this->assertSame('Commission: by unit, see below', $report['figures']['statement']['commission_rule']);
        $html = $this->html($report, $cid);
        foreach (['First Court', 'Second Court', 'Expenses', 'Musa', 'Repairs: Tap', 'Paid to you', 'SL-1', 'Commission: by unit, see below'] as $words) {
            $this->assertStringContainsString($words, $html);
        }
        $expenses = collect($report['sections'])->firstWhere('key', 'expenses');
        $this->assertSame('No', $expenses['rows'][0]['receipt']);

        $this->at('2026-03-01 09:00:00');
        [$solo, $me] = $this->rentalsAccount('owner');
        [, $units] = $this->building($me, 1, 300000);
        $lease = $this->tenantIn($me, $units[0], '2026-03-01', ['deposit_minor' => 100000]);
        app(Payments::class)->receive($me, $lease, ['amount_minor' => 400000, 'method' => 'cash']);
        $this->at('2026-04-02 09:00:00');
        $self = \App\Services\Rentals\RentalsModule::selfLandlord((int) $solo->id);
        $report = StatementPdf::report($self, '2026-03-01', '2026-03-31');
        $this->assertNull($report['figures']['statement']['commission_rule']);
        $html = $this->html($report, (int) $solo->id);
        $this->assertStringNotContainsString('Commission', $html);
        $this->assertStringContainsString('Held for your tenants', $html);
        $this->assertBooksAgree((int) $solo->id);
    }
}
