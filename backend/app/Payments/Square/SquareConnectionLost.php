<?php

namespace App\Payments\Square;

use App\Payments\PaymentsUnavailable;
use Throwable;

/**
 * Square no longer accepts the restaurant's connection: the owner revoked it, or its refresh
 * token was refused. The owner has to connect Square again.
 */
final class SquareConnectionLost extends PaymentsUnavailable
{
    public static function from(Throwable $previous): self
    {
        return new self('This restaurant’s Square account isn’t connected any more.', previous: $previous);
    }
}
