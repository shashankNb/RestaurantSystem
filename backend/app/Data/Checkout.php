<?php

namespace App\Data;

use App\Models\Order;
use App\Payments\PaymentIntent;

/**
 * An order waiting for payment and, for Stripe, the PaymentIntent the app pays it with.
 * (With Square there's nothing to set up first: the app sends its card or wallet token to
 * pay, see SquareCheckoutService.)
 */
final readonly class Checkout
{
    public function __construct(
        public Order $order,
        public ?PaymentIntent $paymentIntent,
        /** False when an earlier request with the same Idempotency-Key created it. */
        public bool $created,
    ) {}
}
