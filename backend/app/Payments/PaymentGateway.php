<?php

namespace App\Payments;

use App\Models\Order;

/**
 * The payment provider, behind an interface so tests can swap in a fake. Every call that
 * changes something takes an idempotency key, so a retry never charges or refunds twice.
 */
interface PaymentGateway
{
    /**
     * Creates a PaymentIntent for the order's total. Repeating the key returns the same one.
     *
     * @throws PaymentsUnavailable
     */
    public function createPaymentIntent(Order $order, string $idempotencyKey): PaymentIntent;

    /**
     * @throws PaymentsUnavailable
     */
    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent;

    /**
     * Stops an unpaid PaymentIntent from being paid. Does nothing if it can't be cancelled
     * any more (it was paid, or already cancelled).
     *
     * @throws PaymentsUnavailable
     */
    public function cancelPaymentIntent(string $paymentIntentId): void;

    /**
     * Refunds the order's payment in full and returns the refund's ID.
     *
     * @throws PaymentsUnavailable
     */
    public function refund(Order $order, string $idempotencyKey): string;
}
