<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * Maps to the LIVE `users` table schema (not Laravel skeleton default).
     */
    public function definition(): array
    {
        return [
            'email'             => fake()->unique()->safeEmail(),
            'employee_id'       => (string) fake()->unique()->numberBetween(1, 999),
            'first_name'        => fake()->firstName(),
            'last_name'         => fake()->lastName(),
            'surname'           => fake()->lastName(),
            'gender'            => fake()->randomElement(['M', 'F']),
            'password'          => static::$password ??= Hash::make('password'),
            'role'              => fake()->randomElement(['officer', 'section_head', 'sub_section_head', 'hr_manager', 'dept_head', 'manager', 'managing_director', 'bod_chairman']),
            'designation'       => fake()->jobTitle(),
            'phone'             => fake()->phoneNumber(),
            'address'           => fake()->address(),
            'profile_image_url' => null,
            'is_active'         => true,
            'last_activity'     => null,
        ];
    }

    /**
     * Indicate that the user is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
