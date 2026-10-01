<?php

namespace App\Data;

/**
 * Something that stops the cart being ordered as it is. `message` says what's wrong and
 * how to fix it; `field` points at the part of the request it concerns
 * ("items.0.modifier_option_ids", "postcode", "scheduled_for").
 */
final readonly class QuoteError
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $field = null,
    ) {}
}
