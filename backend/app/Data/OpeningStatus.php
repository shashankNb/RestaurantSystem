<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * Whether a restaurant is inside its opening hours at a given moment. Pausing orders is
 * separate: a paused restaurant can be open but not accepting orders.
 */
final readonly class OpeningStatus
{
    public function __construct(
        public bool $isOpen,
        /** When the current opening ends; null while closed. */
        public ?CarbonImmutable $closesAt,
        /** When it next opens; null while open, or when no opening is scheduled soon. */
        public ?CarbonImmutable $nextOpeningAt,
    ) {}
}
