<?php

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * restaurant_id is copied from the order item when the modifier is created.
 *
 * @extends Factory<OrderItemModifier>
 */
class OrderItemModifierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'modifier_option_id' => null,
            'group_name' => 'Spice level',
            'name' => 'Medium',
            'price_delta_cents' => 0,
        ];
    }
}
