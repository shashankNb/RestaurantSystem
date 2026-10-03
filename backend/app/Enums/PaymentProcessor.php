<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Who takes a restaurant's online payments, into the restaurant's own account. The owner
 * chooses in the back office; each order keeps the processor it was placed with, so its
 * refund goes back the same way after a switch.
 */
enum PaymentProcessor: string implements HasLabel
{
    case Stripe = 'stripe';
    case Square = 'square';

    public function getLabel(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::Square => 'Square',
        };
    }

    public function other(): self
    {
        return $this === self::Stripe ? self::Square : self::Stripe;
    }
}
