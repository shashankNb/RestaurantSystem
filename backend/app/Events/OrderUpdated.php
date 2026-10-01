<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order changed status. Sent to the restaurant's staff channel and to the order's own
 * channel for the customer's tracking screen. Carries status and times only, never names,
 * addresses or items: listeners fetch details from the API if they need them.
 *
 * Broadcast straight away rather than queued (it's dispatched after the database commit),
 * so new orders reach the kitchen within a second. See OrderService::announce().
 */
class OrderUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("restaurant.{$this->order->restaurant_id}.orders"),
            new Channel("order.{$this->order->public_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }

    /**
     * @return array<string, string|null>
     */
    public function broadcastWith(): array
    {
        $order = $this->order;

        return [
            'public_id' => $order->public_id,
            'order_number' => $order->display_number,
            'status' => $order->status->value,
            'fulfilment_type' => $order->fulfilment_type->value,
            'scheduled_for' => $order->scheduled_for?->toIso8601ZuluString(),
            'placed_at' => $order->placed_at?->toIso8601ZuluString(),
            'accepted_at' => $order->accepted_at?->toIso8601ZuluString(),
            'estimated_ready_at' => $order->estimated_ready_at?->toIso8601ZuluString(),
            'ready_at' => $order->ready_at?->toIso8601ZuluString(),
            'completed_at' => $order->completed_at?->toIso8601ZuluString(),
            'rejected_at' => $order->rejected_at?->toIso8601ZuluString(),
            'cancelled_at' => $order->cancelled_at?->toIso8601ZuluString(),
            'updated_at' => $order->updated_at?->toIso8601ZuluString(),
        ];
    }
}
