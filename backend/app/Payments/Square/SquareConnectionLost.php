<?php

namespace App\Payments\Square;

use App\Payments\PaymentsUnavailable;
use Throwable;

/**
 * Square doesn't accept the restaurant's access token: it's wrong, from the other
 * environment than its application ID, or was replaced in Square's Developer Console. The
 * owner has to enter it again.
 */
final class SquareConnectionLost extends PaymentsUnavailable
{
    public static function from(Throwable $previous): self
    {
        return new self('Square didn’t accept this restaurant’s access token.', previous: $previous);
    }
}
