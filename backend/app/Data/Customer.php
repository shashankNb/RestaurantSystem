<?php

namespace App\Data;

/**
 * Who the order is for and, for delivery, where it goes. Copied onto the order, so later
 * changes to the customer's account or saved addresses never alter it.
 */
final readonly class Customer
{
    public function __construct(
        public string $name,
        public string $phone,
        public string $email,
        public ?string $deliveryLine1 = null,
        public ?string $deliveryLine2 = null,
        public ?string $deliverySuburb = null,
        public ?string $deliveryState = null,
        public ?string $deliveryInstructions = null,
        public ?string $notes = null,
        /** An Expo push token, for guests who want notifications about this order. */
        public ?string $pushToken = null,
    ) {}
}
