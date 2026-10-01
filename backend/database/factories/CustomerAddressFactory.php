<?php

namespace Database\Factories;

use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAddress>
 */
class CustomerAddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'Home',
            'line1' => fake()->numberBetween(1, 200).' City Road',
            'line2' => null,
            'suburb' => 'Southbank',
            'state' => 'VIC',
            'postcode' => '3006',
            'delivery_instructions' => null,
        ];
    }
}
