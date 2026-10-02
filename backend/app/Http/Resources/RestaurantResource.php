<?php

namespace App\Http\Resources;

use App\Data\OpeningStatus;
use App\Models\OpeningHour;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything the app needs at start-up: branding, contact details, whether it can take
 * orders right now, and how customers can get their food.
 *
 * Expects openingHours, specialHours (upcoming only) and deliveryZones (active only) loaded.
 *
 * @mixin Restaurant
 */
class RestaurantResource extends JsonResource
{
    public function __construct(Restaurant $resource, private readonly OpeningStatus $status)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'abn' => $this->abn,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'brand_color' => $this->brand_color,
            'logo_url' => $this->logo_url,
            'cover_image_url' => $this->cover_image_url,
            'status' => [
                'is_open' => $this->status->isOpen,
                'is_accepting_orders' => $this->is_accepting_orders,
                'can_order_asap' => $this->status->isOpen && $this->is_accepting_orders,
                'closes_at' => $this->status->closesAt?->toIso8601ZuluString(),
                'next_opening_at' => $this->status->nextOpeningAt?->toIso8601ZuluString(),
            ],
            'fulfilment' => [
                'pickup' => [
                    'enabled' => $this->pickup_enabled,
                    'prep_minutes' => $this->default_prep_minutes,
                ],
                'delivery' => [
                    'enabled' => $this->delivery_enabled && $this->deliveryZones->isNotEmpty(),
                    'zones' => DeliveryZoneResource::collection($this->deliveryZones),
                ],
                'dine_in' => [
                    'enabled' => $this->offersDineIn(),
                    // Labels customers choose from, in the restaurant's order.
                    'tables' => $this->offersDineIn() ? $this->activeDiningTables->pluck('label')->values()->all() : [],
                ],
            ],
            'opening_hours' => OpeningHourResource::collection(
                // Monday first, then each day's shifts in time order.
                $this->openingHours
                    ->sortBy(fn (OpeningHour $hours): string => (($hours->day_of_week + 6) % 7).$hours->opens_at)
                    ->values(),
            ),
            'special_hours' => SpecialHourResource::collection($this->specialHours->sortBy('date')->values()),
            'payments' => [
                // The restaurant's own Stripe account, for the apps' payment forms. Only the
                // publishable key, and only once all its keys are in (until then the apps say
                // payments aren't set up, and checkout refuses).
                'stripe_publishable_key' => $this->acceptsPayments() ? $this->stripe_publishable_key : null,
            ],
        ];
    }
}
