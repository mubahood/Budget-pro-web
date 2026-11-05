<?php

namespace Tests\Feature\Rentals;

use App\Models\RentalLandlord;
use App\Models\RentalStatement;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Landlords\MonthEndFigures;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Landlords\StatementBook;
use App\Services\Rentals\Landlords\StatementPdf;
use App\Services\Rentals\Payments\Payments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * The month before it is final (LC-04): the batched figures equal the statements issued, whatever the mix of money; 200 landlords close inside a request; the bulk PDF has a page per landlord.
 */
class RentalsMonthEndFiguresTest extends ApiTestCase
{
    use MakesRentals;

    /** What a close must stay inside on the shared host: one request, in one process. Measured here on a slower-than-needed path, with room for the host being slower. */
    private const CLOSE_SECONDS_LIMIT = 30;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_figures_equal_the_statements_for_every_landlord_and_every_kind_of_money(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        $pay = app(Payments::class);
        [$p1, $u1] = $this->building($owner, 3, 400000);
        [$p2, $u2] = $this->building($owner, 2, 250000);
        [$p3, $u3] = $this->building($owner, 1, 100000, ['commission_type' => 'flat', 'commission_minor' => 15000]);
        $a = $this->tenantIn($owner, $u1[0], '2026-09-01', ['deposit_minor' => 200000]);
        $c = $this->tenantIn($owner, $u1[1], '2026-09-01', ['deposit_minor' => 0, 'deposit_held_by' => 'landlord']);
        $d = $this->tenantIn($owner, $u2[0], '2026-09-01', ['deposit_minor' => 250000, 'deposit_held_by' => 'landlord']);
        $e = $this->tenantIn($owner, $u3[0], '2026-09-01', ['deposit_minor' => 0]);
        Carbon::setTestNow('2026-10-03 09:00:00');
        foreach ([$a, $c, $d, $e] as $lease) {
            app(\App\Services\Rentals\Billing\Billing::class)->raise($lease->fresh());
        }
        $pay->receive($owner, $a->fresh(), ['amount_minor' => 1200000, 'method' => 'bank']);
        $pay->receive($owner, $c->fresh(), ['amount_minor' => 123456, 'method' => 'cash']);
        $reversed = $pay->receive($owner, $d->fresh(), ['amount_minor' => 300000, 'method' => 'mobile_money']);
        $pay->receive($owner, $e->fresh(), ['amount_minor' => 50000, 'method' => 'cash']);
        $l1 = RentalLandlord::withoutGlobalScopes()->findOrFail($p1->landlord_id);
        $l2 = RentalLandlord::withoutGlobalScopes()->findOrFail($p2->landlord_id);
        Carbon::setTestNow('2026-10-15 09:00:00');
        app(Expenses::class)->record($owner, ['amount_minor' => 40000, 'method' => 'bank', 'category' => 'Repairs', 'charged_to' => 'landlord', 'property_id' => $p1->id]);
        app(Expenses::class)->record($owner, ['amount_minor' => 7000, 'method' => 'bank', 'category' => 'Agency office', 'charged_to' => 'agency']);
        app(Payouts::class)->record($owner, $l1, ['amount_minor' => 100000, 'method' => 'bank']);
        app(Payouts::class)->record($owner, $l2, ['amount_minor' => 500000, 'method' => 'bank', 'advance' => true, 'advance_reason' => 'Asked ahead', 'advance_others_ok' => true]);
        $pay->reverse($owner, $reversed, 'Bounced');
        $pay->waive($owner, \App\Models\RentalCharge::where('lease_id', $e->id)->where('status', 'open')->firstOrFail(), 10000, 'Goodwill');
        $pay->receive($owner, $d->fresh(), ['amount_minor' => 100000, 'method' => 'cash']);
        Carbon::setTestNow('2026-11-02 09:00:00');

        $figures = MonthEndFigures::month($cid, '2026-10');
        $close = app(MonthClose::class)->close($owner, '2026-10');
        $statements = RentalStatement::where('company_id', $cid)->where('month', '2026-10-01')->where('status', 'issued')->get()->keyBy('landlord_id');
        $this->assertCount(count($figures['rows']), $statements, 'one statement a row');
        foreach ($figures['rows'] as $row) {
            $s = $statements[$row['landlord_id']];
            foreach (['opening_minor', 'collected_minor', 'commission_minor', 'expenses_minor', 'payouts_minor', 'deposits_minor', 'other_minor', 'closing_minor'] as $k) {
                $this->assertSame((int) $s->{$k}, (int) $row[$k], "{$row['name']} {$k}");
            }
            $this->assertSame(count($s->body['lines']), (int) $row['lines'], $row['name']);
        }
        $t = $figures['totals'];
        $this->assertSame((int) $statements->sum('collected_minor'), $t['collected']);
        $this->assertSame((int) $statements->sum('commission_minor'), $t['commission']);
        $this->assertSame((int) $statements->sum('expenses_minor'), $t['expenses']);
        $this->assertSame((int) $statements->sum('payouts_minor'), $t['payouts']);
        $this->assertSame((int) $statements->sum('closing_minor'), $t['owed_to_landlords'] - $t['owed_back_by_landlords']);
        $this->assertSame($t['collected'], (int) $close->totals['collected']);
        $this->assertSame(64, strlen($close->proof));
        $this->assertSame(1, (int) MonthEndFigures::lookAt($cid, '2026-10')['below_zero'], 'the landlord paid ahead');
        // The position adds up: ADR 0010's invariant, in the close's own totals.
        $this->assertSame($t['cash_total'], $t['owed_to_landlords'] - $t['owed_back_by_landlords'] + $t['deposits'] + $t['credit'] + $t['agency_own']);
        $this->assertSame($t['cash_total'], array_sum($t['cash']));
        $this->assertBooksAgree($cid);
    }

