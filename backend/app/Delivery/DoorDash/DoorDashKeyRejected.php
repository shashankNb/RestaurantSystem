<?php

namespace App\Delivery\DoorDash;

use App\Delivery\CourierUnavailable;
use Throwable;

/**
 * DoorDash didn't accept the restaurant's access key: mistyped, deleted in DoorDash's
 * Developer Portal, or made for the other environment. The owner has to enter it again.
 */
final class DoorDashKeyRejected extends CourierUnavailable
{
    public static function from(Throwable $previous): self
    {
        return new self('DoorDash didn’t accept this restaurant’s access key.', previous: $previous);
    }
}
