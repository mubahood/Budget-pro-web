<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 *
 * Matches the real `admin_users` schema (Encore Admin's Administrator table),
 * which has no `email_verified_at` or `remember_token` columns — unlike
 * Laravel's stock scaffold this factory replaced.
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'first_name' => $first,
            'last_name' => $last,
            'name' => "{$first} {$last}",
            'username' => fake()->unique()->safeEmail(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => 'Active',
        ];
    }
}
