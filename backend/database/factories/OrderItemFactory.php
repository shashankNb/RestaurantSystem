<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * restaurant_id is copied from the order when the item is created.
 *
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->numberBetween(8, 25) * 100;
        $quantity = fake()->numberBetween(1, 3);

        return [
            'order_id' => Order::factory(),
            'menu_item_id' => null,
            'name' => ucwords(fake()->word().' '.fake()->word()),
            'unit_price_cents' => $unitPrice,
            'quantity' => $quantity,
            'line_total_cents' => $unitPrice * $quantity,
            'notes' => null,
        ];
    }
}
