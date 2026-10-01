<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * restaurant_id is copied from the order when the event is created.
 *
 * @extends Factory<OrderStatusEvent>
 */
class OrderStatusEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'from_status' => null,
            'to_status' => OrderStatus::PendingPayment,
            'user_id' => null,
            'note' => null,
        ];
    }
}
