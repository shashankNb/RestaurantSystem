<?php

namespace App\Http\Controllers\Api;

use App\Enums\FulfilmentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\SlotsRequest;
use App\Models\Restaurant;
use App\Services\DeliveryService;
use App\Services\OpeningHoursService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * When a customer can have their order: now (ASAP), or one of the 15-minute times the
 * cart offers for later.
 */
class SlotsController extends Controller
{
    public function __invoke(SlotsRequest $request, Restaurant $restaurant, OpeningHoursService $hours, DeliveryService $delivery): JsonResponse
    {
        $now = CarbonImmutable::now();
        $type = $request->fulfilmentType();

        [$offered, $leadMinutes] = $type === FulfilmentType::Pickup
            ? [$restaurant->pickup_enabled, $restaurant->default_prep_minutes]
            : $this->delivery($restaurant, $delivery, $request->string('postcode')->toString());

        $status = $hours->status($restaurant, $now);

        return response()->json([
            'data' => [
                'fulfilment_type' => $type->value,
                'asap' => [
                    'available' => $offered && $status->isOpen && $restaurant->is_accepting_orders,
                    'estimated_minutes' => $leadMinutes,
                ],
                'slots' => $offered
                    ? array_map(fn (CarbonImmutable $slot): string => $slot->toIso8601ZuluString(), $hours->slots($restaurant, $now, $leadMinutes))
                    : [],
            ],
        ]);
    }

    /**
     * Delivery is offered when enabled with an active zone. It takes the zone's delivery
     * time, or the longest one while the postcode isn't known.
     *
     * @return array{0: bool, 1: int}
     */
    private function delivery(Restaurant $restaurant, DeliveryService $delivery, string $postcode): array
    {
        $longest = $delivery->longestEstimatedMinutes($restaurant);

        if (! $restaurant->delivery_enabled || $longest === null) {
            return [false, $restaurant->default_prep_minutes];
        }

        $zone = $postcode === '' ? null : $delivery->zoneFor($restaurant, $postcode);

        return [true, $zone->estimated_minutes ?? $longest];
    }
}
