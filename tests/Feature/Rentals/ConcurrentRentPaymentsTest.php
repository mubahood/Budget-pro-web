<?php

namespace Tests\Feature\Rentals;

use App\Models\Company;
use App\Models\RentalCharge;
use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Models\User;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\Jobs\RentalCheck;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\Payments\Payments;
use App\Services\Rentals\RentalsModule;
use App\Services\Team\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Estate Ledger under real concurrency (ADR 0010 §6): separate processes (pcntl_fork) and real row locks, no wrapping
 * transaction. Five phones sending the same payment post it once; two payments landing on one bill at the same moment
 * never overpay it (the second becomes credit); two payouts of 60 % of a landlord's balance let exactly one through.
 */
class ConcurrentRentPaymentsTest extends TestCase
{
    private ?int $companyId = null;

    protected function tearDown(): void
    {
        if ($this->companyId) {
            DB::reconnect();
            $cid = $this->companyId;
            foreach (['rental_activities', 'rental_entries', 'rental_allocations', 'rental_payments', 'rental_follow_ups', 'rental_meter_readings', 'rental_charges',
                'rental_rent_changes', 'rental_lease_items', 'rental_payouts', 'rental_expenses', 'rental_maintenance', 'rental_leases', 'rental_tenants', 'rental_units',
                'rental_property_staff', 'rental_properties', 'rental_landlords', 'rental_logins', 'rental_documents', 'rental_settings'] as $table) {
                DB::table($table)->where('company_id', $cid)->delete();
            }
            DB::table((new User())->getTable())->where('company_id', $cid)->delete();
            DB::table('companies')->where('id', $cid)->delete();
        }
        parent::tearDown();
    }

    /** @return list<string> each child's outcome: ok or the refusal code */
    private function race(int $n, callable $work): array
    {
        $dir = sys_get_temp_dir().'/rentals-race-'.uniqid();
        mkdir($dir);
        DB::disconnect();
        $pids = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                DB::reconnect();
                Permissions::flush();
                $out = 'ok';
                try {
                    $work($i);
                } catch (\App\Exceptions\BusinessRuleException $e) {
                    $out = $e->errorCode();
                } catch (\Throwable $e) {
                    $out = 'error: '.$e->getMessage();
                }
                file_put_contents("{$dir}/{$i}", $out);
                DB::disconnect();
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();
        $results = [];
        for ($i = 0; $i < $n; $i++) {
            $results[] = (string) @file_get_contents("{$dir}/{$i}");
            @unlink("{$dir}/{$i}");
        }
        @rmdir($dir);
        sort($results);

        return $results;
    }

    /** @return array{0: Company, 1: User, 2: RentalLease} committed books: an agency, its owner and one lease owing 300,000 */
    private function books(): array
    {
        $company = Company::factory()->create(['name' => 'Race Lettings', 'status' => 'Active', 'currency' => 'UGX', 'enabled_modules' => ['rentals']]);
        $this->companyId = $cid = (int) $company->id;
        $owner = User::factory()->create(['company_id' => $cid, 'email' => 'race_'.uniqid().'@example.com', 'password' => bcrypt('secret123')]);
        $company->owner_id = $owner->id;
        $company->saveQuietly();
        RentalsModule::settings($cid)->forceFill(['commission_type' => 'percent', 'commission_bp' => 1000, 'setup_at' => now()])->save();
        $landlord = app(Landlords::class)->create($owner, ['name' => 'Race Landlord']);
        $property = app(Properties::class)->create($owner, ['name' => 'Race Court', 'landlord_id' => $landlord->id]);
        $unit = app(Units::class)->create($owner, ['property_id' => $property->id, 'code' => 'R1', 'rent_minor' => 300000]);
        $lease = app(Leases::class)->moveIn($owner, ['unit_id' => $unit->id, 'start_date' => now()->toDateString(), 'rent_minor' => 300000, 'deposit_minor' => 0,
            'tenant' => ['name' => 'Race Tenant']]);

        return [$company->fresh(), $owner, $lease];
    }

    public function test_racing_payments_post_once_never_overpay_and_payouts_respect_the_balance(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency test.');
        }
        [$company, $owner, $lease] = $this->books();
        $cid = (int) $company->id;

        $uuid = (string) Str::uuid();
        $same = $this->race(5, fn () => app(Payments::class)->receive(User::find($owner->id), RentalLease::find($lease->id),
            ['amount_minor' => 100000, 'method' => 'cash', 'uuid' => $uuid], 'app'));
        $this->assertSame(['ok', 'ok', 'ok', 'ok', 'ok'], $same, 'every retry gets the one payment back');
        $this->assertSame(1, RentalPayment::withoutGlobalScopes()->where('company_id', $cid)->where('uuid', $uuid)->count());

        $both = $this->race(2, fn () => app(Payments::class)->receive(User::find($owner->id), RentalLease::find($lease->id), ['amount_minor' => 200000, 'method' => 'cash']));
        $this->assertSame(['ok', 'ok'], $both);
        $rent = RentalCharge::withoutGlobalScopes()->where('company_id', $cid)->where('lease_id', $lease->id)->where('kind', 'rent')->firstOrFail();
        $this->assertSame([300000, 'paid'], [(int) $rent->paid_minor, $rent->status], 'the bill is paid once, never beyond its amount');
        $this->assertSame(200000, Payments::credit($lease->fresh()), 'the rest is credit');

        $landlord = $lease->landlord()->withoutGlobalScopes()->first();
        $this->assertSame(270000, LandlordAccounts::balance($landlord));
        $payouts = $this->race(2, fn () => app(Payouts::class)->record(User::find($owner->id), $landlord->fresh(), ['amount_minor' => 162000, 'method' => 'cash']));
        $this->assertSame(['ok', 'payout_above_balance'], $payouts);
        $this->assertSame(108000, LandlordAccounts::balance($landlord));

        $this->assertSame([], app(RentalCheck::class)->run($cid));
    }
}
