<?php

namespace Tests\Feature\Rentals;

use App\Models\Company;
use App\Models\RentalClose;
use App\Models\RentalLandlord;
use App\Models\RentalLogin;
use App\Models\RentalPayment;
use App\Models\RentalStatement;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Jobs\RentalDaily;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Landlords\StatementBook;
use App\Services\Rentals\Landlords\StatementPdf;
use App\Services\Rentals\Landlords\StatementProof;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Portal\PersonView;
use App\Services\Rentals\RentalLinks;
use App\Services\Rentals\Reports\RentalExport;
use App\Services\Rentals\Reports\RentalReports;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * Issued statements (LC-03): a frozen copy per landlord per closed month, read by every door; reopening and closing again keeps what did not change and replaces what did, saying how; an open
 * month is stamped; another landlord's cannot be reached.
 */
class RentalsStatementBookTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Two landlords: A with a paid October and an expense and a payout, B with one receipt. The clock on 3 November, nothing closed. */
    private function october(): object
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$pa, $ua] = $this->building($owner, 2, 500000);
        [$pb, $ub] = $this->building($owner, 1, 200000);
        $la = $this->tenantIn($owner, $ua[0], '2026-10-01', ['deposit_minor' => 0]);
        $lb = $this->tenantIn($owner, $ub[0], '2026-10-01', ['deposit_minor' => 0]);
        $pay = app(Payments::class);
        Carbon::setTestNow('2026-10-05 09:00:00');
        $pay->receive($owner, $la, ['amount_minor' => 500000, 'method' => 'bank']);
        $pay->receive($owner, $lb, ['amount_minor' => 100000, 'method' => 'cash']);
        Carbon::setTestNow('2026-10-20 09:00:00');
        $a = RentalLandlord::withoutGlobalScopes()->findOrFail($pa->landlord_id);
        $b = RentalLandlord::withoutGlobalScopes()->findOrFail($pb->landlord_id);
        app(Expenses::class)->record($owner, ['amount_minor' => 30000, 'method' => 'bank', 'category' => 'Repairs', 'charged_to' => 'landlord', 'property_id' => $pa->id, 'vendor' => 'A plumber']);
        app(Payouts::class)->record($owner, $a, ['amount_minor' => 100000, 'method' => 'bank', 'reference' => 'SLIP-9']);
        Carbon::setTestNow('2026-11-03 09:00:00');

        return (object) ['company' => $company, 'cid' => (int) $company->id, 'owner' => $owner, 'a' => $a, 'b' => $b, 'la' => $la, 'lb' => $lb, 'pa' => $pa, 'pay' => $pay, 'ua' => $ua];
    }

    private function close(object $x, string $month = '2026-10'): RentalClose
    {
        return app(MonthClose::class)->close($x->owner, $month);
    }

    public function test_a_close_writes_one_statement_a_landlord_equal_to_the_journal_line_for_line(): void
    {
        $x = $this->october();
        $live = LandlordAccounts::statement($x->a, '2026-10-01', '2026-10-31');
        $close = $this->close($x);
        $this->assertEquals(['issued' => 2, 'kept' => 0, 'replaced' => 0], $close->totals['statements']);

        $s = StatementBook::current($x->a, '2026-10');
        $this->assertMatchesRegularExpression('/^ST-2026-10-000[12]$/', $s->number);
        $this->assertSame('issued', $s->status);
        $this->assertSame(1, (int) $s->version);
        $this->assertEquals($live['lines'], $s->body['lines']);
        $this->assertEquals($live['totals'], $s->body['totals']);
        $this->assertSame($live['opening_minor'], (int) $s->opening_minor);
        $this->assertSame($live['closing_minor'], (int) $s->closing_minor);
        $this->assertSame((int) $live['totals']['collected_minor'], (int) $s->collected_minor);
        $this->assertSame($s->proof, StatementProof::of($s->fresh()->body), 'the proof survives the database');
        $this->assertSame(['status' => 'issued', 'number' => $s->number, 'issued_on' => '2026-11-03'], StatementBook::statusFor($x->a, '2026-10'));
        $this->assertSame(['status' => 'open', 'number' => null, 'issued_on' => null], StatementBook::statusFor($x->a, '2026-11'));
        $this->assertSame(2, (int) DB::table('rental_settings')->where('company_id', $x->cid)->value('seq_statement'));
        $this->assertBooksAgree($x->cid);
    }

    public function test_a_landlord_with_nothing_this_month_has_no_statement_and_one_with_only_a_balance_or_a_unit_has(): void
    {
        $x = $this->october();
        $idle = app(\App\Services\Rentals\Directory\Landlords::class)->create($x->owner, ['name' => 'Nothing Here', 'phone' => '0772000111']);
        Carbon::setTestNow('2026-10-20 09:00:00');
        [, $empty] = $this->building($x->owner, 1, 100000);
        Carbon::setTestNow('2026-11-03 09:00:00');
        $this->close($x);
        $this->assertNull(StatementBook::current($idle, '2026-10'));
        $ids = DB::table('rental_statements')->where('company_id', $x->cid)->pluck('landlord_id');
        $this->assertCount(3, $ids, 'the two with money and the one with an empty unit');
        $this->assertSame(StatementBook::STAMP_NOT_ISSUED, StatementBook::read($idle, '2026-10-01', '2026-10-31')['stamp']);
        $this->assertNotNull($empty);
    }

    public function test_reopen_add_a_back_dated_line_and_close_replaces_with_the_difference_and_the_old_stays_readable(): void
    {
        $x = $this->october();
        $this->close($x);
        $old = StatementBook::current($x->b, '2026-10');
        $untouched = StatementBook::current($x->a, '2026-10');
        app(MonthClose::class)->reopen($x->owner, '2026-10', 'A receipt was missed');

        // While reopened, the month reads live and is stamped; a link to the copy says so.
        $this->assertSame(StatementBook::STAMP_OPEN, StatementBook::read($x->b, '2026-10-01', '2026-10-31')['stamp']);
        $this->assertSame('This month was reopened: a corrected statement may follow.', StatementBook::read($x->b, '2026-10-01', '2026-10-31', (int) $old->id)['stamp']);
        $this->assertSame(['status' => 'open', 'number' => null, 'issued_on' => null], StatementBook::statusFor($x->b, '2026-10'));

        $x->pay->receive($x->owner, $x->lb->fresh(), ['amount_minor' => 50000, 'method' => 'cash', 'paid_on' => '2026-10-28']);
        Carbon::setTestNow('2026-11-04 09:00:00');
        $again = $this->close($x);
        $this->assertSame(2, (int) $again->version);
        $this->assertEquals(['issued' => 1, 'kept' => 1, 'replaced' => 1], $again->totals['statements'], 'only the landlord whose figures changed');

        $new = StatementBook::current($x->b, '2026-10');
        $this->assertNotSame($old->number, $new->number);
        $this->assertSame(2, (int) $new->version);
        $this->assertSame((int) $old->id, (int) $new->replaces_id);
        $this->assertSame($old->number, $new->body['replaces']['number']);
        $what = collect($new->body['replaces']['difference'])->keyBy('what');
        $this->assertEquals(['what' => 'Rent and fees collected', 'before_minor' => 100000, 'after_minor' => 150000], $what['Rent and fees collected']);
        $this->assertTrue($what->has('Balance at the end'));

        $old = $old->fresh();
        $this->assertSame('replaced', $old->status);
        $this->assertSame((int) $new->id, (int) $old->replaced_by_id);
        $this->assertSame('Replaced by '.$new->number.'.', StatementBook::read($x->b, '2026-10-01', '2026-10-31', (int) $old->id)['stamp']);
        $this->assertSame((int) $old->closing_minor, (int) StatementBook::read($x->b, '2026-10-01', '2026-10-31', (int) $old->id)['closing_minor'], 'the old paper still says what it said');

        $same = StatementBook::current($x->a, '2026-10');
        $this->assertSame((int) $untouched->id, (int) $same->id, 'same statement, no new version');
        $this->assertSame($untouched->number, $same->number);
        $this->assertBooksAgree($x->cid);
    }

    public function test_reopen_changing_nothing_for_anyone_keeps_every_statement(): void
    {
        $x = $this->october();
        $this->close($x);
        $before = RentalStatement::where('company_id', $x->cid)->orderBy('id')->pluck('number')->all();
        app(MonthClose::class)->reopen($x->owner, '2026-10', 'Looking');
        $again = $this->close($x);
        $this->assertEquals(['issued' => 0, 'kept' => 2, 'replaced' => 0], $again->totals['statements']);
        $this->assertSame($before, RentalStatement::where('company_id', $x->cid)->orderBy('id')->pluck('number')->all());
    }

    public function test_next_months_opening_is_this_months_closing_across_versions(): void
    {
        $x = $this->october();
        $this->close($x);
        Carbon::setTestNow('2026-12-02 09:00:00');
        app(RentalDaily::class)->run($x->cid);
        $this->close($x, '2026-11');
        $nov = StatementBook::current($x->a, '2026-11');
        $this->assertSame((int) StatementBook::current($x->a, '2026-10')->closing_minor, (int) $nov->opening_minor);

        // A correction to October: November then October reopen, both close again, and the chain still holds.
        $close = app(MonthClose::class);
        $close->reopen($x->owner, '2026-11', 'Back to October');
        $close->reopen($x->owner, '2026-10', 'The receipt');
        $x->pay->receive($x->owner, $x->la->fresh(), ['amount_minor' => 20000, 'method' => 'cash', 'paid_on' => '2026-10-29']);
        $this->close($x);
        $this->close($x, '2026-11');
        $oct2 = StatementBook::current($x->a, '2026-10');
        $nov2 = StatementBook::current($x->a, '2026-11');
        $this->assertSame(2, (int) $oct2->version);
        $this->assertSame(2, (int) $nov2->version);
        $this->assertSame((int) $oct2->closing_minor, (int) $nov2->opening_minor);
        $this->assertBooksAgree($x->cid);
    }

    public function test_the_issued_paper_is_the_same_document_through_every_door_and_later_changes_do_not_touch_it(): void
    {
        $x = $this->october();
        $this->close($x);
        $s = StatementBook::current($x->a, '2026-10');
        $staff = StatementPdf::report($x->a, '2026-10-01', '2026-10-31');
        $this->assertSame($s->proof, $staff['figures']['statement']['proof']);
        $this->assertSame($s->number, $staff['figures']['statement']['number']);
        $this->assertNull($staff['figures']['statement']['stamp'], 'a closed month carries no stamp');
        $this->assertSame((int) $s->closing_minor, (int) $staff['totals']['closing_minor']);

        $login = $this->landlordLogin($x->a);
        $mine = new PersonView($login);
        $page = $mine->statement('2026-10');
        $this->assertSame($s->proof, $page['issued']['proof']);
        $this->assertSame($s->number, $page['issued']['number']);
        $this->assertSame((int) $s->closing_minor, (int) $page['closing_minor']);
        $this->assertSame(StatementBook::STAMP_OPEN, $mine->statement('2026-11')['stamp']);

        // A tenant pays later, a name is corrected, the account is renamed: the paper stands.
        $body = $s->body;
        $x->pay->receive($x->owner, $x->la->fresh(), ['amount_minor' => 900000, 'method' => 'bank']);
        DB::table('rental_tenants')->where('id', $x->la->tenant_id)->update(['name' => 'Corrected Name']);
        DB::table('rental_landlords')->where('id', $x->a->id)->update(['name' => 'Renamed Landlord']);
        Company::withoutGlobalScopes()->whereKey($x->cid)->update(['name' => 'Renamed Agency']);
        $this->assertSame($body, RentalStatement::find($s->id)->body);
        $again = StatementPdf::report($x->a->fresh(), '2026-10-01', '2026-10-31');
        $this->assertStringContainsString((string) $x->a->name, $again['subtitle'], 'the name as issued');
        $this->assertSame($staff['rows'], $again['rows']);
        $this->assertSame($staff['sections'], $again['sections']);
        $this->assertStringContainsString('%PDF', StatementPdf::render($x->a->fresh(), '2026-10-01', '2026-10-31'));
        $this->assertBooksAgree($x->cid);
    }

    public function test_an_open_month_and_an_unofficial_period_are_stamped_on_the_paper_and_a_closed_month_is_not(): void
    {
        $x = $this->october();
        $words = fn (string $from, string $to) => StatementBook::read($x->a, $from, $to)['stamp'];
        $this->assertSame(StatementBook::STAMP_OPEN, $words('2026-10-01', '2026-10-31'), 'ended but not closed');
        $this->assertSame(StatementBook::STAMP_OPEN, $words('2026-11-01', '2026-11-03'), 'this month so far');
        $this->assertSame(StatementBook::STAMP_UNOFFICIAL, $words('2026-10-05', '2026-10-20'));
        $this->assertSame(StatementBook::STAMP_UNOFFICIAL, $words('2026-09-01', '2026-10-31'));
        $this->assertStringContainsString('Not closed yet', $this->html(StatementPdf::report($x->a, '2026-10-01', '2026-10-31'), $x->cid));
        $this->close($x);
        $this->assertNull($words('2026-10-01', '2026-10-31'));
        $html = $this->html(StatementPdf::report($x->a, '2026-10-01', '2026-10-31'), $x->cid);
        $this->assertStringNotContainsString('Not closed yet', $html);
        $this->assertStringContainsString(StatementBook::current($x->a, '2026-10')->number, $html);
        $this->assertStringContainsString('Unofficial period', $this->html(StatementPdf::report($x->a, '2026-10-05', '2026-10-20'), $x->cid));
    }

    public function test_a_landlord_reaches_only_their_own_and_another_accounts_are_404(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $x = $this->october();
        $this->close($x);
        $mine = StatementBook::current($x->a, '2026-10');
        $theirs = StatementBook::current($x->b, '2026-10');
        // Their copy under my name: not found, whoever asks.
        $this->assertNull(StatementBook::byId($x->a, (int) $theirs->id));
        $bad = RentalLinks::statementPdf($x->a, '2026-10-01', '2026-10-31', 'portal', (int) $this->landlordLogin($x->a)->id, 30, (int) $theirs->id);
        $this->getJson($bad['url'])->assertNotFound();
        $good = RentalLinks::statementPdf($x->a, '2026-10-01', '2026-10-31', 'portal', (int) $this->landlordLogin($x->a)->id, 30, (int) $mine->id);
        $this->get($good['url'])->assertOk();
        $other = RentalLinks::statementPdf($x->b, '2026-10-01', '2026-10-31', 'portal', (int) $this->landlordLogin($x->a)->id);
        $this->getJson($other['url'])->assertNotFound();

        Carbon::setTestNow('2026-10-01 09:00:00');
        [$elsewhere, $them] = $this->rentalsAccount();
        Carbon::setTestNow('2026-11-03 09:00:00');
        $this->assertNotSame((int) $elsewhere->id, $x->cid);
        // Another account's staff through the API: the landlord is not theirs.
        $this->getJson('/api/v1/rentals/landlords/'.$x->a->id.'/statement?from=2026-10-01&to=2026-10-31', $this->auth($them->createToken('t')->plainTextToken))->assertNotFound();
        // The office, through the API, reads the copy.
        $res = $this->getJson('/api/v1/rentals/landlords/'.$x->a->id.'/statement?from=2026-10-01&to=2026-10-31', $this->auth($x->owner->createToken('t')->plainTextToken))->assertOk();
        $this->assertSame($mine->number, $res->json('data.issued.number'));
    }

    public function test_rendering_twelve_closed_months_reads_only_the_copies(): void
    {
        Carbon::setTestNow('2026-01-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$p, $units] = $this->building($owner, 2, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-01-01', ['deposit_minor' => 0]);
        $landlord = RentalLandlord::withoutGlobalScopes()->findOrFail($p->landlord_id);
        $pay = app(Payments::class);
        $close = app(MonthClose::class);
        for ($m = 1; $m <= 12; $m++) {
            $first = Carbon::create(2026, $m, 1, 9);
            Carbon::setTestNow($first->copy()->addDays(2));
            app(RentalDaily::class)->run((int) $company->id);
            $pay->receive($owner, $lease->fresh(), ['amount_minor' => 300000, 'method' => 'cash']);
            Carbon::setTestNow($first->copy()->addMonthNoOverflow()->addDay());
            $close->close($owner, $first->format('Y-m'));
        }
        $this->assertSame(12, RentalStatement::where('company_id', $company->id)->count());
        DB::flushQueryLog();
        DB::enableQueryLog();
        foreach (range(1, 12) as $m) {
            $from = sprintf('2026-%02d-01', $m);
            (new RentalReports)->run('landlord_statement', (int) $company->id, null, ['landlord_id' => (int) $landlord->id, 'from' => $from, 'to' => Carbon::parse($from)->endOfMonth()->toDateString()]);
        }
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        foreach (['rental_entries', 'rental_allocations', 'rental_charges', 'rental_leases', 'rental_units'] as $table) {
            $this->assertSame(0, $queries->filter(fn ($q) => str_contains($q, $table))->count(), $table);
        }
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_a_late_reversal_shows_as_a_correction_on_the_next_statement_and_the_owner_mode_paper_has_owner_words(): void
    {
        $x = $this->october();
        $this->close($x);
        $x->pay->reverse($x->owner, RentalPayment::where('company_id', $x->cid)->where('lease_id', $x->la->id)->firstOrFail(), 'Bounced');
        Carbon::setTestNow('2026-12-02 09:00:00');
        $this->close($x, '2026-11');
        $body = StatementBook::current($x->a, '2026-11')->body;
        $this->assertCount(1, $body['corrections']);
        $this->assertSame(-450000, (int) $body['corrections'][0]['amount_minor']);
        $html = $this->html(StatementPdf::report($x->a, '2026-11-01', '2026-11-30'), $x->cid);
        $this->assertStringContainsString('Corrections from earlier months', $html);

        Carbon::setTestNow('2026-10-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount('owner');
        [, $units] = $this->building($owner, 1, 300000);
        $lease = $this->tenantIn($owner, $units[0], '2026-10-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 300000, 'method' => 'cash']);
        Carbon::setTestNow('2026-11-03 09:00:00');
        app(MonthClose::class)->close($owner, '2026-10');
        $self = \App\Services\Rentals\RentalsModule::selfLandlord((int) $company->id);
        $report = StatementPdf::report($self, '2026-10-01', '2026-10-31');
        $this->assertSame('Property income statement', $report['title']);
        $html = $this->html($report, (int) $company->id);
        $this->assertStringNotContainsString('Commission', $html);
        $this->assertStringNotContainsString('landlord', mb_strtolower(strip_tags(preg_replace('/<style.*?<\/style>/s', '', $html))));
        $this->assertBooksAgree((int) $company->id);
    }

    private function landlordLogin(RentalLandlord $l): RentalLogin
    {
        return RentalLogin::withoutGlobalScopes()->firstOrCreate(['company_id' => $l->company_id, 'subject_type' => 'landlord', 'subject_id' => $l->id], ['email' => 'll'.$l->id.'@example.com', 'password' => 'x']);
    }

    /** @param array<string, mixed> $report */
    private function html(array $report, int $cid): string
    {
        $m = new \ReflectionMethod(RentalExport::class, 'html');

        return (string) $m->invoke(null, $report, $cid);
    }
}
