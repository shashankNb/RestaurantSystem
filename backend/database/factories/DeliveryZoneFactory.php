<?php

namespace Database\Factories;

use App\Models\DeliveryZone;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryZone>
 */
class DeliveryZoneFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'name' => 'Inner Melbourne',
            'postcodes' => ['3000', '3006', '3008'],
            'fee_cents' => 600,
            'min_order_cents' => 2500,
            'estimated_minutes' => 45,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
