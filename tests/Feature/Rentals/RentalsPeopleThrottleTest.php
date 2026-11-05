<?php

namespace Tests\Feature\Rentals;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Tenants and landlords are counted one by one, not by the address they share: behind a mobile network thousands of phones can
 * have one address, and one busy person must not lock the others out.
 */
class RentalsPeopleThrottleTest extends RentalsPhoneApiCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withMiddleware(ThrottleRequests::class);
    }

    public function test_one_persons_requests_do_not_use_up_anothers(): void
    {
        $w = $this->world();
        [$first] = $this->person($w['owner'], 'tenant', (int) $w['brian']->tenant_id);
        [$second] = $this->person($w['owner'], 'landlord', (int) $this->landlordOf($w)->id);

        $stopped = 0;
        for ($i = 1; $i <= 260; $i++) {
            $status = $this->getJson('/api/v1/rentals/me', $first)->status();
            if ($status === 429) {
                $stopped = $i;
                break;
            }
            $this->assertSame(200, $status);
        }
        $this->assertGreaterThan(200, $stopped, 'a person has a working budget, and it does run out');

        $this->getJson('/api/v1/rentals/me', $second)->assertOk();
    }
}
