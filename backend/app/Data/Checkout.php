<?php

namespace App\Data;

use App\Models\Order;
use App\Payments\PaymentIntent;

/**
 * An order waiting for payment and the PaymentIntent the app pays it with.
 */
final readonly class Checkout
{
    public function __construct(
        public Order $order,
        public PaymentIntent $paymentIntent,
        /** False when an earlier request with the same Idempotency-Key created it. */
        public bool $created,
    ) {}
}
