<?php

namespace App\Payments;

final readonly class PaymentIntent
{
    public function __construct(
        public string $id,
        /** Given to the app to complete the payment; never logged or stored. */
        public string $clientSecret,
        public string $status,
        public int $amountCents,
    ) {}
}
