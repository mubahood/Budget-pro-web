<?php

namespace Tests\Feature\Rentals;

use App\Models\RentalLandlord;
use App\Models\RentalStatement;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Jobs\RentalCheck;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Landlords\StatementBook;
use App\Services\Rentals\Landlords\StatementPdf;
use App\Services\Rentals\Landlords\StatementProof;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Reports\RentalExport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * The nightly proof of each statement (LC-05): each fault, forged with a raw change inside the test's transaction, is reported by the check that is there for it; and the proof code
 * prints on the paper and reads the same on the portal.
 */
class RentalsCheckProofTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Two closed months with money and a part payment. */
    private function books(): object
    {
        Carbon::setTestNow('2026-09-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$p, $units] = $this->building($owner, 2, 400000);
        $lease = $this->tenantIn($owner, $units[0], '2026-09-01', ['deposit_minor' => 100000]);
        $pay = app(Payments::class);
        Carbon::setTestNow('2026-09-10 09:00:00');
        $pay->receive($owner, $lease, ['amount_minor' => 350000, 'method' => 'cash']);
        Carbon::setTestNow('2026-10-02 09:00:00');
        app(MonthClose::class)->close($owner, '2026-09');
        app(\App\Services\Rentals\Jobs\RentalDaily::class)->run((int) $company->id);
        $pay->receive($owner, $lease->fresh(), ['amount_minor' => 300000, 'method' => 'cash']);
        $landlord = RentalLandlord::withoutGlobalScopes()->findOrFail($p->landlord_id);
        Carbon::setTestNow('2026-10-20 09:00:00');
        app(Expenses::class)->record($owner, ['amount_minor' => 10000, 'method' => 'cash', 'category' => 'Repairs', 'charged_to' => 'landlord', 'property_id' => $p->id]);
        app(Payouts::class)->record($owner, $landlord, ['amount_minor' => 10000, 'method' => 'cash']);
        Carbon::setTestNow('2026-11-03 09:00:00');
        app(MonthClose::class)->close($owner, '2026-10');

        return (object) ['cid' => (int) $company->id, 'owner' => $owner, 'landlord' => $landlord, 'lease' => $lease, 'property' => $p];
    }

    /** @return list<string> the checks that report something */
    private function failing(int $cid): array
    {
        return array_column((new RentalCheck)->run($cid), 'check');
    }

    /** Forge a fault in a savepoint, read what the check says, and undo it. */
    private function forged(object $b, string $check, callable $fault, ?string $because = null): void
    {
        DB::beginTransaction();
        try {
            $fault();
            $this->assertContains($check, $this->failing($b->cid), $because ?? "{$check} reports its fault");
        } finally {
            DB::rollBack();
        }
        $this->assertSame([], $this->failing($b->cid), "clean again after {$check}");
    }

    public function test_each_fault_is_reported_by_its_check_and_clean_books_report_nothing(): void
    {
        $b = $this->books();
        $this->assertSame([], $this->failing($b->cid));
        $statement = fn () => RentalStatement::where('company_id', $b->cid)->where('month', '2026-10-01')->where('status', 'issued')->firstOrFail();
        $table = fn () => DB::table('rental_statements')->where('id', $statement()->id);

        $this->forged($b, 'allocation_journal', fn () => DB::table('rental_allocations')->where('company_id', $b->cid)->where('commission_minor', '>', 0)->limit(1)
            ->update(['commission_minor' => DB::raw('commission_minor - 1'), 'landlord_minor' => DB::raw('landlord_minor + 1')]));
        $this->forged($b, 'allocation_journal', fn () => DB::table('rental_entries')->where('company_id', $b->cid)->where('type', 'collected')->where('income_minor', '>', 0)->limit(1)
            ->update(['income_minor' => DB::raw('income_minor - 5'), 'landlord_minor' => DB::raw('landlord_minor + 5')]));
        $this->forged($b, 'statement_opening', fn () => $table()->update(['opening_minor' => 1]));
        $this->forged($b, 'statement_closing', fn () => $table()->update(['closing_minor' => 1]));
        $this->forged($b, 'statement_chain', fn () => DB::table('rental_statements')->where('company_id', $b->cid)->where('month', '2026-09-01')->update(['closing_minor' => DB::raw('closing_minor + 1')]));
        $this->forged($b, 'statement_lines', function () use ($statement, $table) {
            $body = $statement()->body;
            $body['lines'][0]['amount_minor'] += 1;
            $table()->update(['body' => json_encode($body)]);
        });
        $this->forged($b, 'statement_proof', function () use ($statement, $table) {
            $body = $statement()->body;
            $body['lines'][0]['description'] = 'Edited by hand';
            $table()->update(['body' => json_encode($body)]);
        });
        $this->forged($b, 'close_total', fn () => DB::table('rental_closes')->where('company_id', $b->cid)->where('month', '2026-10-01')->update(['totals' => json_encode(['owed_to_landlords' => 1, 'owed_back_by_landlords' => 0])]));
        $this->forged($b, 'closed_after', function () use ($b) {
            Carbon::setTestNow('2026-11-03 10:00:00');
            DB::table('rental_entries')->insert(['company_id' => $b->cid, 'group_id' => 'forged', 'type' => 'expense_agency', 'occurred_on' => '2026-10-15', 'method' => 'cash', 'cash_minor' => -1, 'landlord_minor' => 0, 'deposit_minor' => 0,
                'credit_minor' => 0, 'income_minor' => 0, 'expense_minor' => 1, 'equity_minor' => 0, 'created_at' => now()]);
        });
        $this->forged($b, 'lease_landlord', function () use ($b) {
            $other = app(\App\Services\Rentals\Directory\Landlords::class)->create($b->owner, ['name' => 'Someone Else', 'phone' => '0772555123']);
            DB::table('rental_properties')->where('id', $b->property->id)->update(['landlord_id' => $other->id]);
        });
        $this->forged($b, 'commission_report', fn () => DB::table('rental_charges')->where('company_id', $b->cid)->where('kind', 'rent')
            ->whereIn('id', DB::table('rental_allocations')->where('company_id', $b->cid)->where('applied_on', '>=', '2026-10-01')->where('commission_minor', '>', 0)->select('charge_id'))
            ->update(['kind' => 'item', 'commission_type' => 'none']));

        // A month reopened: its statements are not held to the books until it closes again, and a row added then is no fault.
        app(MonthClose::class)->reopen($b->owner, '2026-10', 'A fix');
        app(Payments::class)->receive($b->owner, $b->lease->fresh(), ['amount_minor' => 1000, 'method' => 'cash', 'paid_on' => '2026-10-25']);
        $this->assertSame([], $this->failing($b->cid));
        Carbon::setTestNow('2026-11-03 10:00:00');
        app(MonthClose::class)->close($b->owner, '2026-10');
        $this->assertSame([], $this->failing($b->cid), 'closed again, the row is older than the close');
    }

    public function test_the_proof_prints_on_the_paper_and_is_the_portals(): void
    {
        $b = $this->books();
        $s = StatementBook::current($b->landlord, '2026-10');
        $short = StatementProof::short($s->proof);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{4} [0-9A-F]{4} [0-9A-F]{4}$/', $short);
        $report = StatementPdf::report($b->landlord, '2026-10-01', '2026-10-31');
        $this->assertSame($short, $report['figures']['statement']['proof_short']);
        $this->assertSame($s->proof, StatementProof::of($s->fresh()->body));
        $pdf = RentalExport::pdf($report, $b->cid);
        $this->assertStringStartsWith('%PDF', $pdf);
        $text = $this->pdfText($pdf);
        if ($text !== null) {
            $this->assertStringContainsString('Proof '.$short, $text);
            $this->assertStringContainsString($s->number, $text);
            $this->assertStringNotContainsString('generated', $text, 'an issued copy prints the same each time');
        }
    }

    public function test_the_built_demo_is_clean_after_a_close(): void
    {
        config(['rentals.demo.enabled' => true, 'edition.name' => 'saas']);
        $disk = sys_get_temp_dir().'/rentals-demo-proof-'.uniqid();
        \Illuminate\Support\Facades\Storage::set('public', \Illuminate\Support\Facades\Storage::createLocalDriver(['root' => $disk]));
        Carbon::setTestNow('2026-11-03 10:00:00');
        try {
            $demo = new \App\Services\Rentals\Demo\RentalsDemo;
            $demo->rebuild(null, \App\Services\Rentals\Demo\RentalsDemo::AGENCY);
            $company = \App\Services\Rentals\Demo\RentalsDemo::company();
            $cid = (int) $company->id;
            $this->assertSame([], $this->failing($cid), 'all clean on the built demo');
            $owner = \App\Services\Rentals\Demo\RentalsDemo::login('owner');
            $close = app(MonthClose::class)->close($owner, '2026-10');
            $this->assertSame('closed', $close->status);
            $this->assertSame([], $this->failing($cid), 'and after a close');
            $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('rentals:check', ['--company' => $cid]));
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($disk);
        }
    }

    private function pdfText(string $pdf): ?string
    {
        $bin = trim((string) shell_exec('command -v pdftotext'));
        if ($bin === '') {
            return null;
        }
        $file = tempnam(sys_get_temp_dir(), 'stmt');
        file_put_contents($file, $pdf);
        $text = (string) shell_exec(escapeshellarg($bin).' -layout '.escapeshellarg($file).' -');
        @unlink($file);

        return $text;
    }
}
