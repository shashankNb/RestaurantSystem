<?php

namespace App\Payments;

use RuntimeException;

/**
 * The payment provider couldn't be reached, refused the request, or isn't configured.
 * The API answers 503 so the app can offer to try again.
 */
final class PaymentsUnavailable extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('Payments aren’t set up yet. Add the Stripe keys to the backend’s .env.');
    }

    public static function because(\Throwable $previous): self
    {
        return new self('We couldn’t reach the payment provider. Try again in a moment.', previous: $previous);
    }
}
