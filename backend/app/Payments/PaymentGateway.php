<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\Restaurant;

/**
 * The payment provider, behind an interface so tests can swap in a fake. Every call that
 * changes something takes an idempotency key, so a retry never charges or refunds twice.
 * Each call is about one order, and goes to its restaurant's own Stripe account.
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
     * The order's PaymentIntent (stripe_payment_intent_id).
     *
     * @throws PaymentsUnavailable
     */
    public function retrievePaymentIntent(Order $order): PaymentIntent;

    /**
     * Stops the order's unpaid PaymentIntent from being paid. Does nothing if it can't be
     * cancelled any more (it was paid, or already cancelled).
     *
     * @throws PaymentsUnavailable
     */
    public function cancelPaymentIntent(Order $order): void;

    /**
     * Refunds the order's payment in full and returns the refund's ID.
     *
     * @throws PaymentsUnavailable
     */
    public function refund(Order $order, string $idempotencyKey): string;

    /**
     * Checks the restaurant's secret key with its Stripe account and returns the account's
     * name, for the back office.
     *
     * @throws PaymentsUnavailable
     */
    public function accountName(Restaurant $restaurant): string;

    /**
     * Whether Apple Pay and Google Pay are switched on in the restaurant's Stripe account,
     * and, given its website's domain, registers that domain for them if it isn't yet.
     *
     * @throws PaymentsUnavailable
     */
    public function prepareWallets(Restaurant $restaurant, ?string $domain): WalletSetup;

    /**
     * Switches Apple Pay and Google Pay on in the restaurant's Stripe account (its default
     * payment method settings). Only ever at the owner's request: it changes their account.
     *
     * @throws PaymentsUnavailable
     */
    public function turnOnWallets(Restaurant $restaurant): void;
}
