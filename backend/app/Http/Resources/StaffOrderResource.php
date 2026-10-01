<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as the kitchen sees it: everything needed to make it, hand it over and reach the
 * customer. Only for staff of the order's restaurant.
 *
 * Expects items.modifiers loaded (and the restaurant, for new orders' deadlines).
 *
 * @mixin Order
 */
class StaffOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'order_number' => $this->display_number,
            'status' => $this->status->value,
            'payment_status' => $this->payment_status->value,
            'fulfilment_type' => $this->fulfilment_type->value,
            'scheduled_for' => $this->scheduled_for?->toIso8601ZuluString(),
            'placed_at' => $this->placed_at?->toIso8601ZuluString(),
            // A new order is rejected (and refunded) automatically if nobody accepts it by then.
            'accept_by' => $this->status === OrderStatus::Placed
                ? app(OrderService::class)->acceptDeadline($this->resource)->toIso8601ZuluString()
                : null,
            'accepted_at' => $this->accepted_at?->toIso8601ZuluString(),
            'prep_minutes' => $this->prep_minutes,
            'estimated_ready_at' => $this->estimated_ready_at?->toIso8601ZuluString(),
            'ready_at' => $this->ready_at?->toIso8601ZuluString(),
            'customer' => [
                'name' => $this->customer_name,
                'phone' => $this->customer_phone,
                'email' => $this->customer_email,
            ],
            'delivery' => $this->delivery_line1 === null ? null : [
                'line1' => $this->delivery_line1,
                'line2' => $this->delivery_line2,
                'suburb' => $this->delivery_suburb,
                'state' => $this->delivery_state,
                'postcode' => $this->delivery_postcode,
                'instructions' => $this->delivery_instructions,
            ],
            'notes' => $this->notes,
            'items' => OrderResource::items($this->resource),
            'total_cents' => $this->total_cents,
        ];
    }
}
