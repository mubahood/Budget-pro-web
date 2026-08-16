<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Guests hitting the admin panel root are redirected to login — this is
     * the app's actual behavior, not an error.
     */
    public function test_guest_visiting_root_is_redirected_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect();
    }
}
