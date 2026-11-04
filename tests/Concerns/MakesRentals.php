<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\Plan;
use App\Models\RentalLease;
use App\Models\RentalProperty;
use App\Models\RentalUnit;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Rentals\Directory\Landlords;
use App\Services\Rentals\Directory\Properties;
use App\Services\Rentals\Directory\Units;
use App\Services\Rentals\Jobs\RentalCheck;
use App\Services\Rentals\Leases\Leases;
use App\Services\Rentals\RentalsModule;
use App\Services\Team\TeamService;
use Database\Seeders\EstateLedgerPlanSeeder;

/**
 * An Estate Ledger account for the kernel's tests: registered with the rentals module like the app does, set up, with a
 * landlord, a property and units; staff by role; and the books check every money test ends with.
 */
trait MakesRentals
{
    /** @return array{0: Company, 1: User} */
    protected function rentalsAccount(string $mode = 'agency', array $settings = []): array
    {
        if (class_exists(EstateLedgerPlanSeeder::class) && ! Plan::where('slug', 'estate-ledger-agency')->exists()) {
            $this->seed(EstateLedgerPlanSeeder::class);
        }
        $t = $this->registerTenant(['modules' => ['rentals'], 'currency' => 'UGX']);
        $company = Company::withoutGlobalScopes()->findOrFail($t['company_id']);
        if (($plan = Plan::where('slug', 'estate-ledger-agency')->value('id')) !== null) {
            Subscription::where('company_id', $company->id)->update(['plan_id' => $plan]);
        }
        $owner = User::withoutGlobalScopes()->findOrFail($t['user_id']);
        app(RentalsModule::class)->setup($company, $owner, $settings + ['mode' => $mode, 'grace_days' => 5, 'bill_days_before' => 5,
            'commission_type' => 'percent', 'commission_bp' => 1000, 'deposit_held_by' => 'agency', 'late_fee_type' => 'none']);

        return [$company->fresh(), $owner];
    }

    protected function rentalsStaff(Company $company, string $role): User
    {
        $owner = User::withoutGlobalScopes()->findOrFail($company->owner_id);

        return app(TeamService::class)->createMember($company, $owner, ucfirst(str_replace(['rentals_', '_'], ['', ' '], $role)).' '.uniqid(),
            $role.'_'.uniqid().'@example.com', null, 'Secret-pass-123', $role);
    }

    /** A landlord's property with units "A1".."An" at $rent a month. @return array{0: RentalProperty, 1: list<RentalUnit>} */
    protected function building(User $by, int $units = 3, int $rent = 300000, array $property = []): array
    {
        $cid = (int) $by->company_id;
        $landlordId = RentalsModule::isOwnerMode($cid) ? null : app(Landlords::class)->create($by, ['name' => 'Landlord '.uniqid(), 'phone' => '0772'.random_int(100000, 999999)])->id;
        $p = app(Properties::class)->create($by, $property + ['name' => 'Court '.uniqid(), 'landlord_id' => $landlordId, 'kind' => 'residential']);
        $list = app(Units::class)->createMany($by, (int) $p->id, 'A', 1, $units, ['rent_minor' => $rent, 'kind' => 'apartment']);

        return [$p->fresh(), $list];
    }

    /** Move a new tenant into $unit. @param array<string, mixed> $over */
    protected function tenantIn(User $by, RentalUnit $unit, string $start, array $over = []): RentalLease
    {
        return app(Leases::class)->moveIn($by, $over + ['unit_id' => $unit->id, 'start_date' => $start, 'rent_minor' => $unit->rent_minor,
            'tenant' => ['name' => 'Tenant '.uniqid(), 'phone' => '0701'.random_int(100000, 999999)]]);
    }

    protected function assertBooksAgree(int $companyId): void
    {
        $problems = app(RentalCheck::class)->run($companyId);
        $this->assertSame([], $problems, 'rentals:check: '.json_encode($problems));
    }
}
