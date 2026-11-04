<?php

namespace Tests\Feature\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Services\Rentals\Landlords\Payouts;
use App\Services\Rentals\RentalsModule;
use Tests\Concerns\MakesRentals;
use Tests\Feature\Api\ApiTestCase;

/** An owner-run account has no landlords (KR-06): a refused drawing speaks of money in hand, an agency's payout of the landlord. */
class OwnerDrawingWordsTest extends ApiTestCase
{
    use MakesRentals;

    private function refusal(callable $fn): string
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return $e->getMessage();
        }
        $this->fail('Expected a refusal.');
    }

    public function test_a_refused_drawing_names_no_landlord_and_an_agency_payout_still_does(): void
    {
        [$company, $owner] = $this->rentalsAccount('owner');
        $self = RentalsModule::selfLandlord((int) $company->id);
        $above = $this->refusal(fn () => app(Payouts::class)->record($owner, $self, ['amount_minor' => 1000, 'method' => 'cash']));
        $this->assertStringContainsString('in hand from the property', $above);
        $this->assertStringNotContainsStringIgnoringCase('landlord', $above);

        [$agency, $manager] = $this->rentalsAccount('agency');
        [$property] = $this->building($manager, 1);
        $landlord = \App\Models\RentalLandlord::withoutGlobalScopes()->where('company_id', $agency->id)->whereKey($property->landlord_id)->firstOrFail();
        $this->assertStringContainsString('The landlord is owed', $this->refusal(fn () => app(Payouts::class)->record($manager, $landlord, ['amount_minor' => 1000, 'method' => 'cash'])));
    }
}
