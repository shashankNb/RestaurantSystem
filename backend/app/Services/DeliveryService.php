<?php

namespace App\Services;

use App\Data\DeliveryCheck;
use App\Models\DeliveryZone;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Collection;

/**
 * Delivery areas are lists of postcodes. A postcode can sit in more than one active zone;
 * the customer then gets the cheapest.
 */
final class DeliveryService
{
    public function check(Restaurant $restaurant, string $postcode): DeliveryCheck
    {
        $postcode = self::normalisePostcode($postcode);
        $zones = $this->activeZones($restaurant);

        if (! $restaurant->delivery_enabled || $zones->isEmpty()) {
            return DeliveryCheck::notDeliverable('We’re not delivering at the moment. Choose pickup instead.');
        }

        $zone = $this->cheapest($zones->filter(fn (DeliveryZone $zone): bool => $zone->coversPostcode($postcode)));

        if ($zone === null) {
            $covered = $zones->flatMap(fn (DeliveryZone $zone): array => $zone->postcodes)->unique()->sort()->values()->all();

            return DeliveryCheck::notDeliverable(
                "We don’t deliver to {$postcode}. We deliver to ".self::listOf($covered).'. Choose pickup, or use an address in one of those postcodes.',
            );
        }

        return DeliveryCheck::deliverable($zone);
    }

    /**
     * The zone that delivers to a postcode, or null.
     */
    public function zoneFor(Restaurant $restaurant, string $postcode): ?DeliveryZone
    {
        return $this->check($restaurant, $postcode)->zone;
    }

    /**
     * The longest delivery time of any active zone: the lead time for delivery when the
     * customer's postcode isn't known yet.
     */
    public function longestEstimatedMinutes(Restaurant $restaurant): ?int
    {
        $longest = $this->activeZones($restaurant)->max('estimated_minutes');

        return is_numeric($longest) ? (int) $longest : null;
    }

    public static function normalisePostcode(string $postcode): string
    {
        return preg_replace('/\s+/', '', $postcode) ?? $postcode;
    }

    /**
     * @return Collection<int, DeliveryZone>
     */
    private function activeZones(Restaurant $restaurant): Collection
    {
        return $restaurant->deliveryZones()->where('is_active', true)->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, DeliveryZone>  $zones
     */
    private function cheapest(Collection $zones): ?DeliveryZone
    {
        return $zones->sortBy([['fee_cents', 'asc'], ['id', 'asc']])->first();
    }

    /**
     * "3000", "3000 and 3006", "3000, 3006 and 3008".
     *
     * @param  array<int, string>  $items
     */
    private static function listOf(array $items): string
    {
        $items = array_values($items);

        if (count($items) <= 1) {
            return implode('', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
