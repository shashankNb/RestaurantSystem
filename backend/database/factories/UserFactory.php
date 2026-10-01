<?php

namespace Database\Factories;

use App\Enums\RestaurantRole;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('04## ### ###'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
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

    public function ownerOf(Restaurant $restaurant): static
    {
        return $this->withRoleAt($restaurant, RestaurantRole::Owner);
    }

    public function staffOf(Restaurant $restaurant): static
    {
        return $this->withRoleAt($restaurant, RestaurantRole::Staff);
    }

    private function withRoleAt(Restaurant $restaurant, RestaurantRole $role): static
    {
        return $this->afterCreating(function (User $user) use ($restaurant, $role): void {
            $restaurant->memberships()->create(['user_id' => $user->id, 'role' => $role]);
        });
    }
}
