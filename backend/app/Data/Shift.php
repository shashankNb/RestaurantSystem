<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * A span of time when a restaurant is open, as UTC instants.
 */
final readonly class Shift
{
    public function __construct(
        public CarbonImmutable $opensAt,
        public CarbonImmutable $closesAt,
    ) {}

    public function contains(CarbonImmutable $at): bool
    {
        return $at->greaterThanOrEqualTo($this->opensAt) && $at->lessThan($this->closesAt);
    }
}
