<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Onboarding\RegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A sign-up may name any business type the setup wizard offers (config('onboarding.business_types')),
 * and the types older apps send keep working. The new shop website offers the wizard's list.
 */
class RegistrationBusinessTypesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\AdminRolesSeeder::class);
        $this->seed(\Database\Seeders\PlanSeeder::class);
    }

    public function test_the_accepted_types_are_the_old_list_plus_every_wizard_type(): void
    {
        $types = RegistrationService::businessTypes();
        foreach (RegistrationService::BUSINESS_TYPES as $old) {
            $this->assertContains($old, $types);
        }
        foreach (array_keys(config('onboarding.business_types')) as $wizard) {
            $this->assertContains($wizard, $types);
        }
        $this->assertSame(count($types), count(array_unique($types)));
    }

    public function test_api_register_accepts_a_wizard_type_and_stores_it(): void
    {
        $email = 'bt_'.uniqid().'@example.com';
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Bar', 'last_name' => 'Owner', 'email' => $email, 'password' => 'secret123',
            'company_name' => 'Corner Bar', 'currency' => 'UGX', 'business_type' => 'restaurant_bar',
        ])->assertStatus(201);

        $user = User::withoutGlobalScopes()->where('email', $email)->first();
        $company = Company::withoutGlobalScopes()->where('owner_id', $user->id)->first();
        $this->assertSame('restaurant_bar', $company->business_type);
        $this->assertSame(['shop', 'finance'], $company->modules());
    }

    public function test_old_types_still_work_and_unknown_types_are_refused(): void
    {
        $base = ['first_name' => 'A', 'last_name' => 'B', 'password' => 'secret123', 'company_name' => 'C', 'currency' => 'UGX'];
        $this->postJson('/api/v1/auth/register', $base + ['email' => 'old_'.uniqid().'@example.com', 'business_type' => 'restaurant'])->assertStatus(201);
        $this->postJson('/api/v1/auth/register', $base + ['email' => 'bad_'.uniqid().'@example.com', 'business_type' => 'spaceship'])
            ->assertStatus(422)->assertJsonValidationErrors(['business_type']);
    }
}
