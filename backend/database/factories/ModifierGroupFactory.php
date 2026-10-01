<?php

namespace Database\Factories;

use App\Models\ModifierGroup;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Optional, pick up to one, by default.
 *
 * @extends Factory<ModifierGroup>
 */
class ModifierGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'name' => ucfirst(fake()->unique()->word().' '.fake()->word()),
            'min_select' => 0,
            'max_select' => 1,
            'sort_order' => 0,
        ];
    }

    public function selecting(int $min, int $max): static
    {
        return $this->state(['min_select' => $min, 'max_select' => $max]);
    }
}
