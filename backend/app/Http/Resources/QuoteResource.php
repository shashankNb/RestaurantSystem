<?php

namespace App\Http\Resources;

use App\Data\Quote;
use App\Data\QuoteError;
use App\Data\QuoteLine;
use App\Models\ModifierOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The server's prices for a cart, and anything that stops it being ordered as it is.
 *
 * @property Quote $resource
 */
class QuoteResource extends JsonResource
{
    public function __construct(Quote $quote)
    {
        parent::__construct($quote);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quote = $this->resource;

        return [
            'can_place_order' => $quote->canPlaceOrder(),
            'lines' => array_map(fn (QuoteLine $line): array => [
                'menu_item_id' => $line->cartItem->menuItemId,
                'name' => $line->item?->name,
                'quantity' => $line->cartItem->quantity,
                'unit_price_cents' => $line->unitPriceCents,
                'line_total_cents' => $line->lineTotalCents,
                'modifiers' => array_map(fn (ModifierOption $option): array => [
                    'id' => $option->id,
                    'group' => $option->group?->name,
                    'name' => $option->name,
                    'price_delta_cents' => $option->price_delta_cents,
                ], $line->options),
                'notes' => $line->cartItem->notes,
                'errors' => array_map(fn (QuoteError $error): string => $error->message, $line->errors),
            ], $quote->lines),
            'subtotal_cents' => $quote->subtotalCents,
            'delivery_fee_cents' => $quote->deliveryFeeCents,
            'discount_cents' => $quote->discountCents,
            'total_cents' => $quote->totalCents,
            'gst_cents' => $quote->gstCents,
            'promo_code' => $quote->promoCode === null ? null : [
                'code' => $quote->promoCode->code,
                'description' => $quote->promoCode->describe(),
            ],
            'fulfilment' => [
                'type' => $quote->cart->fulfilmentType->value,
                'estimated_minutes' => $quote->estimatedMinutes,
                'delivery_zone' => $quote->deliveryZone === null ? null : new DeliveryZoneResource($quote->deliveryZone),
                'table' => $quote->table?->label,
            ],
            'scheduled_for' => $quote->cart->scheduledFor?->toIso8601ZuluString(),
            'errors' => array_map(fn (QuoteError $error): array => [
                'code' => $error->code,
                'message' => $error->message,
                'field' => $error->field,
            ], $quote->errors),
        ];
    }
}
