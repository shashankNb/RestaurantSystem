<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\SpecialHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpecialHour>
 */
class SpecialHourFactory extends Factory
{
    /**
     * Closed all day by default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'date' => fake()->dateTimeBetween('+1 day', '+1 month')->format('Y-m-d'),
            'is_closed' => true,
            'opens_at' => null,
            'closes_at' => null,
            'note' => 'Public holiday',
        ];
    }

    public function hours(string $opensAt, string $closesAt): static
    {
        return $this->state([
            'is_closed' => false,
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
            'note' => 'Special hours',
        ]);
    }
}
