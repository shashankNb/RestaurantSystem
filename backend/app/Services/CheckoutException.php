<?php

namespace App\Services;

use App\Data\Quote;
use RuntimeException;

/**
 * Why an order couldn't be created. The API turns each into its documented status.
 */
final class CheckoutException extends RuntimeException
{
    private function __construct(string $message, public readonly int $status, public readonly ?Quote $quote = null)
    {
        parent::__construct($message);
    }

    /** 422: the cart can't be ordered as it is; the quote says why. */
    public static function notOrderable(Quote $quote): self
    {
        return new self($quote->errors[0]->message ?? 'This order can’t be placed.', 422, $quote);
    }

    /** 422: the key was used before for a different order. */
    public static function keyReused(): self
    {
        return new self('This Idempotency-Key was already used for a different order. Send a new key for a new order.', 422);
    }

    /** 409: the first request with this key is still setting up the payment. */
    public static function inProgress(): self
    {
        return new self('This order is still being set up. Try again in a few seconds.', 409);
    }
}
