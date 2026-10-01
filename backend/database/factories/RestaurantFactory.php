<?php

namespace Database\Factories;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Restaurant>
 */
class RestaurantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->lastName().' Kitchen';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => fake()->sentence(),
            'timezone' => 'Australia/Melbourne',
            'currency' => 'AUD',
            'phone' => fake()->numerify('03 #### ####'),
            'email' => fake()->unique()->safeEmail(),
            'address' => [
                'line1' => fake()->numberBetween(1, 400).' Swanston Street',
                'line2' => null,
                'suburb' => 'Melbourne',
                'state' => 'VIC',
                'postcode' => '3000',
                'country' => 'AU',
            ],
            'abn' => fake()->numerify('## ### ### ###'),
            'brand_color' => '#7A1F2B',
            'is_accepting_orders' => true,
            'pickup_enabled' => true,
            'delivery_enabled' => true,
            'default_prep_minutes' => 20,
            'auto_reject_minutes' => 10,
        ];
    }

    public function paused(): static
    {
        return $this->state(['is_accepting_orders' => false]);
    }
}
