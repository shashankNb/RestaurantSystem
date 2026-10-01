<?php

namespace App\Data;

use App\Models\DeliveryZone;

/**
 * Whether a restaurant delivers to a postcode: the zone and its terms, or why not.
 */
final readonly class DeliveryCheck
{
    private function __construct(
        public ?DeliveryZone $zone,
        /** Why delivery isn't possible, in words the customer can act on. */
        public ?string $message,
    ) {}

    public static function deliverable(DeliveryZone $zone): self
    {
        return new self($zone, null);
    }

    public static function notDeliverable(string $message): self
    {
        return new self(null, $message);
    }

    /**
     * @phpstan-assert-if-true !null $this->zone
     */
    public function isDeliverable(): bool
    {
        return $this->zone !== null;
    }
}
