<?php

declare(strict_types=1);

namespace Modules\Merchant\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** @var class-string<User> */
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Required by the schema, so the factory must satisfy it: a bare
            // User::factory()->create() has to produce a valid row.
            'merchant_id' => Merchant::factory(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
