<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            // owner_id is NOT NULL; tests create the owning User afterward and
            // update this via $company->update(['owner_id' => $user->id]).
            'owner_id' => 1,
            'name' => $this->faker->company(),
            'email' => $this->faker->unique()->safeEmail(),
            'status' => 'Active',
            'currency' => 'UGX',
            'license_expire' => now()->addYear(),
        ];
    }
}
