<?php

namespace App\Http\Resources;

use App\Enums\FulfilmentType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\OrderStatusEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as its customer sees it: status, timeline, items and totals. Contact details
 * and the street address are left out, because a tracking link can be forwarded.
 *
 * Expects restaurant, items.modifiers and statusEvents loaded.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
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
            'estimated_ready_at' => $this->estimated_ready_at?->toIso8601ZuluString(),
            'placed_at' => $this->placed_at?->toIso8601ZuluString(),
            'accepted_at' => $this->accepted_at?->toIso8601ZuluString(),
            'ready_at' => $this->ready_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'rejected_at' => $this->rejected_at?->toIso8601ZuluString(),
            'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
            'refunded_at' => $this->refunded_at?->toIso8601ZuluString(),
            'rejection_reason' => $this->rejection_reason,
            'restaurant' => [
                'slug' => $this->restaurant->slug,
                'name' => $this->restaurant->name,
                'phone' => $this->restaurant->phone,
            ],
            'delivery' => $this->fulfilment_type === FulfilmentType::Delivery ? [
                'suburb' => $this->delivery_suburb,
                'postcode' => $this->delivery_postcode,
            ] : null,
            'items' => self::items($this->resource),
            'subtotal_cents' => $this->subtotal_cents,
            'delivery_fee_cents' => $this->delivery_fee_cents,
            'discount_cents' => $this->discount_cents,
            'total_cents' => $this->total_cents,
            'gst_cents' => $this->gst_cents,
            'promo_code' => $this->promo_code,
            'timeline' => $this->statusEvents->map(fn (OrderStatusEvent $event): array => [
                'status' => $event->to_status->value,
                'at' => $event->created_at->toIso8601ZuluString(),
            ])->values(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function items(Order $order): array
    {
        return $order->items->map(fn (OrderItem $item): array => [
            // IDs let "Order again" rebuild the cart; they're null if the item has since been deleted.
            'menu_item_id' => $item->menu_item_id,
            'name' => $item->name,
            'quantity' => $item->quantity,
            'unit_price_cents' => $item->unit_price_cents,
            'line_total_cents' => $item->line_total_cents,
            'notes' => $item->notes,
            'modifiers' => $item->modifiers->map(fn (OrderItemModifier $modifier): array => [
                'modifier_option_id' => $modifier->modifier_option_id,
                'group' => $modifier->group_name,
                'name' => $modifier->name,
                'price_delta_cents' => $modifier->price_delta_cents,
            ])->values()->all(),
        ])->values()->all();
    }
}
