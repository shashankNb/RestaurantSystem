<?php

namespace App\Delivery;

use RuntimeException;
use Throwable;

/**
 * The courier (DoorDash) couldn't be reached, didn't accept the restaurant's access key, or
 * isn't set up. Nothing was booked or changed, so it can be tried again. The API answers 503.
 */
class CourierUnavailable extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('DoorDash isn’t set up for this restaurant.');
    }

    public static function because(Throwable $previous): self
    {
        return new self('We couldn’t reach DoorDash. Try again in a moment.', previous: $previous);
    }
}
