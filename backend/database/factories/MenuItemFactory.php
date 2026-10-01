<?php

namespace Database\Factories;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * restaurant_id is copied from the category when the item is created.
 *
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => MenuCategory::factory(),
            'name' => ucwords(fake()->unique()->word().' '.fake()->word()),
            'description' => fake()->sentence(),
            'price_cents' => fake()->numberBetween(8, 25) * 100,
            'image' => null,
            'dietary_tags' => [],
            'allergens' => [],
            'is_available' => true,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function soldOut(): static
    {
        return $this->state(['is_available' => false]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
