<?php

namespace App\Data;

/**
 * Where a delivery goes, as the customer entered it. The restaurant's own delivery zones need
 * only the postcode; DoorDash needs the street and suburb too.
 */
final readonly class DeliveryAddress
{
    public function __construct(
        public ?string $line1,
        public ?string $line2,
        public ?string $suburb,
        public ?string $state,
        public ?string $postcode,
    ) {}

    /** Enough of it for a courier to find. */
    public function isComplete(): bool
    {
        return filled($this->line1) && filled($this->suburb) && filled($this->postcode);
    }

    /**
     * The address on one line, the way couriers take it: "12 Southbank Boulevard, Southbank
     * VIC 3006, Australia" (the platform's restaurants are all in Australia). The second line,
     * an apartment say, goes in the driver's instructions instead, where it can't confuse the
     * map.
     */
    public function oneLine(): string
    {
        return implode(', ', array_filter([
            $this->line1,
            implode(' ', array_filter([$this->suburb, $this->state, $this->postcode])),
            'Australia',
        ]));
    }
}
