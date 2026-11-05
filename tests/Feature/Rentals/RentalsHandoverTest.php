<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalCharge;
use App\Models\RentalEntry;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalProperty;
use App\Models\RentalPropertyOwner;
use App\Services\Rentals\CashBook;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Expenses;
use App\Services\Rentals\Jobs\RentalDaily;
use App\Services\Rentals\Landlords\Handover;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\MonthClose;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Payments\Deposits;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Payments\Receipt;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * Giving a property to another landlord (LC-01): before it, a receipt could pay the old landlord while an expense hit the new one. No money moves at the
 * handover; live leases and unpaid bills from the day go across, the rest stays, and every test ends with rentals:check finding nothing.
 */
class RentalsHandoverTest extends ApiTestCase
{
    use MakesRentals;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function refused(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected refusal {$code}");
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode(), $e->getMessage());
        }
    }

    /** A landlord A with one property, a live lease paid for March, and landlord B. @return array<string, mixed> */
    private function world(array $lease = []): array
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$p, $units] = $this->building($owner, 2, 100000);
        $a = RentalLandlord::withoutGlobalScopes()->findOrFail($p->landlord_id);
        $b = app(Landlords::class)->create($owner, ['name' => 'Landlord B']);
        $l = $this->tenantIn($owner, $units[0], '2026-03-01', $lease + ['deposit_minor' => 0]);

        return ['company' => $company, 'owner' => $owner, 'cid' => (int) $company->id, 'p' => $p, 'units' => $units, 'a' => $a, 'b' => $b, 'lease' => $l];
    }

    public function test_a_direct_change_is_refused_once_a_lease_or_journal_row_exists_and_allowed_before(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [, $owner] = $this->rentalsAccount();
        [$p, $units] = $this->building($owner, 1, 100000);
        $b = app(Landlords::class)->create($owner, ['name' => 'Landlord B']);
        $c = app(Landlords::class)->create($owner, ['name' => 'Landlord C']);
        $this->assertFalse(Properties::landlordLocked($p));
        $typed = app(Properties::class)->update($owner, $p, ['landlord_id' => $b->id]);
        $this->assertSame((int) $b->id, (int) $typed->landlord_id, 'a typing fix before anything is recorded');

        $this->tenantIn($owner, $units[0], '2026-03-01', ['deposit_minor' => 0]);
        $this->assertTrue(Properties::landlordLocked($typed->fresh()));
        $this->refused('use_handover', fn () => app(Properties::class)->update($owner, $typed->fresh(), ['landlord_id' => $c->id]));
        $same = app(Properties::class)->update($owner, $typed->fresh(), ['landlord_id' => $b->id, 'name' => 'Same landlord, new name']);
        $this->assertSame('Same landlord, new name', $same->name);

        [$empty] = $this->building($owner, 1, 100000);
        app(CashBook::class)->opening($owner, 'cash', 5000, Carbon::parse('2026-03-01'));
        app(Expenses::class)->record($owner, ['property_id' => $empty->id, 'category' => 'Repairs', 'amount_minor' => 1000, 'method' => 'cash']);
        $this->refused('use_handover', fn () => app(Properties::class)->update($owner, $empty->fresh(), ['landlord_id' => $c->id]));
    }

    public function test_after_a_handover_new_money_goes_to_the_new_landlord_and_older_money_stays(): void
    {
        $w = $this->world();
        ['owner' => $owner, 'cid' => $cid, 'p' => $p, 'a' => $a, 'b' => $b, 'lease' => $lease] = $w;
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 40000, 'method' => 'cash']); // March part paid
        Carbon::setTestNow('2026-04-01 09:00:00');
        app(RentalDaily::class)->run($cid); // April's bill, unpaid
        $march = RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->where('period_start', '2026-03-01')->firstOrFail();
        $april = RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->where('period_start', '2026-04-01')->firstOrFail();
        $aBefore = LandlordAccounts::balance($a);
        $this->assertSame(36000, $aBefore);
        $receipts = DB::table('rental_entries')->where('company_id', $cid)->count();

        $row = app(Handover::class)->handOver($owner, $p, ['landlord_id' => $b->id, 'from' => '2026-04-01', 'reason' => 'Sold to B']);
        $this->assertSame((int) $b->id, (int) $row->landlord_id);
        $this->assertSame($receipts, DB::table('rental_entries')->where('company_id', $cid)->count(), 'no journal row');
        $this->assertSame($aBefore, LandlordAccounts::balance($a), 'A keeps the balance');
        $this->assertSame(0, LandlordAccounts::balance($b));
        $this->assertSame((int) $b->id, (int) $lease->fresh()->landlord_id);
        $this->assertSame((int) $b->id, (int) $p->fresh()->landlord_id);
        $this->assertSame((int) $b->id, (int) $april->fresh()->landlord_id, 'unpaid bill from the day moves');
        $this->assertSame((int) $a->id, (int) $march->fresh()->landlord_id, 'older part-paid bill stays');
        $this->assertSame(1000, (int) $lease->fresh()->commission_bp, 'the rule stays on the lease');

        // Settles March (A's) first, then April (B's).
        app(Payments::class)->receive($owner, $lease->fresh(), ['amount_minor' => 160000, 'method' => 'cash']);
        $this->assertSame($aBefore + 54000, LandlordAccounts::balance($a), 'the older bill\'s later money is A\'s');
        $this->assertSame(90000, LandlordAccounts::balance($b), 'the new bill\'s money is B\'s');
        app(Expenses::class)->record($owner, ['property_id' => $p->id, 'category' => 'Repairs', 'amount_minor' => 1000, 'method' => 'cash']);
        $this->assertSame(89000, LandlordAccounts::balance($b), 'an expense now goes to B');
        $this->assertSame($aBefore + 54000 + 89000, LandlordAccounts::balance($a) + LandlordAccounts::balance($b), 'the balances add up to the old balance plus the new money');

        Carbon::setTestNow('2026-05-01 09:00:00');
        app(RentalDaily::class)->run($cid);
        $may = RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->where('period_start', '2026-05-01')->firstOrFail();
        $this->assertSame((int) $b->id, (int) $may->landlord_id, 'bills raised later are B\'s');
        $this->assertBooksAgree($cid);
    }

    public function test_the_old_statement_and_receipts_reprint_unchanged_and_occupancy_follows_who_owned_it(): void
    {
        $w = $this->world();
        ['owner' => $owner, 'cid' => $cid, 'p' => $p, 'a' => $a, 'b' => $b, 'lease' => $lease] = $w;
        $receipt = app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash']);
        Carbon::setTestNow('2026-04-10 09:00:00');
        $before = LandlordAccounts::statement($a, '2026-03-01', '2026-03-31');
        $receiptBefore = Receipt::text($receipt->fresh());
        $this->assertSame(['units' => 2, 'occupied' => 1, 'vacant' => 1], $before['occupancy']);

        app(Handover::class)->handOver($owner, $p, ['landlord_id' => $b->id, 'from' => '2026-04-10', 'reason' => 'Sold']);

        $this->assertSame($before, LandlordAccounts::statement($a, '2026-03-01', '2026-03-31'), 'A\'s old statement reprints unchanged');
        $this->assertSame($receiptBefore, Receipt::text($receipt->fresh()), 'a receipt stays what it was');
        $this->assertSame(['units' => 0, 'occupied' => 0, 'vacant' => 0], LandlordAccounts::occupancy($b, '2026-03-31'), 'B owned nothing in March');
        $this->assertSame(['units' => 0, 'occupied' => 0, 'vacant' => 0], LandlordAccounts::occupancy($a, '2026-04-10'));
        $this->assertSame(['units' => 2, 'occupied' => 1, 'vacant' => 1], LandlordAccounts::occupancy($b, '2026-04-10'));
        $this->assertSame(['units' => 2, 'occupied' => 1, 'vacant' => 1], LandlordAccounts::occupancy($b), 'today');
        $this->assertSame((int) $a->id, Handover::ownerOn($p->fresh(), '2026-03-31'));
        $this->assertSame((int) $b->id, Handover::ownerOn($p->fresh(), '2026-04-10'));
        $this->assertBooksAgree($cid);
    }

    public function test_a_deposit_the_old_landlord_holds_is_in_the_preview_and_the_refund_falls_on_the_new_landlord(): void
    {
        $w = $this->world(['deposit_minor' => 50000, 'deposit_held_by' => 'landlord']);
        ['owner' => $owner, 'cid' => $cid, 'p' => $p, 'a' => $a, 'b' => $b, 'lease' => $lease, 'units' => $units] = $w;
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 170000, 'method' => 'cash']); // deposit 50,000, rent, 20,000 ahead
        $agencyLease = $this->tenantIn($owner, $units[1], '2026-03-01', ['deposit_minor' => 30000, 'deposit_held_by' => 'agency']);
        app(Payments::class)->receive($owner, $agencyLease, ['amount_minor' => 30000, 'method' => 'cash']);
        $this->assertSame(50000 + 90000, LandlordAccounts::balance($a));

        $preview = app(Handover::class)->preview($owner, $p, (int) $b->id, '2026-03-01');
        $this->assertSame(50000, $preview['landlord_deposits_minor']);
        $this->assertSame((int) $lease->id, $preview['landlord_deposits'][0]['lease_id']);
        $this->assertSame(30000, $preview['agency_deposits_minor']);
        $this->assertSame(20000, $preview['credit_minor']);
        $this->assertSame(['count' => 1, 'minor' => 100000], $preview['bills_move'], 'the second lease\'s rent is unpaid');
        $this->assertSame(['count' => 0, 'minor' => 0], $preview['bills_stay']);
        $this->assertCount(2, $preview['leases']);
        $this->assertSame(140000, $preview['old']['balance_minor']);
        $this->assertFalse($preview['waits']);

        Carbon::setTestNow('2026-03-15 09:00:00');
        app(Handover::class)->handOver($owner, $p, ['landlord_id' => $b->id, 'from' => '2026-03-15', 'reason' => 'Sold']);
        $this->assertSame(140000, LandlordAccounts::balance($a));
        $this->assertSame(50000, Deposits::held($lease->fresh()), 'the deposit stays with the lease');
        Deposits::refundKept($owner, app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-15'])['lease'], 50000, 'cash');
        $this->assertSame(-50000, LandlordAccounts::balance($b), 'the refund falls on the new landlord, as the preview says');
        $this->assertSame(140000 - 0, LandlordAccounts::balance($a));
        $this->assertBooksAgree($cid);
    }

    public function test_a_day_ahead_waits_for_its_day_and_bills_in_between_go_to_the_right_landlord(): void
    {
        $w = $this->world();
        ['owner' => $owner, 'cid' => $cid, 'p' => $p, 'a' => $a, 'b' => $b, 'lease' => $lease] = $w;
        $row = app(Handover::class)->handOver($owner, $p, ['landlord_id' => $b->id, 'from' => '2026-05-01', 'reason' => 'Sale completes in May']);
        $this->assertNull($row->applied_at);
        $this->assertSame((int) $a->id, (int) $p->fresh()->landlord_id, 'nothing moves yet');
        $this->assertSame((int) $a->id, (int) $lease->fresh()->landlord_id);
        $this->assertTrue(Properties::landlordLocked($p->fresh()));
        $this->refused('handover_waiting', fn () => app(Handover::class)->handOver($owner, $p->fresh(), ['landlord_id' => $b->id, 'from' => '2026-06-01', 'reason' => 'Again']));
        $this->refused('landlord_has_property', fn () => app(Landlords::class)->update($owner, $b->fresh(), ['status' => 'inactive']));

        Carbon::setTestNow('2026-04-01 09:00:00');
        app(CashBook::class)->opening($owner, 'cash', 5000, Carbon::parse('2026-04-01'));
        app(RentalDaily::class)->run($cid);
        $this->assertSame((int) $a->id, (int) RentalCharge::where('lease_id', $lease->id)->where('period_start', '2026-04-01')->value('landlord_id'), 'April is still A\'s');
        app(Expenses::class)->record($owner, ['property_id' => $p->id, 'category' => 'Repairs', 'amount_minor' => 1000, 'method' => 'cash']);
        $this->assertSame(-1000, LandlordAccounts::balance($a), 'and so is April\'s expense');

        Carbon::setTestNow('2026-04-27 09:00:00'); // May's bill goes out before May, and May is B's
        app(RentalDaily::class)->run($cid);
        $may = RentalCharge::where('lease_id', $lease->id)->where('period_start', '2026-05-01')->firstOrFail();
        $this->assertSame((int) $b->id, (int) $may->landlord_id);
        $this->assertSame((int) $a->id, (int) $lease->fresh()->landlord_id);

        Carbon::setTestNow('2026-05-01 02:00:00');
        app(RentalDaily::class)->run($cid);
        $this->assertSame((int) $b->id, (int) $p->fresh()->landlord_id, 'the night\'s run applies it on the day');
        $this->assertSame((int) $b->id, (int) $lease->fresh()->landlord_id);
        $this->assertNotNull($row->fresh()->applied_at);
        $this->assertSame('2026-04-30', RentalPropertyOwner::where('property_id', $p->id)->where('landlord_id', $a->id)->firstOrFail()->to_date->toDateString());
        $this->assertSame(0, app(Handover::class)->applyDue($cid));
        $this->assertBooksAgree($cid);
    }

    public function test_a_waiting_handover_can_be_cancelled_and_moves_nothing(): void
    {
        $w = $this->world();
        ['owner' => $owner, 'cid' => $cid, 'p' => $p, 'a' => $a, 'b' => $b] = $w;
        $this->refused('nothing_waiting', fn () => app(Handover::class)->cancelWaiting($owner, $p));
        app(Handover::class)->handOver($owner, $p, ['landlord_id' => $b->id, 'from' => '2026-05-01', 'reason' => 'Maybe']);
        app(Handover::class)->cancelWaiting($owner, $p->fresh());
        $this->assertNull(Handover::scheduled($cid, (int) $p->id));
        Carbon::setTestNow('2026-05-02 09:00:00');
        app(RentalDaily::class)->run($cid);
        $this->assertSame((int) $a->id, (int) $p->fresh()->landlord_id);
        $this->assertBooksAgree($cid);
    }

    public function test_the_day_cannot_be_in_a_closed_month_or_before_the_current_owner(): void
    {
        $w = $this->world();
        ['company' => $company, 'owner' => $owner, 'cid' => $cid, 'p' => $p, 'a' => $a, 'b' => $b] = $w;
        Carbon::setTestNow('2026-04-15 09:00:00');
        app(MonthClose::class)->close($owner, '2026-03');
        $this->refused('period_closed', fn () => app(Handover::class)->handOver($owner, $p, ['landlord_id' => $b->id, 'from' => '2026-03-20', 'reason' => 'Late']));
        $this->assertSame((int) $a->id, (int) $p->fresh()->landlord_id);
        $this->assertSame(0, RentalPropertyOwner::where('property_id', $p->id)->count(), 'nothing was written');

        app(Handover::class)->handOver($owner, $p->fresh(), ['landlord_id' => $b->id, 'from' => '2026-04-02', 'reason' => 'A past day in an open month']);
        $this->assertSame((int) $b->id, (int) $p->fresh()->landlord_id);
        $this->refused('from_too_early', fn () => app(Handover::class)->handOver($owner, $p->fresh(), ['landlord_id' => $a->id, 'from' => '2026-04-02', 'reason' => 'Back']));
        $this->refused('same_landlord', fn () => app(Handover::class)->handOver($owner, $p->fresh(), ['landlord_id' => $b->id, 'from' => '2026-04-20', 'reason' => 'Again']));
        $this->assertBooksAgree($cid);
    }

    public function test_a_second_handover_keeps_each_owners_days(): void
    {
        $w = $this->world();
        ['owner' => $owner, 'cid' => $cid, 'p' => $p, 'a' => $a, 'b' => $b] = $w;
        $c = app(Landlords::class)->create($owner, ['name' => 'Landlord C']);
        Carbon::setTestNow('2026-04-01 09:00:00');
        app(Handover::class)->handOver($owner, $p, ['landlord_id' => $b->id, 'from' => '2026-04-01', 'reason' => 'First']);
        Carbon::setTestNow('2026-06-01 09:00:00');
        app(Handover::class)->handOver($owner, $p->fresh(), ['landlord_id' => $c->id, 'from' => '2026-06-01', 'reason' => 'Second']);
        foreach (['2026-03-15' => $a, '2026-05-15' => $b, '2026-06-15' => $c] as $day => $who) {
            $this->assertSame((int) $who->id, Handover::ownerOn($p->fresh(), $day), $day);
            $this->assertSame([(int) $p->id], Handover::propertyIdsOn($cid, (int) $who->id, $day), $day);
        }
        $this->assertCount(3, Handover::history($cid, (int) $p->id));
        $this->assertBooksAgree($cid);
    }

    public function test_a_bill_changes_landlord_only_while_nothing_is_paid_on_it(): void
    {
        $w = $this->world();
        ['owner' => $owner, 'cid' => $cid, 'b' => $b, 'lease' => $lease] = $w;
        $bill = RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->firstOrFail();
        $bill->forceFill(['landlord_id' => $b->id])->save();
        $this->assertSame((int) $b->id, (int) $bill->fresh()->landlord_id);
        $bill->forceFill(['landlord_id' => $w['a']->id])->save();
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 40000, 'method' => 'cash']);
        $this->refused('record_locked', fn () => $bill->fresh()->forceFill(['landlord_id' => $b->id])->save());
        $this->refused('record_locked', fn () => $bill->fresh()->forceFill(['amount_minor' => 1])->save());
        $this->assertBooksAgree($cid);
    }

    public function test_an_unpaid_deposit_goes_with_the_unit_and_an_old_bill_stays(): void
    {
        $w = $this->world(['deposit_minor' => 50000, 'deposit_held_by' => 'landlord']);
        ['owner' => $owner, 'cid' => $cid, 'p' => $p, 'a' => $a, 'b' => $b, 'lease' => $lease] = $w;
        Carbon::setTestNow('2026-04-10 09:00:00');
        app(Handover::class)->handOver($owner, $p, ['landlord_id' => $b->id, 'from' => '2026-04-10', 'reason' => 'Sold']);
        $this->assertSame((int) $b->id, (int) RentalCharge::where('lease_id', $lease->id)->where('kind', 'deposit')->value('landlord_id'));
        $this->assertSame((int) $a->id, (int) RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->where('period_start', '2026-03-01')->value('landlord_id'));
        app(Payments::class)->receive($owner, $lease->fresh(), ['amount_minor' => 150000, 'method' => 'cash']);
        $this->assertSame(50000, LandlordAccounts::balance($b), 'B holds the deposit now paid');
        $this->assertSame(90000, LandlordAccounts::balance($a), 'A earned the older rent');
        $this->assertBooksAgree($cid);
    }

    public function test_no_longer_with_us_warns_about_a_balance_and_passes_with_a_reason(): void
    {
        $w = $this->world();
        ['owner' => $owner, 'cid' => $cid, 'p' => $p, 'a' => $a, 'b' => $b, 'lease' => $lease] = $w;
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash']);
        $this->refused('landlord_has_property', fn () => app(Landlords::class)->update($owner, $a, ['status' => 'inactive']));
        app(Handover::class)->handOver($owner, $p, ['landlord_id' => $b->id, 'reason' => 'Sold']);
        $this->refused('landlord_has_balance', fn () => app(Landlords::class)->update($owner, $a->fresh(), ['status' => 'inactive']));
        $this->assertSame('active', $a->fresh()->status);
        $done = app(Landlords::class)->update($owner, $a->fresh(), ['status' => 'inactive', 'inactive_reason' => 'Paying out next week']);
        $this->assertSame('inactive', $done->status);
        $meta = json_encode(DB::table('rental_activities')->where('company_id', $cid)->where('action', 'landlord.updated')->orderByDesc('id')->value('meta'));
        $this->assertStringContainsString('Paying out next week', (string) $meta);
        $zero = app(Landlords::class)->create($owner, ['name' => 'Nothing owed']);
        $this->assertSame('inactive', app(Landlords::class)->update($owner, $zero, ['status' => 'inactive'])->status, 'no balance, no question');
        $this->assertBooksAgree($cid);
    }

    public function test_permission_scope_isolation_and_owner_mode(): void
    {
        $w = $this->world();
        ['company' => $company, 'owner' => $owner, 'p' => $p, 'b' => $b] = $w;
        foreach (['rentals_caretaker', 'rentals_accountant'] as $role) {
            $staff = $this->rentalsStaff($company, $role);
            $this->refused('forbidden', fn () => app(Handover::class)->handOver($staff, $p, ['landlord_id' => $b->id, 'reason' => 'No']));
            $this->refused('forbidden', fn () => app(Handover::class)->preview($staff, $p, (int) $b->id, null));
        }
        $this->assertSame((int) $w['a']->id, (int) $p->fresh()->landlord_id);
        $this->refused('reason_required', fn () => app(Handover::class)->handOver($owner, $p, ['landlord_id' => $b->id, 'reason' => ' ']));

        // Another account's landlord: refused. Another account's property: the screen and the API find nothing.
        [$other, $otherOwner] = $this->rentalsAccount();
        $theirs = app(Landlords::class)->create($otherOwner, ['name' => 'Their landlord']);
        $this->refused('landlord_required', fn () => app(Handover::class)->handOver($owner, $p, ['landlord_id' => $theirs->id, 'reason' => 'Theirs']));
        $this->refused('not_found', fn () => app(Properties::class)->find($otherOwner, (int) $p->id));
        $this->refused('not_found', fn () => app(Handover::class)->handOver($otherOwner, $p, ['landlord_id' => $theirs->id, 'reason' => 'Theirs']));

        $inactive = app(Landlords::class)->create($owner, ['name' => 'Gone']);
        app(Landlords::class)->update($owner, $inactive, ['status' => 'inactive']);
        $this->refused('landlord_required', fn () => app(Handover::class)->handOver($owner, $p, ['landlord_id' => $inactive->id, 'reason' => 'Gone']));

        [, $self] = $this->rentalsAccount('owner');
        [$mine] = $this->building($self, 1, 100000);
        $this->refused('owner_mode', fn () => app(Handover::class)->handOver($self, $mine, ['landlord_id' => $mine->landlord_id, 'reason' => 'Self']));
        $this->assertSame(1, (int) RentalProperty::whereKey($mine->id)->count());
        $this->assertBooksAgree((int) $w['cid']);
    }

    public function test_through_the_api(): void
    {
        $w = $this->world();
        ['owner' => $owner, 'cid' => $cid, 'p' => $p, 'a' => $a, 'b' => $b, 'company' => $company] = $w;
        $h = $this->auth($owner->createToken('t')->plainTextToken);
        $this->putJson("/api/v1/rentals/properties/{$p->id}", ['landlord_id' => $b->id], $h)->assertStatus(422)->assertJsonPath('errors.code', 'use_handover');
        $care = $this->auth($this->rentalsStaff($company, 'rentals_caretaker')->createToken('t')->plainTextToken);
        $this->postJson("/api/v1/rentals/properties/{$p->id}/handover", ['landlord_id' => $b->id, 'reason' => 'x'], $care)->assertStatus(403);
        $this->postJson("/api/v1/rentals/properties/{$p->id}/handover", ['landlord_id' => $b->id], $h)->assertStatus(422)->assertJsonPath('errors.code', 'reason_required');
        $this->postJson("/api/v1/rentals/properties/{$p->id}/handover", ['landlord_id' => $b->id, 'reason' => 'Sold', 'from' => '2026-03-01'], $h)->assertOk();
        $this->assertSame((int) $b->id, (int) $p->fresh()->landlord_id);
        [, $other] = $this->rentalsAccount();
        $this->postJson("/api/v1/rentals/properties/{$p->id}/handover", ['landlord_id' => $b->id, 'reason' => 'x'], $this->auth($other->createToken('t')->plainTextToken))->assertStatus(404);
        $this->assertBooksAgree($cid);
        $this->assertSame(0, RentalEntry::where('company_id', $cid)->count());
        $this->assertSame(0, (int) Minor::nonNegative(0));
        $this->assertSame((int) $a->id, (int) RentalPropertyOwner::where('property_id', $p->id)->orderBy('id')->value('landlord_id'));
        $this->assertTrue(RentalLease::whereKey($w['lease']->id)->where('landlord_id', $b->id)->exists());
    }
}
