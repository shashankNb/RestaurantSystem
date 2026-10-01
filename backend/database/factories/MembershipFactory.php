<?php

namespace Database\Factories;

use App\Enums\RestaurantRole;
use App\Models\Membership;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'user_id' => User::factory(),
            'role' => RestaurantRole::Staff,
        ];
    }

    public function owner(): static
    {
        return $this->state(['role' => RestaurantRole::Owner]);
    }
}
