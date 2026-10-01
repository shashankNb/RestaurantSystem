<?php

namespace Database\Factories;

use App\Enums\PromoCodeType;
use App\Models\PromoCode;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 10% off with no limits, by default.
 *
 * @extends Factory<PromoCode>
 */
class PromoCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'code' => fake()->unique()->bothify('SAVE####'),
            'type' => PromoCodeType::Percent,
            'value' => 10,
            'min_order_cents' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'max_uses' => null,
            'uses_count' => 0,
            'is_active' => true,
        ];
    }

    public function fixed(int $cents): static
    {
        return $this->state(['type' => PromoCodeType::Fixed, 'value' => $cents]);
    }

    public function expired(): static
    {
        return $this->state(['starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]);
    }

    public function notStarted(): static
    {
        return $this->state(['starts_at' => now()->addDay(), 'ends_at' => null]);
    }

    public function usedUp(): static
    {
        return $this->state(['max_uses' => 5, 'uses_count' => 5]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