    public function test_the_look_at_list_counts_what_it_says(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$p, $u] = $this->building($owner, 2, 100000);
        $lease = $this->tenantIn($owner, $u[0], '2026-10-01', ['deposit_minor' => 0]);
        app(\App\Services\Rentals\CashBook::class)->opening($owner, 'cash', 10000, Carbon::parse('2026-10-01'));
        app(Expenses::class)->record($owner, ['amount_minor' => 10000, 'method' => 'cash', 'category' => 'Repairs', 'charged_to' => 'agency', 'paid_on' => '2026-10-01']);
        Carbon::setTestNow('2026-11-05 09:00:00');
        $f = MonthEndFigures::lookAt($cid, '2026-10');
        $this->assertSame(0, $f['bills_missing'], 'October has its bill');
        $this->assertSame(1, $f['expenses_no_receipt']);
        $this->assertSame(0, $f['agency_own_minor'], 'the opening cash spent');
        $g = MonthEndFigures::lookAt($cid, '2026-11');
        $this->assertSame(1, $g['bills_missing'], 'November has none yet');
        unset($lease, $p);
    }

    public function test_two_hundred_landlords_close_inside_a_request_and_the_preview_stays_in_its_query_budget(): void
    {
        Carbon::setTestNow('2026-11-03 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        $this->seedLandlords($cid, 200, 8, 6);
        $this->assertBooksAgree($cid);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $figures = MonthEndFigures::month($cid, '2026-10');
        MonthEndFigures::lookAt($cid, '2026-10');
        $previewQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(200, $figures['rows']);
        $this->assertLessThan(40, $previewQueries, 'the preview of the month does not grow with the landlords');

        $started = microtime(true);
        $close = app(MonthClose::class)->close($owner, '2026-10');
        $seconds = microtime(true) - $started;
        fwrite(STDERR, sprintf("\n200 landlords, 1,600 leases, 6 months: close took %.2f s, peak memory %d MB\n", $seconds, (int) (memory_get_peak_usage(true) / 1048576)));
        $this->assertEquals(['issued' => 200, 'kept' => 0, 'replaced' => 0], $close->totals['statements']);
        $this->assertLessThan(self::CLOSE_SECONDS_LIMIT, $seconds, 'if this fails on a slower machine, see the chunked fallback in §9');
        $this->assertSame(200, RentalStatement::where('company_id', $cid)->where('month', '2026-10-01')->count());
        $this->assertSame('ST-2026-10-0200', RentalStatement::where('company_id', $cid)->orderByDesc('id')->value('number'));
        $this->assertBooksAgree($cid);
    }

    public function test_the_bulk_pdf_has_a_cover_and_a_page_per_landlord(): void
    {
        Carbon::setTestNow('2026-11-03 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        $this->seedLandlords($cid, 5, 1, 1);
        app(MonthClose::class)->close($owner, '2026-10');
        $pdf = StatementPdf::renderMany($cid, '2026-10');
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(1 + 5, $this->pages($pdf), 'a cover and one page each');
        $this->assertSame(1, StatementPdf::parts($cid, '2026-10'));
        $this->assertCount(5, StatementBook::forMonth($cid, '2026-10'));
    }

    /**
     * Landlords with a property, units and paid-up leases, written straight to the tables as the services would write them (books that rentals:check passes): $months of history up to and including October.
     */
    private function seedLandlords(int $cid, int $landlords, int $units, int $months): void
    {
        $now = now()->toDateTimeString();
        $rows = ['ll' => [], 'pr' => []];
        $start = Carbon::parse('2026-10-01')->subMonthsNoOverflow($months - 1);
        $rent = 500000;
        $commission = 50000;
        for ($i = 1; $i <= $landlords; $i++) {
            $l = DB::table('rental_landlords')->insertGetId(['company_id' => $cid, 'code' => 'LL-'.$i, 'name' => 'Landlord '.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'status' => 'active', 'phone' => '07720'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'created_at' => $now, 'updated_at' => $now]);
            $p = DB::table('rental_properties')->insertGetId(['company_id' => $cid, 'landlord_id' => $l, 'code' => 'P-'.$i, 'name' => 'Court '.$i, 'kind' => 'residential', 'status' => 'active', 'created_at' => $start->toDateTimeString(), 'updated_at' => $now]);
            $entries = [];
            for ($u = 1; $u <= $units; $u++) {
                $unit = DB::table('rental_units')->insertGetId(['company_id' => $cid, 'property_id' => $p, 'code' => 'A'.$u, 'kind' => 'apartment', 'rent_minor' => $rent, 'status' => 'occupied', 'created_at' => $start->toDateTimeString(), 'updated_at' => $now]);
                $tenant = DB::table('rental_tenants')->insertGetId(['company_id' => $cid, 'code' => 'T-'.$i.'-'.$u, 'name' => 'Tenant '.$i.' '.$u, 'phone' => '0701'.$i.$u, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
                $lease = DB::table('rental_leases')->insertGetId(['company_id' => $cid, 'number' => 'L-'.$i.'-'.$u, 'unit_id' => $unit, 'tenant_id' => $tenant, 'property_id' => $p, 'landlord_id' => $l, 'status' => 'active', 'start_date' => $start->toDateString(),
                    'rent_minor' => $rent, 'cycle_months' => 1, 'anchor' => 'start', 'commission_type' => 'percent', 'commission_bp' => 1000, 'commission_minor' => 0, 'deposit_minor' => 0, 'deposit_held_by' => 'agency', 'billed_until' => '2026-10-31', 'created_at' => $start->toDateTimeString(), 'updated_at' => $now]);
                for ($m = 0; $m < $months; $m++) {
                    $first = $start->copy()->addMonthsNoOverflow($m);
                    $day = $first->toDateString();
                    $charge = DB::table('rental_charges')->insertGetId(['company_id' => $cid, 'lease_id' => $lease, 'tenant_id' => $tenant, 'unit_id' => $unit, 'property_id' => $p, 'landlord_id' => $l, 'kind' => 'rent', 'description' => 'Rent '.$first->format('M Y'),
                        'period_start' => $day, 'period_end' => $first->copy()->endOfMonth()->toDateString(), 'due_date' => $day, 'amount_minor' => $rent, 'paid_minor' => $rent, 'status' => 'paid', 'goes_to' => 'landlord', 'commission_type' => 'percent', 'commission_bp' => 1000, 'commission_minor' => 0,
                        'source' => 'auto', 'dedupe_key' => "rent:{$lease}:{$day}", 'created_at' => $now, 'updated_at' => $now]);
                    $payment = DB::table('rental_payments')->insertGetId(['company_id' => $cid, 'uuid' => (string) Str::uuid(), 'number' => 'R-'.$lease.'-'.$m, 'lease_id' => $lease, 'tenant_id' => $tenant, 'unit_id' => $unit, 'property_id' => $p, 'landlord_id' => $l,
                        'amount_minor' => $rent, 'method' => 'cash', 'paid_on' => $day, 'channel' => 'web', 'unallocated_minor' => 0, 'created_at' => $now, 'updated_at' => $now]);
                    $alloc = DB::table('rental_allocations')->insertGetId(['company_id' => $cid, 'payment_id' => $payment, 'charge_id' => $charge, 'source' => 'direct', 'amount_minor' => $rent, 'commission_minor' => $commission, 'landlord_minor' => $rent - $commission, 'agency_minor' => 0, 'deposit_minor' => 0, 'applied_on' => $day, 'created_at' => $now]);
                    $entries[] = ['company_id' => $cid, 'group_id' => (string) Str::uuid(), 'type' => 'collected', 'occurred_on' => $day, 'method' => 'cash', 'cash_minor' => $rent, 'landlord_minor' => $rent - $commission, 'deposit_minor' => 0, 'credit_minor' => 0, 'income_minor' => $commission, 'expense_minor' => 0, 'equity_minor' => 0,
                        'landlord_id' => $l, 'property_id' => $p, 'unit_id' => $unit, 'lease_id' => $lease, 'tenant_id' => $tenant, 'payment_id' => $payment, 'allocation_id' => $alloc, 'charge_id' => $charge, 'description' => 'Rent '.$first->format('M Y'), 'created_at' => $first->copy()->addHours(10)->toDateTimeString()];
                }
            }
            DB::table('rental_entries')->insert($entries);
        }
    }

    private function pages(string $pdf): int
    {
        return preg_match_all('/\/Type\s*\/Page[^s]/', $pdf);
    }
}
