<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalAllocation;
use App\Models\RentalCharge;
use App\Models\RentalCommissionChange;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalUnit;
use App\Services\Rentals\Billing\Billing;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\Import\RentalImport;
use App\Services\Rentals\Jobs\RentalDaily;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Leases\CommissionChanges;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\Payments\Receipt;
use App\Services\Rentals\RentalsModule;
use App\Services\Rentals\Reports\RentalReports;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/**
 * A commission rule on a unit (LC-14), changing one from a date for one lease or many (LC-15), the report that shows what it hides (LC-16), and whether a
 * tenant was already there (LC-22). Figures are invented. Every money test ends with rentals:check finding nothing.
 */
class RentalsCommissionRulesTest extends ApiTestCase
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

    private function pct(int $bp): array
    {
        return ['commission_type' => 'percent', 'commission_bp' => $bp, 'commission_minor' => 0];
    }

    // ── LC-14: a rule on a unit ─────────────────────────────────────────────

    public function test_the_chain_is_lease_unit_property_landlord_account(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency', ['commission_bp' => 1000]);
        $cid = (int) $company->id;
        [$p, $units] = $this->building($owner, 5, 100000);
        $landlord = RentalLandlord::withoutGlobalScopes()->findOrFail($p->landlord_id);
        $chain = fn ($u) => Billing::commissionChain($p->fresh(), $landlord->fresh(), $u);
        $this->assertSame('account', $chain($units[0])['set_on']);
        $this->assertSame(1000, $chain($units[0])['commission_bp']);
        app(Landlords::class)->update($owner, $landlord, $this->pct(900));
        $this->assertSame(['landlord', 900], [$chain($units[0])['set_on'], $chain($units[0])['commission_bp']]);
        app(Properties::class)->update($owner, $p, $this->pct(800));
        $this->assertSame(['property', 800], [$chain($units[0])['set_on'], $chain($units[0])['commission_bp']]);
        app(Units::class)->update($owner, $units[0], $this->pct(1200));
        $this->assertSame(['unit', 1200], [$chain($units[0]->fresh())['set_on'], $chain($units[0]->fresh())['commission_bp']]);
        $this->assertSame(['commission_type' => 'percent', 'commission_bp' => 1200, 'commission_minor' => 0], Billing::commissionFor($p->fresh(), $landlord->fresh(), $units[0]->fresh()));

        $a = $this->tenantIn($owner, $units[0]->fresh(), '2026-03-01', ['deposit_minor' => 0]);
        $this->assertSame(1200, (int) $a->commission_bp, 'the unit\'s');
        $b = $this->tenantIn($owner, $units[1], '2026-03-01', ['deposit_minor' => 0]);
        $this->assertSame(800, (int) $b->commission_bp, 'the property\'s');
        $c = $this->tenantIn($owner, $units[2], '2026-03-01', ['deposit_minor' => 0, 'commission' => ['type' => 'percent', 'bp' => 1500]]);
        $this->assertSame(1500, (int) $c->commission_bp, 'the lease\'s own beats them all');
        app(Units::class)->update($owner, $units[3], ['commission_type' => 'none']);
        $none = $this->tenantIn($owner, $units[3]->fresh(), '2026-03-01', ['deposit_minor' => 0]);
        $this->assertSame('none', $none->commission_type, 'none on a unit means none, blank means follow');

        // A re-let keeps the unit's rule; a live lease and its bills do not change when the unit's rule does.
        app(Units::class)->update($owner, $units[0]->fresh(), $this->pct(1300));
        $this->assertSame(1200, (int) $a->fresh()->commission_bp);
        app(Leases::class)->moveOut($owner, $a->fresh(), ['date' => '2026-03-01']);
        $again = $this->tenantIn($owner, $units[0]->fresh(), '2026-03-02', ['deposit_minor' => 0]);
        $this->assertSame(1300, (int) $again->commission_bp);
        $this->assertBooksAgree($cid);
    }

    public function test_a_flat_rule_on_a_unit_with_a_part_month_and_following_again(): void
    {
        Carbon::setTestNow('2026-03-15 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency', ['anchor' => 'first']);
        [$p, $units] = $this->building($owner, 2, 310000);
        app(Units::class)->update($owner, $units[0], ['commission_type' => 'flat', 'commission_minor' => 31000]);
        $lease = $this->tenantIn($owner, $units[0]->fresh(), '2026-03-15', ['deposit_minor' => 0, 'anchor' => 'first']);
        $bill = RentalCharge::where('lease_id', $lease->id)->where('kind', 'rent')->firstOrFail();
        $this->assertSame('flat', $bill->commission_type);
        $this->assertSame(170000, (int) $bill->amount_minor, 'seventeen days of thirty-one');
        $this->assertSame(17000, (int) $bill->commission_minor, 'the fee for those days');
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 170000, 'method' => 'cash']);
        $this->assertSame(153000, LandlordAccounts::balance(RentalLandlord::findOrFail($p->landlord_id)));

        $count = app(Units::class)->setCommission($owner, $p, [(int) $units[0]->id, (int) $units[1]->id], $this->pct(700));
        $this->assertSame(2, $count);
        $this->assertSame(700, (int) $units[1]->fresh()->commission_bp);
        $this->assertSame(0, (int) $units[0]->fresh()->commission_minor, 'a rule keeps only what it needs');
        app(Units::class)->setCommission($owner, $p, [(int) $units[0]->id], ['commission_type' => null]);
        $this->assertNull($units[0]->fresh()->commission_type);
        $this->assertNull($units[0]->fresh()->commission_bp);
        $this->refused('units_required', fn () => app(Units::class)->setCommission($owner, $p, [], $this->pct(700)));
        $this->refused('commission_invalid', fn () => app(Units::class)->setCommission($owner, $p, [(int) $units[0]->id], ['commission_type' => 'percent']));
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_units_of_another_property_or_account_or_role_are_refused_and_owner_mode_has_none(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$p, $units] = $this->building($owner, 1, 100000);
        [, $others] = $this->building($owner, 1, 100000);
        $this->refused('not_found', fn () => app(Units::class)->setCommission($owner, $p, [(int) $others[0]->id], $this->pct(700)));
        foreach (['rentals_caretaker', 'rentals_accountant'] as $role) {
            $this->refused('forbidden', fn () => app(Units::class)->setCommission($this->rentalsStaff($company, $role), $p, [(int) $units[0]->id], $this->pct(700)));
        }
        [, $self] = $this->rentalsAccount('owner');
        [$mine, $myUnits] = $this->building($self, 1, 100000);
        $this->refused('owner_mode', fn () => app(Units::class)->setCommission($self, $mine, [(int) $myUnits[0]->id], $this->pct(700)));
        $u = app(Units::class)->update($self, $myUnits[0], $this->pct(700));
        $this->assertNull($u->commission_type, 'an owner-run account writes no rule');
        $this->assertSame('none', $this->tenantIn($self, $u, '2026-03-01', ['deposit_minor' => 0])->commission_type);
    }

    public function test_import_sets_the_units_rule_and_says_sitting(): void
    {
        Carbon::setTestNow('2026-03-15 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        $sheets = ['landlords' => "name\nOkello Peter\n", 'properties' => "name,landlord_name,commission_percent\nCourt,Okello Peter,10\n",
            'units' => "property_name,code,rent,commission_percent,commission_flat\nCourt,A1,\"100,000\",12.5,\nCourt,A2,\"100,000\",,\"15,000\"\nCourt,A3,\"100,000\",,\nCourt,A4,\"100,000\",,\n",
            'tenancies' => "property_name,unit_code,tenant_name,start_date,origin\nCourt,A1,Asha,2026-01-01,\nCourt,A2,Ben,2026-02-01,new\nCourt,A3,Cleo,2026-02-01,sitting\n",
        ];
        $this->assertSame([], app(RentalImport::class)->dryRun($cid, $owner, $sheets, '2026-03-15')['errors']);
        app(RentalImport::class)->run($cid, $owner, $sheets, '2026-03-15');
        $unit = fn (string $c) => RentalUnit::where('company_id', $cid)->where('code', $c)->firstOrFail();
        $lease = fn (string $c) => RentalLease::where('company_id', $cid)->where('unit_id', $unit($c)->id)->firstOrFail();
        $this->assertSame(['percent', 1250], [$unit('A1')->commission_type, (int) $unit('A1')->commission_bp]);
        $this->assertSame(['flat', 15000], [$unit('A2')->commission_type, (int) $unit('A2')->commission_minor]);
        $this->assertNull($unit('A3')->commission_type);
        $this->assertSame(1250, (int) $lease('A1')->commission_bp);
        $this->assertSame(['flat', 15000], [$lease('A2')->commission_type, (int) $lease('A2')->commission_minor]);
        $this->assertSame(1000, (int) $lease('A3')->commission_bp);
        $this->assertSame(['sitting', 'new', 'sitting'], [$lease('A1')->origin, $lease('A2')->origin, $lease('A3')->origin]);
        $this->assertBooksAgree($cid);

        $bad = ['units' => "property_name,code,rent,commission_percent,commission_flat\nCourt,B1,\"100,000\",10,\"15,000\"\n"];
        $this->assertStringContainsString('not both', json_encode(app(RentalImport::class)->dryRun($cid, $owner, $bad, '2026-03-15')['errors']));
        $badOrigin = ['tenancies' => "property_name,unit_code,tenant_name,start_date,origin\nCourt,A4,Dan,2026-02-01,maybe\n"];
        $this->assertStringContainsString('origin must be', json_encode(app(RentalImport::class)->dryRun($cid, $owner, $badOrigin, '2026-03-15')['errors']));
    }

    // ── LC-15: from a date ──────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function fifteen(): array
    {
        Carbon::setTestNow('2026-02-20 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [$p, $units] = $this->building($owner, 3, 200000, $this->pct(1500));
        $lease = $this->tenantIn($owner, $units[0], '2026-02-01', ['deposit_minor' => 0]);

        return ['company' => $company, 'owner' => $owner, 'cid' => (int) $company->id, 'p' => $p, 'units' => $units, 'lease' => $lease->fresh(), 'landlord' => RentalLandlord::findOrFail($p->landlord_id)];
    }

    public function test_fifteen_to_ten_from_march_keeps_februarys_bill_at_fifteen(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord] = $this->fifteen();
        $this->assertSame('2026-02-28', $lease->billed_until->toDateString());
        app(CommissionChanges::class)->change($owner, $lease, $this->pct(1000), '2026-03-01', 'New agreement');
        $this->assertCount(2, CommissionChanges::history($lease), 'the starting row and the change');
        $this->assertSame(1500, (int) $lease->fresh()->commission_bp, 'still 15% until the day');
        $this->assertSame(1000, Billing::ruleAt($lease, Carbon::parse('2026-03-01'))['commission_bp']);
        $this->assertSame(1500, Billing::ruleAt($lease, Carbon::parse('2026-02-28'))['commission_bp']);

        Carbon::setTestNow('2026-03-01 01:40:00');
        app(RentalDaily::class)->run($cid);
        $feb = RentalCharge::where('lease_id', $lease->id)->where('period_start', '2026-02-01')->firstOrFail();
        $mar = RentalCharge::where('lease_id', $lease->id)->where('period_start', '2026-03-01')->firstOrFail();
        $this->assertSame([1500, 1000], [(int) $feb->commission_bp, (int) $mar->commission_bp]);
        $this->assertSame(1000, (int) $lease->fresh()->commission_bp, 'the lease follows on the day');

        // February's bill, paid in March, is still 15%; part payment across the change is proportional on each bill's own rule.
        $receipt = app(Payments::class)->receive($owner, $lease->fresh(), ['amount_minor' => 300000, 'method' => 'cash']);
        $this->assertSame(170000 + 90000, LandlordAccounts::balance($landlord));
        $text = Receipt::text($receipt->fresh());
        $this->assertSame([30000, 10000], RentalAllocation::where('payment_id', $receipt->id)->orderBy('id')->pluck('commission_minor')->map(fn ($v) => (int) $v)->all());
        app(Payments::class)->receive($owner, $lease->fresh(), ['amount_minor' => 100000, 'method' => 'cash']);
        $this->assertSame(10000, (int) RentalAllocation::where('charge_id', $mar->id)->orderByDesc('id')->value('commission_minor'));
        $this->assertSame(170000 + 180000, LandlordAccounts::balance($landlord), 'exact to the shilling');
        $this->assertSame($text, Receipt::text($receipt->fresh()), 'a receipt stays what it was');
        $this->assertBooksAgree($cid);
    }

    public function test_a_raised_bill_keeps_its_rule_a_billed_date_is_refused_and_a_past_statement_stays(): void
    {
        ['owner' => $owner, 'cid' => $cid, 'lease' => $lease, 'landlord' => $landlord] = $this->fifteen();
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 200000, 'method' => 'cash']);
        $statement = LandlordAccounts::statement($landlord, '2026-02-01', '2026-02-28');
        $this->refused('period_billed', fn () => app(CommissionChanges::class)->change($owner, $lease, $this->pct(1000), '2026-02-28', 'Too early'));
        $this->refused('reason_required', fn () => app(CommissionChanges::class)->change($owner, $lease, $this->pct(1000), '2026-03-01', ' '));
        $this->refused('commission_invalid', fn () => app(CommissionChanges::class)->change($owner, $lease, ['commission_type' => 'percent', 'commission_bp' => 10001], '2026-03-01', 'x'));
        $this->refused('commission_invalid', fn () => app(CommissionChanges::class)->change($owner, $lease, ['commission_type' => 'tiered'], '2026-03-01', 'x'));
        $this->assertSame(0, RentalCommissionChange::where('lease_id', $lease->id)->count());
        app(CommissionChanges::class)->change($owner, $lease, ['commission_type' => 'none'], '2026-03-01', 'Family');
        Carbon::setTestNow('2026-03-02 09:00:00');
        app(RentalDaily::class)->run($cid);
        $this->assertSame($statement, LandlordAccounts::statement($landlord, '2026-02-01', '2026-02-28'));
        $this->assertSame('percent', RentalCharge::where('lease_id', $lease->id)->where('period_start', '2026-02-01')->value('commission_type'));
        $this->assertSame('none', RentalCharge::where('lease_id', $lease->id)->where('period_start', '2026-03-01')->value('commission_type'));
        $this->refused('record_locked', fn () => RentalCommissionChange::where('lease_id', $lease->id)->firstOrFail()->forceFill(['commission_bp' => 1])->save());
        $this->refused('record_locked', fn () => RentalCommissionChange::where('lease_id', $lease->id)->firstOrFail()->delete());
        app(Leases::class)->moveOut($owner, $lease->fresh(), ['date' => '2026-03-02']);
        $this->refused('lease_not_live', fn () => app(CommissionChanges::class)->change($owner, $lease->fresh(), $this->pct(1000), '2026-06-01', 'x'));
        $this->assertBooksAgree($cid);
    }

    public function test_flat_on_quarter_bills_follows_the_change(): void
    {
        Carbon::setTestNow('2026-01-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        [, $units] = $this->building($owner, 1, 100000);
        $lease = $this->tenantIn($owner, $units[0], '2026-01-01', ['deposit_minor' => 0, 'cycle_months' => 3, 'commission' => ['type' => 'percent', 'bp' => 1000]]);
        app(CommissionChanges::class)->change($owner, $lease->fresh(), ['commission_type' => 'flat', 'commission_minor' => 12000], '2026-04-01', 'Flat now');
        Carbon::setTestNow('2026-04-01 01:40:00');
        app(RentalDaily::class)->run((int) $company->id);
        $q2 = RentalCharge::where('lease_id', $lease->id)->where('period_start', '2026-04-01')->firstOrFail();
        $this->assertSame(['flat', 36000, 300000], [$q2->commission_type, (int) $q2->commission_minor, (int) $q2->amount_minor], 'three months of the fee');
        $this->assertSame('flat', $lease->fresh()->commission_type);
        app(Payments::class)->receive($owner, $lease->fresh(), ['amount_minor' => 600000, 'method' => 'cash']);
        $this->assertBooksAgree((int) $company->id);
    }

    public function test_many_leases_preview_equals_result_and_special_rates_stay_unless_ticked(): void
    {
        ['company' => $company, 'owner' => $owner, 'cid' => $cid, 'p' => $p, 'units' => $units, 'lease' => $usual, 'landlord' => $landlord] = $this->fifteen();
        $special = $this->tenantIn($owner, $units[1], '2026-02-01', ['deposit_minor' => 0, 'commission' => ['type' => 'percent', 'bp' => 1200]]);
        $alsoSpecial = $this->tenantIn($owner, $units[2], '2026-02-01', ['deposit_minor' => 0, 'commission' => ['type' => 'flat', 'minor' => 5000]]);
        [$elsewhere, $otherUnits] = $this->building($owner, 1, 200000);
        $outside = $this->tenantIn($owner, $otherUnits[0], '2026-02-01', ['deposit_minor' => 0]);

        $plan = app(CommissionChanges::class)->plan($owner, null, (int) $p->id, $this->pct(1000), '2026-03-01');
        $this->assertCount(3, $plan['rows']);
        $this->assertSame(1, $plan['count']);
        $this->assertSame(30000, $plan['before_minor']);
        $this->assertSame(20000, $plan['after_minor']);
        $rows = collect($plan['rows'])->keyBy('lease_id');
        $this->assertFalse($rows[$usual->id]['special']);
        $this->assertTrue($rows[$special->id]['special']);
        $this->assertSame([24000, 20000], [$rows[$special->id]['before_minor'], $rows[$special->id]['after_minor']]);
        $this->assertTrue($rows[$alsoSpecial->id]['special']);

        $done = app(CommissionChanges::class)->changeMany($owner, null, (int) $p->id, $this->pct(1000), '2026-03-01', 'Rate falls', [(int) $alsoSpecial->id], true);
        $this->assertSame([(int) $usual->id, (int) $alsoSpecial->id], $done['changed']);
        $this->assertSame(1000, (int) $p->fresh()->commission_bp, 'and the property\'s default');
        $this->assertSame(2, RentalCommissionChange::where('batch_uuid', $done['batch'])->count());
        Carbon::setTestNow('2026-03-01 01:40:00');
        app(RentalDaily::class)->run($cid);
        $on = fn ($l) => (int) RentalCharge::where('lease_id', $l->id)->where('period_start', '2026-03-01')->value('commission_bp');
        $this->assertSame([1000, 1200, 1000, 1000], [$on($usual), $on($special), $on($alsoSpecial), $on($outside)]);
        $this->assertSame('percent', $alsoSpecial->fresh()->commission_type);

        // By landlord: every live lease of theirs, and a lease billed past the day is left alone and said so.
        $plan = app(CommissionChanges::class)->plan($owner, (int) $landlord->id, null, $this->pct(800), '2026-03-15');
        $this->assertCount(3, $plan['rows']);
        $this->assertNotNull($plan['rows'][0]['billed']);
        $this->assertSame(0, $plan['count']);
        $this->refused('nothing_to_change', fn () => app(CommissionChanges::class)->changeMany($owner, (int) $landlord->id, null, $this->pct(800), '2026-03-15', 'Too early'));
        $byLandlord = app(CommissionChanges::class)->changeMany($owner, (int) $landlord->id, null, $this->pct(800), '2026-04-01', 'Landlord rate', [], true);
        $this->assertSame(2, count($byLandlord['changed']));
        $this->assertSame(800, (int) $landlord->fresh()->commission_bp);
        $this->assertSame(1000, (int) $outside->fresh()->commission_bp, 'another landlord\'s lease is not touched') ;
        $this->assertBooksAgree($cid);
        unset($company, $elsewhere);
    }

    public function test_commission_changes_permission_scope_isolation_and_owner_mode(): void
    {
        ['company' => $company, 'owner' => $owner, 'p' => $p, 'lease' => $lease] = $this->fifteen();
        foreach (['rentals_caretaker', 'rentals_accountant'] as $role) {
            $staff = $this->rentalsStaff($company, $role);
            $this->refused('forbidden', fn () => app(CommissionChanges::class)->change($staff, $lease, $this->pct(1000), '2026-03-01', 'x'));
            $this->refused('forbidden', fn () => app(CommissionChanges::class)->plan($staff, null, (int) $p->id, $this->pct(1000), '2026-03-01'));
            $this->refused('forbidden', fn () => app(CommissionChanges::class)->changeMany($staff, null, (int) $p->id, $this->pct(1000), '2026-03-01', 'x'));
        }
        [, $other] = $this->rentalsAccount();
        $this->refused('not_found', fn () => app(CommissionChanges::class)->plan($other, null, (int) $p->id, $this->pct(1000), '2026-03-01'));
        $this->refused('not_found', fn () => app(CommissionChanges::class)->plan($other, (int) $p->landlord_id, null, $this->pct(1000), '2026-03-01'));
        $this->refused('scope_required', fn () => app(CommissionChanges::class)->plan($owner, null, null, $this->pct(1000), '2026-03-01'));
        [, $self] = $this->rentalsAccount('owner');
        [, $units] = $this->building($self, 1, 100000);
        $mine = $this->tenantIn($self, $units[0], '2026-02-01', ['deposit_minor' => 0]);
        $this->refused('owner_mode', fn () => app(CommissionChanges::class)->change($self, $mine, $this->pct(1000), '2026-03-01', 'x'));
    }

    // ── LC-16: the report that shows what it hides ──────────────────────────

    public function test_a_landlord_on_no_commission_appears_and_the_blended_rate_falls(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency', ['commission_bp' => 1000]);
        $cid = (int) $company->id;
        [$paying, $pu] = $this->building($owner, 1, 100000);
        [$free, $fu] = $this->building($owner, 1, 100000, ['commission_type' => 'none']);
        $a = $this->tenantIn($owner, $pu[0], '2026-03-01', ['deposit_minor' => 0]);
        $b = $this->tenantIn($owner, $fu[0], '2026-03-01', ['deposit_minor' => 0, 'items' => [['name' => 'Water', 'amount_minor' => 5000, 'goes_to' => 'landlord']]]);
        app(Payments::class)->receive($owner, $a, ['amount_minor' => 100000, 'method' => 'cash']);
        app(Payments::class)->receive($owner, $b, ['amount_minor' => 105000, 'method' => 'cash']);
        $r = app(RentalReports::class)->run('commission', $cid, $owner, ['from' => '2026-03-01', 'to' => '2026-03-31']);
        $this->assertCount(2, $r['rows']);
        $this->assertSame(['gross_minor' => 200000, 'commission_minor' => 10000, 'rate_bp' => 500], $r['totals'], 'all the rent counts, and the rate is true');
        $none = collect($r['rows'])->firstWhere('rate_bp', 0);
        $this->assertSame(['None', 100000, 0], [$none['rule'], $none['gross_minor'], $none['commission_minor']], 'the fee beside rent, with no commission, is not rent');
        $this->assertSame('10%', collect($r['rows'])->firstWhere('rate_bp', 1000)['rule']);
        $this->assertSame(['no_commission'], array_column($r['sections'][0]['rows'], 'kind'));
        $this->assertSame(100000, $r['sections'][0]['rows'][0]['amount_minor']);
        // It ties to the journal and to the allocations.
        $journal = (int) \Illuminate\Support\Facades\DB::table('rental_entries')->where('company_id', $cid)->sum('income_minor');
        $this->assertSame($journal, $r['totals']['commission_minor']);
        $this->assertBooksAgree($cid);
        unset($paying, $free);
    }

    public function test_every_exception_is_listed_and_a_reversal_leaves_nothing_behind(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount('agency', ['commission_bp' => 1000]);
        $cid = (int) $company->id;
        [$p, $u] = $this->building($owner, 6, 100000);
        $low = $this->tenantIn($owner, $u[0], '2026-03-01', ['deposit_minor' => 0, 'commission' => ['type' => 'percent', 'bp' => 500]]);
        $this->tenantIn($owner, $u[1], '2026-03-01', ['deposit_minor' => 0, 'commission' => ['type' => 'flat', 'minor' => 30000]]);
        $this->tenantIn($owner, $u[2], '2026-03-01', ['deposit_minor' => 0, 'commission' => ['type' => 'flat', 'minor' => 15]]);
        $this->tenantIn($owner, $u[3], '2026-03-01', ['deposit_minor' => 0, 'commission' => ['type' => 'percent', 'bp' => 9000]]);
        app(Units::class)->update($owner, $u[4], $this->pct(1200));
        $this->tenantIn($owner, $u[4]->fresh(), '2026-03-01', ['deposit_minor' => 0]);
        $same = $this->tenantIn($owner, $u[5], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $low, ['amount_minor' => 100000, 'method' => 'cash']);
        $kinds = fn (array $rows) => array_values(array_unique(array_column($rows, 'kind')));
        $list = \App\Services\Rentals\Reports\CommissionReport::exceptions($cid, '2026-03-01', '2026-03-31');
        foreach (['low_rate', 'other_rule', 'flat_high', 'flat_typed', 'percent_high'] as $k) {
            $this->assertContains($k, $kinds($list), $k);
        }
        $this->assertStringContainsString('set on the unit', json_encode($list));
        $this->assertStringNotContainsString($same->number, json_encode($list));
        $this->assertCount(1, array_filter($list, fn ($r) => $r['kind'] === 'low_rate'));

        // The gap is a setting.
        app(RentalsModule::class)->saveSettings($company, $owner, ['commission_gap_bp' => 600]);
        $this->assertNotContains('low_rate', $kinds(\App\Services\Rentals\Reports\CommissionReport::exceptions($cid, '2026-03-01', '2026-03-31')));

        // A payment and its reversal in the period: nothing collected shows.
        $receipt = app(Payments::class)->receive($owner, $same, ['amount_minor' => 100000, 'method' => 'cash']);
        app(Payments::class)->reverse($owner, $receipt, 'Bounced');
        $r = app(RentalReports::class)->run('commission', $cid, $owner, ['from' => '2026-03-01', 'to' => '2026-03-31']);
        $this->assertCount(1, $r['rows']);
        $this->assertSame(['gross_minor' => 100000, 'commission_minor' => 5000, 'rate_bp' => 500], $r['totals']);
        $this->refused('forbidden', fn () => app(RentalReports::class)->run('commission', $cid, $this->rentalsStaff($company, 'rentals_caretaker'), ['from' => '2026-03-01', 'to' => '2026-03-31']));
        $this->assertBooksAgree($cid);
    }

    public function test_after_a_handover_the_report_shows_who_earned_the_money(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$p, $u] = $this->building($owner, 1, 100000);
        $lease = $this->tenantIn($owner, $u[0], '2026-03-01', ['deposit_minor' => 0]);
        app(Payments::class)->receive($owner, $lease, ['amount_minor' => 100000, 'method' => 'cash']);
        $a = (int) $p->landlord_id;
        $b = app(Landlords::class)->create($owner, ['name' => 'New owner']);
        app(\App\Services\Rentals\Landlords\Handover::class)->handOver($owner, $p, ['landlord_id' => $b->id, 'reason' => 'Sold']);
        $this->assertSame(10000, app(RentalReports::class)->run('commission', $cid, $owner, ['from' => '2026-03-01', 'to' => '2026-03-31', 'landlord_id' => $a])['totals']['commission_minor']);
        $this->assertSame(0, app(RentalReports::class)->run('commission', $cid, $owner, ['from' => '2026-03-01', 'to' => '2026-03-31', 'landlord_id' => $b->id])['totals']['commission_minor']);
    }

    public function test_the_report_writes_every_format_and_its_queries_do_not_grow_with_the_leases(): void
    {
        Carbon::setTestNow('2026-03-05 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [, $u] = $this->building($owner, 30, 100000);
        $count = function () use ($owner, $cid) {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $r = app(RentalReports::class)->run('commission', $cid, $owner, ['from' => '2026-03-01', 'to' => '2026-03-31']);
            $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::disableQueryLog();

            return [$n, $r];
        };
        foreach (array_slice($u, 0, 2) as $unit) {
            app(Payments::class)->receive($owner, $this->tenantIn($owner, $unit, '2026-03-01', ['deposit_minor' => 0, 'commission' => ['type' => 'flat', 'minor' => 40000]]), ['amount_minor' => 100000, 'method' => 'cash']);
        }
        [$few] = $count();
        foreach (array_slice($u, 2) as $unit) {
            app(Payments::class)->receive($owner, $this->tenantIn($owner, $unit, '2026-03-01', ['deposit_minor' => 0, 'commission' => ['type' => 'flat', 'minor' => 40000]]), ['amount_minor' => 100000, 'method' => 'cash']);
        }
        [$many, $report] = $count();
        $this->assertLessThanOrEqual($few + 1, $many, 'thirty leases read no more than two');
        $this->assertLessThanOrEqual(15, $many);
        $this->assertStringContainsString('To look at', (string) json_encode($report['sections'][0]['title']));
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->assertNotSame('', \App\Services\Rentals\Reports\RentalExport::{$format}($report, $cid));
        }
        $this->assertStringContainsString('Flat fee looks high', \App\Services\Rentals\Reports\RentalExport::csv($report, $cid));
        $this->assertBooksAgree($cid);
    }

    // ── LC-22: sitting or new ───────────────────────────────────────────────

    public function test_origin_shows_on_the_rent_sheet_and_the_year_summary(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$company, $owner] = $this->rentalsAccount();
        $cid = (int) $company->id;
        [$p, $u] = $this->building($owner, 3, 100000);
        $this->tenantIn($owner, $u[0], '2026-03-01', ['deposit_minor' => 0, 'origin' => 'sitting', 'tenant' => ['name' => 'Sitting Asha']]);
        $this->tenantIn($owner, $u[1], '2026-03-01', ['deposit_minor' => 0, 'tenant' => ['name' => 'New Ben']]);
        $this->tenantIn($owner, $u[2], '2026-03-01', ['deposit_minor' => 0, 'tenant' => ['name' => 'New Cleo']]);
        $landlord = RentalLandlord::findOrFail($p->landlord_id);
        $rows = collect(\App\Services\Rentals\Landlords\UnitSheet::rows($landlord, '2026-03-31'));
        $this->assertSame('sitting', $rows->firstWhere('tenant', 'Sitting Asha')['origin']);
        $this->assertSame('new', $rows->firstWhere('tenant', 'New Ben')['origin']);
        $sheet = app(RentalReports::class)->run('landlord_rent_sheet', $cid, $owner, ['month' => '2026-03', 'landlord_id' => $landlord->id]);
        $this->assertContains('Sitting Asha (already living here)', array_column($sheet['rows'], 'tenant'));
        $year = app(RentalReports::class)->run('landlord_year', $cid, $owner, ['year' => '2026', 'landlord_id' => $landlord->id]);
        $this->assertStringContainsString('New tenants found this year: 2. Tenants already living there when the agency took over: 1.', (string) json_encode($year['sections']));
    }

    public function test_origin_defaults_to_new_and_can_be_sitting(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [, $owner] = $this->rentalsAccount();
        [, $u] = $this->building($owner, 3, 100000);
        $this->assertSame('new', $this->tenantIn($owner, $u[0], '2026-03-01', ['deposit_minor' => 0])->origin);
        $this->assertSame('sitting', $this->tenantIn($owner, $u[1], '2026-03-01', ['deposit_minor' => 0, 'origin' => 'sitting'])->origin);
        $this->refused('origin_invalid', fn () => $this->tenantIn($owner, $u[2], '2026-03-01', ['deposit_minor' => 0, 'origin' => 'maybe']));
    }
}
